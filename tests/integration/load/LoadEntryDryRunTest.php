<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\integration\load;

use Lameco\KumaCompile\Payload\PayloadValidator;
use Lameco\KumaCompile\Payload\SchemaGateway;
use Lameco\Kunstmaanmigrator\console\LoadController;
use PHPUnit\Framework\TestCase;
use yii\console\ExitCode;

/**
 * Task 3 — end-to-end dry-run behavior: real files on disk, through
 * `LoadController::readRecords()` (private, exercised indirectly) →
 * `Payload::fromArray()` → `PayloadValidator::validate()` →
 * `LoadController::buildReport()`/`exitCodeFor()`.
 *
 * Knows exactly the section/entryType/site/fields the fixture payloads
 * below use; anything else resolves to "unknown" so UNKNOWN_FIELD is driven
 * purely by the payload data, matching PayloadValidatorTest's FakeSchemaGateway
 * convention.
 */
final class DryRunFakeSchemaGateway implements SchemaGateway
{
    public function sectionByHandle(string $handle): ?array
    {
        return $handle === 'pages' ? ['id' => 1, 'handle' => 'pages'] : null;
    }

    public function entryTypeByHandle(string $handle): ?array
    {
        return $handle === 'contentPage' ? ['id' => 1, 'handle' => 'contentPage', 'hasTitleFormat' => false] : null;
    }

    public function primarySite(): array
    {
        return ['id' => 1, 'handle' => 'en'];
    }

    public function siteByHandle(string $handle): ?array
    {
        return $handle === 'en' ? ['id' => 1, 'handle' => 'en'] : null;
    }

    public function fieldHandlesFor(string $entryTypeHandle): array
    {
        return $entryTypeHandle === 'contentPage' ? ['body'] : [];
    }

    /** Derived from the same fixtures the other lookups use, so fakes stay consistent. */
    public function fieldSlotsFor(string $entryTypeHandle): array
    {
        $slots = [];

        foreach ($this->fieldHandlesFor($entryTypeHandle) as $handle) {
            $nested = $this->blockTypesFor($entryTypeHandle, $handle);
            $slots[$handle] = [
                'type' => $nested === [] ? 'PlainText' : 'Matrix',
                'required' => false,
                'nested' => $nested,
            ];
        }

        return $slots;
    }

    public function blockTypesFor(string $entryTypeHandle, string $fieldHandle): array
    {
        return [];
    }
}

final class LoadEntryDryRunTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/kunstmaan-migrator-dryrun-' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    private function validator(): PayloadValidator
    {
        return new PayloadValidator(new DryRunFakeSchemaGateway());
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $sourceUid, array $fieldValues = []): array
    {
        return [
            'sourceUid' => $sourceUid,
            'section' => 'pages',
            'entryType' => 'contentPage',
            'sites' => [
                'en' => [
                    'enabled' => true,
                    'title' => 'Entry ' . $sourceUid,
                    'slug' => 'entry-' . str_replace(':', '-', $sourceUid),
                    'fieldValues' => $fieldValues,
                ],
            ],
        ];
    }

    private function write(string $relativeName, string $contents): string
    {
        $path = $this->dir . '/' . $relativeName;
        file_put_contents($path, $contents);

        return $path;
    }

    public function testNdjsonWithOneValidAndOneUnknownFieldMutantProducesOneViolationAndExitOne(): void
    {
        $valid = $this->payload('kuma:COM:nt_page:1');
        $mutant = $this->payload('kuma:COM:nt_page:2', ['bogusField' => 'x']);

        $path = $this->write('mixed.ndjson', json_encode($valid) . "\n" . json_encode($mutant) . "\n");

        $report = LoadController::buildReport($path, $this->validator());

        self::assertSame(2, $report['processed']);
        self::assertCount(1, $report['violations']);
        self::assertSame('UNKNOWN_FIELD', $report['violations'][0]['code']);
        self::assertSame('kuma:COM:nt_page:2', $report['violations'][0]['sourceUid']);
        self::assertSame(0, $report['saved']);
        self::assertSame([], $report['failed']);
        self::assertSame(ExitCode::UNSPECIFIED_ERROR, LoadController::exitCodeFor($report));
    }

    public function testNdjsonAllValidProducesNoViolationsAndExitZero(): void
    {
        $one = $this->payload('kuma:COM:nt_page:1');
        $two = $this->payload('kuma:COM:nt_page:2');

        $path = $this->write('valid.ndjson', json_encode($one) . "\n" . json_encode($two) . "\n");

        $report = LoadController::buildReport($path, $this->validator());

        self::assertSame(2, $report['processed']);
        self::assertSame([], $report['violations']);
        self::assertSame(ExitCode::OK, LoadController::exitCodeFor($report));
    }

    public function testJsonSingleObjectFormIsReadAsOneRecord(): void
    {
        $path = $this->write('single.json', json_encode($this->payload('kuma:COM:nt_page:1')));

        $report = LoadController::buildReport($path, $this->validator());

        self::assertSame(1, $report['processed']);
        self::assertSame([], $report['violations']);
        self::assertSame(ExitCode::OK, LoadController::exitCodeFor($report));
    }

    public function testJsonArrayFormIsReadAsMultipleRecords(): void
    {
        $records = [
            $this->payload('kuma:COM:nt_page:1'),
            $this->payload('kuma:COM:nt_page:2', ['bogusField' => 'x']),
        ];
        $path = $this->write('array.json', json_encode($records));

        $report = LoadController::buildReport($path, $this->validator());

        self::assertSame(2, $report['processed']);
        self::assertCount(1, $report['violations']);
        self::assertSame('UNKNOWN_FIELD', $report['violations'][0]['code']);
        self::assertSame(ExitCode::UNSPECIFIED_ERROR, LoadController::exitCodeFor($report));
    }

    public function testMalformedJsonLineInNdjsonProducesUnparseableViolationWithoutAbortingFile(): void
    {
        $valid = $this->payload('kuma:COM:nt_page:1');
        $path = $this->write('broken.ndjson', json_encode($valid) . "\n" . '{"not valid json' . "\n");

        $report = LoadController::buildReport($path, $this->validator());

        self::assertSame(2, $report['processed']);
        self::assertCount(1, $report['violations']);
        self::assertSame('UNPARSEABLE', $report['violations'][0]['code']);
        self::assertSame(ExitCode::UNSPECIFIED_ERROR, LoadController::exitCodeFor($report));
    }

    public function testStructurallyInvalidRecordMissingSourceUidProducesUnparseableViolation(): void
    {
        $broken = $this->payload('kuma:COM:nt_page:1');
        unset($broken['sourceUid']);

        $path = $this->write('missing-source-uid.json', json_encode($broken));

        $report = LoadController::buildReport($path, $this->validator());

        self::assertSame(1, $report['processed']);
        self::assertCount(1, $report['violations']);
        self::assertSame('UNPARSEABLE', $report['violations'][0]['code']);
        self::assertSame('unknown', $report['violations'][0]['sourceUid']);
        self::assertSame(ExitCode::UNSPECIFIED_ERROR, LoadController::exitCodeFor($report));
    }

    public function testNonObjectElementInJsonArrayProducesUnparseableViolation(): void
    {
        $path = $this->write('scalar-in-array.json', json_encode([$this->payload('kuma:COM:nt_page:1'), 'not-an-object']));

        $report = LoadController::buildReport($path, $this->validator());

        self::assertSame(2, $report['processed']);
        self::assertCount(1, $report['violations']);
        self::assertSame('UNPARSEABLE', $report['violations'][0]['code']);
    }
}
