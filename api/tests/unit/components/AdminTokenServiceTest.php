<?php
namespace tests\unit\components;

use app\components\AdminTokenService;
use bizley\jwt\Jwt;
use PHPUnit\Framework\TestCase;
use tests\fixtures\InMemoryAdminTokenStore;
use Yii;
use yii\web\Application;
use yii\web\HttpException;
require_once dirname(__DIR__, 2) . '/fixtures/InMemoryAdminTokenStore.php';
if (is_file(dirname(__DIR__, 3) . '/components/AdminTokenService.php')) { require_once dirname(__DIR__, 3) . '/components/AdminTokenService.php'; }

class AdminTokenServiceTest extends TestCase
{
    private $originalApp;
    private $originalAliases;
    private $app;
    private $store;
    private $tokens;
    private $now;
    private $identity = ['source' => 'password', 'username' => 'admin', 'credentialFingerprint' => 'test-fingerprint'];
    protected function setUp(): void {
        $this->assertTrue(class_exists(AdminTokenService::class), 'The administrator JWT service must be implemented.');
        $this->originalApp = Yii::$app; $this->originalAliases = Yii::$aliases; $this->now = time();
        $this->app = new Application(['id' => 'admin-token-test', 'basePath' => dirname(__DIR__, 3), 'components' => [
            'request' => ['cookieValidationKey' => 'jwt-test', 'enableCsrfCookie' => false],
            'jwt' => ['class' => Jwt::class, 'signer' => Jwt::HS256, 'signingKey' => ['key' => str_repeat('test-admin-signing-key-', 3), 'method' => Jwt::METHOD_PLAIN]],
        ]]);
        $this->store = new InMemoryAdminTokenStore();
        $this->tokens = new AdminTokenService(['jwt' => 'jwt', 'store' => $this->store, 'clock' => function () { return $this->now; }]);
    }
    protected function tearDown(): void {
        if ($this->app) { $this->app->errorHandler->unregister(); Yii::$app = $this->originalApp; Yii::$aliases = $this->originalAliases; }
    }
    private function bearer(string $value): void { $this->app->request->headers->set('Authorization', 'Bearer ' . $value); }
    private function unauthorized(callable $call): void {
        try { $call(); $this->fail('Invalid authentication must be rejected.'); } catch (HttpException $e) { $this->assertSame(401, $e->statusCode); $this->assertNull($e->getPrevious()); }
    }
    public function testDefaultSigningComponentIsTheSeparateAdministratorAuthority(): void {
        $this->assertSame('adminJwt', (new AdminTokenService(['store' => $this->store]))->jwt);
    }
    public function testIssuedJwtUsesOwnDomainAndKeepsEightHourAbsoluteRefreshDeadline(): void {
        $token = $this->tokens->issue($this->identity);
        $claims = $this->app->jwt->parse($token['accessToken'])->claims();
        $this->assertSame('game-admin', $claims->get('identityDomain'));
        $this->assertSame(['game-admin'], $claims->get('aud'));
        $this->assertSame('access', $claims->get('tokenUse'));
        $this->assertSame($this->now + 10800, strtotime($token['expires']));
        $this->assertSame($this->now + 28800, strtotime($token['refreshExpires']));
        $this->assertStringNotContainsString($token['refreshToken'], json_encode($this->store->records));
        $this->assertArrayNotHasKey('role', $claims->all());
        $this->assertSame(PHP_SESSION_NONE, session_status());
    }
    public function testAnotherNodeReadsTheSameIdentityWithoutPhpSession(): void {
        $token = $this->tokens->issue($this->identity); $this->bearer($token['accessToken']);
        $second = new AdminTokenService(['jwt' => 'jwt', 'store' => $this->store, 'clock' => function () { return $this->now; }]);
        $this->assertSame($this->identity, $second->identityFromRequest());
    }
    public function testMissingBearerIsAnonymousAndMalformedOrV2BearerIsRejected(): void {
        $this->assertNull($this->tokens->identityFromRequest());
        $this->bearer('not-a-jwt'); $this->unauthorized(function () { $this->tokens->identityFromRequest(); });
        $jwt = $this->app->jwt->getBuilder()->withClaim('identityDomain', 'v2')->withClaim('uid', 42)->getToken($this->app->jwt->getConfiguration()->signer(), $this->app->jwt->getConfiguration()->signingKey());
        $this->bearer($jwt->toString()); $this->unauthorized(function () { $this->tokens->identityFromRequest(); });
    }
    public function testWrongSignatureAndMissingTimeClaimsAreRejected(): void {
        $token = $this->tokens->issue($this->identity); $parts = explode('.', $token['accessToken']); $parts[2] = str_repeat('a', strlen($parts[2]));
        $this->bearer(implode('.', $parts)); $this->unauthorized(function () { $this->tokens->identityFromRequest(); });
        $jwt = $this->app->jwt->getBuilder()->permittedFor('game-admin')->withClaim('identityDomain', 'game-admin')->withClaim('tokenUse', 'access')->withClaim('sid', str_repeat('a', 32))->getToken($this->app->jwt->getConfiguration()->signer(), $this->app->jwt->getConfiguration()->signingKey());
        $this->bearer($jwt->toString()); $this->unauthorized(function () { $this->tokens->identityFromRequest(); });
    }
    public function testRefreshIsOneUseAndNeverExtendsTheInitialEightHours(): void {
        $first = $this->tokens->issue($this->identity); $this->now += 28790;
        $rotated = $this->tokens->refresh($first['refreshToken']);
        $this->assertSame($this->identity, $rotated['identity']);
        $this->assertNotSame($first['refreshToken'], $rotated['token']['refreshToken']);
        $this->assertSame($first['refreshExpires'], $rotated['token']['refreshExpires']);
        $this->assertSame($first['refreshExpires'], $rotated['token']['expires']);
        $this->unauthorized(function () use ($first) { $this->tokens->refresh($first['refreshToken']); });
        $this->now += 10; $this->unauthorized(function () use ($rotated) { $this->tokens->refresh($rotated['token']['refreshToken']); });
    }
    public function testLogoutImmediatelyRevokesEveryAccessTokenInTheFamily(): void {
        $first = $this->tokens->issue($this->identity); $next = $this->tokens->refresh($first['refreshToken']);
        $this->bearer($next['token']['accessToken']); $this->tokens->revoke($next['token']['refreshToken']);
        foreach ([$first['accessToken'], $next['token']['accessToken']] as $access) { $this->bearer($access); $this->unauthorized(function () { $this->tokens->identityFromRequest(); }); }
        $this->unauthorized(function () use ($next) { $this->tokens->refresh($next['token']['refreshToken']); });
    }
    public function testCurrentIdentityValidatorRunsBeforeRefreshRotation(): void {
        $first = $this->tokens->issue($this->identity); $records = $this->store->records;
        $this->unauthorized(function () use ($first) { $this->tokens->refresh($first['refreshToken'], static function () { return false; }); });
        $this->assertSame($records, $this->store->records);
    }
    public function testExpiredAccessAndMissingActiveFamilyAreRejected(): void {
        $token = $this->tokens->issue($this->identity); $this->bearer($token['accessToken']); $this->now += 10800;
        $this->unauthorized(function () { $this->tokens->identityFromRequest(); });
        $this->now -= 10800; $this->store->records = [];
        $this->unauthorized(function () { $this->tokens->identityFromRequest(); });
    }
    public function testStorageFailureIs503WithoutCredentialOrConnectionDetails(): void {
        $token = $this->tokens->issue($this->identity); $this->bearer($token['accessToken']); $this->store->unavailable = true;
        try { $this->tokens->identityFromRequest(); $this->fail('Storage outage must fail closed.'); } catch (HttpException $e) {
            $this->assertSame(503, $e->statusCode); $this->assertNull($e->getPrevious()); $this->assertStringNotContainsString($token['accessToken'], $e->getMessage());
        }
    }
    public function testIssuerIsBoundInJwtAndRefreshRecordsAcrossEnvironments(): void {
        $this->tokens->issuer = 'https://admin-a.example';
        $token = $this->tokens->issue($this->identity);
        $this->assertSame('https://admin-a.example', $this->app->jwt->parse($token['accessToken'])->claims()->get('iss'));
        $other = new AdminTokenService(['jwt' => 'jwt', 'store' => $this->store, 'issuer' => 'https://admin-b.example']);
        $this->bearer($token['accessToken']);
        $this->unauthorized(function () use ($other) { $other->identityFromRequest(); });
        $this->unauthorized(function () use ($other, $token) { $other->refresh($token['refreshToken']); });
    }
    public function testUnreadableSigningConfigurationIsServiceUnavailableNotInvalidCredentials(): void {
        $token = $this->tokens->issue($this->identity); $this->bearer($token['accessToken']);
        $this->app->set('unreadableJwt', ['class' => Jwt::class, 'signer' => Jwt::HS256, 'signingKey' => ['key' => '/private/tmp/game-test-missing-key-file', 'method' => Jwt::METHOD_FILE]]);
        $this->tokens = new AdminTokenService(['store' => $this->store, 'jwt' => 'unreadableJwt']);
        try { $this->tokens->identityFromRequest(); $this->fail('Key configuration must fail safely.'); } catch (HttpException $e) {
            $this->assertSame(503, $e->statusCode); $this->assertStringNotContainsString('game-test-missing-key-file', $e->getMessage()); $this->assertNull($e->getPrevious());
        }
    }

    public function testStaleRefreshAloneCannotReportSuccessfulLogoutOfARotatedFamily(): void {
        $first = $this->tokens->issue($this->identity); $next = $this->tokens->refresh($first['refreshToken']);
        $this->unauthorized(function () use ($first) { $this->tokens->revoke($first['refreshToken']); });
        $this->bearer($next['token']['accessToken']); $this->assertSame($this->identity, $this->tokens->identityFromRequest());
    }

}
