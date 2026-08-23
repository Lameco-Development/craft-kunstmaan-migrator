<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\controllers;

use Craft;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use Lameco\KumaCompile\Legacy\CheckoutScanner;
use Lameco\KumaCompile\Legacy\Dsn;
use Lameco\KumaCompile\Legacy\EntityTableIndex;
use Lameco\KumaCompile\Legacy\Introspection;
use Lameco\KumaCompile\Legacy\Introspector;
use Lameco\KumaCompile\Legacy\LegacyCatalogue;
use Lameco\KumaCompile\Legacy\LegacyDatabase;
use Lameco\KumaCompile\Mapping\MappingDocument;
use Lameco\KumaCompile\Mapping\Skeleton;
use lameco\kunstmaanmigrator\mapping\MappingEditor;
use lameco\kunstmaanmigrator\mapping\SetupDraft;
use lameco\kunstmaanmigrator\mapping\SetupStep;
use lameco\kunstmaanmigrator\Plugin;
use lameco\kunstmaanmigrator\ProductionGuard;
use lameco\kunstmaanmigrator\run\EnvironmentPipeline;
use PDO;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * Setting a migration up, one question at a time.
 *
 * This was a command with flags you had to already know:
 * `mapping/init --environments=COM=enreach_website,DE=enreach_website_de`. It
 * asks an operator to remember database names, to spot that
 * `enreach_website_oss` is a near-duplicate of the LV one, and to know which
 * locales each publishes before deciding where they go. None of that is
 * knowledge — it is all a query — so the wizard runs the queries and asks only
 * the things that are genuinely decisions.
 *
 * Four steps, and each answers its own question before you leave it: the
 * connection is tested where you type it, an uploads folder is checked while
 * you type it, and the last screen shows what is about to be written rather
 * than writing it and hoping.
 */
final class SetupController extends Controller
{
    public function actionIndex(): Response
    {
        return $this->redirect('kunstmaan-migrator/setup/detect');
    }

    // ── Step 0 ───────────────────────────────────────────────────────────────

    /**
     * If the assets are being migrated, a working copy of the legacy site is
     * almost certainly sitting in a sibling folder — and that copy already
     * knows what the next screens ask: its `.env` has the database, its
     * `public/uploads/media` is the media root, and its `composer.lock` says
     * it is a Kunstmaan site at all. So this scans and offers; every value it
     * prefills stays editable on the screens that follow.
     */
    public function actionDetect(): Response
    {
        $this->guard();

        $root = trim((string) $this->request->getQueryParam('root', ''));

        if ($root === '') {
            // The Craft project's parent is where sibling checkouts live —
            // ~/Sites for a checkout at ~/Sites/<project>.
            $root = dirname((string) Craft::getAlias('@root'));
        }

        return $this->step(SetupStep::Detect, 'detect', [
            'root' => $root,
            'checkouts' => (new CheckoutScanner())->scan($root),
            'existingMapping' => $this->existingMapping(),
        ]);
    }

