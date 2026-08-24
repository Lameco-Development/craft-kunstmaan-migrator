<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\console;

use Lameco\Kunstmaanmigrator\console\StateController;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `state/diff` is a pure function over two exports, so it is testable without a state table,
 * a database or a Craft application — which is the point of keeping the comparison out of the
 * action body.
 */
final class StateDiffTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private function export(array ...$rows): array
    {
        return array_map(
            static fn(array $row): array => ['sourceUid' => $row[0], 'entryId' => $row[1], 'targetType' => 'entry', 'alias_of' => null],
            $rows,
        );
    }

    #[Test]
    public function an_entry_the_previous_run_wrote_and_this_one_did_not_is_lost(): void
    {
        $diff = StateController::diff(
            $this->export(['kuma:COM:content_pages:1', 10], ['kuma:COM:content_pages:2', 11]),
            $this->export(['kuma:COM:content_pages:1', 10]),
        );

        self::assertSame(['kuma:COM:content_pages:2'], $diff['lost']);
        self::assertSame(1, $diff['counts']['lost']);
    }

    #[Test]
    public function an_entry_whose_element_id_moved_was_re_created_not_updated(): void
    {
        // The churn signal. The entry is still written, so nothing is lost — but its element id
        // changed, and anything holding the old one is now stale.
        $diff = StateController::diff(
            $this->export(['kuma:COM:content_pages:1', 10]),
            $this->export(['kuma:COM:content_pages:1', 77]),
        );

        self::assertSame([], $diff['lost']);
        self::assertSame([['sourceUid' => 'kuma:COM:content_pages:1', 'was' => 10, 'now' => 77]], $diff['recreated']);
    }

    #[Test]
    public function a_run_that_wrote_more_reports_the_additions_without_failing(): void
    {
        // A corrected mapping is supposed to produce these. Failing on them would make the gate
        // fire on exactly the outcome the workflow is aiming at.
        $diff = StateController::diff(
            $this->export(['kuma:COM:content_pages:1', 10]),
            $this->export(['kuma:COM:content_pages:1', 10], ['kuma:COM:content_pages:2', 11]),
        );

        self::assertSame(['kuma:COM:content_pages:2'], $diff['added']);
        self::assertSame([], $diff['lost']);
    }

    #[Test]
    public function two_identical_exports_report_nothing(): void
    {
        $diff = StateController::diff(
            $this->export(['kuma:COM:content_pages:1', 10]),
            $this->export(['kuma:COM:content_pages:1', 10]),
        );

        self::assertSame(['from' => 1, 'to' => 1, 'lost' => 0, 'added' => 0, 'recreated' => 0], $diff['counts']);
    }
}
