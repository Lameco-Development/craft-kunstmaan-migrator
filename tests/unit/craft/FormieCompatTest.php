<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\craft;

use Lameco\Kunstmaanmigrator\craft\FormieCompat;
use PHPUnit\Framework\TestCase;

/**
 * The gateway's Formie 3 / Formie 4 seams, driven with doubles shaped like
 * each major's classes, so both paths are covered whichever Formie the dev
 * install happens to have.
 */
final class FormieCompatTest extends TestCase
{
    public function testFormie3LayoutIsToldWhatToDelete(): void
    {
        $layout = new class() {
            public ?array $deleted = null;

            public function setDeletedItems(array $items): void
            {
                $this->deleted = $items;
            }
        };

        FormieCompat::recordDeletedItems($layout, static fn(): array => ['fields' => [7], 'rows' => [3], 'pages' => []]);

        self::assertSame(['fields' => [7], 'rows' => [3], 'pages' => []], $layout->deleted);
    }

    public function testFormie4LayoutIsLeftToDiffItself(): void
    {
        $layout = new class() {
        };
        $built = false;

        FormieCompat::recordDeletedItems($layout, static function() use (&$built): array {
            $built = true;

            return [];
        });

        self::assertFalse($built, 'the deleted-items list is not even built when Formie has nowhere to put it');
    }

    public function testFormie3SubmissionIsMarkedNew(): void
    {
        $submission = new class() {
            public bool $isNewSubmission = false;
        };

        FormieCompat::markNewSubmission($submission);

        self::assertTrue($submission->isNewSubmission);
    }

    public function testFormie4SubmissionWithoutTheFlagIsLeftAlone(): void
    {
        // A Craft element throws on an unknown property; a plain object would
        // grow a dynamic one. Either way the flag must not be written.
        $submission = new class() {
            public function __set(string $name, mixed $value): void
            {
                throw new \LogicException("Setting unknown property: $name");
            }
        };

        FormieCompat::markNewSubmission($submission);

        $this->addToAssertionCount(1);
    }

    public function testFormie4UploadFolder(): void
    {
        $field = new class() {
            public function getUploadFolderForSubmission(object $submission): object
            {
                return (object) ['id' => '42'];
            }
        };

        self::assertSame(42, FormieCompat::uploadFolderId($field, new \stdClass()));
    }

    public function testFormie3UploadFolder(): void
    {
        $field = new class() {
            public function resolveDynamicPathToFolderId(?object $submission = null): int
            {
                return 17;
            }
        };

        self::assertSame(17, FormieCompat::uploadFolderId($field, new \stdClass()));
    }

    public function testUploadFolderOfAFieldWithNeither(): void
    {
        self::assertNull(FormieCompat::uploadFolderId(new \stdClass(), new \stdClass()));
    }
}
