<?php

declare(strict_types=1);

namespace Lameco\Kunstmaanmigrator\tests\unit\load;

use Lameco\Kunstmaanmigrator\craft\FormGateway;
use Lameco\Kunstmaanmigrator\load\FormMigrationService;
use Lameco\Kunstmaanmigrator\tests\support\InMemoryFormGateway;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The forms lane's own decisions, behind the Formie seam.
 *
 * The unmerged attempt at this lane was 667 lines with no test and a hard
 * dependency on Formie, so none of this was checkable without a booted Craft
 * with that plugin installed. It is checkable in milliseconds now, which is the
 * whole argument for the seam.
 */
final class FormMigrationServiceTest extends TestCase
{
    private function service(FormGateway $gateway): FormMigrationService
    {
        $service = (new ReflectionClass(FormMigrationService::class))->newInstanceWithoutConstructor();
        $service->forms = $gateway;

        return $service;
    }

    private function invoke(FormMigrationService $service, string $method, mixed ...$args): mixed
    {
        return (new ReflectionClass(FormMigrationService::class))
            ->getMethod($method)
            ->invoke($service, ...$args);
    }

    /**
     * Two legacy pages routinely share a title. Naming the form after the title
     * would have one silently overwrite the other, so the handle comes from the
     * legacy identity, which is unique by construction.
     */
    public function testTheHandleComesFromTheLegacyIdentityNotTheTitle(): void
    {
        $service = $this->service(new InMemoryFormGateway());

        self::assertSame(
            'kumaComPotionslandingpage27',
            $this->invoke($service, 'handleFor', 'kuma:COM:form:PotionsLandingPage:27', 'kuma'),
        );
    }

    /**
     * A page id is unique within one legacy database and a migration walks
     * three, so COM's PotionsLandingPage 27 and DE's are different pages. An
     * earlier draft of this method left the environment out and the second run
     * would have overwritten the first — the same class of bug as the rewriter
     * caching bare legacy ids across databases.
     */
    public function testTwoEnvironmentsDoNotCollideOnOneHandle(): void
    {
        $service = $this->service(new InMemoryFormGateway());

        $com = $this->invoke($service, 'handleFor', 'kuma:COM:form:PotionsLandingPage:27', '');
        $de = $this->invoke($service, 'handleFor', 'kuma:DE:form:PotionsLandingPage:27', '');

        self::assertNotSame($com, $de);
    }

    public function testTheGatewayIsAskedForEachFormExactlyOnce(): void
    {
        $gateway = new InMemoryFormGateway();
        $warnings = [];

        $gateway->saveForm('a', 'A', [['type' => 'singleLineText']], [], $warnings);
        $gateway->saveForm('a', 'A renamed', [['type' => 'singleLineText']], [], $warnings);

        self::assertCount(1, $gateway->saved);
        self::assertSame('A renamed', $gateway->saved['a']['title']);
    }

    public function testAGatewayThatRefusesReportsRatherThanThrows(): void
    {
        $gateway = new InMemoryFormGateway();
        $gateway->refuse = ['broken'];
        $warnings = [];

        self::assertNull($gateway->saveForm('broken', 'Broken', [], [], $warnings));
        self::assertNotSame([], $warnings);
    }

    public function testTheLaneNamesItselfAfterTheRegistryHandle(): void
    {
        self::assertSame('forms', $this->service(new InMemoryFormGateway())->handle());
    }
}
