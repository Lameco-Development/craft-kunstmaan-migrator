<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\tests\unit\safety;

use InvalidArgumentException;
use lameco\kunstmaanmigrator\safety\GateResult;
use PHPUnit\Framework\TestCase;

final class MigrationGateServiceTest extends TestCase
{
    public function testGateResultSerializesAllFields(): void
    {
        $result = new GateResult(
            id: 'queue-worker-readiness',
            label: 'Queue worker readiness',
            status: 'unknown',
            severity: 'error',
            message: 'Queue readiness could not be verified.',
            remediation: 'Use the CLI after confirming a worker is running.',
            cliCommand: './craft kunstmaan-migrator/migrate --live',
            blocking: false,
        );

        self::assertSame([
            'id' => 'queue-worker-readiness',
            'label' => 'Queue worker readiness',
            'status' => 'unknown',
            'severity' => 'error',
            'message' => 'Queue readiness could not be verified.',
            'remediation' => 'Use the CLI after confirming a worker is running.',
            'cliCommand' => './craft kunstmaan-migrator/migrate --live',
            'blocking' => true,
        ], $result->toArray());
    }

    public function testGateResultAllowsOnlyLockedStatuses(): void
    {
        foreach (['passed', 'warning', 'blocked', 'unknown'] as $status) {
            $result = new GateResult(
                id: $status,
                label: $status,
                status: $status,
                severity: $status === 'passed' ? 'success' : 'warning',
                message: $status,
                remediation: '',
                cliCommand: '',
                blocking: $status !== 'passed',
            );

            self::assertSame($status, $result->toArray()['status']);
        }
    }

    public function testGateResultRejectsUnsupportedStatuses(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new GateResult(
            id: 'bad',
            label: 'Bad',
            status: 'unsupported',
            severity: 'error',
            message: 'Bad status',
            remediation: '',
            cliCommand: '',
            blocking: true,
        );
    }
}
