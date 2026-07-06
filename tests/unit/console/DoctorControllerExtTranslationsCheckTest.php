<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\console;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Phase 8 / Plan 08-14 / TAX-09 / D-09 — characterization test for the
 * DoctorController check: ext_translations presence.
 *
 * Why source-shape characterisation rather than runtime invocation:
 *   - DoctorController::check* methods touch Plugin::getInstance() (Craft bootstrap),
 *     LegacyDbService (live DB), and stdout — they cannot be exercised in pure
 *     unit tests without a Craft+DB harness. The runtime contract is enforced
 *     instead via:
 *       * presence of the private method on the class (ReflectionMethod)
 *       * the method's source body referencing the right constant + producing
 *         INFO/WARN/OK strings only (never FAIL — D-09 mandate)
 *       * the dispatcher in actionIndex() including a checkExtTranslations() call
 *
 * D-09 invariant: WARN-only on empty / INFO on missing / OK on populated;
 * NEVER returns false. This test guards regressions on that invariant.
 */
final class DoctorControllerExtTranslationsCheckTest extends TestCase
{
    private string $sourcePath;
    /** @var string */
    private string $source;

    protected function setUp(): void
    {
        $this->sourcePath = dirname(__DIR__, 3) . '/src/console/DoctorController.php';
        self::assertFileExists($this->sourcePath);
        $this->source = (string) file_get_contents($this->sourcePath);
    }

    public function testCheckExtTranslationsMethodIsDeclared(): void
    {
        $rc = new ReflectionClass(\lameco\kunstmaanmigrator\console\DoctorController::class);
        self::assertTrue(
            $rc->hasMethod('checkExtTranslations'),
            'DoctorController::checkExtTranslations() must exist (Plan 08-14 / TAX-09).',
        );
        $rm = $rc->getMethod('checkExtTranslations');
        self::assertTrue($rm->isPrivate(), 'checkExtTranslations() must be private (matches sibling check methods).');
        self::assertSame('bool', (string) $rm->getReturnType(), 'Must return bool.');
    }

    public function testDispatcherInvokesCheckExtTranslations(): void
    {
        // The dispatcher chain in actionIndex() — every check is wired with `$this->checkX() && $ok`.
        self::assertMatchesRegularExpression(
            '/\$ok\s*=\s*\$this->checkExtTranslations\(\)\s*&&\s*\$ok\s*;/',
            $this->source,
            'actionIndex() must dispatch checkExtTranslations() in the chain.',
        );
    }

    public function testCheckQueriesExtTranslationsConstant(): void
    {
        // The private method must reference the KunstmaanCoreTables::EXT_TRANSLATIONS
        // constant (Plan 08-04) rather than inlining the literal string.
        $body = $this->extractMethodBody('checkExtTranslations');
        self::assertStringContainsString(
            'KunstmaanCoreTables::EXT_TRANSLATIONS',
            $body,
            'Body must reference the Plan 08-04 constant, not a literal string.',
        );
        self::assertStringContainsString(
            'queryScalar',
            $body,
            'Body must use LegacyDbService::queryScalar to count rows.',
        );
    }

    public function testCheckNeverReturnsFalse(): void
    {
        // D-09 invariant: WARN-or-INFO-or-OK; never FAIL. The method body must
        // contain NO `return false` lines.
        $body = $this->extractMethodBody('checkExtTranslations');
        self::assertStringNotContainsString(
            'return false',
            $body,
            'D-09: ext_translations check must NEVER return false (WARN/INFO/OK only).',
        );
        self::assertGreaterThanOrEqual(
            2,
            substr_count($body, 'return true'),
            'Method must return true on every code path (empty / populated / missing).',
        );
    }

    public function testCheckEmitsExpectedStrings(): void
    {
        $body = $this->extractMethodBody('checkExtTranslations');
        // Empty case (WARN — D-09 verbatim copy hint).
        self::assertStringContainsString(
            'ext_translations is empty',
            $body,
            'Empty-table branch must surface the WARN copy.',
        );
        self::assertStringContainsString(
            'D-09',
            $body,
            'Empty branch copy or docblock must cite D-09.',
        );
        // Missing case (INFO).
        self::assertStringContainsString(
            'ext_translations table not present',
            $body,
            'Throwable branch must surface the INFO copy ("table not present").',
        );
        // Populated case (OK).
        self::assertStringContainsString(
            'ext_translations populated',
            $body,
            'Populated branch must surface the OK copy.',
        );
    }

    public function testCheckIsTheLastCheckInDispatcher(): void
    {
        // Order matters for the operator-visible report. v2 loader prune:
        // checkExtTranslations() is the last remaining check after the
        // analyze/mapping/locale-detection checks were removed.
        $checks = [
            'checkLegacyDb',
            'checkStorageDir',
            'checkStateTable',
            'checkAdapterPlugins',
            'checkExtTranslations',
        ];
        $lastPos = -1;
        foreach ($checks as $name) {
            $needle = '$this->' . $name . '()';
            $pos = strpos($this->source, $needle);
            self::assertNotFalse($pos, "Dispatcher must invoke {$name}().");
            self::assertGreaterThan(
                $lastPos,
                $pos,
                "{$name}() must appear after the previous check in actionIndex() dispatch order.",
            );
            $lastPos = $pos;
        }
    }

    /** Extract the body of a private method from the source file. */
    private function extractMethodBody(string $methodName): string
    {
        $rc = new ReflectionClass(\lameco\kunstmaanmigrator\console\DoctorController::class);
        if (!$rc->hasMethod($methodName)) {
            return '';
        }
        $rm = $rc->getMethod($methodName);
        $start = (int) $rm->getStartLine();
        $end   = (int) $rm->getEndLine();
        $lines = file($this->sourcePath) ?: [];
        return implode('', array_slice($lines, $start - 1, $end - $start + 1));
    }
}
