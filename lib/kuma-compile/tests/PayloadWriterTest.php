<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Tests;

use Lameco\KumaCompile\Compile\PayloadWriter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PayloadWriterTest extends TestCase
{
    #[Test]
    public function an_empty_field_set_stays_an_object(): void
    {
        // PHP cannot tell [] apart from {}; encoded as a list, the loader would read a
        // field set as a list of blocks.
        $json = (new PayloadWriter(null))->encode([
            'sourceUid' => 'kuma:COM:kuma_nodes:1',
            'sites' => ['en' => ['fieldValues' => ['builder' => [['type' => 'cardsBlock', 'fields' => []]]]]],
        ]);

        self::assertStringContainsString('"fields":{}', $json);
        self::assertStringNotContainsString('"fields":[]', $json);
    }

    #[Test]
    public function matrix_blocks_stay_a_list(): void
    {
        $json = (new PayloadWriter(null))->encode([
            'sites' => ['en' => ['fieldValues' => ['builder' => [
                ['type' => 'a', 'fields' => ['x' => 1]],
                ['type' => 'b', 'fields' => ['y' => 2]],
            ]]]],
        ]);

        self::assertStringContainsString('"builder":[{"type":"a"', $json);
    }
}
