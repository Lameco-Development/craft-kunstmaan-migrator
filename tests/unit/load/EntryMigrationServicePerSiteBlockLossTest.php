<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\load;

use craft\elements\Entry;
use craft\enums\PropagationMethod;
use craft\fields\Matrix;
use craft\fields\PlainText;
use craft\models\FieldLayout;
use Lameco\Kunstmaanmigrator\load\EntryMigrationService;
use Lameco\Kunstmaanmigrator\load\MigrationReport;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * A Matrix field with `propagationMethod: all` keeps one block set shared
 * across every site, so locales carrying *different* legacy parts cannot both
 * survive — each save replaces the other's, and the losing locale renders the
 * winner's content. The loader cannot fix a Craft-side field configuration;
 * what it must do is say so before writing, once per entry+field.
 */
final class EntryMigrationServicePerSiteBlockLossTest extends TestCase
{
    private function report(EntryMigrationService $svc, Entry $entry, array $perSite, ?MigrationReport $report): void
    {
        (new ReflectionMethod(EntryMigrationService::class, 'reportUnrepresentablePerSiteBlocks'))
            ->invoke($svc, $entry, $perSite, $report, 'App_Entity_Pages_TextPage', '5');
    }

    /** @param list<string> $refs */
    private function siteData(array $refs): array
    {
        return ['fieldValues' => ['pageBuilder' => array_map(
            static fn(string $ref): array => ['type' => 'text', 'fields' => ['_sourcePartRef' => $ref]],
            $refs,
        )]];
    }

    public function testDivergentLocalesOnASharedBlockSetAreReportedAsALoss(): void
    {
        $svc = new EntryMigrationService();
        $report = new MigrationReport();
        $entry = LossStubEntry::withField(LossStubEntry::matrix(PropagationMethod::All));

        $this->report($svc, $entry, [
            'default' => $this->siteData(['Text:1', 'Text:2']),
            'en' => $this->siteData(['Text:1', 'Text:3']),
        ], $report);

        self::assertCount(1, $svc->perSiteBlockLosses);
        self::assertStringContainsString('field "pageBuilder"', $svc->perSiteBlockLosses[0]);
        self::assertStringContainsString('2 locales', $svc->perSiteBlockLosses[0]);
        self::assertSame(1, $report->counts['fallback.perSiteBlocksNotRepresentable'] ?? 0);
    }

    public function testIdenticalRefsAcrossLocalesAreRepresentableAndStaySilent(): void
    {
        // The common case — warning on it would make the warning worthless.
        $svc = new EntryMigrationService();
        $entry = LossStubEntry::withField(LossStubEntry::matrix(PropagationMethod::All));

        $this->report($svc, $entry, [
            'default' => $this->siteData(['Text:1', 'Text:2']),
            'en' => $this->siteData(['Text:1', 'Text:2']),
        ], null);

        self::assertSame([], $svc->perSiteBlockLosses);
    }

    public function testASingleLocaleCanNeverDiverge(): void
    {
        $svc = new EntryMigrationService();
        $entry = LossStubEntry::withField(LossStubEntry::matrix(PropagationMethod::All));

        $this->report($svc, $entry, ['default' => $this->siteData(['Text:1'])], null);

        self::assertSame([], $svc->perSiteBlockLosses);
    }

    public function testAPerSiteBlockSetRepresentsDivergentLocalesFine(): void
    {
        // propagationMethod: none gives every site its own nested entries —
        // exactly the fix the warning tells the operator to make.
        $svc = new EntryMigrationService();
        $entry = LossStubEntry::withField(LossStubEntry::matrix(PropagationMethod::None));

        $this->report($svc, $entry, [
            'default' => $this->siteData(['Text:1']),
            'en' => $this->siteData(['Text:2']),
        ], null);

        self::assertSame([], $svc->perSiteBlockLosses);
    }

    public function testAFieldTheLayoutCannotResolveToAMatrixIsNotJudged(): void
    {
        $svc = new EntryMigrationService();
        $plainText = (new \ReflectionClass(PlainText::class))->newInstanceWithoutConstructor();
        $entry = LossStubEntry::withField($plainText);

        $this->report($svc, $entry, [
            'default' => $this->siteData(['Text:1']),
            'en' => $this->siteData(['Text:2']),
        ], null);

        self::assertSame([], $svc->perSiteBlockLosses);
    }
}

/**
 * Supplies the field layout the propagation probe resolves the field through —
 * page-builder fields are layout instances, so the layout is the only
 * authority the production code consults.
 *
 * @internal
 */
final class LossStubEntry extends Entry
{
    private ?object $field = null;

    public static function withField(?object $field): self
    {
        $entry = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $entry->field = $field;

        return $entry;
    }

    public static function matrix(PropagationMethod $method): Matrix
    {
        $matrix = (new \ReflectionClass(Matrix::class))->newInstanceWithoutConstructor();
        $matrix->propagationMethod = $method;

        return $matrix;
    }

    public function getFieldLayout(): ?FieldLayout
    {
        return LossStubFieldLayout::withField($this->field);
    }
}

/**
 * @internal
 */
final class LossStubFieldLayout extends FieldLayout
{
    private ?object $field = null;

    public static function withField(?object $field): self
    {
        $layout = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $layout->field = $field;

        return $layout;
    }

    public function getFieldByHandle(string $handle): ?\craft\base\FieldInterface
    {
        /** @var \craft\base\FieldInterface|null $field */
        $field = $this->field;

        return $field;
    }
}
