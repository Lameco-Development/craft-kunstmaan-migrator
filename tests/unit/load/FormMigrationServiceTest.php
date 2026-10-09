<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\load;

use Lameco\Kunstmaanmigrator\adapters\AdapterGate;
use Lameco\Kunstmaanmigrator\craft\FormGateway;
use Lameco\Kunstmaanmigrator\load\FormMigrationService;
use Lameco\Kunstmaanmigrator\load\MigrationOptions;
use Lameco\Kunstmaanmigrator\load\MigrationReport;
use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\run\EnvironmentContext;
use Lameco\Kunstmaanmigrator\Source\LegacyDatabase;
use Lameco\Kunstmaanmigrator\tests\support\EnvironmentFactory;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryFormGateway;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryMigrationState;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryPluginRegistry;
use Lameco\Kunstmaanmigrator\tests\support\SettingsFactory;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The forms lane's own decisions, behind the Formie seam.
 *
 * The unmerged attempt at this lane was 667 lines with no test and a hard
 * dependency on Formie, so none of this was checkable without a booted Craft
 * with that plugin installed. It is checkable in milliseconds now, which is the
 * whole argument for the seam.
 */
final class FormMigrationServiceTest extends TestCase
{
    private function service(FormGateway $gateway): FormMigrationService
    {
        $service = (new ReflectionClass(FormMigrationService::class))->newInstanceWithoutConstructor();
        $service->forms = $gateway;

        return $service;
    }

    private function invoke(FormMigrationService $service, string $method, mixed ...$args): mixed
    {
        return (new ReflectionClass(FormMigrationService::class))
            ->getMethod($method)
            ->invoke($service, ...$args);
    }

    /**
     * Two legacy pages routinely share a title. Naming the form after the title
     * would have one silently overwrite the other, so the handle comes from the
     * legacy identity, which is unique by construction.
     */
    public function testTheHandleComesFromTheLegacyIdentityNotTheTitle(): void
    {
        $service = $this->service(new InMemoryFormGateway());

        self::assertSame(
            'kumaComPotionslandingpage27',
            $this->invoke($service, 'handleFor', 'kuma:COM:form:PotionsLandingPage:27', 'kuma'),
        );
    }

    /**
     * A page id is unique within one legacy database and a migration walks
     * three, so COM's PotionsLandingPage 27 and DE's are different pages. An
     * earlier draft of this method left the environment out and the second run
     * would have overwritten the first — the same class of bug as the rewriter
     * caching bare legacy ids across databases.
     */
    public function testTwoEnvironmentsDoNotCollideOnOneHandle(): void
    {
        $service = $this->service(new InMemoryFormGateway());

        $com = $this->invoke($service, 'handleFor', 'kuma:COM:form:PotionsLandingPage:27', '');
        $de = $this->invoke($service, 'handleFor', 'kuma:DE:form:PotionsLandingPage:27', '');

        self::assertNotSame($com, $de);
    }

    public function testTheGatewayIsAskedForEachFormExactlyOnce(): void
    {
        $gateway = new InMemoryFormGateway();
        $warnings = [];

        $gateway->saveForm('a', 'A', [['type' => 'singleLineText']], [], $warnings);
        $gateway->saveForm('a', 'A renamed', [['type' => 'singleLineText']], [], $warnings);

        self::assertCount(1, $gateway->saved);
        self::assertSame('A renamed', $gateway->saved['a']['title']);
    }

    public function testAGatewayThatRefusesReportsRatherThanThrows(): void
    {
        $gateway = new InMemoryFormGateway();
        $gateway->refuse = ['broken'];
        $warnings = [];

        self::assertNull($gateway->saveForm('broken', 'Broken', [], [], $warnings));
        self::assertNotSame([], $warnings);
    }

    public function testTheLaneNamesItselfAfterTheRegistryHandle(): void
    {
        self::assertSame('forms', $this->service(new InMemoryFormGateway())->handle());
    }

    /**
     * Which Formie handle each legacy pagepart became is the gateway's call —
     * it folds accents and suffixes collisions — so the lane records what the
     * gateway answered, keyed on the part. That record is the only reliable way
     * a stored submission, which names the part, finds its field.
     */
    public function testTheStateRowRecordsWhichHandleEachPagepartBecame(): void
    {
        $gateway = new InMemoryFormGateway();
        $service = $this->service($gateway);
        $service->stateService = $state = new InMemoryMigrationState();

        $this->invoke($service, 'load', [
            'sourceUid' => 'kuma:NL:form:VacancyFormPage:75',
            'title' => 'Solliciteren',
            'fields' => [
                ['type' => 'singleLineText', 'label' => 'Voornaam', 'handle' => '', 'required' => true, 'settings' => [], 'partRef' => 'SingleLineText:198'],
                ['type' => 'dropdown', 'label' => 'Aanhef', 'handle' => '', 'required' => false, 'settings' => [], 'partRef' => 'Choice:66'],
            ],
        ], new MigrationOptions(), [], 'kuma', new MigrationReport());

        self::assertSame(
            ['SingleLineText:198' => ['handle' => 'voornaam', 'type' => 'singleLineText'], 'Choice:66' => ['handle' => 'aanhef', 'type' => 'dropdown']],
            $state->get('form', 'kuma:NL:form:VacancyFormPage:75')['meta']['fieldMap'],
        );
    }

