<?php

declare(strict_types=1);

namespace Lameco\KumaCompile\Tests;

use Lameco\KumaCompile\Legacy\CheckoutScanner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The wizard's detect step: a folder of checkouts, and only the Kunstmaan ones
 * offered — with the database its own `.env` names and the uploads folder found
 * rather than typed.
 */
final class CheckoutScannerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/kuma-scan-' . bin2hex(random_bytes(4));

        // A Kunstmaan site with everything detectable.
        $site = $this->root . '/acme-website';
        mkdir($site . '/public/uploads/media', 0o777, true);
        file_put_contents($site . '/composer.lock', json_encode(['packages' => [
            ['name' => 'kunstmaan/node-bundle', 'version' => '7.2.0'],
        ]]));
        file_put_contents($site . '/.env', "APP_ENV=prod\nDATABASE_URL=\"mysql://root:s3cr%40t@127.0.0.1:3307/acme_website?serverVersion=8\"\n");
        // .env.local wins where both speak.
        file_put_contents($site . '/.env.local', "DATABASE_URL=mysql://root:s3cr%40t@127.0.0.1:3307/acme_website_local\n");

        // A Symfony site that is not Kunstmaan.
        $other = $this->root . '/plain-symfony';
        mkdir($other, 0o777, true);
        file_put_contents($other . '/composer.lock', json_encode(['packages' => [
            ['name' => 'symfony/framework-bundle', 'version' => 'v6.4.0'],
        ]]));

        // A folder with no lock at all.
        mkdir($this->root . '/not-a-project', 0o777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function only_kunstmaan_checkouts_are_offered_with_env_and_uploads_read(): void
    {
        $found = (new CheckoutScanner())->scan($this->root);

        self::assertCount(1, $found);
        self::assertSame('acme-website', $found[0]['name']);
        self::assertSame('7.2.0', $found[0]['kunstmaan']);
        self::assertSame($this->root . '/acme-website/public/uploads/media', $found[0]['mediaRoot']);

        // `.env.local` overrode the database; the encoded password decodes.
        self::assertSame([
            'host' => '127.0.0.1',
            'port' => 3307,
            'user' => 'root',
            'password' => 's3cr@t',
            'database' => 'acme_website_local',
        ], $found[0]['database']);
    }

    #[Test]
    public function a_folder_that_is_not_a_checkout_inspects_to_null(): void
    {
        self::assertNull((new CheckoutScanner())->inspect($this->root . '/plain-symfony'));
        self::assertNull((new CheckoutScanner())->inspect($this->root . '/nowhere'));
    }
}
