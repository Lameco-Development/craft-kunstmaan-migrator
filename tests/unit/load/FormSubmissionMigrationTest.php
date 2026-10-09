<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\load;

use Lameco\Kunstmaanmigrator\load\FormMigrationService;
use Lameco\Kunstmaanmigrator\load\MigrationOptions;
use Lameco\Kunstmaanmigrator\load\MigrationReport;
use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\run\EnvironmentContext;
use Lameco\Kunstmaanmigrator\Source\LegacyDatabase;
use Lameco\Kunstmaanmigrator\tests\support\EnvironmentFactory;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryFormGateway;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryMigrationState;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * `forms.submissions:` loaded behind the Formie seam.
 *
 * Node 222 is the live vacancy form the lane already wrote; node 131 is a
 * deleted contact page whose form exists nowhere but in its submissions.
 */
final class FormSubmissionMigrationTest extends TestCase
{
    private const LANE_FORM = 'kuma:NL:form:VacancyFormPage:75';

    private InMemoryFormGateway $gateway;
    private InMemoryMigrationState $state;
    private FormMigrationService $service;
    private string $filesRoot;

    protected function setUp(): void
    {
        $this->gateway = new InMemoryFormGateway();
        $this->state = new InMemoryMigrationState();
        $this->service = (new ReflectionClass(FormMigrationService::class))->newInstanceWithoutConstructor();
        $this->service->forms = $this->gateway;
        $this->service->stateService = $this->state;

        $this->filesRoot = sys_get_temp_dir() . '/kuma-submissions-' . uniqid();
        mkdir($this->filesRoot . '/uploads/formsubmissions/abc', 0777, true);
        file_put_contents($this->filesRoot . '/uploads/formsubmissions/abc/cv.pdf', '%PDF');
    }

    /** What the forms lane leaves behind for node 222: the form, and which handle each part became. */
    private function laneWroteTheVacancyForm(): int
    {
        $warnings = [];
        $id = (int) $this->gateway->saveForm('kumaNlVacancyformpage75', 'Solliciteren', [], [], $warnings);
        $this->state->record('form', self::LANE_FORM, 'formie_form', $id, null, null, [
            'handle' => 'kumaNlVacancyformpage75',
            'fieldMap' => [
                'Choice:66' => ['handle' => 'aanhef', 'type' => 'dropdown'],
                'SingleLineText:198' => ['handle' => 'voornaam', 'type' => 'singleLineText'],
                'FileUpload:1' => ['handle' => 'uploadCv', 'type' => 'fileUpload'],
            ],
        ]);

        return $id;
    }

    private function context(string $submissions = 'nodes: all'): EnvironmentContext
    {
        $yaml = <<<YAML
            version: 1
            environments:
              NL: { database: legacy, locales: { nl: berkvensNl } }
            forms:
              context: main
              fields:
                SingleLineText: { table: kuma_single_line_text_page_parts, type: singleLineText }
              submissions:
                $submissions
                volume: formieUploads
                filesRoot: {$this->filesRoot}
            YAML;
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, $yaml);
        $base = EnvironmentFactory::make('NL', ['nl' => 'berkvensNl'], ['berkvensNl' => [1, 'nl-NL', true]]);

