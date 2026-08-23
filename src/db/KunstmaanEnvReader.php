<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\db;

use Craft;
use lameco\kunstmaanmigrator\Plugin;
use Symfony\Component\Dotenv\Dotenv;
use Throwable;
use yii\base\Component;

/**
 * Reads `.env.example` then `.env` from the Kunstmaan source path with a
 * strict 2-key whitelist (Phase 4.1 / Plan 01 / D-01..D-09).
 *
 * Whitelist: DATABASE_URL, DEFAULT_LOCALE. Every other key is silently
 * dropped before it lands in any field — third-party secret keys NEVER
 * enter plugin memory. The whitelist is hard-coded; adding keys requires
 * a code change, not a config change.
 *
 * Read order matches Symfony convention: `.env.example` first, then `.env`
 * (override per-key). When `.env` is absent (rehearsal-against-checkout
 * pattern), `.env.example` is the sole source.
 *
 * Failure modes (D-06 — defensive, never throws):
 *   - missing source path → all accessors return null
 *   - non-existent or non-readable .env files → silently skipped
 *   - malformed Dotenv content → caught + logged via Craft::warning;
 *     `loaded` flag stays true so we don't retry on every accessor call
 *
 * DSN parsing (D-08, D-09):
 *   - `parse_url()` decomposes the DSN; only mysql / mysql+pdo / pdo:mysql
 *     schemes are honored — postgres / sqlite / file: / javascript: schemes
 *     leave `parsedDsn` null so downstream consumers see blanks
 *   - each component is `urldecode()`'d (Symfony DSNs URL-encode `@`, `/`,
 *     `:` in passwords)
 *
 * Relocated from src/source/ to src/db/ in the v2 loader prune. The v1.x
 * `ensureLoaded()` runtime path resolved the Kunstmaan source checkout via
 * `KunstmaanSourcePathResolver` (analyze-stage machinery, removed in this
 * prune); that component is no longer registered, so the resolver lookup
 * below throws and `ensureLoaded()` degrades to "no .env found" rather than
 * crashing. `Settings::beforeValidate()` is the sole production consumer,
 * via the `loadFromPath()` test seam / DI.
 */
final class KunstmaanEnvReader extends Component
{
    /**
     * Hard-coded 2-key whitelist. Adding a key here is a deliberate code
     * change reviewed in PR — secrets MUST NOT slide in via configuration
     * (T-04.1-01-01 mitigation).
     */
    private const WHITELIST = ['DATABASE_URL', 'DEFAULT_LOCALE'];

    /**
     * Lazy-load latch. Once set, the reader does not re-parse — even on
     * failure. Prevents retry storms when accessors are hit in a loop.
     */
    private bool $loaded = false;

    private ?string $databaseUrl = null;
    private ?string $defaultLocale = null;

    /**
     * Pre-parsed DSN components. null when DATABASE_URL is missing OR when
     * the scheme is not mysql / mysql+pdo / pdo:mysql (D-09).
     *
     * @var array{host?: string|null, port?: int|null, user?: string|null, password?: string|null, database?: string|null}|null
     */
    private ?array $parsedDsn = null;

    /**
     * Whitelisted keys that were actually found across the read pair.
     * Used by the 9th doctor check to report "found: DATABASE_URL,
     * DEFAULT_LOCALE" without leaking any values.
     *
     * @var list<string>
     */
    private array $foundKeys = [];

    public function getDatabaseUrl(): ?string
    {
        $this->ensureLoaded();
        return $this->databaseUrl;
    }

    public function getDefaultLocale(): ?string
    {
        $this->ensureLoaded();
        return $this->defaultLocale;
    }

    public function getDsnHost(): ?string
    {
        $this->ensureLoaded();
        return $this->parsedDsn['host'] ?? null;
    }

    public function getDsnPort(): ?int
    {
        $this->ensureLoaded();
        return $this->parsedDsn['port'] ?? null;
    }

    public function getDsnUser(): ?string
    {
        $this->ensureLoaded();
        return $this->parsedDsn['user'] ?? null;
    }

    public function getDsnPassword(): ?string
    {
        $this->ensureLoaded();
        return $this->parsedDsn['password'] ?? null;
    }

    public function getDsnDatabase(): ?string
    {
        $this->ensureLoaded();
        return $this->parsedDsn['database'] ?? null;
    }

    /**
     * Whitelisted keys actually found in `.env` / `.env.example`. Used by
     * the doctor 9th check (Task 3) to report presence WITHOUT exposing
     * values (T-04.1-01-04 mitigation).
     *
     * @return list<string>
     */
    public function found(): array
    {
        $this->ensureLoaded();
        return $this->foundKeys;
    }

