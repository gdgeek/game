<?php
namespace tests\unit\components;
use PHPUnit\Framework\TestCase;

class AdminAuthConfigTest extends TestCase
{
    private $environment = [];
    private $server;
    protected function setUp(): void {
        $this->server = $_SERVER;
        foreach (['ADMIN_AUTH_REDIS_HOST','ADMIN_AUTH_REDIS_PORT','ADMIN_AUTH_REDIS_DB','REDIS_HOST','REDIS_PORT','REDIS_DB','JWT_KEY','JWT_PUB_KEY','ADMIN_JWT_KEY','ADMIN_JWT_PUB_KEY','ADMIN_AUTH_REDIS_PASSWORD','REDIS_PASSWORD'] as $key) { $this->environment[$key] = getenv($key); }
        putenv('ADMIN_AUTH_REDIS_HOST=auth-redis.example'); putenv('ADMIN_AUTH_REDIS_PORT=6381'); putenv('ADMIN_AUTH_REDIS_DB=15');
        putenv('ADMIN_JWT_KEY=/fixture/jwt-private.pem'); putenv('ADMIN_JWT_PUB_KEY=/fixture/jwt-public.pem'); putenv('JWT_KEY=/fixture/legacy-game-key.pem');
        putenv('REDIS_HOST=business-redis.example'); putenv('REDIS_PORT=6379'); putenv('REDIS_DB=2');
    }
    protected function tearDown(): void { $_SERVER = $this->server; foreach ($this->environment as $key => $value) { putenv($value === false ? $key : $key . '=' . $value); } }
    public function testAdminRedisIsIndependentOfBusinessRedisAndAdminDisablesYiiCookieIdentity(): void {
        $_SERVER['REQUEST_URI'] = '/admin-api/session';
        $config = require dirname(__DIR__, 4) . '/files/api/config/web.php';
        $this->assertSame('auth-redis.example', $config['components']['adminAuthRedis']['hostname']);
        $this->assertSame(6381, $config['components']['adminAuthRedis']['port']);
        $this->assertSame(15, $config['components']['adminAuthRedis']['database']);
        $this->assertSame(0, $config['components']['adminAuthRedis']['retries']);
        $this->assertSame('yii\\redis\\Connection', $config['components']['adminAuthRedis']['class']);
        $this->assertSame(\bizley\jwt\Jwt::ES256, $config['components']['adminJwt']['signer']);
        $this->assertSame('/fixture/jwt-private.pem', $config['components']['adminJwt']['signingKey']['key']);
        $this->assertSame('/fixture/jwt-public.pem', $config['components']['adminJwt']['verifyingKey']['key']);
        $this->assertSame(\bizley\jwt\Jwt::HS256, $config['components']['jwt']['signer']);
        $this->assertSame('business-redis.example', $config['components']['redis']['hostname']);
        $this->assertSame('2', $config['components']['cache']['redis']['database']);
        $this->assertFalse($config['components']['user']['enableSession']);
        $this->assertFalse($config['components']['user']['enableAutoLogin']);
        $this->assertSame('admin/refresh', $config['components']['urlManager']['rules']['POST admin-api/refresh']);
    }
    public function testAdminRedisFallsBackToMainDeploymentAndDefaultPublicKeyPath(): void {
        foreach (['ADMIN_AUTH_REDIS_HOST','ADMIN_AUTH_REDIS_PORT','ADMIN_AUTH_REDIS_DB','ADMIN_AUTH_REDIS_PASSWORD','ADMIN_JWT_KEY','ADMIN_JWT_PUB_KEY'] as $key) { putenv($key); }
        putenv('REDIS_DB=0'); putenv('REDIS_PASSWORD=test-fallback-password');
        $config = require dirname(__DIR__, 4) . '/files/api/config/web.php';
        $this->assertSame('business-redis.example', $config['components']['adminAuthRedis']['hostname']);
        $this->assertSame(0, $config['components']['adminAuthRedis']['database']);
        $this->assertSame('test-fallback-password', $config['components']['adminAuthRedis']['password']);
        $this->assertSame('/var/www/.ssh/jwt-key.pem', $config['components']['adminJwt']['signingKey']['key']);
        $this->assertSame('/fixture/legacy-game-key.pem', $config['components']['jwt']['signingKey']['key']);
        $this->assertSame('/var/www/.ssh/jwt-key-pub.pem', $config['components']['adminJwt']['verifyingKey']['key']);
    }
    public function testV2KeepsItsExistingUserAndBusinessRedisConfiguration(): void {
        $_SERVER['REQUEST_URI'] = '/v2/user';
        $config = require dirname(__DIR__, 4) . '/files/api/config/web.php';
        $this->assertSame('app\modules\v2\models\User', $config['components']['user']['identityClass']);
        $this->assertNotSame(false, $config['components']['user']['enableSession'] ?? true);
        $this->assertTrue($config['components']['user']['enableAutoLogin']);
        $this->assertArrayNotHasKey('session', $config['components']);
    }
}
