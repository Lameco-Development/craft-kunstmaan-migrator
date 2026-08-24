<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\Target;

/** One row of a block spec's migration-notes table. */
final readonly class Note
{
    /**
     * @param 'part'|'item' $scope whether the property sits on the pagepart or a child row
     * @param list<string> $sources legacy property names
     * @param list<string> $targets Craft field handles
     */
    public function __construct(
        public string $scope,
        public array $sources,
        public array $targets,
        public string $kind,
        public string $note,
    ) {
    }

    public function isMapped(): bool
    {
        return $this->kind === SpecNotes::MAPPED && $this->targets !== [];
    }
}
