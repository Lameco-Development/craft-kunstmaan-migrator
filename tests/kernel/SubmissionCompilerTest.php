<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\kernel;

use Lameco\Kunstmaanmigrator\Compile\SubmissionCompiler;
use Lameco\Kunstmaanmigrator\Mapping\Mapping;
use Lameco\Kunstmaanmigrator\Source\LegacyDatabase;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Kunstmaan form submissions, grouped per legacy form-owning node.
 *
 * A submission names the node it was posted on, not the form; the fields name
 * the pagepart they answered (`field_<namespace><Class>PagePart<id>`), not a
 * Formie handle. Both are read from the data — the node may be deleted, the
 * label may have changed three times since.
 */
final class SubmissionCompilerTest extends TestCase
{
    private const MAPPING = <<<'YAML'
        version: 1
        environments:
          NL:
            database: legacy
            locales: { nl: berkvensNl }
        forms:
          context: main
          fields:
            SingleLineText: { table: kuma_single_line_text_page_parts, type: singleLineText }
          submissions:
            nodes: all
        YAML;

    private function db(): LegacyDatabase
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

        // Node 222 is live (public version 75); node 131 was deleted, its last
        // version still names the page it was.
        $pdo->exec('INSERT INTO kuma_nodes VALUES (222, 0), (131, 1), (999, 0)');
        $pdo->exec("INSERT INTO kuma_node_versions VALUES
                    (11, 21, 'App\\\\Entity\\\\Pages\\\\VacancyFormPage', 75),
                    (12, 22, 'App\\\\Entity\\\\Pages\\\\FormPage', 40),
                    (13, 22, 'App\\\\Entity\\\\Pages\\\\FormPage', 41),
                    (14, 23, 'App\\\\Entity\\\\Pages\\\\LandingPageForm', 69)");
        $pdo->exec("INSERT INTO kuma_node_translations VALUES
                    (21, 222, 'nl', 'Solliciteren', 1, 11),
                    (22, 131, 'nl', 'Contact', 1, 12),
                    (23, 876, 'nl', 'Stalen aanvragen', 1, 14)");

        $pdo->exec("INSERT INTO kuma_form_submissions VALUES
                    (1, 222, '10.0.0.1', 'nl', '2017-05-24 11:22:49'),
                    (2, 222, '10.0.0.2', 'nl', '2024-09-04 12:06:47'),
                    (3, 131, '10.0.0.3', 'nl', '2016-04-06 12:44:41'),
                    (4, 131, '10.0.0.4', 'nl', '2025-06-06 04:16:23'),
                    (5, 876, '10.0.0.4', 'nl', '2025-06-06 04:16:25')");

        $name = 'field_KunstmaanFormBundleEntityPagePartsSingleLineTextPagePart198';
        $choice = 'field_KunstmaanFormBundleEntityPagePartsChoicePagePart66';
        $file = 'field_KunstmaanFormBundleEntityPagePartsFileUploadPagePart1';
        $mail = 'field_KunstmaanFormBundleEntityPagePartsEmailPagePart36';
        $text = 'field_KunstmaanFormBundleEntityPagePartsMultiLineTextPagePart2';
        $pdo->exec("INSERT INTO kuma_form_submission_fields
                    (id, form_submission_id, field_name, label, discr, sfsf_value, tfsf_value, cfsf_value, multiple, choices, ffsf_value, efsf_value, sequence, url)
                    VALUES
                    (10, 1, '$name', 'Voornaam en achternaam', 'string', 'Jan Jansen', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
                    (11, 1, '$choice', 'Dhr./mevr.', 'choice', NULL, NULL, 'i:1;', 0, 'a:2:{i:0;s:4:\"Dhr.\";i:1;s:5:\"Mevr.\";}', NULL, NULL, NULL, NULL),
                    (12, 1, '$file', 'Upload je CV', 'file', NULL, NULL, NULL, NULL, NULL, 'cv.docx', NULL, NULL, NULL),
                    (20, 2, '$choice', 'Aanhef', 'choiceformsubmissionfield', NULL, NULL, 'i:0;', 0, 'a:2:{i:0;s:4:\"Dhr.\";i:1;s:5:\"Mevr.\";}', NULL, NULL, 1, NULL),
                    (21, 2, '$name', 'Voornaam', 'stringformsubmissionfield', 'Piet', NULL, NULL, NULL, NULL, NULL, NULL, 2, NULL),
                    (22, 2, '$mail', 'E-mailadres', 'emailformsubmissionfield', NULL, NULL, NULL, NULL, NULL, NULL, 'piet@example.nl', 3, NULL),
                    (23, 2, '$file', 'Upload CV', 'fileformsubmissionfield', NULL, NULL, NULL, NULL, NULL, 'cv.pdf', NULL, 4, '/uploads/formsubmissions/abc/cv.pdf'),
                    (30, 3, '$text', 'Uw vraag', 'text', NULL, 'Hoe laat?', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
                    (31, 3, 'field_KunstmaanFormBundleEntityPagePartsChoicePagePart9', 'Markt', 'choice', NULL, NULL, 'a:2:{i:0;i:0;i:1;i:2;}', 0, 'a:3:{i:0;s:10:\"Woningbouw\";i:1;s:14:\"Utiliteitsbouw\";i:2;s:8:\"Woonzorg\";}', NULL, NULL, NULL, NULL)");

        return new LegacyDatabase($pdo, 'NL', 'legacy');
    }

    private function mapping(string $yaml = self::MAPPING): Mapping
    {
        $path = tempnam(sys_get_temp_dir(), 'kuma') . '.yaml';
        file_put_contents($path, $yaml);

        return Mapping::fromFile($path);
    }

    /** @return array<int, array<string, mixed>> node id => form group */
    private function compile(string $yaml = self::MAPPING): array
    {
        $out = [];

        (new SubmissionCompiler($this->mapping($yaml)))->compile(
            $this->db(),
            'NL',
            static function(array $group) use (&$out): void {
                $out[$group['node']] = $group;
            },
        );

        return $out;
    }

    /** Node 876 holds only an empty submission, so it has nothing to land and no form to ask for. */
    #[Test]
    public function submissions_are_grouped_per_node_under_the_form_the_lane_would_name(): void
    {
        $groups = $this->compile();

        self::assertSame([131, 222], array_keys($groups));
        self::assertSame('kuma:NL:form:VacancyFormPage:75', $groups[222]['formUid']);
        self::assertSame('Solliciteren', $groups[222]['title']);
        self::assertTrue($groups[222]['live']);
        self::assertCount(2, $groups[222]['submissions']);
    }

    /**
     * A deleted node keeps its translations and versions: the form it carried is
     * named after its public version — the page a lane run before the deletion
     * would have compiled — not after a later draft.
     */
    #[Test]
    public function a_deleted_node_is_named_after_its_public_version(): void
    {
        $group = $this->compile()[131];

        self::assertSame('kuma:NL:form:FormPage:40', $group['formUid']);
        self::assertFalse($group['live']);
    }

    #[Test]
    public function each_submission_carries_an_environment_scoped_key_and_its_original_date(): void
    {
        $submission = $this->compile()[222]['submissions'][0];

        self::assertSame('NL:kuma_form_submission:1', $submission['key']);
        self::assertSame('2017-05-24 11:22:49', $submission['created']);
        self::assertSame('10.0.0.1', $submission['ip']);
        self::assertSame('nl', $submission['lang']);
    }

    /**
     * The label of part 198 went from "Voornaam en achternaam" to "Voornaam";
     * the part id did not. Values are keyed on the part, so both submissions
     * answer the same field.
     */
    #[Test]
    public function values_are_keyed_on_the_pagepart_they_answered_not_on_their_label(): void
    {
        $submissions = $this->compile()[222]['submissions'];

        self::assertSame('Jan Jansen', $submissions[0]['values']['SingleLineText:198']['value']);
        self::assertSame('Piet', $submissions[1]['values']['SingleLineText:198']['value']);
        self::assertSame('piet@example.nl', $submissions[1]['values']['Email:36']['value']);
    }

    /** `cfsf_value` is a serialised index into the serialised `choices`. */
    #[Test]
    public function a_choice_decodes_to_the_labels_it_picked(): void
    {
        $groups = $this->compile();

        self::assertSame(['Mevr.'], $groups[222]['submissions'][0]['values']['Choice:66']['value']);
        self::assertSame(['Dhr.'], $groups[222]['submissions'][1]['values']['Choice:66']['value']);
        self::assertSame(['Woningbouw', 'Woonzorg'], $groups[131]['submissions'][0]['values']['Choice:9']['value']);
    }

    #[Test]
    public function a_file_value_carries_its_name_and_where_the_legacy_site_kept_it(): void
    {
        $submissions = $this->compile()[222]['submissions'];

        self::assertSame(['name' => 'cv.docx', 'url' => null], $submissions[0]['values']['FileUpload:1']['value']);
        self::assertSame(
            ['name' => 'cv.pdf', 'url' => '/uploads/formsubmissions/abc/cv.pdf'],
            $submissions[1]['values']['FileUpload:1']['value'],
        );
    }

    /**
     * What an archive form for the node would hold: every part any submission
     * answered, under its newest label, typed from the submission field class
     * in either of Kunstmaan's two discriminator vocabularies.
     */
    #[Test]
    public function the_group_describes_every_field_its_submissions_answered(): void
    {
        $fields = $this->compile()[222]['fields'];

        self::assertSame(
            ['Choice:66', 'SingleLineText:198', 'Email:36', 'FileUpload:1'],
            array_keys($fields),
        );
        self::assertSame(['kind' => 'choice', 'label' => 'Aanhef', 'choices' => ['Dhr.', 'Mevr.'], 'multiple' => false], $fields['Choice:66']);
        self::assertSame('Voornaam', $fields['SingleLineText:198']['label']);
        self::assertSame('email', $fields['Email:36']['kind']);
        self::assertSame('file', $fields['FileUpload:1']['kind']);
    }

    /** A choice stored as a list of indexes picked more than one: checkboxes, not a dropdown. */
    #[Test]
    public function a_choice_answered_with_a_list_is_described_as_multiple(): void
    {
        self::assertTrue($this->compile()[131]['fields']['Choice:9']['multiple']);
        self::assertSame('text', $this->compile()[131]['fields']['MultiLineText:2']['kind']);
    }

    /**
     * Berkvens NL holds 14 submissions with no field row at all, one IP, one
     * morning. A Formie submission with nothing in it is noise in the inbox.
     */
    #[Test]
    public function a_submission_without_a_single_value_is_skipped_and_counted(): void
    {
        $compiler = new SubmissionCompiler($this->mapping());
        $out = [];
        $compiler->compile($this->db(), 'NL', static function(array $group) use (&$out): void {
            $out[$group['node']] = $group;
        });

        self::assertSame([3], array_column($out[131]['submissions'], 'id'));
        self::assertSame(2, $compiler->skipped()['submission carries no value']);
    }

    #[Test]
    public function only_the_listed_nodes_compile_when_the_mapping_names_them(): void
    {
        $groups = $this->compile(str_replace('nodes: all', 'nodes: [131]', self::MAPPING));

        self::assertSame([131], array_keys($groups));
    }

    #[Test]
    public function nothing_compiles_when_the_mapping_does_not_opt_in(): void
    {
        $yaml = str_replace("  submissions:\n    nodes: all", '', self::MAPPING);

        self::assertSame([], $this->compile($yaml));
    }
}
