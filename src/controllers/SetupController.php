<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\controllers;

use Craft;
use craft\helpers\App;
use craft\web\Controller;
use Lameco\KumaCompile\Legacy\EntityTableIndex;
use Lameco\KumaCompile\Legacy\LegacyCatalogue;
use Lameco\KumaCompile\Legacy\LegacyDatabase;
use Lameco\KumaCompile\Mapping\MappingDocument;
use Lameco\KumaCompile\Mapping\Skeleton;
use lameco\kunstmaanmigrator\mapping\SetupDraft;
use lameco\kunstmaanmigrator\Plugin;
use lameco\kunstmaanmigrator\ProductionGuard;
use lameco\kunstmaanmigrator\run\EnvironmentPipeline;
use lameco\kunstmaanmigrator\utilities\MigrationUtility;
use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * Setting a migration up, before there is a mapping to edit.
 *
 * Everything here was previously a command with flags you had to already know
 * — `mapping/init --environments=COM=enreach_website,DE=enreach_website_de` —
 * which asks an operator to remember database names, spot that
 * `enreach_website_oss` is a near-duplicate of the LV one, and know which
 * locales each publishes before choosing where they go.
 *
 * None of that is knowledge; it is all a query. So the wizard discovers it and
 * asks the questions that are genuinely decisions: which of these databases is
 * this migration, what do you call each one, where are its uploads, and which
 * Craft site does each locale write to.
 *
 * It ends by writing the mapping file and handing over to the editor. The file
 * remains the single source of truth — this is a faster way to its first
 * draft, not a replacement for it.
 */
