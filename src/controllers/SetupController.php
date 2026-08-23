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
use lameco\kunstmaanmigrator\mapping\SetupDraft;
use lameco\kunstmaanmigrator\mapping\SetupStep;
use lameco\kunstmaanmigrator\Plugin;
use lameco\kunstmaanmigrator\ProductionGuard;
use lameco\kunstmaanmigrator\run\EnvironmentPipeline;
use lameco\kunstmaanmigrator\utilities\MigrationUtility;
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

        // The detect step found the checkout; its uploads folder is the media
        // root for every environment until the operator says otherwise.
        $sourcePath = (string) (Plugin::getInstance()->getSettings()->legacySourcePath ?? '');
        $detectedMedia = $sourcePath !== '' && is_dir($sourcePath . '/public/uploads/media')
            ? $sourcePath . '/public/uploads/media'
            : '';

        foreach ($draft->environments as $label => $database) {
            $environments[$label] = [
                'database' => $database,
                'locales' => $catalogue->locales($database),
                'mediaRoot' => $detectedMedia,
                'chosen' => [],
            ];
        }

        return $this->step(SetupStep::Locales, 'locales', [
            'environments' => $environments,
            'sites' => Craft::$app->getSites()->getAllSites(),
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
            'mediaRoot' => (array) $this->request->getBodyParam('mediaRoot', []),
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
        $summary = [];

        foreach ($draft->environments as $label => $database) {
            $migrating = [];
            $skipping = [];

            foreach ((array) ($choices['locales'][$label] ?? []) as $locale => $choice) {
                $site = trim((string) ($choice['site'] ?? ''));

                if ($site !== '') {
                    $migrating[(string) $locale] = $site;

                    continue;
                }

                $skipping[(string) $locale] = trim((string) ($choice['reason'] ?? ''))
                    ?: Craft::t('kunstmaan-migrator', 'no reason given');
            }

            $summary[] = [
                'label' => $label,
                'database' => $database,
                'migrating' => $migrating,
                'skipping' => $skipping,
                'mediaRoot' => self::lines((string) ($choices['mediaRoot'][$label] ?? '')),
            ];
        }

        return $this->step(SetupStep::Review, 'review', [
            'summary' => $summary,
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

        // Refusing rather than overwriting: the mapping is the migration, and a
        // wizard that clobbers a finished one is hours of decisions gone.
        if (is_file($path)) {
            Craft::$app->getSession()->setError(Craft::t(
                'kunstmaan-migrator',
                'There is already a mapping at {path}. Move it aside first, or edit it instead.',
                ['path' => $path],
            ));

            return $this->redirect('kunstmaan-migrator/mapping');
        }

        try {
            $this->write($draft, $path);
        } catch (Throwable $e) {
            Craft::$app->getSession()->setError(self::readable($e));

            return $this->redirect('kunstmaan-migrator/setup/sites');
        }

        Craft::$app->getSession()->setNotice(Craft::t(
            'kunstmaan-migrator',
            'Mapping created. Next: tell the migrator what each piece of old content becomes.',
        ));

        return $this->redirect('kunstmaan-migrator/mapping');
    }

    // ── Plumbing ─────────────────────────────────────────────────────────────

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
        foreach ([...$_ENV, ...$_SERVER] as $name => $value) {
            if (is_string($value) && $value === $secret && preg_match('/^[A-Z][A-Z0-9_]*$/', (string) $name) === 1) {
                return (string) $name;
            }
        }

        return null;
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

        if ($source !== '' && is_dir($source)) {
            $introspector = new Introspector();
            $artifact = $introspector->introspect($source);
            $introspector->write($artifact, dirname($path) . '/introspection.json');
            $entities = EntityTableIndex::fromIntrospection(Introspection::fromArray($artifact));
        }

        @mkdir(dirname($path), 0o775, true);
        file_put_contents($path, (new Skeleton($entities))->generate($databases));

        // The skeleton writes every locale and uploads folder as a TODO, because
        // a generator cannot know them. The wizard just asked, so it fills them
        // in — through MappingDocument, so they land the same way every later
        // edit does.
        $document = MappingDocument::fromFile($path);
        $choices = $this->choices();

        foreach ($draft->environments as $label => $database) {
            $document->patch('environments', $label, [
                'database' => $database,
                'mediaRoot' => self::lines((string) ($choices['mediaRoot'][$label] ?? '')),
                'locales' => self::localeMap((array) ($choices['locales'][$label] ?? [])),
            ]);
        }

        $document->save();
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
            $site = trim((string) ($choice['site'] ?? ''));

            if ($site !== '') {
                $out[(string) $locale] = $site;

                continue;
            }

            $reason = trim((string) ($choice['reason'] ?? ''));
            $out[(string) $locale] = new TaggedValue('unmapped', $reason !== '' ? $reason : 'not migrating');
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
        $this->requirePermission('utility:' . MigrationUtility::id());

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
