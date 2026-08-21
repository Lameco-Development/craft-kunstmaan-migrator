<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\controllers;

use Craft;
use craft\helpers\App;
use craft\web\Controller;
use lameco\kunstmaanmigrator\Plugin;
use PDO;
use Throwable;
use yii\web\Response;

/**
 * The settings screen's one action: prove the legacy database is reachable
 * before a run finds out the hard way.
 *
 * Connecting is cheap and a full migration is hours, so the answer belongs in
 * the screen where the credentials are typed rather than in the first minute
 * of a run.
 */
final class SettingsController extends Controller
{
    public function actionTestConnection(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('accessPlugin-kunstmaan-migrator');

        $settings = Plugin::getInstance()->getSettings();

        $server = App::parseEnv($settings->legacyDbServer) ?: '';
        $database = App::parseEnv($settings->legacyDbDatabase) ?: '';

        if ($server === '' || $database === '') {
            return $this->asJson([
                'ok' => false,
                'message' => Craft::t('app', 'Set a server and a database first.'),
            ]);
        }

        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s', $server, $settings->legacyDbPort, $database),
                (string) App::parseEnv($settings->legacyDbUser),
                (string) App::parseEnv($settings->legacyDbPassword),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5],
            );

            $prefix = (string) App::parseEnv($settings->legacyDbTablePrefix);
            $nodes = $this->countOrNull($pdo, $prefix . $settings->nodesTableName);
            $media = $this->countOrNull($pdo, $prefix . 'kuma_media');
        } catch (Throwable $e) {
            return $this->asJson(['ok' => false, 'message' => $e->getMessage()]);
        }

        if ($nodes === null) {
            return $this->asJson([
                'ok' => false,
                'message' => sprintf(
                    'Connected, but "%s" is not there — this looks like the wrong database.',
                    $prefix . $settings->nodesTableName,
                ),
            ]);
        }

        return $this->asJson([
            'ok' => true,
            'message' => sprintf(
                'Connected. %s nodes, %s media.',
                number_format($nodes),
                $media === null ? 'no' : number_format($media),
            ),
        ]);
    }

    /**
     * A missing table is an answer, not a failure — it is how the screen tells
     * a reachable-but-wrong database from an unreachable one.
     */
    private function countOrNull(PDO $pdo, string $table): ?int
    {
        try {
            $statement = $pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '', $table) . '`');

            return $statement === false ? null : (int) $statement->fetchColumn();
        } catch (Throwable) {
            return null;
        }
    }
}