final class SetupController extends Controller
{
    public function actionIndex(): Response
    {
        $this->requireCpRequest();
        $this->requirePermission('utility:' . MigrationUtility::id());

        $databases = [];
        $error = null;

        try {
            $databases = $this->catalogue()->kunstmaanDatabases();
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        // Suggested here rather than in the template: a view that computes what
        // it displays is a view you cannot check without rendering it.
        foreach ($databases as $i => $candidate) {
            $databases[$i]['label'] = SetupDraft::suggestLabel((string) $candidate['database']);
        }

        return $this->renderTemplate('kunstmaan-migrator/_setup-databases', [
            'databases' => $databases,
            'error' => $error,
            'isProduction' => ProductionGuard::isProduction(),
            'mappingPath' => $this->mappingPath(),
        ]);
    }

    /**
     * Step two: what each chosen database publishes, and where it goes.
     */
    public function actionLocales(): Response
    {
        $this->requireCpRequest();
        $this->requirePermission('utility:' . MigrationUtility::id());

        $draft = SetupDraft::fromString((string) $this->request->getQueryParam('envs', ''));

        if ($draft->isEmpty()) {
            return $this->redirect('kunstmaan-migrator/setup');
        }

        $catalogue = $this->catalogue();
        $environments = [];

        foreach ($draft->environments as $label => $database) {
            $environments[$label] = [
                'database' => $database,
                'locales' => $catalogue->locales($database),
            ];
        }

        return $this->renderTemplate('kunstmaan-migrator/_setup-locales', [
            'environments' => $environments,
            'envs' => $draft->toString(),
            'sites' => Craft::$app->getSites()->getAllSites(),
        ]);
    }

    /**
     * Step three: discover the corpus and write the mapping.
     */
    public function actionWrite(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('utility:' . MigrationUtility::id());

        if (ProductionGuard::isProduction()) {
            throw new BadRequestHttpException('Refusing to read a legacy database against CRAFT_ENVIRONMENT=production.');
        }

        $draft = SetupDraft::fromString((string) $this->request->getBodyParam('envs', ''));
        $path = $this->mappingPath();

        if ($draft->isEmpty() || $path === null) {
            Craft::$app->getSession()->setError(Craft::t(
                'kunstmaan-migrator',
                'Choose at least one database, and set a mapping file path in the plugin settings.',
            ));

            return $this->redirect('kunstmaan-migrator/setup');
        }

        // Refusing rather than overwriting: the mapping is the migration, and
        // a wizard that clobbers a finished one is hours of decisions gone.
        if (is_file($path)) {
            Craft::$app->getSession()->setError(Craft::t(
                'kunstmaan-migrator',
                'A mapping already exists at that path. Move it aside first, or edit it instead.',
            ));

            return $this->redirect('kunstmaan-migrator/mapping');
        }

        try {
            $this->write($draft, $path);
        } catch (Throwable $e) {
            Craft::$app->getSession()->setError($e->getMessage());

            return $this->redirectToPostedUrl();
        }

        Craft::$app->getSession()->setNotice(Craft::t('kunstmaan-migrator', 'Mapping created.'));

        return $this->redirect('kunstmaan-migrator/mapping');
    }

    private function write(SetupDraft $draft, string $path): void
    {
        $dsn = EnvironmentPipeline::dsnFromSettings();
        $databases = [];

        foreach ($draft->environments as $label => $database) {
            $databases[$label] = LegacyDatabase::connect($label, $database, $dsn);
        }

        $source = trim((string) $this->request->getBodyParam('source', ''));
        $entities = $source !== '' ? EntityTableIndex::fromSource($source) : EntityTableIndex::empty();

        @mkdir(dirname($path), 0o775, true);
        file_put_contents($path, (new Skeleton($entities))->generate($databases));

        // The skeleton writes every locale and media root as a TODO, because a
        // generator cannot know them. The wizard just asked, so it fills them
        // in — through MappingDocument, so the answers land in the file the
        // same way every later edit does.
        $document = MappingDocument::fromFile($path);
        $locales = (array) $this->request->getBodyParam('locales', []);
        $roots = (array) $this->request->getBodyParam('mediaRoot', []);

        foreach ($draft->environments as $label => $database) {
            $document->patch('environments', $label, [
                'database' => $database,
                'mediaRoot' => self::mediaRoots((string) ($roots[$label] ?? '')),
                'locales' => self::localeMap((array) ($locales[$label] ?? [])),
            ]);
        }

        $document->save();
    }

    /**
     * A media root chain, split on newlines.
     *
     * Several because an environment routinely falls back to another site's
     * uploads for media that only ever lived there — Enreach's DE looks in its
     * own directory first, then COM's.
     *
     * @return list<string>
     */
    private static function mediaRoots(string $raw): array
    {
        return array_values(array_filter(array_map(trim(...), preg_split('~[\r\n]+~', $raw) ?: [])));
    }

    /**
     * Locale to Craft site, with the deliberate omissions kept as omissions.
     *
     * A locale nobody migrates is not the same as a locale nobody has decided
     * about, so it is written as `!unmapped "<reason>"` rather than dropped —
     * the mapping's own way of saying "no, and here is why".
     *
     * @param array<string, mixed> $posted
     * @return array<string, mixed>
     */
    private static function localeMap(array $posted): array
    {
        $out = [];

        foreach ($posted as $locale => $choice) {
            $locale = (string) $locale;
            $site = trim((string) ($choice['site'] ?? ''));

            if ($site !== '') {
                $out[$locale] = $site;

                continue;
            }

            $reason = trim((string) ($choice['reason'] ?? ''));
            $out[$locale] = new \Symfony\Component\Yaml\Tag\TaggedValue(
                'unmapped',
                $reason !== '' ? $reason : 'no Craft site for this locale',
            );
        }

        return $out;
    }

    private function catalogue(): LegacyCatalogue
    {
        return new LegacyCatalogue(EnvironmentPipeline::dsnFromSettings());
    }

    private function mappingPath(): ?string
    {
        $path = App::parseEnv(Plugin::getInstance()->getSettings()->mappingPath);

        return is_string($path) && $path !== '' ? $path : null;
    }
}
