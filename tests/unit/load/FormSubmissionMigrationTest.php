<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\load;

use Lameco\Kunstmaanmigrator\load\MigrationOptions;
use Lameco\Kunstmaanmigrator\load\MigrationReport;
use Lameco\Kunstmaanmigrator\load\SubmissionMigrationService;
use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\run\EnvironmentContext;
use Lameco\Kunstmaanmigrator\Source\LegacyDatabase;
use Lameco\Kunstmaanmigrator\tests\support\EnvironmentFactory;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryFormGateway;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryMigrationState;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
    private SubmissionMigrationService $service;
    private string $filesRoot;

    /** Where the 2024 CV upload says it is, relative to the files root. */
    private string $cvUrl = '/uploads/formsubmissions/abc/cv.pdf';

    /** The legacy locale the mapping binds, and the pages and submissions carry. */
    private string $lang = 'nl';

    /** A locale the submissions carry instead, when a test needs one the mapping does not bind. */
    private ?string $submissionLang = null;

    private bool $hadEnvironment = false;

    private ?string $environment = null;

    protected function setUp(): void
    {
        $this->gateway = new InMemoryFormGateway();
        $this->state = new InMemoryMigrationState();
        $this->service = new SubmissionMigrationService($this->gateway, $this->state);

        $this->filesRoot = sys_get_temp_dir() . '/kuma-submissions-' . uniqid();
        mkdir($this->filesRoot . '/uploads/formsubmissions/abc', 0777, true);
        file_put_contents($this->filesRoot . '/uploads/formsubmissions/abc/cv.pdf', '%PDF');

        $this->hadEnvironment = array_key_exists('CRAFT_ENVIRONMENT', $_SERVER);
        $this->environment = $_SERVER['CRAFT_ENVIRONMENT'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->hadEnvironment) {
            $_SERVER['CRAFT_ENVIRONMENT'] = $this->environment;
        } else {
            unset($_SERVER['CRAFT_ENVIRONMENT']);
        }
    }

    /** What the forms lane leaves behind for node 222: the form, and which handle each part became. */
    /** @param array<string, mixed> $uploadSettings what the mapping put on the file field */
    private function laneWroteTheVacancyForm(array $uploadSettings = []): int
    {
        $warnings = [];
        $handles = [];
        $id = (int) $this->gateway->saveForm('kumaNlVacancyformpage75', 'Solliciteren', [
            ['type' => 'dropdown', 'label' => 'Aanhef', 'handle' => 'aanhef', 'required' => false, 'settings' => [], 'partRef' => 'Choice:66'],
            ['type' => 'singleLineText', 'label' => 'Voornaam', 'handle' => 'voornaam', 'required' => true, 'settings' => [], 'partRef' => 'SingleLineText:198'],
            ['type' => 'fileUpload', 'label' => 'Upload je CV', 'handle' => 'uploadCv', 'required' => false, 'settings' => $uploadSettings, 'partRef' => 'FileUpload:1'],
        ], [], $warnings, $handles);
        $this->state->record('form', self::LANE_FORM, 'formie_form', $id, null, null, [
            'handle' => 'kumaNlVacancyformpage75',
            'fieldMap' => $handles,
        ]);

        return $id;
    }

    private function context(string $submissions = 'nodes: all', string $env = 'NL'): EnvironmentContext
    {
        $site = $env === 'FR' ? 'berkvensFr' : 'berkvensNl';
        $yaml = <<<YAML
            version: 1
            environments:
              $env: { database: legacy, locales: { {$this->lang}: $site } }
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
        $base = EnvironmentFactory::make($env, [$this->lang => $site], ['berkvensNl' => [1, 'nl-NL', true], 'berkvensFr' => [2, 'fr-FR']]);

        return new EnvironmentContext(
            name: $env,
            database: 'legacy',
            sites: $base->sites,
            mapping: Mapping::fromFile($path),
            legacy: new LegacyDatabase($this->db(), $env, 'legacy'),
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
                    (21, 222, '{$this->lang}', 'Solliciteren', 1, 11),
                    (22, 131, '{$this->lang}', 'Contact', 1, 12)");
        $lang = $this->submissionLang ?? $this->lang;
        $pdo->exec("INSERT INTO kuma_form_submissions VALUES
                    (1, 222, '10.0.0.1', '$lang', '2017-05-24 11:22:49'),
                    (2, 222, '10.0.0.2', '$lang', '2024-09-04 12:06:47'),
                    (3, 131, '10.0.0.3', '$lang', '2016-04-06 12:44:41')");

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
            [22, 2, 'FileUploadPagePart1', 'Upload CV', 'fileformsubmissionfield', null, null, null, null, null, 'cv.pdf', null, 3, $this->cvUrl],
            [30, 3, 'SingleLineTextPagePart121', 'Naam', 'string', 'Klaas', null, null, null, null, null, null, null, null],
            [31, 3, 'MultiLineTextPagePart44', 'Uw vraag', 'text', null, 'Hoe laat?', null, null, null, null, null, null, null],
            [32, 3, 'ChoicePagePart9', 'Markt', 'choice', null, null, 'a:2:{i:0;i:0;i:1;i:2;}', 0, $markt, null, null, null, null],
        ] as $row) {
            $row[2] = $prefix . $row[2];
            $insert->execute($row);
        }

        return $pdo;
    }

    private function migrate(?MigrationOptions $opts = null, string $submissions = 'nodes: all', string $env = 'NL'): MigrationReport
    {
        $report = new MigrationReport();
        $this->service->migrate($opts ?? new MigrationOptions(), $this->context($submissions, $env), $report, 'kuma');

        return $report;
    }

    private function submissionId(int $legacyId, string $env = 'NL'): int
    {
        $id = $this->state->getTargetId('form_submission', $env . ':kuma_form_submission:' . $legacyId);
        self::assertNotNull($id, "legacy submission $legacyId was not recorded");

        return $id;
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
    public function an_uploaded_file_is_copied_in_from_under_the_files_root_and_lands_as_its_asset(): void
    {
        $this->laneWroteTheVacancyForm();

        $this->migrate(submissions: 'nodes: [222]');

        self::assertSame([7000 => realpath($this->filesRoot . '/uploads/formsubmissions/abc/cv.pdf')], $this->gateway->uploads);
        self::assertSame([7000], $this->written(2)['values']['uploadCv']);
    }

    /**
     * The mapping points the lane's file field at a volume and a site group's
     * subpath; the copy lands in that folder, where Formie itself would put a
     * new upload — not in the root of the lane's volume.
     */
    #[Test]
    public function an_upload_lands_in_the_folder_its_file_field_names(): void
    {
        $this->laneWroteTheVacancyForm(['uploadLocationSource' => 'volume:uid-uploads', 'uploadLocationSubpath' => 'berkvensNl']);

        $this->migrate(submissions: 'nodes: [222]');

        self::assertSame([7000 => ['source' => 'volume:uid-uploads', 'subpath' => 'berkvensNl']], $this->gateway->uploadFolders);
    }

    /** A file field that names no upload location leaves the copy in the root of the lane's volume. */
    #[Test]
    public function an_upload_on_a_field_without_a_location_lands_in_the_lane_volume_root(): void
    {
        $this->laneWroteTheVacancyForm();

        $this->migrate(submissions: 'nodes: [222]');

        self::assertSame([7000 => ['source' => 'formieUploads', 'subpath' => '']], $this->gateway->uploadFolders);
    }

    /**
     * An archive form is the lane's own, so its file field takes the lane's
     * volume and `subpath:` — the site group's folder (ADR-0006) — and the
     * copies land where the field says they are.
     */
    #[Test]
    public function an_archive_forms_file_field_takes_the_lanes_subpath_and_its_uploads_land_there(): void
    {
        $this->migrate(submissions: "nodes: [222]\n    subpath: berkvensNl");

        $archive = array_values(array_filter($this->gateway->saved, static fn(array $form): bool => (bool) ($form['settings']['archived'] ?? false)));
        self::assertCount(1, $archive);
        $upload = array_values(array_filter($archive[0]['fields'], static fn(array $field): bool => $field['type'] === 'fileUpload'));
        self::assertSame(['uploadVolume' => 'formieUploads', 'uploadLocationSubpath' => 'berkvensNl'], $upload[0]['settings']);
        self::assertSame([7000 => ['source' => 'formieUploads', 'subpath' => 'berkvensNl']], $this->gateway->uploadFolders);
    }

    /** The 2017 upload has a name and no url; nothing on disk answers it, so the lead lands without it. */
    #[Test]
    public function a_file_that_is_not_on_disk_is_reported_and_the_submission_still_lands(): void
    {
        $this->laneWroteTheVacancyForm();

        $report = $this->migrate(submissions: 'nodes: [222]');

        self::assertArrayNotHasKey('uploadCv', $this->written(1)['values']);
        self::assertSame(1, $report->counts['submissionFilesMissing'] ?? 0);
        self::assertStringContainsString('NL:kuma_form_submission:1: uploadCv', implode("\n", $report->warnings));
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

    /**
     * The gateway copies a file in as a new asset every time it is asked to.
     * A forced re-run that copied the CV again would leave `cv_1.pdf`,
     * `cv_2.pdf`… in the private volume; instead the asset the first run made
     * is attached again, which also restores it on a form whose field was rebuilt.
     */
    #[Test]
    public function force_attaches_the_asset_an_earlier_run_made_rather_than_copying_again(): void
    {
        $this->laneWroteTheVacancyForm();
        $this->migrate();

        $this->migrate(new MigrationOptions(force: true));

        self::assertCount(1, $this->gateway->uploads);
        self::assertSame([7000], $this->written(2)['values']['uploadCv']);
        self::assertSame('Piet', $this->written(2)['values']['voornaam']);
    }

    /** A copy that failed recorded no asset, so the next forced run tries it again. */
    #[Test]
    public function force_retries_a_file_whose_copy_failed(): void
    {
        $this->laneWroteTheVacancyForm();
        $this->gateway->failUploads = ['cv.pdf'];
        $this->migrate();
        self::assertSame([], $this->gateway->uploads);

        $this->gateway->failUploads = [];
        $this->migrate(new MigrationOptions(force: true));

        self::assertCount(1, $this->gateway->uploads);
        self::assertSame([7000], $this->written(2)['values']['uploadCv']);
    }

    /**
     * Formie files a submission's content under each field's uid. The forms
     * lane re-saves a form on `--force`; were its fields rebuilt with new uids,
     * every migrated lead on it would open empty.
     */
    #[Test]
    public function re_saving_a_form_leaves_the_values_its_submissions_hold(): void
    {
        $this->migrate(submissions: 'nodes: [131]');
        $form = $this->gateway->saved['kumaNlFormpage40'];
        $warnings = [];

        $this->gateway->saveForm('kumaNlFormpage40', $form['title'], $form['fields'], $form['settings'], $warnings);

        self::assertSame(
            ['naam' => 'Klaas', 'uwVraag' => 'Hoe laat?', 'markt' => ['Woningbouw', 'Woonzorg']],
            $this->gateway->valuesOf($this->submissionId(3)),
        );
    }

    /** A url climbing out of the files root is not followed, whatever is out there. */
    #[Test]
    public function an_upload_url_outside_the_files_root_is_not_followed(): void
    {
        $outside = dirname($this->filesRoot) . '/kuma-outside-' . uniqid() . '.pdf';
        file_put_contents($outside, '%PDF');
        $this->cvUrl = '/../' . basename($outside);
        $this->laneWroteTheVacancyForm();

        $report = $this->migrate(submissions: 'nodes: [222]');

        self::assertSame([], $this->gateway->uploads);
        self::assertSame(2, $report->counts['submissionFilesMissing'] ?? 0);
        unlink($outside);
    }

    /**
     * A run report is read, pasted and kept. It names the submission by its
     * state key and the field by its handle; never an applicant's name, address
     * or file name — not even inside a driver error that echoes bound values.
     */
    #[Test]
    public function the_report_carries_no_personal_data(): void
    {
        $this->laneWroteTheVacancyForm();
        $this->gateway->failUploads = ['cv.pdf'];
        $report = $this->migrate();

        $this->gateway->throwOnSubmission = "SQLSTATE[22001]: value 'Jan Jansen <piet@example.nl>' too long";
        $forced = $this->migrate(new MigrationOptions(force: true));

        $text = implode("\n", [...$report->warnings, ...$forced->warnings]);
        self::assertStringContainsString('NL:kuma_form_submission:2', $text);
        self::assertStringContainsString('RuntimeException', $text);

        foreach (['cv.pdf', 'cv.docx', 'piet@example.nl', 'Jan Jansen', 'Piet', 'Klaas', '10.0.0.'] as $personal) {
            self::assertStringNotContainsString($personal, $text);
        }
    }

    /**
     * FR runs with its own `forms.submissions:` block and environment-scoped
     * state keys, onto Berkvens FR — not the primary site. Its content sits
     * under the Kunstmaan `nl` locale, as on the real corpus. A forced re-run
     * finds what it wrote there and updates it in place.
     */
    #[Test]
    public function an_fr_submission_lands_on_the_fr_site_and_force_updates_it_in_place(): void
    {
        $this->migrate(submissions: 'nodes: [131]', env: 'FR');
        $id = $this->submissionId(3, 'FR');

        $report = $this->migrate(new MigrationOptions(force: true), 'nodes: [131]', 'FR');

        self::assertArrayHasKey('kumaFrFormpage40', $this->gateway->saved);
        self::assertSame(2, $this->gateway->submissions[$id]['siteId']);
        self::assertCount(1, $this->gateway->submissions);
        self::assertSame(1, $report->counts['submissionsUpdated'] ?? 0);
    }

    /** The public pass is a legacy-reading, writing path, so it refuses production on its own. */
    #[Test]
    public function it_refuses_to_run_in_production(): void
    {
        $this->laneWroteTheVacancyForm();
        $_SERVER['CRAFT_ENVIRONMENT'] = 'production';

        $report = $this->migrate();

        self::assertSame([], $this->gateway->submissions);
        self::assertStringContainsString('production', implode("\n", $report->warnings));
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

        $this->service->migrate(new MigrationOptions(), new EnvironmentContext(
            name: 'NL',
            database: 'legacy',
            sites: $context->sites,
            mapping: Mapping::fromArray($data),
            legacy: $context->legacy,
        ), $report, 'kuma');

        self::assertSame([], $this->gateway->submissions);
        self::assertSame([], $this->gateway->saved);
    }

    /**
     * A submission in a locale the environment does not bind stays on the
     * environment's own site. Formie would otherwise file it on Craft's primary
     * site — Berkvens NL — and an FR lead would cross site groups.
     */
    #[Test]
    public function a_submission_in_an_unbound_locale_stays_on_its_environments_site(): void
    {
        $this->submissionLang = 'en';

        $this->migrate(submissions: 'nodes: [131]', env: 'FR');

        self::assertSame(2, $this->gateway->submissions[$this->submissionId(3, 'FR')]['siteId']);
    }
}
