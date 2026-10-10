<?php

use Codeception\Module\Yii2;
use app\components\HealthService;
use yii\db\Connection;

class HealthCest
{
    private Yii2 $yii;

    public function _inject(Yii2 $yii)
    {
        $this->yii = $yii;
    }

    public function _before(\FunctionalTester $I)
    {
        $runtime = $this->loadRuntimeConfig();
        \Yii::$app->set('urlManager', $runtime['components']['urlManager']);
        $I->stopFollowingRedirects();
        $I->deleteHeader('Authorization');
        $I->assertTrue(\Yii::$app->user->isGuest);
        $I->assertTrue($runtime['components']['urlManager']['enablePrettyUrl']);
        $I->assertTrue($runtime['components']['urlManager']['enableStrictParsing']);
    }

    public function anonymousGetReturnsHealthyJson(\FunctionalTester $I)
    {
        $I->amOnPage('/health');

        $I->seeResponseCodeIs(200);
        $I->assertSame('application/json; charset=UTF-8', \Yii::$app->response->headers->get('Content-Type'));
        $result = json_decode($I->grabPageSource(), true, 512, JSON_THROW_ON_ERROR);
        $I->assertSame('healthy', $result['status']);
        $I->assertSame('up', $result['checks']['database']['status']);
        $I->assertIsInt($result['checks']['database']['responseTime']);
        $I->assertNotFalse(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $result['timestamp']));
        $I->assertTrue(\Yii::$app->user->isGuest);
    }

    public function anonymousHeadReturnsHealthyStatusWithAnEmptyBody(\FunctionalTester $I)
    {
        $body = $this->yii->_request('HEAD', '/health');

        $I->seeResponseCodeIs(200);
        $I->assertSame('', $body);
        $I->assertTrue(\Yii::$app->user->isGuest);
    }

    public function databaseFailureReturnsUnavailableJson(\FunctionalTester $I)
    {
        $privatePath = $this->useUnavailableDatabase();

        $I->amOnPage('/health');

        $I->seeResponseCodeIs(503);
        $I->assertSame('application/json; charset=UTF-8', \Yii::$app->response->headers->get('Content-Type'));
        $result = json_decode($I->grabPageSource(), true, 512, JSON_THROW_ON_ERROR);
        $I->assertSame('unhealthy', $result['status']);
        $I->assertSame('down', $result['checks']['database']['status']);
        $I->assertSame('Service unavailable', $result['checks']['database']['error']);
        $I->assertStringNotContainsString($privatePath, $I->grabPageSource());
        $I->assertStringNotContainsString('SQLSTATE', $I->grabPageSource());
        $I->assertTrue(\Yii::$app->user->isGuest);
    }

    public function databaseFailureHeadReturnsUnavailableWithAnEmptyBody(\FunctionalTester $I)
    {
        $this->useUnavailableDatabase();

        $body = $this->yii->_request('HEAD', '/health');

        $I->seeResponseCodeIs(503);
        $I->assertSame('', $body);
        $I->assertTrue(\Yii::$app->user->isGuest);
    }

    public function redisFailureDoesNotPreventTheHealthRouteFromReturningSafeJson(\FunctionalTester $I)
    {
        $privateSocket = sys_get_temp_dir() . '/game-health-missing-' . bin2hex(random_bytes(8)) . '/private-redis.sock';
        $redis = new \yii\redis\Connection([
            'unixSocket' => $privateSocket,
            'connectionTimeout' => 0.1,
            'dataTimeout' => 0.1,
        ]);
        $runtime = $this->loadRuntimeConfig();
        \Yii::$app->set('redis', $redis);
        \Yii::$app->set('cache', new \yii\redis\Cache(['redis' => $redis]));
        \Yii::$app->set('healthService', $runtime['components']['healthService']);
        // Recreate the real URL manager after configuring the failed production cache.
        \Yii::$app->set('urlManager', $runtime['components']['urlManager']);

        $I->amOnPage('/health');

        $I->seeResponseCodeIs(503);
        $I->assertSame('application/json; charset=UTF-8', \Yii::$app->response->headers->get('Content-Type'));
        $body = $I->grabPageSource();
        $result = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $I->assertSame('unhealthy', $result['status']);
        $I->assertSame('up', $result['checks']['database']['status']);
        $I->assertSame('down', $result['checks']['redis']['status']);
        $I->assertSame('Service unavailable', $result['checks']['redis']['error']);
        $I->assertStringNotContainsString($privateSocket, $body);
        $I->assertStringNotContainsString('127.0.0.1', $body);
        $I->assertTrue(\Yii::$app->user->isGuest);
    }

    public function unsupportedPostCannotReturnSuccess(\FunctionalTester $I)
    {
        $this->yii->_request('POST', '/health');

        $I->seeResponseCodeIsClientError();
    }

    private function useUnavailableDatabase(): string
    {
        $privatePath = sys_get_temp_dir() . '/game-health-missing-' . bin2hex(random_bytes(8)) . '/private-database.sqlite';
        \Yii::$app->set('healthService', new HealthService([
            'db' => new Connection(['dsn' => 'sqlite:' . $privatePath]),
        ]));

        return $privatePath;
    }

    private function loadRuntimeConfig(): array
    {
        // Evaluate the actual strict URL rules with isolated, harmless config dependencies.
        $directory = sys_get_temp_dir() . '/game-health-routes-' . bin2hex(random_bytes(8));
        if (!mkdir($directory, 0700)) {
            throw new \RuntimeException('Unable to create isolated route config directory');
        }
        try {
            copy(dirname(__DIR__, 3) . '/files/api/config/web.php', $directory . '/web.php');
            file_put_contents($directory . '/params.php', '<?php return [];');
            file_put_contents($directory . '/db.php', '<?php return [];');

            return require $directory . '/web.php';
        } finally {
            foreach (['web.php', 'params.php', 'db.php'] as $file) {
                @unlink($directory . '/' . $file);
            }
            @rmdir($directory);
        }
    }
}
