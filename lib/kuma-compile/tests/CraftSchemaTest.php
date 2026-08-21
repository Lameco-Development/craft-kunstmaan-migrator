<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Tests;

use Lameco\KumaCompile\Target\CraftSchema;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CraftSchemaTest extends TestCase
{
    private function schema(): CraftSchema
    {
        return CraftSchema::fromProjectConfig(__DIR__ . '/fixtures/craft');
    }

    #[Test]
    public function a_null_instance_handle_falls_back_to_the_base_field_handle(): void
    {
        // The layout element declares handle: null, meaning "use the field's own handle".
        // Missing this is what made an earlier attempt read the Matrix field as `None`.
        $slot = $this->schema()->slot('contentBlock', 'contentColumns');

        self::assertNotNull($slot);
        self::assertTrue($slot->isMatrix());
        self::assertSame(['contentColumn'], $slot->nested);
    }

    #[Test]
    public function a_heading_inside_a_matrix_resolves_to_a_path_not_the_root(): void
    {
        self::assertSame('contentColumns[0]', $this->schema()->pathFor('contentBlock', 'heading'));
        self::assertSame('', $this->schema()->pathFor('contentColumn', 'heading'), 'block level');
        self::assertNull($this->schema()->pathFor('contentBlock', 'quote'), 'no such field anywhere');
    }

    #[Test]
    public function the_nested_entry_type_is_read_not_guessed(): void
    {
        // The old convention table said "singular of the field handle", which would give
        // `contentColumn` here by luck and `logos` -> `logo` wrongly elsewhere.
        self::assertSame('contentColumn', $this->schema()->nestedTypeOf('contentBlock', 'contentColumns'));
        self::assertNull($this->schema()->nestedTypeOf('contentBlock', 'heading'), 'not a Matrix');
    }

    #[Test]
    public function it_reports_required_fields_and_known_handles(): void
    {
        self::assertSame(['contentColumns'], $this->schema()->requiredFields('contentBlock'));
        self::assertTrue($this->schema()->hasEntryType('contentColumn'));
        self::assertFalse($this->schema()->hasEntryType('nope'));
        self::assertTrue($this->schema()->hasSection('pages'));
    }
}