        return new EnvironmentContext(
            name: 'NL',
            database: 'legacy',
            sites: $base->sites,
            mapping: Mapping::fromFile($path),
            legacy: new LegacyDatabase($this->db(), 'NL', 'legacy'),
        );
    }

    private function db(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE kuma_nodes (id INTEGER, deleted INTEGER)');
        $pdo->exec('CREATE TABLE kuma_node_versions (id INTEGER, node_translation_id INTEGER, ref_entity_name TEXT, ref_id INTEGER)');
        $pdo->exec('CREATE TABLE kuma_node_translations
                    (id INTEGER, node_id INTEGER, lang TEXT, title TEXT, online INTEGER, public_node_version_id INTEGER)');
        $pdo->exec('CREATE TABLE kuma_form_submissions (id INTEGER, node_id INTEGER, ip_address TEXT, lang TEXT, created TEXT)');
        $pdo->exec('CREATE TABLE kuma_form_submission_fields (
                    id INTEGER, form_submission_id INTEGER, field_name TEXT, label TEXT, discr TEXT,
                    sfsf_value TEXT, tfsf_value TEXT, bfsf_value INTEGER, cfsf_value TEXT, expanded INTEGER,
                    multiple INTEGER, choices TEXT, required INTEGER, ffsf_value TEXT, efsf_value TEXT,
                    sequence INTEGER, uuid TEXT, url TEXT, internal_name TEXT)');

        $pdo->exec('INSERT INTO kuma_nodes VALUES (222, 0), (131, 1)');
        $pdo->exec("INSERT INTO kuma_node_versions VALUES
                    (11, 21, 'App\\\\Entity\\\\Pages\\\\VacancyFormPage', 75),
                    (12, 22, 'App\\\\Entity\\\\Pages\\\\FormPage', 40)");
        $pdo->exec("INSERT INTO kuma_node_translations VALUES
                    (21, 222, 'nl', 'Solliciteren', 1, 11),
                    (22, 131, 'nl', 'Contact', 1, 12)");
        $pdo->exec("INSERT INTO kuma_form_submissions VALUES
                    (1, 222, '10.0.0.1', 'nl', '2017-05-24 11:22:49'),
                    (2, 222, '10.0.0.2', 'nl', '2024-09-04 12:06:47'),
                    (3, 131, '10.0.0.3', 'nl', '2016-04-06 12:44:41')");

        $prefix = 'field_KunstmaanFormBundleEntityPageParts';
        $dhr = 'a:2:{i:0;s:4:"Dhr.";i:1;s:5:"Mevr.";}';
        $markt = 'a:3:{i:0;s:10:"Woningbouw";i:1;s:14:"Utiliteitsbouw";i:2;s:8:"Woonzorg";}';
        $insert = $pdo->prepare('INSERT INTO kuma_form_submission_fields
            (id, form_submission_id, field_name, label, discr, sfsf_value, tfsf_value, cfsf_value, multiple, choices, ffsf_value, efsf_value, sequence, url)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

        foreach ([
            [10, 1, 'SingleLineTextPagePart198', 'Voornaam en achternaam', 'string', 'Jan Jansen', null, null, null, null, null, null, null, null],
            [11, 1, 'ChoicePagePart66', 'Dhr./mevr.', 'choice', null, null, 'i:1;', 0, $dhr, null, null, null, null],
            [12, 1, 'FileUploadPagePart1', 'Upload je CV', 'file', null, null, null, null, null, 'cv.docx', null, null, null],
            [20, 2, 'SingleLineTextPagePart198', 'Voornaam', 'stringformsubmissionfield', 'Piet', null, null, null, null, null, null, 1, null],
            [21, 2, 'EmailPagePart36', 'E-mailadres', 'emailformsubmissionfield', null, null, null, null, null, null, 'piet@example.nl', 2, null],
            [22, 2, 'FileUploadPagePart1', 'Upload CV', 'fileformsubmissionfield', null, null, null, null, null, 'cv.pdf', null, 3, '/uploads/formsubmissions/abc/cv.pdf'],
            [30, 3, 'SingleLineTextPagePart121', 'Naam', 'string', 'Klaas', null, null, null, null, null, null, null, null],
            [31, 3, 'MultiLineTextPagePart44', 'Uw vraag', 'text', null, 'Hoe laat?', null, null, null, null, null, null, null],
            [32, 3, 'ChoicePagePart9', 'Markt', 'choice', null, null, 'a:2:{i:0;i:0;i:1;i:2;}', 0, $markt, null, null, null, null],
        ] as $row) {
            $row[2] = $prefix . $row[2];
            $insert->execute($row);
        }

        return $pdo;
    }

    private function migrate(?MigrationOptions $opts = null, string $submissions = 'nodes: all'): MigrationReport
    {
        $report = new MigrationReport();
        $this->service->migrateSubmissions($opts ?? new MigrationOptions(), $this->context($submissions), $report, 'kuma');

        return $report;
    }

    /** @return array<string, mixed> the submission written for a legacy id */
    private function written(int $legacyId): array
    {
        $id = $this->state->getTargetId('form_submission', 'NL:kuma_form_submission:' . $legacyId);
        self::assertNotNull($id, "legacy submission $legacyId was not recorded");

        return $this->gateway->submissions[$id];
    }

    #[Test]
    public function a_submission_lands_on_the_form_the_lane_wrote_under_its_field_handles(): void
    {
        $formId = $this->laneWroteTheVacancyForm();

        $this->migrate(submissions: 'nodes: [222]');

        $submission = $this->written(1);
        self::assertSame($formId, $submission['formId']);
        self::assertSame('Jan Jansen', $submission['values']['voornaam']);
        self::assertSame('2017-05-24 11:22:49', $submission['dateCreated']);
        self::assertSame(1, $submission['siteId']);
        self::assertSame('10.0.0.1', $submission['ipAddress']);
    }

    /** The lane's dropdown has no options (the mapping cannot build them), so the picked label is the value. */
    #[Test]
    public function a_choice_on_a_dropdown_is_the_label_it_picked(): void
    {
        $this->laneWroteTheVacancyForm();

        $this->migrate(submissions: 'nodes: [222]');

        self::assertSame('Mevr.', $this->written(1)['values']['aanhef']);
    }

    /**
     * Part 36 (the e-mail field) is not on the lane's form record. The value is
     * reported, not silently dropped, and the rest of the submission still lands.
     */
    #[Test]
    public function a_value_for_a_part_the_form_does_not_have_is_reported(): void
    {
        $this->laneWroteTheVacancyForm();

        $report = $this->migrate(submissions: 'nodes: [222]');

        self::assertSame('Piet', $this->written(2)['values']['voornaam']);
        self::assertSame(1, $report->counts['submissionValuesUnmatched'] ?? 0);
        self::assertStringContainsString('Email:36', implode("\n", $report->warnings));
    }

    #[Test]
    public function an_uploaded_file_is_handed_over_from_under_the_files_root_into_the_volume(): void
    {
        $this->laneWroteTheVacancyForm();

        $this->migrate(submissions: 'nodes: [222]');

        $submission = $this->written(2);
        self::assertSame(['uploadCv' => [$this->filesRoot . '/uploads/formsubmissions/abc/cv.pdf']], $submission['files']);
        self::assertSame('formieUploads', $submission['uploadVolume']);
        self::assertArrayNotHasKey('uploadCv', $submission['values']);
    }

    /** The 2017 upload has a name and no url; nothing on disk answers it, so the lead lands without it. */
    #[Test]
    public function a_file_that_is_not_on_disk_is_reported_and_the_submission_still_lands(): void
    {
        $this->laneWroteTheVacancyForm();

        $report = $this->migrate(submissions: 'nodes: [222]');

        self::assertSame([], $this->written(1)['files'] ?? []);
        self::assertSame(1, $report->counts['submissionFilesMissing'] ?? 0);
        self::assertStringContainsString('cv.docx', implode("\n", $report->warnings));
    }

    /**
     * Node 131's page is deleted, so no lane form exists. Its submissions get an
     * archive form, under the handle the lane would have given the page, built
     * from what the submissions answered.
     */
    #[Test]
    public function a_node_whose_form_is_gone_gets_an_archive_form(): void
    {
        $this->migrate(submissions: 'nodes: [131]');

        $form = $this->gateway->saved['kumaNlFormpage40'] ?? null;
        self::assertNotNull($form);
        self::assertTrue($form['settings']['archived']);
        self::assertStringContainsString('Contact', $form['title']);
        self::assertSame(
            ['singleLineText', 'multiLineText', 'checkboxes'],
            array_column($form['fields'], 'type'),
        );
        self::assertSame(
            [['label' => 'Woningbouw', 'value' => 'Woningbouw'], ['label' => 'Utiliteitsbouw', 'value' => 'Utiliteitsbouw'], ['label' => 'Woonzorg', 'value' => 'Woonzorg']],
            $form['fields'][2]['settings']['options'],
        );

        $submission = $this->written(3);
        self::assertSame($form['id'], $submission['formId']);
        self::assertSame(['naam' => 'Klaas', 'uwVraag' => 'Hoe laat?', 'markt' => ['Woningbouw', 'Woonzorg']], $submission['values']);
        self::assertSame('formie_form', $this->state->get('form', 'kuma:NL:form:FormPage:40')['targetType'] ?? null);
    }

    #[Test]
    public function a_second_run_writes_nothing_new(): void
    {
        $this->laneWroteTheVacancyForm();
        $this->migrate();
        $count = count($this->gateway->submissions);

        $report = $this->migrate();

        self::assertSame($count, count($this->gateway->submissions));
        self::assertSame(3, $report->counts['submissionsSkipped'] ?? 0);
        self::assertSame(0, $report->counts['submissionsCreated'] ?? 0);
    }

    /** `--force` rewrites in place: the same Formie submission, not a second copy of the lead. */
    #[Test]
    public function force_updates_the_submission_an_earlier_run_wrote(): void
    {
        $this->laneWroteTheVacancyForm();
        $this->migrate();
        $before = $this->state->getTargetId('form_submission', 'NL:kuma_form_submission:1');

        $report = $this->migrate(new MigrationOptions(force: true));

        self::assertSame($before, $this->state->getTargetId('form_submission', 'NL:kuma_form_submission:1'));
        self::assertCount(3, $this->gateway->submissions);
        self::assertSame(3, $report->counts['submissionsUpdated'] ?? 0);
    }

    #[Test]
    public function a_dry_run_counts_and_writes_nothing(): void
    {
        $this->laneWroteTheVacancyForm();

        $report = $this->migrate(new MigrationOptions(dryRun: true));

        self::assertSame([], $this->gateway->submissions);
        self::assertSame(3, $report->counts['submissionsCompiled'] ?? 0);
    }

    /** A lane form written before handles were recorded cannot be joined; say how to fix it. */
    #[Test]
    public function a_lane_form_without_a_field_map_is_reported_not_guessed(): void
    {
        $this->state->record('form', self::LANE_FORM, 'formie_form', 500, null, null, ['handle' => 'x']);

        $report = $this->migrate(submissions: 'nodes: [222]');

        self::assertSame([], $this->gateway->submissions);
        self::assertStringContainsString('--force', implode("\n", $report->warnings));
    }

    #[Test]
    public function nothing_moves_unless_the_mapping_opts_in(): void
    {
        $report = new MigrationReport();
        $context = $this->context();
        $data = $context->mapping?->all() ?? [];
        unset($data['forms']['submissions']);

        $this->service->migrateSubmissions(new MigrationOptions(), new EnvironmentContext(
            name: 'NL',
            database: 'legacy',
            sites: $context->sites,
            mapping: Mapping::fromArray($data),
            legacy: $context->legacy,
        ), $report, 'kuma');

        self::assertSame([], $this->gateway->submissions);
        self::assertSame([], $this->gateway->saved);
    }
}
