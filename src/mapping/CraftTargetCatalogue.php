<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\mapping;

use Craft;

final class CraftTargetCatalogue implements TargetCatalogue
{
    public function entryTypes(): array
    {
        $handles = array_map(
            static fn ($entryType): string => (string) $entryType->handle,
            Craft::$app->getEntries()->getAllEntryTypes(),
        );

        sort($handles);

        return array_values(array_unique($handles));
    }

    public function entryTypesBySection(): array
    {
        $groups = [];

        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            foreach ($section->getEntryTypes() as $entryType) {
                $groups[(string) $section->name][(string) $entryType->handle] = true;
            }
        }

        ksort($groups);

        return array_map(static function (array $handles): array {
            $handles = array_keys($handles);
            sort($handles);

            return $handles;
        }, $groups);
    }
}
