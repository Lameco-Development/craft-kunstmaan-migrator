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

            // This carried no factory and a paragraph explaining why: the redirect
            // records come from the mapping rather than a table, so the pass needed
            // the mapping and an open connection, and `migrateAll(options, sites)`
            // could hold neither. EnvironmentContext holds both, so the documented
            // permanent exception is now an ordinary adapter.
            new Adapter('redirects', 'Redirects', 'retourEnabled', 'retour', static fn () => Plugin::getInstance()->redirectMigrationService),

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
            // The `forms:` lane. Optional the same way the others are: Formie is
            // suggested, never required, and the gate reports its absence as a
            // reason rather than an error.
            new Adapter(
                'forms',
                'Forms',
                'formsEnabled',
                'formie',
                static fn () => Plugin::getInstance()->formMigrationService,
                [
                    new AdapterSetting(
                        'handlePrefix',
                        'Form handle prefix',
                        AdapterSetting::TYPE_STRING,
                        'kuma',
                        'Prefixes every migrated form handle, so migrated forms are '
                        . 'distinguishable from ones built by hand and cannot collide with them.',
                    ),
                    new AdapterSetting(
                        'submitActionMessage',
                        'Confirmation message',
                        AdapterSetting::TYPE_STRING,
                        '',
                        'Shown after a submission. Legacy forms carry their own `thanks` text '
                        . 'per page; this is the fallback for the ones that do not.',
                    ),
                ],
            ),

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
