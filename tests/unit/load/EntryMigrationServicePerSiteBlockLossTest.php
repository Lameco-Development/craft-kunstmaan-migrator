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
use Lameco\Kunstmaanmigrator\run\RunTally;
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
    /** Where the loss is counted — the run's, so console and queue both see it. */
    private RunTally $tally;

    protected function setUp(): void
    {
        $this->tally = new RunTally();
    }

    private function report(EntryMigrationService $svc, Entry $entry, array $perSite, ?MigrationReport $report): void
    {
        (new ReflectionMethod(EntryMigrationService::class, 'reportUnrepresentablePerSiteBlocks'))
            ->invoke($svc, $entry, $perSite, $report, 'App_Entity_Pages_TextPage', '5', $this->tally);
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

        self::assertCount(1, $this->tally->perSiteBlockLosses);
        self::assertStringContainsString('field "pageBuilder"', $this->tally->perSiteBlockLosses[0]);
        self::assertStringContainsString('2 locales', $this->tally->perSiteBlockLosses[0]);
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

        self::assertSame([], $this->tally->perSiteBlockLosses);
    }

    public function testASingleLocaleCanNeverDiverge(): void
    {
        $svc = new EntryMigrationService();
        $entry = LossStubEntry::withField(LossStubEntry::matrix(PropagationMethod::All));

        $this->report($svc, $entry, ['default' => $this->siteData(['Text:1'])], null);

        self::assertSame([], $this->tally->perSiteBlockLosses);
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

        self::assertSame([], $this->tally->perSiteBlockLosses);
    }

    /** @param list<string> $labels */
    private function buttonSiteData(array $labels): array
    {
        return ['fieldValues' => ['heroButtons' => array_map(
            static fn(string $label): array => ['type' => 'button', 'fields' => [
                'commonLink' => ['value' => 'https://enreach.com/contact', 'label' => $label],
            ]],
            $labels,
        )]];
    }

    /**
     * The blind spot. `heroButtons` comes from the sidecar lane, so its blocks carry no
     * `_sourcePartRef` — measured, 0 of 102 against 3,298 of 3,316 page-builder blocks. The
     * check keyed on refs alone, so the field never reached the divergence test and the loss
     * shipped silently: `/en-us/products/ms-365` served the Danish button "Kontakt os".
     */
    public function testRefLessBlocksThatDivergeAcrossLocalesAreReported(): void
    {
        $svc = new EntryMigrationService();
        $report = new MigrationReport();
        $entry = LossStubEntry::withField(LossStubEntry::matrix(PropagationMethod::All));

        $this->report($svc, $entry, [
            'comEnUs' => $this->buttonSiteData(['More information']),
            'comDkDa' => $this->buttonSiteData(['Kontakt os']),
        ], $report);

        self::assertCount(1, $this->tally->perSiteBlockLosses);
        self::assertStringContainsString('field "heroButtons"', $this->tally->perSiteBlockLosses[0]);
        self::assertSame(1, $report->counts['fallback.perSiteBlocksNotRepresentable'] ?? 0);
    }

    /** The same button in every locale is representable, and must stay quiet. */
    public function testRefLessBlocksThatAgreeAcrossLocalesStaySilent(): void
    {
        $svc = new EntryMigrationService();
        $entry = LossStubEntry::withField(LossStubEntry::matrix(PropagationMethod::All));

        $this->report($svc, $entry, [
            'comEnUs' => $this->buttonSiteData(['More information']),
            'comNlNl' => $this->buttonSiteData(['More information']),
        ], null);

        self::assertSame([], $this->tally->perSiteBlockLosses);
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

        self::assertSame([], $this->tally->perSiteBlockLosses);
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
