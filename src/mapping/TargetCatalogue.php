<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\mapping;

/**
 * What this Craft install offers a mapping to choose from.
 *
 * Deliberately not more methods on SchemaGateway, which answers a different
 * question: the loader asks "does this handle exist, and what shape is it",
 * about a handle somebody already wrote. The editor asks "what may I write",
 * with nothing written yet. One is validation, the other is a menu, and the
 * seven fakes that implement SchemaGateway have no use for a menu.
 */
interface TargetCatalogue
{
    /**
     * Entry type handles, for the `pages` and `entities` lanes.
     *
     * @return list<string>
     */
    public function entryTypes(): array;

    /**
     * Section handles, for the lanes that name a section as well as a type.
     *
     * @return list<string>
     */
    public function sections(): array;

    /**
     * Entry type handles grouped by the section that uses them.
     *
     * Types no section uses — Matrix block types — are absent here;
     * `entryTypes()` still lists them.
     *
     * @return array<string, list<string>> section name => entry type handles
     */
    public function entryTypesBySection(): array;
}
