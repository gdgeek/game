<?php

namespace app\components;

use Yii;
use yii\base\Component;
use yii\db\Connection;
use yii\redis\Connection as RedisConnection;

class HealthService extends Component
{
    public const DATABASE_TIMEOUT = 5;
    public const REDIS_TIMEOUT = 3;

    /** @var Connection|null Defaults to the application's database. */
    public $db;

    /** @var RedisConnection|null Defaults to Redis when configured by the application. */
    public $redis;

    public function check(): array
    {
        $checks = ['database' => $this->checkDatabase()];
        if ($this->redis !== null || Yii::$app->has('redis')) {
            $checks['redis'] = $this->checkRedis();
        }

        $result = [
            'status' => in_array('down', array_column($checks, 'status'), true) ? 'unhealthy' : 'healthy',
            'timestamp' => date('c'),
            'checks' => $checks,
        ];
        foreach (['gitCommit' => 'GIT_COMMIT', 'version' => 'BUILD_TIME'] as $key => $filename) {
            $value = $this->readBuildMetadata($filename);
            if ($value !== null) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function checkDatabase(): array
    {
        $started = hrtime(true);
        try {
            $db = $this->db ?? Yii::$app->get('db');
            if (!$db->isActive && $db->driverName === 'mysql') {
                $db->attributes = array_replace($db->attributes ?? [], [\PDO::ATTR_TIMEOUT => self::DATABASE_TIMEOUT]);
            }
            $db->createCommand('SELECT 1')->queryScalar();

            return $this->checkResult($started);
        } catch (\Throwable $exception) {
            return $this->checkResult($started, 'Service unavailable');
        }
    }

    private function checkRedis(): array
    {
        $started = hrtime(true);
        try {
            $redis = $this->redis ?? Yii::$app->get('redis');
            if ($redis instanceof RedisConnection) {
                $redis->connectionTimeout = self::REDIS_TIMEOUT;
                $redis->dataTimeout = self::REDIS_TIMEOUT;
            }
            $reply = $redis->executeCommand('PING');

            return $this->checkResult($started, $reply === 'PONG' || $reply === true ? null : 'Unexpected PING response');
        } catch (\Throwable $exception) {
            return $this->checkResult($started, 'Service unavailable');
        }
    }

    private function checkResult(int $started, ?string $error = null): array
    {
        $result = [
            'status' => $error === null ? 'up' : 'down',
            'responseTime' => (int) round((hrtime(true) - $started) / 1000000),
        ];
        if ($error !== null) {
            $result['error'] = $error;
        }

        return $result;
    }

    private function readBuildMetadata(string $filename): ?string
    {
        $appPath = Yii::getAlias('@app');
        foreach ([$appPath, dirname($appPath)] as $directory) {
            $path = $directory . '/' . $filename;
            if (!is_file($path) || !is_readable($path)) {
                continue;
            }
            $value = @file_get_contents($path);
            if ($value !== false && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
