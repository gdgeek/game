<?php

namespace tests\unit\components;

use app\components\HealthService;
use yii\db\Connection;

class HealthServiceTest extends \Codeception\Test\Unit
{
    public function testHealthyDatabaseUsesARealSelectQuery()
    {
        $db = new Connection(['dsn' => 'sqlite::memory:']);

        try {
            $result = (new HealthService(['db' => $db]))->check();

            $this->assertSame('healthy', $result['status']);
            $this->assertSame('up', $result['checks']['database']['status']);
            $this->assertIsInt($result['checks']['database']['responseTime']);
            $this->assertGreaterThanOrEqual(0, $result['checks']['database']['responseTime']);
            $this->assertTrue($db->isActive);
            $this->assertArrayNotHasKey('redis', $result['checks']);
            $this->assertNotFalse(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $result['timestamp']));
        } finally {
            $db->close();
        }
    }

    public function testDatabaseFailureIsUnhealthyWithoutLeakingConnectionDetails()
    {
        $privatePath = sys_get_temp_dir() . '/game-health-missing-' . bin2hex(random_bytes(8)) . '/private-database.sqlite';
        $db = new Connection(['dsn' => 'sqlite:' . $privatePath]);

        try {
            $result = (new HealthService(['db' => $db]))->check();

            $this->assertSame('unhealthy', $result['status']);
            $this->assertSame('down', $result['checks']['database']['status']);
            $this->assertSame('Service unavailable', $result['checks']['database']['error']);
            $this->assertIsInt($result['checks']['database']['responseTime']);
            $this->assertGreaterThanOrEqual(0, $result['checks']['database']['responseTime']);
            $this->assertStringNotContainsString($privatePath, json_encode($result));
            $this->assertStringNotContainsString('SQLSTATE', json_encode($result));
        } finally {
            $db->close();
        }
    }

    public function testConfiguredRedisPongIsHealthy()
    {
        $redis = new HealthRedisProbe('PONG');
        \Yii::$app->set('redis', $redis);

        $result = $this->checkWithRedis(null);

        $this->assertSame('healthy', $result['status']);
        $this->assertSame('up', $result['checks']['redis']['status']);
        $this->assertSame([['PING', []]], $redis->commands);
        $this->assertIsInt($result['checks']['redis']['responseTime']);
        $this->assertGreaterThanOrEqual(0, $result['checks']['redis']['responseTime']);
    }

    public function testRedisBooleanTrueIsHealthy()
    {
        $result = $this->checkWithRedis(new HealthRedisProbe(true));

        $this->assertSame('healthy', $result['status']);
        $this->assertSame('up', $result['checks']['redis']['status']);
    }

    public function testUnexpectedRedisPingResponseIsUnhealthy()
    {
        $result = $this->checkWithRedis(new HealthRedisProbe('private-redis-response'));

        $this->assertSame('unhealthy', $result['status']);
        $this->assertSame('up', $result['checks']['database']['status']);
        $this->assertSame('down', $result['checks']['redis']['status']);
        $this->assertSame('Unexpected PING response', $result['checks']['redis']['error']);
        $this->assertStringNotContainsString('private-redis-response', json_encode($result));
    }

    public function testRedisFailureIsUnhealthyWithoutLeakingExceptionDetails()
    {
        $privateMessage = 'redis password=secret at private-cache.internal';
        $result = $this->checkWithRedis(new HealthRedisProbe(new \RuntimeException($privateMessage)));

        $this->assertSame('unhealthy', $result['status']);
        $this->assertSame('down', $result['checks']['redis']['status']);
        $this->assertSame('Service unavailable', $result['checks']['redis']['error']);
        $this->assertIsInt($result['checks']['redis']['responseTime']);
        $this->assertStringNotContainsString($privateMessage, json_encode($result));
    }

    public function testBuildMetadataIsOptional()
    {
        $this->withBuildDirectory(function ($directory, $service) {
            $result = $service->check();

            $this->assertArrayNotHasKey('gitCommit', $result);
            $this->assertArrayNotHasKey('version', $result);
        });
    }

    public function testBuildMetadataIsReadAndTrimmedFromTheApplicationDirectory()
    {
        $this->withBuildDirectory(function ($directory, $service) {
            file_put_contents($directory . '/app/GIT_COMMIT', "abc123def456\n");
            file_put_contents($directory . '/app/BUILD_TIME', "2026-10-11T12:34:56Z\n");

            $result = $service->check();

            $this->assertSame('abc123def456', $result['gitCommit']);
            $this->assertSame('2026-10-11T12:34:56Z', $result['version']);
        });
    }

    public function testBuildMetadataCanBeReadFromTheParentDirectory()
    {
        $this->withBuildDirectory(function ($directory, $service) {
            file_put_contents($directory . '/GIT_COMMIT', "parent-commit\n");
            file_put_contents($directory . '/BUILD_TIME', "parent-build-time\n");

            $result = $service->check();

            $this->assertSame('parent-commit', $result['gitCommit']);
            $this->assertSame('parent-build-time', $result['version']);
        });
    }

    public function testEmptyBuildMetadataIsOmitted()
    {
        $this->withBuildDirectory(function ($directory, $service) {
            file_put_contents($directory . '/app/GIT_COMMIT', " \n");
            file_put_contents($directory . '/app/BUILD_TIME', "\n");

            $result = $service->check();

            $this->assertArrayNotHasKey('gitCommit', $result);
            $this->assertArrayNotHasKey('version', $result);
        });
    }

    private function checkWithRedis($redis): array
    {
        $db = new Connection(['dsn' => 'sqlite::memory:']);
        try {
            return (new HealthService(['db' => $db, 'redis' => $redis]))->check();
        } finally {
            $db->close();
        }
    }

    private function withBuildDirectory(callable $check): void
    {
        $directory = sys_get_temp_dir() . '/game-health-build-' . bin2hex(random_bytes(8));
        $originalAlias = \Yii::getAlias('@app');
        $db = new Connection(['dsn' => 'sqlite::memory:']);
        // Load the service before moving @app so application class autoloading stays real.
        $service = new HealthService(['db' => $db]);
        if (!mkdir($directory . '/app', 0700, true)) {
            throw new \RuntimeException('Unable to create isolated build directory');
        }
        try {
            \Yii::setAlias('@app', $directory . '/app');
            $check($directory, $service);
        } finally {
            \Yii::setAlias('@app', $originalAlias);
            $db->close();
            foreach (['/app/GIT_COMMIT', '/app/BUILD_TIME', '/GIT_COMMIT', '/BUILD_TIME'] as $file) {
                @unlink($directory . $file);
            }
            @rmdir($directory . '/app');
            @rmdir($directory);
        }
    }
}

class HealthRedisProbe
{
    public array $commands = [];
    private $reply;

    public function __construct($reply)
    {
        $this->reply = $reply;
    }

    public function executeCommand($name, $parameters = [])
    {
        $this->commands[] = [$name, $parameters];
        if ($this->reply instanceof \Throwable) {
            throw $this->reply;
        }

        return $this->reply;
    }
}