    /** @param array<string, mixed> $record */
    private function load(FormMigrationService $service, array $record, MigrationOptions $opts, MigrationReport $report): void
    {
        $this->invoke($service, 'load', $record, $opts, [], 'kuma', $report);
    }

    /** @return array<string, mixed> */
    private function vacancyForm(): array
    {
        return [
            'sourceUid' => 'kuma:NL:form:VacancyFormPage:75',
            'title' => 'Solliciteren',
            'fields' => [['type' => 'singleLineText', 'label' => 'Voornaam', 'handle' => '', 'required' => true, 'settings' => [], 'partRef' => 'SingleLineText:198']],
        ];
    }

    public function testADryRunCountsTheFormAndWritesNothing(): void
    {
        $gateway = new InMemoryFormGateway();
        $service = $this->service($gateway);
        $report = new MigrationReport();

        $this->load($service, $this->vacancyForm(), new MigrationOptions(dryRun: true), $report);

        self::assertSame([], $gateway->saved);
        self::assertSame(1, $report->counts['compiled'] ?? 0);
    }

    public function testASecondRunSkipsAndForceUpdatesTheSameForm(): void
    {
        $gateway = new InMemoryFormGateway();
        $service = $this->service($gateway);
        $service->stateService = new InMemoryMigrationState();
        $this->load($service, $this->vacancyForm(), new MigrationOptions(), new MigrationReport());

        $again = new MigrationReport();
        $this->load($service, $this->vacancyForm(), new MigrationOptions(), $again);
        $forced = new MigrationReport();
        $this->load($service, $this->vacancyForm(), new MigrationOptions(force: true), $forced);

        self::assertSame(1, $again->counts['skipped'] ?? 0);
        self::assertSame(1, $forced->counts['updated'] ?? 0);
        self::assertCount(1, $gateway->saved);
    }

    public function testAFormTheGatewayRefusesIsCountedAsFailedAndRecordsNothing(): void
    {
        $gateway = new InMemoryFormGateway();
        $gateway->refuse = ['kumaNlVacancyformpage75'];
        $service = $this->service($gateway);
        $service->stateService = $state = new InMemoryMigrationState();
        $report = new MigrationReport();

        $this->load($service, $this->vacancyForm(), new MigrationOptions(), $report);

        self::assertSame(1, $report->counts['failed'] ?? 0);
        self::assertNotSame([], $report->warnings);
        self::assertNull($state->get('form', 'kuma:NL:form:VacancyFormPage:75'));
    }

    public function testTheLaneDoesNotRunWhenTheOperatorTurnedItOff(): void
    {
        $gateway = new InMemoryFormGateway();
        $service = $this->service($gateway);
        $service->adapterGate = new AdapterGate(new InMemoryPluginRegistry(['formie' => '3.1.41']), SettingsFactory::make(['formsEnabled' => false]));

        $report = $service->migrateAll(new MigrationOptions(), EnvironmentFactory::make('NL'));

        self::assertSame([], $gateway->saved);
        self::assertNotSame([], $report->warnings);
    }

    public function testTheLaneSaysSoWhenItHasNoMappingOrLegacyDatabase(): void
    {
        $service = $this->service(new InMemoryFormGateway());
        $service->adapterGate = new AdapterGate(new InMemoryPluginRegistry(['formie' => '3.1.41']), SettingsFactory::make(['formsEnabled' => true]));

        $report = $service->migrateAll(new MigrationOptions(), EnvironmentFactory::make('NL'));

        self::assertStringContainsString('mapping', implode("\n", $report->warnings));
    }

    public function testTheLaneSaysSoWhenFormieIsNotInstalled(): void
    {
        $service = $this->service(new InMemoryFormGateway(available: false));
        $service->adapterGate = new AdapterGate(new InMemoryPluginRegistry(['formie' => '3.1.41']), SettingsFactory::make(['formsEnabled' => true]));
        $base = EnvironmentFactory::make('NL');

        $report = $service->migrateAll(new MigrationOptions(), new EnvironmentContext(
            name: 'NL',
            database: 'legacy',
            sites: $base->sites,
            mapping: Mapping::fromArray(['version' => 1, 'environments' => ['NL' => ['database' => 'legacy', 'locales' => ['nl' => 'default']]]]),
            legacy: new LegacyDatabase(new PDO('sqlite::memory:'), 'NL', 'legacy'),
        ));

        self::assertStringContainsString('formie is not installed', implode("\n", $report->warnings));
    }

    public function testAFormWhoseSaveThrowsIsCountedAsFailedAndTheRunGoesOn(): void
    {
        $gateway = new InMemoryFormGateway();
        $gateway->throwOnForm = 'Invalid field layout ID: 12';
        $report = new MigrationReport();

        $this->load($this->service($gateway), $this->vacancyForm(), new MigrationOptions(), $report);

        self::assertSame(1, $report->counts['failed'] ?? 0);
        self::assertStringContainsString('Invalid field layout ID', implode("\n", $report->warnings));
    }
}
