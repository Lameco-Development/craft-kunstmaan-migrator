<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\adapters;

use lameco\kunstmaanmigrator\Plugin;
use yii\base\Component;
use yii\base\Event;

/**
 * Every adapter the migrator knows about.
 *
 * Built in are the four that ship with the plugin. A project with its own
 * third-party target registers through EVENT_REGISTER_ADAPTERS rather than by
 * editing this class — that costs one event now and is awkward to retrofit
 * once callers assume the list is fixed.
 */
final class AdapterRegistry extends Component
{
    public const EVENT_REGISTER_ADAPTERS = 'registerAdapters';

    /** @var list<Adapter>|null */
    private ?array $adapters = null;

    /** @return list<Adapter> */
    public function all(): array
    {
        if ($this->adapters !== null) {
            return $this->adapters;
        }

        $event = new RegisterAdaptersEvent();
        $event->adapters = self::builtIn();
        $this->trigger(self::EVENT_REGISTER_ADAPTERS, $event);

        return $this->adapters = array_values($event->adapters);
    }

    public function byHandle(string $handle): ?Adapter
    {
        foreach ($this->all() as $adapter) {
            if ($adapter->handle === $handle) {
                return $adapter;
            }
        }

        return null;
    }

    /** @return list<Adapter> */
    private static function builtIn(): array
    {
        return [
            new Adapter('seo', 'SEO', 'seoEnabled', 'seomatic', static fn () => Plugin::getInstance()->seoMigrationService),

            // No factory: the redirect records come from the mapping's `redirects:`
            // lane rather than from the legacy database, so the pass needs the
            // compiler and the environment it is compiling — neither of which fits
            // migrateAll(options, sites). EnvironmentPipeline runs it directly and
            // this row exists for the gate and the settings screen. The seam that
            // would let it join the loop is an EnvironmentContext carrying the
            // environment, its database and its media roots; that is the next step,
            // and it retires the last three properties the pipeline writes onto
            // long-lived singletons.
            new Adapter('redirects', 'Redirects', 'retourEnabled', 'retour'),

            new Adapter(
                'navigation',
                'Navigation',
                'navigationEnabled',
                'navigation',
                static fn () => Plugin::getInstance()->navigationMigrationService,
                [
                    new AdapterSetting(
                        'navHandle',
                        'Target navigation',
                        AdapterSetting::TYPE_STRING,
                        'headerMain',
                        'The verbb/navigation nav the legacy NodeMenu is written into.',
                        legacyProperty: 'nodeMenuNavHandle',
                    ),
                    new AdapterSetting(
                        'excludedInternalNames',
                        'Excluded internal names',
                        AdapterSetting::TYPE_LIST,
                        ['settings'],
                        'Legacy `kuma_nodes.internal_name` values to leave out of the nav. '
                        . 'Every Lameco site filters `settings`; some also filter a legacy overview page.',
                        legacyProperty: 'nodeMenuExcludedInternalNames',
                    ),
                ],
            ),

            // The translation pass writes Craft's own site catalogs, so it
            // needs nothing installed. enupal-translate is an enhancement it
            // checks for separately, not a requirement to run at all.
            new Adapter(
                'translations',
                'Translations',
                'translationsEnabled',
                null,
                static fn () => Plugin::getInstance()->translationMigrationService,
                [
                    new AdapterSetting(
                        'domains',
                        'Symfony translation domains',
                        AdapterSetting::TYPE_LIST,
                        ['messages'],
                        'Which `kuma_translation` domains to migrate. `messages` is what a '
                        . '`{% trans %}` without an explicit `from` uses; others are skipped with a warning.',
                        legacyProperty: 'translationDomains',
                    ),
                ],
            ),
        ];
    }
}