    /**
     * Test seam (D-05 follow-on): bypass Plugin::getInstance() and parse
     * a directory directly. Used by KunstmaanEnvReaderTest's pure-PHPUnit
     * shape and re-used internally by ensureLoaded().
     *
     * Side effects: sets `$this->loaded = true` and populates the public
     * accessor backing fields. Calling this twice is supported but
     * subsequent calls are silently ignored (loaded latch wins) — a fresh
     * Component instance is the canonical way to re-parse.
     */
    public function loadFromPath(string $sourcePath): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;

        if ($sourcePath === '' || !is_dir($sourcePath)) {
            return;
        }

        $envExample = $sourcePath . '/.env.example';
        $env        = $sourcePath . '/.env';

        $merged = [];
        foreach ([$envExample, $env] as $file) {
            if (!is_file($file) || !is_readable($file)) {
                continue;
            }
            $contents = @file_get_contents($file);
            if ($contents === false) {
                continue;
            }
            try {
                $parsed = (new Dotenv())->parse($contents, $file);
            } catch (Throwable $e) {
                // T-04.1-01-03 mitigation: malformed .env doesn't crash the plugin.
                // Log + continue — operator sees the issue in Craft logs;
                // doctor check surfaces the resulting blank values.
                $this->safeWarn('KunstmaanEnvReader: malformed env file ' . $file . ': ' . $e->getMessage());
                continue;
            }
            foreach (self::WHITELIST as $key) {
                if (array_key_exists($key, $parsed)) {
                    $merged[$key] = (string) $parsed[$key];
                }
            }
        }

        $this->databaseUrl   = $merged['DATABASE_URL']   ?? null;
        $this->defaultLocale = $merged['DEFAULT_LOCALE'] ?? null;
        $this->foundKeys     = array_values(array_intersect(self::WHITELIST, array_keys($merged)));

        if ($this->databaseUrl !== null && $this->databaseUrl !== '') {
            $this->parseDsn($this->databaseUrl);
        }
    }

    /**
     * Resolve source path via the Plugin's resolver, then delegate to
     * loadFromPath. Used at runtime when consumers hit a public accessor.
     */
    private function ensureLoaded(): void
    {
        if ($this->loaded) {
            return;
        }
        try {
            // Nullsafe: in the unit tier no plugin instance exists, and "no
            // plugin" means the same as "no source path" — nothing to read.
            $sourcePath = Plugin::getInstance()?->kunstmaanSourcePathResolver?->resolve();
        } catch (Throwable $e) {
            $this->loaded = true;
            $this->safeWarn('KunstmaanEnvReader: source-path resolver failed: ' . $e->getMessage());
            return;
        }
        if ($sourcePath === null) {
            $this->loaded = true;
            return;
        }
        $this->loadFromPath($sourcePath);
    }

    /**
     * Decompose a Symfony-style DATABASE_URL into addressable components.
     * D-09: only mysql / mysql+pdo / pdo:mysql schemes are honored.
     * D-08: each component is percent-decoded.
     */
    private function parseDsn(string $dsn): void
    {
        $parts = @parse_url($dsn);
        if (!is_array($parts)) {
            return;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (
            $scheme !== 'mysql'
            && $scheme !== 'mysql+pdo'
            && !str_starts_with($scheme, 'pdo:mysql')
            && !str_starts_with($scheme, 'pdo_mysql')
        ) {
            return;
        }

        $database = null;
        if (isset($parts['path'])) {
            $database = ltrim(urldecode((string) $parts['path']), '/');
            if ($database === '') {
                $database = null;
            }
        }

        $this->parsedDsn = [
            'host'     => isset($parts['host']) ? urldecode((string) $parts['host']) : null,
            'port'     => isset($parts['port']) ? (int) $parts['port'] : null,
            'user'     => isset($parts['user']) ? urldecode((string) $parts['user']) : null,
            'password' => isset($parts['pass']) ? urldecode((string) $parts['pass']) : null,
            'database' => $database,
        ];
    }

    /**
     * Defensive Craft::warning wrapper. The unit tests call into this
     * class without a Craft bootstrap — Craft::warning would resolve to
     * an undefined static. Swallow the resulting Error so tests still
     * pass; production code always has Craft loaded.
     */
    private function safeWarn(string $message): void
    {
        try {
            Craft::warning($message, 'kunstmaanmigrator');
        } catch (Throwable) {
            // Bootless test environment — no logger available. Acceptable
            // because the doctor 9th check (Task 3) is the operator-visible
            // path; this warning is a secondary signal in production logs.
        }
    }
}