    public function actionChooseCheckout(): Response
    {
        $this->guard();
        $this->requirePostRequest();

        $path = trim((string) $this->request->getBodyParam('path', ''));
        $checkout = $path !== '' ? (new CheckoutScanner())->inspect($path) : null;

        if ($checkout === null) {
            Craft::$app->getSession()->setError(Craft::t('kunstmaan-migrator', 'That folder is not a Kunstmaan checkout.'));

            return $this->redirect('kunstmaan-migrator/setup/detect');
        }

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $settings->legacySourcePath = $checkout['path'];

        $passwordNote = null;

        if ($checkout['database'] !== null) {
            $settings->legacyDbServer = $checkout['database']['host'];
            $settings->legacyDbPort = $checkout['database']['port'];
            $settings->legacyDbUser = $checkout['database']['user'];
            $settings->legacyDbDatabase = $checkout['database']['database'];

            // A literal password is refused by the settings model — it would be
            // written into project config and committed. So the prefill is a
            // reference: when an env var of this project already resolves to the
            // found password, its name goes in; otherwise the operator is told
            // exactly what to add and where the password sits.
            $password = $checkout['database']['password'];
            $reference = $password !== '' ? self::envReferenceFor($password) : null;

            if ($reference !== null) {
                $settings->legacyDbPassword = '$' . $reference;
            } elseif ($password !== '') {
                $passwordNote = Craft::t(
                    'kunstmaan-migrator',
                    'The database password is in {env}. Add it to this project\'s .env (e.g. KUMA_DB_PASSWORD=…) and set the password field to $KUMA_DB_PASSWORD.',
                    ['env' => $checkout['path'] . '/.env'],
                );
            }
        }

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray())) {
            Craft::$app->getSession()->setError(implode(' ', $settings->getErrorSummary(true)));

            return $this->redirect('kunstmaan-migrator/setup/detect');
        }

        Craft::$app->getSession()->setNotice(trim(Craft::t(
            'kunstmaan-migrator',
            'Prefilled from {path} — check the connection and adjust anything that is off.',
            ['path' => $checkout['path'] . '/.env'],
        ) . ' ' . ($passwordNote ?? '')));

        return $this->redirect('kunstmaan-migrator/setup/connect');
    }

    // ── Step 1 ───────────────────────────────────────────────────────────────

    public function actionConnect(): Response
    {
        $this->guard();

        return $this->step(SetupStep::Connect, 'connect', [
            'settings' => Plugin::getInstance()->getSettings(),
        ]);
    }

    public function actionSaveConnection(): Response
    {
        $this->guard();
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        foreach (['legacyDbServer', 'legacyDbDatabase', 'legacyDbUser', 'legacyDbPassword'] as $attribute) {
            $settings->$attribute = trim((string) $this->request->getBodyParam($attribute, ''));
        }

        $settings->legacyDbPort = (int) ($this->request->getBodyParam('legacyDbPort') ?: 3306);

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray())) {
            Craft::$app->getSession()->setError(implode(' ', $settings->getErrorSummary(true)));

            return $this->redirect('kunstmaan-migrator/setup/connect');
        }

        return $this->redirect('kunstmaan-migrator/setup/sites');
    }

    /**
     * Whether those credentials reach a database server, answered where they
     * are typed rather than two screens later when a query fails.
     */
    public function actionTestConnection(): Response
    {
        $this->guard();
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $dsn = new Dsn(
            host: (string) App::parseEnv((string) $this->request->getBodyParam('legacyDbServer', '')) ?: '127.0.0.1',
            port: (int) ($this->request->getBodyParam('legacyDbPort') ?: 3306),
            user: (string) App::parseEnv((string) $this->request->getBodyParam('legacyDbUser', '')) ?: 'root',
            password: (string) App::parseEnv((string) $this->request->getBodyParam('legacyDbPassword', '')),
            charset: 'utf8mb4',
        );

        try {
            $found = (new LegacyCatalogue($dsn))->kunstmaanDatabases();
        } catch (Throwable $e) {
            return $this->asFailure(self::readable($e));
        }

        // The detect step read a database name out of the checkout's .env; the
        // test should answer about *that* site, not count everything on the
        // server. Without one, the count is the only answer there is.
        $detected = trim((string) ($this->request->getBodyParam('legacyDbDatabase')
            ?? Plugin::getInstance()->getSettings()->legacyDbDatabase ?? ''));

        if ($detected !== '') {
            $names = array_map(static fn (array $c): string => (string) $c['database'], $found);
            $siblings = self::siblingsOf($detected, $names);

            if (in_array($detected, $names, true)) {
                return $this->asJson([
                    'message' => $siblings === []
                        ? Craft::t('kunstmaan-migrator', 'Connected — {db} found.', ['db' => $detected])
                        : Craft::t('kunstmaan-migrator', 'Connected — {db} found, plus {n, plural, =1{1 sibling database} other{# sibling databases}} ({siblings}).', [
                            'db' => $detected,
                            'n' => count($siblings),
                            'siblings' => implode(', ', $siblings),
                        ]),
                ]);
            }

            return $this->asFailure(Craft::t(
                'kunstmaan-migrator',
                'Connected, but no database called {db} on this server. The next step lists the {n} Kunstmaan sites it does hold.',
                ['db' => $detected, 'n' => count($found)],
            ));
        }

        return $this->asJson([
            'message' => Craft::t('kunstmaan-migrator', '{n, plural, =0{Connected, but found no Kunstmaan sites} =1{Connected — 1 Kunstmaan site found} other{Connected — # Kunstmaan sites found}}', [
                'n' => count($found),
            ]),
        ]);
    }

    // ── Step 2 ───────────────────────────────────────────────────────────────

    public function actionSites(): Response
    {
        $this->guard();

        $databases = [];
        $error = null;

        try {
            $databases = $this->catalogue()->kunstmaanDatabases();
        } catch (Throwable $e) {
            $error = self::readable($e);
        }

        // The database the earlier steps settled on, and its siblings by naming
        // convention (enreach_website -> enreach_website_de), arrive pre-checked.
        // A pre-check is a suggestion, not a decision — the near-duplicate that
        // should stay behind is unticked here, once.
        $detected = trim((string) (Plugin::getInstance()->getSettings()->legacyDbDatabase ?? ''));
        $names = array_map(static fn (array $c): string => (string) $c['database'], $databases);
        $siblings = $detected !== '' ? self::siblingsOf($detected, $names) : [];

        foreach ($databases as $i => $candidate) {
            $database = (string) $candidate['database'];
            $databases[$i]['label'] = SetupDraft::suggestLabel($database);
            $databases[$i]['preselected'] = $database === $detected || in_array($database, $siblings, true);
        }

        // A dev machine's server holds every client's databases. Once the earlier
        // steps named one site, listing the other eleven again is noise — and a
        // mis-tick away from migrating someone else's content. So the list is the
        // named site and its siblings, with the full server one click away.
        $total = count($databases);
        $showAll = (bool) $this->request->getQueryParam('all', false);

        if (!$showAll && in_array($detected, $names, true)) {
            $databases = array_values(array_filter(
                $databases,
                static fn (array $c): bool => (bool) $c['preselected'],
            ));
        }

        return $this->step(SetupStep::Sites, 'sites', [
            'databases' => $databases,
            'error' => $error,
            'detected' => $detected,
            'narrowed' => count($databases) < $total,
            'hiddenCount' => $total - count($databases),
        ]);
    }

    public function actionChooseSites(): Response
    {
        $this->guard();
        $this->requirePostRequest();

        $draft = SetupDraft::fromString((string) $this->request->getBodyParam('envs', ''));

        if ($draft->isEmpty()) {
            Craft::$app->getSession()->setError(Craft::t('kunstmaan-migrator', 'Choose at least one site.'));

            return $this->redirect('kunstmaan-migrator/setup/sites');
        }

        return $this->redirect('kunstmaan-migrator/setup/locales?envs=' . rawurlencode($draft->toString()));
    }

    // ── Step 3 ───────────────────────────────────────────────────────────────

    public function actionLocales(): Response
    {
        $this->guard();

        $draft = $this->draft();
        $catalogue = $this->catalogue();
        $environments = [];

        $sites = Craft::$app->getSites()->getAllSites();

        foreach ($draft->environments as $label => $database) {
            $locales = $catalogue->locales($database);
            $suggested = [];

            foreach (array_keys($locales) as $locale) {
                $suggested[$locale] = self::suggestSite((string) $locale, $sites, (string) $label);
            }

            $environments[$label] = [
                'database' => $database,
                'locales' => $locales,
                'suggested' => $suggested,
                'chosen' => [],
            ];
        }

        return $this->step(SetupStep::Locales, 'locales', [
            'environments' => $environments,
            'sites' => $sites,
            'envs' => $draft->toString(),
        ]);
    }

    public function actionChooseLocales(): Response
    {
        $this->guard();
        $this->requirePostRequest();

        $draft = SetupDraft::fromString((string) $this->request->getBodyParam('envs', ''));

        // Carried forward as one encoded blob rather than re-asked on the review
        // screen: the review is there to show what was decided, not to collect it.
        $choices = [
            'locales' => (array) $this->request->getBodyParam('locales', []),
        ];

        return $this->redirect(sprintf(
            'kunstmaan-migrator/setup/review?envs=%s&choices=%s',
            rawurlencode($draft->toString()),
            rawurlencode(base64_encode(json_encode($choices, JSON_THROW_ON_ERROR))),
        ));
    }

    /**
     * Whether an uploads folder is actually there, answered while it is typed.
     */
    public function actionCheckPaths(): Response
    {
        $this->guard();
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $paths = self::lines((string) $this->request->getBodyParam('paths', ''));
        $missing = [];

        foreach ($paths as $path) {
            $resolved = (string) App::parseEnv($path);

            if ($resolved === '' || !is_dir($resolved) || !is_readable($resolved)) {
                $missing[] = $path;
            }
        }

        if ($missing === []) {
            return $this->asJson([
                'ok' => true,
                'message' => Craft::t('kunstmaan-migrator', '{n, plural, =1{Folder found} other{# folders found}}', ['n' => count($paths)]),
            ]);
        }

        return $this->asJson([
            'ok' => false,
            'message' => Craft::t('kunstmaan-migrator', 'Not found: {paths}', ['paths' => implode(', ', $missing)]),
        ]);
    }

    // ── Step 4 ───────────────────────────────────────────────────────────────

    public function actionReview(): Response
    {
        $this->guard();

        $draft = $this->draft();
        $choices = $this->choices();
        $catalogue = $this->catalogue();
        $summary = [];
        $totalPages = 0;
        $totalSkipped = 0;

        foreach ($draft->environments as $label => $database) {
            // The page counts are what make a wrong binding jump out — a locale
            // with four pages bound to the main site, or 335 pages headed for
            // "not migrating", reads very differently from its name alone.
            $counts = $catalogue->locales($database);
            $migrating = [];
            $skipping = [];

            foreach ((array) ($choices['locales'][$label] ?? []) as $locale => $choice) {
                $site = self::chosenSite((array) $choice);
                $pages = (int) ($counts[$locale] ?? 0);

                if ($site !== '') {
                    $migrating[(string) $locale] = ['site' => $site, 'pages' => $pages];
                    $totalPages += $pages;

                    continue;
                }

                $skipping[(string) $locale] = $pages;
                $totalSkipped += $pages;
            }

            $summary[] = [
                'label' => $label,
                'database' => $database,
                'migrating' => $migrating,
                'skipping' => $skipping,
            ];
        }

        return $this->step(SetupStep::Review, 'review', [
            'existingMapping' => $this->existingMapping(),
            'existingDecided' => $this->existingDecided(),
            'mediaRoot' => $this->mediaRoot(),
            'summary' => $summary,
            'totalPages' => $totalPages,
            'totalSkipped' => $totalSkipped,
            'envs' => $draft->toString(),
            'choices' => (string) $this->request->getQueryParam('choices', ''),
            'mappingPath' => $this->mappingPath(),
            'sourcePath' => (string) (Plugin::getInstance()->getSettings()->legacySourcePath ?? ''),
        ]);
    }

    public function actionWrite(): Response
    {
        $this->guard();
        $this->requirePostRequest();

        $draft = SetupDraft::fromString((string) $this->request->getBodyParam('envs', ''));
        $path = $this->mappingPath();

        if ($draft->isEmpty() || $path === null) {
            Craft::$app->getSession()->setError(Craft::t('kunstmaan-migrator', 'Start again — nothing was chosen.'));

            return $this->redirect('kunstmaan-migrator/setup/sites');
        }

        // The mapping is the migration, and a wizard that clobbers a finished
        // one is hours of decisions gone. So when a file exists the default is
        // to merge — newly discovered rows join it as open, every decision and
        // comment stays — and replacing is the explicit choice, moved aside
        // with a timestamp, never destroyed.
        $replaced = null;

        if (is_file($path) && $this->request->getBodyParam('existing', 'merge') !== 'replace') {
            try {
                $stats = $this->merge($draft, $path);
            } catch (Throwable $e) {
                Craft::$app->getSession()->setError(self::readable($e));

                return $this->redirect('kunstmaan-migrator/setup/sites');
            }

            Craft::$app->getSession()->setNotice(Craft::t(
                'kunstmaan-migrator',
                'Mapping merged: {added} new rows to decide, {refreshed} live counts refreshed, every decision kept.',
                ['added' => $stats['added'], 'refreshed' => $stats['refreshed']],
            ));

            return $this->redirect('kunstmaan-migrator/mapping');
        }

        if (is_file($path)) {
            $replaced = $path . '.replaced-' . date('Ymd-His');

            if (!rename($path, $replaced)) {
                Craft::$app->getSession()->setError(Craft::t(
                    'kunstmaan-migrator',
                    'Could not move the existing mapping aside at {path}.',
                    ['path' => $path],
                ));

                return $this->redirect('kunstmaan-migrator/setup/detect');
            }
        }

        try {
            $this->write($draft, $path);
        } catch (Throwable $e) {
            Craft::$app->getSession()->setError(self::readable($e));

            return $this->redirect('kunstmaan-migrator/setup/sites');
        }

        Craft::$app->getSession()->setNotice($replaced === null
            ? Craft::t('kunstmaan-migrator', 'Mapping created. Next: tell the migrator what each piece of old content becomes.')
            : Craft::t('kunstmaan-migrator', 'Mapping created. The previous one is kept at {path}.', ['path' => $replaced]));

        return $this->redirect('kunstmaan-migrator/mapping');
    }

    // ── Plumbing ─────────────────────────────────────────────────────────────

    /**
     * The site a posted locale row chose — `-` is the selectize-safe spelling
     * of "Not migrating" (an empty-valued option cannot be selected back).
     *
     * @param array<string, mixed> $choice
     */
    private static function chosenSite(array $choice): string
    {
        $site = trim((string) ($choice['site'] ?? ''));

        return $site === '-' ? '' : $site;
    }

    /** The mapping already on disk, or null — the fact the wizard must not discover last. */
    private function existingMapping(): ?string
    {
        $path = $this->mappingPath();

        return $path !== null && is_file($path) ? $path : null;
    }

    /**
     * How many decided rows a replace would set back to open.
     *
     * "A mapping already exists" and "a mapping with 98 decided rows exists"
     * are different warnings, and only the second states what the checkbox
     * costs. Null when the file cannot be read as a mapping — an unreadable
     * file carries no decisions worth counting.
     */
    private function existingDecided(): ?int
    {
        if ($this->existingMapping() === null) {
            return null;
        }

        try {
            $editor = MappingEditor::create(Plugin::getInstance()->getSettings());
            $decided = 0;

            foreach (['parts', 'pages', 'entities', 'sidecars'] as $lane) {
                $progress = $editor->progress($lane);
                $decided += $progress['decided'] + $progress['dropped'];
            }

            return $decided;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Where the old site's uploads live: the override from the plugin settings
     * when one is set, otherwise the detected checkout's `public/uploads/media`
     * — the path every Kunstmaan site here uses, which is why the wizard stopped
     * asking for it.
     */
    private function mediaRoot(): string
    {
        $settings = Plugin::getInstance()->getSettings();
        $override = trim((string) App::parseEnv((string) ($settings->legacyMediaRoot ?? '')));

        if ($override !== '') {
            return $override;
        }

        $sourcePath = (string) ($settings->legacySourcePath ?? '');

        return $sourcePath !== '' && is_dir($sourcePath . '/public/uploads/media')
            ? $sourcePath . '/public/uploads/media'
            : '';
    }

    /**
     * The Craft site a legacy locale most likely lands on, by language: legacy
     * `de` matches the site whose language is `de` or `de-*`. A suggestion the
     * dropdown starts on, not a decision — the operator changes it in place.
     *
     * @param list<\craft\models\Site> $sites
     */
    private static function suggestSite(string $locale, array $sites, string $environment = ''): string
    {
        // Kunstmaan locales are sometimes country codes where Craft languages
        // are language codes — dk is Danish, br is Brazilian Portuguese.
        $aliases = ['dk' => 'da', 'br' => 'pt'];
        $locale = strtolower($locale);
        $language = $aliases[$locale] ?? $locale;

        $matches = array_values(array_filter($sites, static function ($site) use ($language): bool {
            $siteLanguage = strtolower((string) $site->language);

            return $siteLanguage === $language || str_starts_with($siteLanguage, $language . '-');
        }));

        if ($matches === []) {
            return '';
        }

        // Two sites can share a language (comEnUs and comLvEn are both English).
        // The environment's own name is the tiebreak — LV's `en` belongs on the
        // site whose handle says lv — and the primary site takes the rest.
        foreach ($matches as $site) {
            if ($environment !== '' && stripos((string) $site->handle, $environment) !== false) {
                return (string) $site->handle;
            }
        }

        foreach ($matches as $site) {
            if ($site->primary ?? false) {
                return (string) $site->handle;
            }
        }

        return (string) $matches[0]->handle;
    }

    /**
     * Sibling databases of the detected one, by the naming convention every
     * multi-country Kunstmaan install here follows: the same stem with a
     * suffix — enreach_website, enreach_website_de, enreach_website_lv.
     *
     * @param list<string> $candidates
     * @return list<string>
     */
    private static function siblingsOf(string $database, array $candidates): array
    {
        return array_values(array_filter(
            $candidates,
            static fn (string $name): bool => $name !== $database && str_starts_with($name, $database . '_'),
        ));
    }

    /**
     * The name of an environment variable this project already holds that
     * resolves to the given secret — so the prefill can reference it instead
     * of ever writing the secret itself.
     */
    private static function envReferenceFor(string $secret): ?string
    {
        $matches = [];

        foreach ([...$_ENV, ...$_SERVER] as $name => $value) {
            if (is_string($value) && $value === $secret && preg_match('/^[A-Z][A-Z0-9_]*$/', (string) $name) === 1) {
                $matches[] = (string) $name;
            }
        }

        // On a dev box half the env resolves to the same value (root/root), so
        // the name has to say what it holds: a variable called *_USER matching
        // the password is a coincidence, not a reference.
        foreach ($matches as $name) {
            if (preg_match('/PASSWORD|SECRET|_PASS(_|$)/', $name) === 1) {
                return $name;
            }
        }

        return $matches[0] ?? null;
    }


    private function write(SetupDraft $draft, string $path): void
    {
        $dsn = EnvironmentPipeline::dsnFromSettings();
        $databases = [];

        foreach ($draft->environments as $label => $database) {
            $databases[$label] = LegacyDatabase::connect($label, $database, $dsn);
        }

        $source = trim((string) $this->request->getBodyParam('source', ''))
            ?: (string) (Plugin::getInstance()->getSettings()->legacySourcePath ?? '');

        // The same artifact the CLI writes, next to the mapping: booted metadata
        // when the checkout runs, the static scan when it does not — and the
        // skeleton reads it, so child-collection ownership is exact.
        $entities = EntityTableIndex::empty();
        $introspection = null;

        if ($source !== '' && is_dir($source)) {
            $introspector = new Introspector();
            $artifact = $introspector->introspect($source);
            $introspector->write($artifact, dirname($path) . '/introspection.json');
            $introspection = Introspection::fromArray($artifact);
            $entities = EntityTableIndex::fromIntrospection($introspection);
        }

        @mkdir(dirname($path), 0o775, true);
        file_put_contents($path, (new Skeleton($entities, $introspection))->generate($databases));

        // The skeleton writes every locale and uploads folder as a TODO, because
        // a generator cannot know them. The wizard just asked, so it fills them
        // in — through MappingDocument, so they land the same way every later
        // edit does.
        $document = MappingDocument::fromFile($path);
        $this->applyDraft($document, $draft);
        $document->save();
    }

    /**
     * Fold a fresh survey into an existing mapping without losing a decision.
     *
     * A re-run of the wizard used to mean replace-or-refuse, and replace is
     * how a finished mapping became a skeleton this August. Merging keeps the
     * old file as the base — every decision, every comment — and patches in
     * only what the new survey found: rows that did not exist yet, refreshed
     * live counts on ones that did, and the environment answers the wizard
     * just collected. Rows the survey no longer sees are kept, not deleted;
     * `coverage` is the place that reports a table gone missing.
     *
     * @return array{added: int, refreshed: int}
     */
    private function merge(SetupDraft $draft, string $path): array
    {
        $dsn = EnvironmentPipeline::dsnFromSettings();
        $databases = [];

        foreach ($draft->environments as $label => $database) {
            $databases[$label] = LegacyDatabase::connect($label, $database, $dsn);
        }

        $source = trim((string) $this->request->getBodyParam('source', ''))
            ?: (string) (Plugin::getInstance()->getSettings()->legacySourcePath ?? '');

        $entities = EntityTableIndex::empty();
        $introspection = null;

        if ($source !== '' && is_dir($source)) {
            $introspector = new Introspector();
            $artifact = $introspector->introspect($source);
            $introspector->write($artifact, dirname($path) . '/introspection.json');
            $introspection = Introspection::fromArray($artifact);
            $entities = EntityTableIndex::fromIntrospection($introspection);
        }

        $skeletonPath = tempnam(sys_get_temp_dir(), 'kuma-skeleton') . '.yaml';
        file_put_contents($skeletonPath, (new Skeleton($entities, $introspection))->generate($databases));

        try {
            $skeleton = MappingDocument::fromFile($skeletonPath);
            $document = MappingDocument::fromFile($path);
            $added = 0;
            $refreshed = 0;

            foreach (['parts', 'pages', 'entities', 'sidecars'] as $lane) {
                foreach ($skeleton->lane($lane) as $key => $spec) {
                    if (!is_array($spec)) {
                        continue;
                    }

                    $existing = $document->row($lane, (string) $key);

                    if ($existing === null) {
                        $document->patch($lane, (string) $key, $spec);
                        $added++;
                    } elseif (array_key_exists('live', $spec) && ($existing['live'] ?? null) !== $spec['live']) {
                        $document->patch($lane, (string) $key, ['live' => $spec['live']]);
                        $refreshed++;
                    }
                }
            }

            $this->applyDraft($document, $draft);
            $document->save();
        } finally {
            @unlink($skeletonPath);
        }

        return ['added' => $added, 'refreshed' => $refreshed];
    }

    /** The wizard's answers — environments, locales, media root — always win: they were just given. */
    private function applyDraft(MappingDocument $document, SetupDraft $draft): void
    {
        $choices = $this->choices();
        $mediaRoot = $this->mediaRoot();

        foreach ($draft->environments as $label => $database) {
            $document->patch('environments', $label, [
                'database' => $database,
                'mediaRoot' => $mediaRoot !== '' ? [$mediaRoot] : [],
                'locales' => self::localeMap((array) ($choices['locales'][$label] ?? [])),
            ]);
        }
    }

    /**
     * Locale to Craft site, with the deliberate omissions kept as omissions.
     *
     * A language nobody migrates is not the same as one nobody has decided
     * about, so it is written as `!unmapped "<reason>"` — the mapping's own way
     * of saying "no, and here is why".
     *
     * @param array<string, mixed> $posted
     * @return array<string, mixed>
     */
    private static function localeMap(array $posted): array
    {
        $out = [];

        foreach ($posted as $locale => $choice) {
            $site = self::chosenSite((array) $choice);

            if ($site !== '') {
                $out[(string) $locale] = $site;

                continue;
            }

            $reason = trim((string) ($choice['reason'] ?? ''));
            $out[(string) $locale] = new TaggedValue('unmapped', $reason !== '' ? $reason : 'not selected during setup');
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function step(SetupStep $step, string $template, array $variables): Response
    {
        $previous = $step->previous();

        return $this->renderTemplate('kunstmaan-migrator/_setup/' . $template, [
            ...$variables,
            'step' => $step,
            'steps' => SetupStep::all(),
            'back' => $previous === null ? null : $this->backTo($previous),
        ]);
    }

    private function backTo(SetupStep $step): string
    {
        $query = array_filter([
            'envs' => (string) $this->request->getQueryParam('envs', ''),
            'choices' => $step === SetupStep::Locales ? '' : (string) $this->request->getQueryParam('choices', ''),
        ]);

        return UrlHelper::cpUrl('kunstmaan-migrator/setup/' . $step->value, $query);
    }

    private function draft(): SetupDraft
    {
        $draft = SetupDraft::fromString((string) $this->request->getQueryParam('envs', ''));

        if ($draft->isEmpty()) {
            throw new BadRequestHttpException('No sites were chosen.');
        }

        return $draft;
    }

    /** @return array{locales: array<string, mixed>, mediaRoot: array<string, mixed>} */
    private function choices(): array
    {
        $raw = (string) ($this->request->getQueryParam('choices') ?? $this->request->getBodyParam('choices', ''));
        $decoded = $raw === '' ? [] : json_decode((string) base64_decode($raw, true), true);

        return [
            'locales' => (array) ($decoded['locales'] ?? []),
            'mediaRoot' => (array) ($decoded['mediaRoot'] ?? []),
        ];
    }

    private function guard(): void
    {
        $this->requireCpRequest();
        $this->requirePermission(Plugin::PERMISSION);

        if (ProductionGuard::isProduction()) {
            throw new BadRequestHttpException('The migrator does not read a legacy database on production.');
        }
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

    /** @return list<string> */
    private static function lines(string $raw): array
    {
        return array_values(array_filter(array_map(trim(...), preg_split('~[\r\n]+~', $raw) ?: [])));
    }

    /**
     * A driver error, said in a way somebody can act on.
     *
     * `SQLSTATE[HY000] [1045] Access denied for user 'root'@'localhost' (using
     * password: NO)` is accurate and tells a non-DBA nothing about what to do.
     */
    private static function readable(Throwable $e): string
    {
        $message = $e->getMessage();

        return match (true) {
            str_contains($message, 'Access denied') => Craft::t('kunstmaan-migrator', 'That user or password was refused by the server.'),
            str_contains($message, 'Connection refused'),
            str_contains($message, 'getaddrinfo'),
            str_contains($message, "Can't connect") => Craft::t('kunstmaan-migrator', 'No database server answered at that address and port.'),
            str_contains($message, 'Unknown database') => Craft::t('kunstmaan-migrator', 'That database does not exist on the server.'),
            default => $message,
        };
    }
}
