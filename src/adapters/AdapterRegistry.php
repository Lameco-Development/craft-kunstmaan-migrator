<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\adapters;

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
            new Adapter('seo', 'SEO', 'seoEnabled', 'seomatic'),
            new Adapter('redirects', 'Redirects', 'retourEnabled', 'retour'),
            new Adapter('navigation', 'Navigation', 'navigationEnabled', 'navigation'),
            // The translation pass writes Craft's own site catalogs, so it
            // needs nothing installed. enupal-translate is an enhancement it
            // checks for separately, not a requirement to run at all.
            new Adapter('translations', 'Translations', 'translationsEnabled'),
        ];
    }
}
