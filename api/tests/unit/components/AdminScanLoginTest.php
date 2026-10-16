<?php

namespace tests\unit\components;

use app\components\AdminAuthService;
use app\components\AdminScanLoginService;
use app\components\AdminDeviceService;
use app\components\AdminGameCatalog;
use app\components\AdminTokenService;
use app\controllers\AdminController;
use app\modules\v2\controllers\SiteController;
use app\modules\v2\controllers\WebLoginController;
use app\modules\v2\models\User;
use bizley\jwt\Jwt;
use Lcobucci\Clock\SystemClock;
use Lcobucci\JWT\Validation\Constraint\LooseValidAt;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\caching\ArrayCache;
use yii\web\Application;
use yii\web\HttpException;

require_once dirname(__DIR__, 2) . '/fixtures/InMemoryAdminTokenStore.php';

class AdminScanLoginTest extends TestCase
{
    private $app;
    private $auth;
    private $scan;
    private $runtime;
    private $now;
    private $original;
    private $devices;
    private $catalog;
    private $ownerProof;
    private $tokenStore;
    private $originalScanOrigin;

    protected function setUp(): void
    {
        $this->original = [Yii::$app, Yii::$aliases, $_SERVER, ini_get_all('session', false), session_id(), array_key_exists('_SESSION', $GLOBALS), $_SESSION ?? [], session_status() === PHP_SESSION_ACTIVE];
        if ($this->original[7]) { session_write_close(); }
        session_id('');
        $this->runtime = sys_get_temp_dir() . '/game-scan-test-' . bin2hex(random_bytes(6));
        mkdir($this->runtime, 0700, true);
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        $this->app = new Application([
            'id' => 'scan-test', 'basePath' => dirname(__DIR__, 3), 'runtimePath' => $this->runtime,
            'components' => [
                'request' => ['hostInfo' => 'https://b0.example.com', 'cookieValidationKey' => 'scan-test-key', 'enableCsrfCookie' => false, 'scriptFile' => dirname(__DIR__, 3) . '/web/index.php', 'scriptUrl' => '/index.php'],
                'session' => ['class' => 'yii\web\Session', 'savePath' => $this->runtime, 'useCookies' => false],
                'db' => ['class' => 'yii\db\Connection', 'dsn' => 'sqlite::memory:'],
                'cache' => ['class' => ArrayCache::class],
                'user' => ['identityClass' => User::class, 'enableAutoLogin' => false, 'enableSession' => false],
                'jwt' => [
                    'class' => Jwt::class, 'signer' => Jwt::HS256,
                    'signingKey' => ['key' => str_repeat('scan-test-signing-key', 3), 'method' => Jwt::METHOD_PLAIN],
                    'validationConstraints' => static function (Jwt $jwt) {
                        $configuration = $jwt->getConfiguration();
                        return [new SignedWith($configuration->signer(), $configuration->verificationKey()), new LooseValidAt(new SystemClock(new \DateTimeZone('UTC')))];
                    },
                ],
            ],
        ]);
        $this->app->db->open();
        $this->app->db->pdo->sqliteCreateFunction('NOW', static function () { return '2026-10-11 15:00:00'; });
        $this->app->db->createCommand('CREATE TABLE user (id INTEGER PRIMARY KEY AUTOINCREMENT, openid TEXT, unionid TEXT, tel TEXT, nickname TEXT, avatar TEXT, role TEXT, created_at TEXT, updated_at TEXT)')->execute();
        $this->app->db->createCommand('CREATE TABLE control (id INTEGER PRIMARY KEY, user_id INTEGER, device_id INTEGER)')->execute();
        $this->app->db->schema->typeMap['json'] = 'json';
        $this->app->db->schema->columnSchemaClass = 'yii\\db\\mysql\\ColumnSchema';
        $this->app->db->queryBuilder->setExpressionBuilders(['yii\\db\\JsonExpression' => 'yii\\db\\mysql\\JsonExpressionBuilder']);
        $this->app->db->createCommand('CREATE TABLE device (id INTEGER PRIMARY KEY, uuid TEXT, tag TEXT, ip TEXT, created_at TEXT, updated_at TEXT)')->execute();
        $this->app->db->createCommand('CREATE TABLE setup (id INTEGER PRIMARY KEY AUTOINCREMENT, device_id INTEGER, scene_id INTEGER, title TEXT, money REAL DEFAULT 0, slogans JSON, pictures JSON, thumbs JSON, shots JSON, updated_at TEXT)')->execute();
        $this->app->db->createCommand()->batchInsert('device', ['id', 'uuid', 'tag'], [[1, 'owned-device', '关联设备'], [2, 'other-device', '他人设备']])->execute();
        $this->app->db->createCommand()->batchInsert('control', ['id', 'user_id', 'device_id'], [[1, 42, 1], [2, 43, 2]])->execute();
        $this->app->db->createCommand('CREATE TABLE player (id INTEGER PRIMARY KEY, tel TEXT, nickname TEXT, openId TEXT, avatar TEXT, created_at TEXT, updated_at TEXT)')->execute();
        $this->app->db->createCommand()->insert('player', ['id' => 42, 'tel' => 'test-player'])->execute();
        $this->app->db->createCommand()->batchInsert('user', ['id', 'openid', 'unionid', 'tel', 'role'], [[42, 'existing-openid', 'existing-unionid', null, 'user'], [43, 'root-openid', 'root-unionid', '15000159790', 'root']])->execute();
        require_once dirname(__DIR__, 3) . '/migrations/m261011_120000_create_admin_scan_login_ticket_table.php';
        $migration = new \m261011_120000_create_admin_scan_login_ticket_table(['db' => $this->app->db, 'compact' => true]);
        $migration->safeUp();
        $this->now = time();
        $this->originalScanOrigin = getenv('ADMIN_SCAN_LOGIN_ORIGIN');
        putenv('ADMIN_SCAN_LOGIN_ORIGIN=https://b0.example.com');
        $this->tokenStore = new ScanOwnerLockTestStore();
        $this->ownerProof = bin2hex(random_bytes(32));
        $this->app->request->headers->set('X-Admin-Scan-Owner', $this->ownerProof);
        $this->auth = new AdminAuthService(['username' => 'test-admin', 'passwordHash' => password_hash('test-password', PASSWORD_DEFAULT), 'cache' => new ArrayCache(), 'tokens' => new AdminTokenService(['store' => $this->tokenStore, 'jwt' => 'jwt'])]);
        $this->scan = new AdminScanLoginService(['auth' => $this->auth, 'origin' => 'https://b0.example.com', 'clock' => function () { return $this->now; }]);
        $this->catalog = new AdminGameCatalog(['cache' => new ArrayCache(), 'loader' => static function () { return [['verse_id' => 700, 'name' => '游戏']]; }]);
        $this->devices = new AdminDeviceService(['catalog' => $this->catalog, 'auth' => $this->auth]);
    }

    protected function tearDown(): void
    {
        if ($this->app) {
            $this->app->session->destroy();
            $this->app->session->close();
            $this->app->db->close();
            $this->app->errorHandler->unregister();
        }
        [Yii::$app, Yii::$aliases, $_SERVER] = array_slice($this->original, 0, 3);
        if ($this->originalScanOrigin === false) { putenv('ADMIN_SCAN_LOGIN_ORIGIN'); }
        else { putenv('ADMIN_SCAN_LOGIN_ORIGIN=' . $this->originalScanOrigin); }
        foreach ($this->original[3] as $name => $value) { if (ini_get($name) !== $value) { ini_set($name, $value); } }
        session_id($this->original[4]);
        if ($this->original[7]) { session_start(); }
        if ($this->original[5]) { $_SESSION = $this->original[6]; } else { unset($_SESSION); }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->runtime, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
        rmdir($this->runtime);
    }

    public function testFullFlowHashesSecretsAndOnlyConsumeIssuesJwt(): void
    {
        $ticket = $this->scan->createTicket();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $ticket['ticketId']);
        $this->assertSame(gmdate('c', $this->now + 300), $ticket['expiresAt']);
        $this->assertStringStartsWith('bujiaban-admin-login://v1/' . $ticket['ticketId'] . '?', $ticket['qrText']);
        $query = $this->query($ticket);
        $this->assertSame('https://b0.example.com', $query['origin']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $query['secret']);
        $row = $this->row($ticket);
        $this->assertSame(hash('sha256', $query['secret']), $row['secret_hash']);
        $this->assertStringNotContainsString($query['secret'], json_encode($row));
        $this->assertSame('pending', $this->scan->status($ticket['ticketId'])['status']);
        $this->scan->approve($ticket['ticketId'], $query['secret'], 42);
        $this->assertSame('approved', $this->scan->status($ticket['ticketId'])['status']);
        $this->assertNull($this->auth->getUser());
        $login = $this->scan->consume($ticket['ticketId']);
        $this->assertSame(['username' => 'wechat:42', 'role' => 'device_manager'], $login['user']);
        $this->assertSame('jwt', $login['authMode']);
        $this->assertArrayHasKey('accessToken', $login['token']);
        $this->authenticate($login);
        $this->assertSame($login['user'], $this->auth->getUser());
        $this->assertSame('consumed', $this->row($ticket)['status']);
        $this->assertHttp(409, function () use ($ticket) { $this->scan->consume($ticket['ticketId']); });
    }

    public function testOwnerProofIsRequiredAndHashedInsteadOfUsingSessionState(): void
    {
        $this->app->request->headers->remove('X-Admin-Scan-Owner');
        $this->assertHttp(400, function () { $this->scan->createTicket(); });
        $this->app->request->headers->set('X-Admin-Scan-Owner', 'not-a-proof');
        $this->assertHttp(400, function () { $this->scan->createTicket(); });
        $this->app->request->headers->set('X-Admin-Scan-Owner', $this->ownerProof);

        $ticket = $this->scan->createTicket();
        $this->assertSame(1, $this->tokenStore->ownerLockCalls);
        $this->assertSame(hash('sha256', $this->ownerProof), $this->row($ticket)['owner_hash']);
        $this->assertStringNotContainsString($this->ownerProof, $ticket['qrText']);
        $this->assertStringNotContainsString($this->ownerProof, json_encode($this->row($ticket)));

        $this->app->request->headers->set('X-Admin-Scan-Owner', bin2hex(random_bytes(32)));
        $this->assertHttp(404, function () use ($ticket) { $this->scan->status($ticket['ticketId']); });
    }

    public function testOwnerProofSharesCurrentTicketAcrossServiceInstancesAndNoProofLoginIsNoop(): void
    {
        $ticket = $this->scan->createTicket();
        $secondNode = new AdminScanLoginService(['auth' => $this->auth, 'origin' => 'https://b0.example.com']);
        $this->assertSame('pending', $secondNode->status($ticket['ticketId'])['status']);

        $this->app->request->headers->remove('X-Admin-Scan-Owner');
        $this->auth->login('test-admin', 'test-password');
        $this->assertSame('pending', $this->row($ticket)['status']);

        $this->app->request->headers->set('X-Admin-Scan-Owner', $this->ownerProof);
        $replacement = $secondNode->createTicket();
        $this->assertSame('cancelled', $this->row($ticket)['status']);
        $this->assertSame('pending', $secondNode->status($replacement['ticketId'])['status']);
        $this->auth->login('test-admin', 'test-password');
        $this->assertSame('cancelled', $this->row($replacement)['status']);
        $this->assertSame(3, $this->tokenStore->ownerLockCalls);
    }

    public function testOtherBrowserCannotReadConsumeOrCancel(): void
    {
        $ticket = $this->scan->createTicket();
        $this->scan->approve($ticket['ticketId'], $this->query($ticket)['secret'], 42);
        $this->app->request->headers->set('X-Admin-Scan-Owner', bin2hex(random_bytes(32)));
        foreach (['status', 'consume', 'cancel'] as $method) {
            $this->assertHttp(404, function () use ($ticket, $method) { $this->scan->$method($ticket['ticketId']); });
        }
        $this->app->request->headers->set('X-Admin-Scan-Owner', $this->ownerProof);
        $this->assertSame('approved', $this->scan->status($ticket['ticketId'])['status']);
    }

    public function testWechatJwtIsAlwaysDeviceManagerAndOnlyOwnsLinkedDevices(): void
    {
        $login = $this->auth->loginWechat(42);
        $this->authenticate($login);
        $this->assertSame(['username' => 'wechat:42', 'role' => 'device_manager'], $this->auth->getUser());
        $this->assertSame([1], array_column($this->devices->getDevices(), 'id'));
        $this->assertSame(700, $this->devices->assignGame(1, 700)['gameId']);
        $this->assertHttp(404, function () { $this->devices->assignGame(2, 700); });
        $this->assertSame(1, (int) $this->app->db->createCommand('SELECT COUNT(*) FROM setup')->queryScalar());
        $sid = $this->app->jwt->parse($login['token']['accessToken'])->claims()->get('sid');
        $this->tokenStore->records[$sid]['identity']['role'] = 'admin';
        $this->assertSame('device_manager', $this->auth->getUser()['role']);
        $this->assertSame([1], array_column($this->devices->getDevices(), 'id'));
    }

    public function testControlRevocationImmediatelyRemovesReadAndWriteAccess(): void
    {
        $this->authenticate($this->auth->loginWechat(42));
        $this->assertSame([1], array_column($this->devices->getDevices(), 'id'));
        $this->app->db->createCommand()->delete('control', ['user_id' => 42])->execute();
        $this->assertSame([], $this->devices->getDevices());
        $this->assertHttp(404, function () { $this->devices->assignGame(1, 700); });
        $this->app->db->createCommand()->delete('user', ['id' => 42])->execute();
        $this->assertHttp(401, function () { $this->devices->getDevices(); });
    }

    public function testWechatRootCannotGainAdminAndPasswordRemainsAllDevices(): void
    {
        $this->authenticate($this->auth->loginWechat(43));
        $this->assertSame('device_manager', $this->auth->getUser()['role']);
        $this->assertSame([2], array_column($this->devices->getDevices(), 'id'));
        $this->assertHttp(404, function () { $this->devices->assignGame(1, 700); });
        $adminLogin = $this->auth->login('test-admin', 'test-password');
        $this->authenticate($adminLogin);
        $this->assertSame(['username' => 'test-admin', 'role' => 'admin'], $this->auth->getUser());
        $this->assertSame([1, 2], array_column($this->devices->getDevices(), 'id'));
        $this->devices->assignGame(2, 700);
        $adminSid = $this->app->jwt->parse($adminLogin['token']['accessToken'])->claims()->get('sid');
        $this->tokenStore->records[$adminSid]['identity']['role'] = 'device_manager';
        $this->assertSame('admin', $this->auth->getUser()['role']);
    }

    public function testHttpBodyCannotElevateWechatDeviceScopeOrChangeOwner(): void
    {
        $this->authenticate($this->auth->loginWechat(42));
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        $this->app->request->headers->set('X-CSRF-Token', $this->app->request->getCsrfToken());
        $this->app->request->setBodyParams(['gameId' => 700, 'role' => 'admin', 'source' => 'password', 'wechatUserId' => 43, 'userId' => 43]);
        $this->assertHttp(404, function () { $this->admin()->runAction('set-game', ['id' => 2]); });
        $this->assertSame(700, $this->admin()->runAction('set-game', ['id' => 1])['gameId']);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->assertSame([1], array_column($this->admin()->runAction('devices'), 'id'));
        $this->assertSame('device_manager', $this->admin()->runAction('session')['user']['role']);
    }

    public function testAnyExistingWechatUserCanScanAndUnlinkedUsersSeeNoDevices(): void
    {
        $this->app->db->createCommand()->insert('user', ['id' => 44, 'openid' => 'unlinked-openid', 'unionid' => 'unlinked-unionid', 'role' => 'user'])->execute();
        $ticket = $this->scan->createTicket();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->app->request->setBodyParams(['secret' => $this->query($ticket)['secret'], 'role' => 'admin', 'userId' => 43]);
        $this->app->request->headers->set('Authorization', 'Bearer ' . $this->token(44));
        $this->assertTrue($this->webLogin()->runAction('approve', ['id' => $ticket['ticketId']])['success']);
        $this->assertSame(44, (int) $this->row($ticket)['approved_user_id']);
        $login = $this->scan->consume($ticket['ticketId']);
        $this->assertSame(['username' => 'wechat:44', 'role' => 'device_manager'], $login['user']);
        $this->authenticate($login);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->assertSame([], $this->admin()->runAction('devices'));
        $this->assertHttp(404, function () { $this->devices->assignGame(1, 700); });
    }

    public function testDeviceServiceRequiresBearerAndRejectsMalformedIdentitySource(): void
    {
        $this->assertHttp(401, function () { $this->devices->getDevices(); });
        $this->assertHttp(401, function () { $this->devices->assignGame(1, 700); });
        $this->app->request->headers->set('Authorization', 'Bearer malformed');
        $this->assertHttp(401, function () { $this->devices->getDevices(); });
    }

    public function testV2DeviceRoutesScopeIndexViewUpdateAndManageForEveryWechatRole(): void
    {
        foreach ([42 => 1, 43 => 2] as $userId => $ownedId) {
            $otherId = $ownedId === 1 ? 2 : 1;
            $this->assertSame([$ownedId], array_column($this->v2Route('GET', 'v2/devices', [], $userId), 'id'));
            $this->assertSame([$ownedId], array_column($this->v2Route('GET', 'v2/devices/manage', [], $userId), 'id'));
            $this->assertSame($ownedId, (int) $this->v2Route('GET', 'v2/devices/' . $ownedId, [], $userId)['id']);
            $this->assertHttp(404, function () use ($otherId, $userId) { $this->v2Route('GET', 'v2/devices/' . $otherId, [], $userId); });
            $this->assertHttp(404, function () use ($otherId, $userId) { $this->v2Route('PUT', 'v2/devices/' . $otherId, ['tag' => '攻击修改'], $userId); });
            $this->assertSame('合法修改-' . $userId, $this->v2Route('PUT', 'v2/devices/' . $ownedId, ['tag' => '合法修改-' . $userId], $userId)['tag']);
        }
    }

    public function testV2BearerCannotAssignUnassignCreateDeleteOrUseRootAssignment(): void
    {
        $this->app->db->createCommand()->update('user', ['tel' => 'test-phone-42'], ['id' => 42])->execute();
        foreach ([42, 43] as $userId) {
            $this->assertHttp(403, function () use ($userId) { $this->v2Route('POST', 'v2/devices/2/assign', ['phone' => 'test-phone-42'], $userId); });
            $this->assertHttp(403, function () use ($userId) { $this->v2Route('DELETE', 'v2/devices/1/assign/42', [], $userId); });
            $this->assertHttp(403, function () use ($userId) { $this->v2Route('POST', 'v2/devices', ['uuid' => 'malicious-new-device'], $userId); });
            $this->assertHttp(403, function () use ($userId) { $this->v2Route('DELETE', 'v2/devices/' . ($userId === 42 ? 1 : 2), [], $userId); });
            $this->app->request->setBodyParams(['phone' => 'test-phone-42', 'device_id' => 2]);
            $this->app->request->headers->set('Authorization', 'Bearer ' . $this->token($userId));
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $module = new \app\modules\v2\Module('v2', $this->app);
            $this->assertHttp(403, function () use ($module) { (new \app\modules\v2\controllers\RootController('root', $module))->runAction('assign'); });
            $this->assertHttp(403, function () use ($module) { (new \app\modules\v2\controllers\ManagerController('manager', $module))->runAction('assign'); });
        }
        $this->assertSame(2, (int) $this->app->db->createCommand('SELECT COUNT(*) FROM control')->queryScalar());
        $this->assertSame(2, (int) $this->app->db->createCommand('SELECT COUNT(*) FROM device')->queryScalar());
    }

    public function testV2SetupRoutesScopeAllCrudAndRejectDeviceRebinding(): void
    {
        $this->app->db->createCommand()->batchInsert('setup', ['id', 'device_id', 'title'], [[1, 1, '自己的设置'], [2, 2, '他人设置']])->execute();
        foreach ([42 => 1, 43 => 2] as $userId => $ownedId) {
            $otherId = $ownedId === 1 ? 2 : 1;
            $this->assertSame([$ownedId], array_column($this->v2Route('GET', 'v2/setups', [], $userId), 'id'));
            $this->assertSame($ownedId, (int) $this->v2Route('GET', 'v2/setups/' . $ownedId, [], $userId)['id']);
            foreach (['GET', 'PUT', 'DELETE'] as $method) {
                $this->assertHttp(404, function () use ($method, $otherId, $userId) { $this->v2Route($method, 'v2/setups/' . $otherId, ['title' => '攻击修改', 'device_id' => ($otherId === 1 ? 2 : 1)], $userId); });
            }
            $this->assertHttp(403, function () use ($ownedId, $otherId, $userId) { $this->v2Route('PUT', 'v2/setups/' . $ownedId, ['device_id' => $otherId], $userId); });
            $this->assertHttp(404, function () use ($otherId, $userId) { $this->v2Route('POST', 'v2/setups', ['device_id' => $otherId, 'title' => '跨设备创建'], $userId); });
            $this->assertSame('合法配置-' . $userId, $this->v2Route('PUT', 'v2/setups/' . $ownedId, ['title' => '合法配置-' . $userId, 'device_id' => $ownedId, 'shots' => [1, 5]], $userId)['title']);
        }
        $this->v2Route('DELETE', 'v2/setups/1', [], 42);
        $result = $this->v2Route('POST', 'v2/setups', ['device_id' => 1, 'title' => '重新创建配置', 'shots' => [1, 5]], 42);
        $this->assertSame(1, (int) $result['device_id']);
        $this->assertSame('重新创建配置', $result['title']);
    }

    public function testV2DeviceBearerIgnoresBrowserSessionAndControlRevocation(): void
    {
        $configuration = require dirname(__DIR__, 4) . '/files/api/config/web.php';
        $this->app->set('user', array_merge(['class' => 'yii\web\User'], $configuration['components']['user']));
        $login = $this->auth->login('test-admin', 'test-password');
        $this->authenticate($login);
        $this->assertSame([1], array_column($this->v2Route('GET', 'v2/devices'), 'id'));
        $this->assertFalse($this->app->user->enableSession);
        $this->assertFalse($this->app->user->enableAutoLogin);
        $this->app->request->headers->remove('Authorization');
        $this->assertHttp(401, function () { $this->app->runAction('v2/device/index'); });
        $this->app->db->createCommand()->insert('setup', ['id' => 1, 'device_id' => 1, 'title' => '关联配置'])->execute();
        $this->app->db->createCommand()->delete('control', ['user_id' => 42])->execute();
        $this->assertSame([], $this->v2Route('GET', 'v2/devices'));
        $this->assertSame([], $this->v2Route('GET', 'v2/setups'));
        $this->assertHttp(404, function () { $this->v2Route('PUT', 'v2/setups/1', ['title' => '撤销后修改']); });
        $this->assertSame('关联配置', $this->app->db->createCommand('SELECT title FROM setup WHERE id=1')->queryScalar());
        $this->authenticate($login);
        $this->assertSame('admin', $this->auth->getUser()['role']);
        $this->assertSame([1, 2], array_column($this->devices->getDevices(), 'id'));
    }

    public function testRefreshCancelsOldTicketAndLateCancelDoesNotClearNew(): void
    {
        $old = $this->scan->createTicket();
        $new = $this->scan->createTicket();
        $this->assertSame('cancelled', $this->scan->status($old['ticketId'])['status']);
        $this->scan->cancel($old['ticketId']);
        $this->scan->approve($new['ticketId'], $this->query($new)['secret'], 42);
        $this->assertSame(['username' => 'wechat:42', 'role' => 'device_manager'], $this->scan->consume($new['ticketId'])['user']);
    }

    public function testPendingAndApprovedExpireAtExactDeadline(): void
    {
        foreach ([false, true] as $approved) {
            $ticket = $this->scan->createTicket();
            if ($approved) { $this->scan->approve($ticket['ticketId'], $this->query($ticket)['secret'], 42); }
            $this->now += 300;
            $this->assertSame('expired', $this->scan->status($ticket['ticketId'])['status']);
            $this->assertHttp(410, function () use ($ticket) { $this->scan->consume($ticket['ticketId']); });
        }
    }

    public function testWrongSecretAndDuplicateApprovalAreRejected(): void
    {
        $ticket = $this->scan->createTicket();
        $this->assertHttp(403, function () use ($ticket) { $this->scan->approve($ticket['ticketId'], str_repeat('0', 64), 42); });
        $this->scan->approve($ticket['ticketId'], $this->query($ticket)['secret'], 42);
        $this->assertHttp(409, function () use ($ticket) { $this->scan->approve($ticket['ticketId'], $this->query($ticket)['secret'], 42); });
    }

    public function testDeletedUserCannotConsumeApprovedTicket(): void
    {
        $ticket = $this->scan->createTicket();
        $this->scan->approve($ticket['ticketId'], $this->query($ticket)['secret'], 42);
        $this->app->db->createCommand()->delete('user', ['id' => 42])->execute();
        $this->assertHttp(403, function () use ($ticket) { $this->scan->consume($ticket['ticketId']); });
        $this->assertNull($this->auth->getUser());
    }

    public function testDeletedUserCannotApproveOrKeepJwt(): void
    {
        $ticket = $this->scan->createTicket();
        $this->scan->approve($ticket['ticketId'], $this->query($ticket)['secret'], 42);
        $this->scan->consume($ticket['ticketId']);
        $this->app->db->createCommand()->delete('user', ['id' => 42])->execute();
        $this->assertNull($this->auth->getUser());
        $ticket = $this->scan->createTicket();
        $this->assertHttp(403, function () use ($ticket) { $this->scan->approve($ticket['ticketId'], $this->query($ticket)['secret'], 42); });
    }

    public function testAudienceMustMatchCanonicalHttpsOrigin(): void
    {
        $ticket = $this->scan->createTicket();
        $this->scan->origin = 'https://b1.example.com';
        $this->assertHttp(403, function () use ($ticket) { $this->scan->approve($ticket['ticketId'], $this->query($ticket)['secret'], 42); });
        $this->assertHttp(403, function () use ($ticket) { $this->scan->consume($ticket['ticketId']); });
        $this->assertHttp(403, function () use ($ticket) { $this->scan->status($ticket['ticketId']); });
        $this->assertHttp(403, function () use ($ticket) { $this->scan->cancel($ticket['ticketId']); });
        foreach (['', 'http://example.com', 'https://example.com/path', 'https://example.com/', 'https://user@example.com', 'https://example.com?bad=1', 'https://example.com:443', 'https://localhost', 'https://example..com', 'https://example.-com', 'https://127.000.000.001'] as $origin) {
            $this->scan->origin = $origin;
            $this->assertFalse($this->scan->isEnabled(), $origin);
            $this->assertHttp(503, function () { $this->scan->createTicket(); });
        }
        $this->scan->origin = 'https://b0.example.com';
        $this->assertTrue($this->scan->isEnabled());
    }

    public function testPasswordLoginAndLogoutInvalidateOutstandingTickets(): void
    {
        foreach (['login', 'logout'] as $action) {
            $ticket = $this->scan->createTicket();
            $action === 'login' ? $this->auth->login('test-admin', 'test-password') : $this->auth->logout();
            $this->assertSame('cancelled', $this->row($ticket)['status']);
            $this->assertHttp(409, function () use ($ticket) { $this->scan->consume($ticket['ticketId']); });
        }
    }

    public function testWebScanConsumeReturnsJwtWithoutSessionCsrf(): void
    {
        $initial = $this->admin()->runAction('session');
        $this->assertTrue($initial['scanLoginEnabled']);
        $this->assertSame('jwt', $initial['authMode']);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $ticket = $this->admin()->runAction('scan-login-create');
        $this->scan->approve($ticket['ticketId'], $this->query($ticket)['secret'], 42);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->assertHttp(405, function () use ($ticket) { $this->admin()->runAction('scan-login-consume', ['id' => $ticket['ticketId']]); });
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $result = $this->admin()->runAction('scan-login-consume', ['id' => $ticket['ticketId']]);
        $this->assertSame(['username' => 'wechat:42', 'role' => 'device_manager'], $result['user']);
        $this->assertSame('jwt', $result['authMode']);
        $this->assertArrayHasKey('refreshToken', $result['token']);
        $this->assertSame('no-store', $this->app->response->headers->get('Cache-Control'));
    }

    public function testApproveUsesRealBearerValidationAndWrappedResponse(): void
    {
        $ticket = $this->scan->createTicket();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->app->request->setBodyParams(['secret' => $this->query($ticket)['secret']]);
        $this->assertHttp(401, function () use ($ticket) { $this->webLogin()->runAction('approve', ['id' => $ticket['ticketId']]); });
        $this->app->request->headers->set('Authorization', 'Bearer ' . $this->token(42, '-1 hour'));
        $this->assertHttp(401, function () use ($ticket) { $this->webLogin()->runAction('approve', ['id' => $ticket['ticketId']]); });
        $this->app->request->headers->set('Authorization', 'Bearer ' . $this->tamper($this->token(43), 42));
        $this->assertHttp(401, function () use ($ticket) { $this->webLogin()->runAction('approve', ['id' => $ticket['ticketId']]); });
        $this->app->request->headers->set('Authorization', 'Bearer ' . $this->token(42));
        $result = $this->webLogin()->runAction('approve', ['id' => $ticket['ticketId']]);
        $this->assertTrue($result['success']);
        $this->assertSame(['status' => 'approved'], $result['data']);
    }

    public function testRefreshCannotTurnForgedOrExpiredIdentityIntoSignedAdminToken(): void
    {
        foreach ([$this->tamper($this->token(43), 42), $this->token(42, '-1 hour'), 'malformed', null] as $token) {
            $this->app->request->setBodyParams(['refreshToken' => $token]);
            $this->assertHttp(401, function () { (new SiteController('site', $this->app))->actionRefreshToken(); });
        }
        $this->app->request->setBodyParams(['refreshToken' => $this->token(42)]);
        $result = (new SiteController('site', $this->app))->actionRefreshToken();
        $this->assertTrue($result['success']);
        $this->assertTrue($this->app->jwt->validate($result['data']['token']['accessToken']));
    }

    public function testWechatWithoutUnionIdOnlyUsesServerReturnedExistingOpenid(): void
    {
        $this->app->set('wechat', new ScanTestWechat(['openid' => 'existing-openid']));
        $this->app->request->setBodyParams(['code' => 'wechat-code', 'openid' => 'root-openid']);
        $result = (new SiteController('site', $this->app))->actionLogin();
        $this->assertSame(42, (int) $result['data']['user']->id);
        $this->app->set('wechat', new ScanTestWechat(['openid' => 'new-openid']));
        $this->assertHttp(401, function () { (new SiteController('site', $this->app))->actionLogin(); });
        $this->assertSame(2, (int) $this->app->db->createCommand('SELECT COUNT(*) FROM user')->queryScalar());
    }

    public function testWechatUnionIdLoginKeepsExistingAndCanCreateCompleteIdentity(): void
    {
        $this->app->request->setBodyParams(['code' => 'wechat-code', 'unionid' => 'root-unionid']);
        $this->app->set('wechat', new ScanTestWechat(['openid' => 'existing-openid', 'unionid' => 'existing-unionid']));
        $result = (new SiteController('site', $this->app))->actionLogin();
        $this->assertSame(42, (int) $result['data']['user']->id);
        $this->app->set('wechat', new ScanTestWechat(['openid' => 'new-openid', 'unionid' => 'new-unionid']));
        $result = (new SiteController('site', $this->app))->actionLogin();
        $this->assertSame('new-unionid', $result['data']['user']->unionid);
        $this->assertNotNull(User::findIdentityByAccessToken($result['data']['token']['accessToken']));
        $this->app->set('wechat', new ScanTestWechat(['errcode' => 40029]));
        $this->assertHttp(401, function () { (new SiteController('site', $this->app))->actionLogin(); });
    }

    public function testV1RefreshCannotLaunderForgedUidIntoSignedV2AdminToken(): void
    {
        $this->app->set('helper', new ScanTestHelper());
        foreach ([$this->tamper($this->token(43), 42), $this->token(42, '-1 hour'), 'malformed'] as $token) {
            $this->app->request->setBodyParams(['refreshToken' => $token]);
            $this->assertHttp(401, function () { (new \app\modules\v1\controllers\CommonController('common', $this->app))->actionRefreshToken(); });
        }
    }

    public function testLegitimateV1PlayerTokenCannotBecomeV2AdminIdentity(): void
    {
        $player = \app\modules\v1\models\User::findOne(42);
        $this->app->request->setBodyParams(['refreshToken' => $player->token()['refreshToken']]);
        $this->assertHttp(401, function () { (new SiteController('site', $this->app))->actionRefreshToken(); });
    }

    public function testBothIdentityDomainsAcceptOwnTokensAndRejectOtherDomain(): void
    {
        $this->app->set('helper', new ScanTestHelper());
        $v1Token = \app\modules\v1\models\User::findOne(42)->token()['refreshToken'];
        $v2Token = $this->token(42);
        $this->assertSame('v1-player', $this->app->jwt->parse($v1Token)->claims()->get('identityDomain'));
        $this->assertSame('v2-user', $this->app->jwt->parse($v2Token)->claims()->get('identityDomain'));
        $this->assertSame(42, (int) \app\modules\v1\models\User::findIdentityByAccessToken($v1Token)->id);
        $this->assertSame(42, (int) User::findIdentityByAccessToken($v2Token)->id);
        $this->assertNull(\app\modules\v1\models\User::findIdentityByAccessToken($v2Token));
        $this->assertNull(User::findIdentityByAccessToken($v1Token));
        $this->app->request->setBodyParams(['refreshToken' => $v1Token]);
        $result = (new \app\modules\v1\controllers\CommonController('common', $this->app))->actionRefreshToken();
        $this->assertSame('v1-player', $this->app->jwt->parse($result['token']['accessToken'])->claims()->get('identityDomain'));
        $this->app->request->setBodyParams(['refreshToken' => $v2Token]);
        $this->assertHttp(401, function () { (new \app\modules\v1\controllers\CommonController('common', $this->app))->actionRefreshToken(); });
        $ticket = $this->scan->createTicket();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->app->request->setBodyParams(['secret' => $this->query($ticket)['secret']]);
        $this->app->request->headers->set('Authorization', 'Bearer ' . $v1Token);
        $this->assertHttp(401, function () use ($ticket) { $this->webLogin()->runAction('approve', ['id' => $ticket['ticketId']]); });
    }

    public function testProductionV1ControllerSelectsPlayerIdentityWithoutChangingAdminBearer(): void
    {
        $configuration = require dirname(__DIR__, 4) . '/files/api/config/web.php';
        $this->app->set('user', array_merge(['class' => 'yii\web\User'], $configuration['components']['user']));
        $this->assertSame(User::class, $this->app->user->identityClass);
        $this->app->user->setIdentity(User::findOne(43));
        $adminLogin = $this->auth->login('test-admin', 'test-password');
        $token = \app\modules\v1\models\User::findOne(42)->token()['accessToken'];
        $this->app->request->headers->set('Authorization', 'Bearer ' . $token);
        $this->assertHttp(401, function () { $this->auth->getUser(); });
        $module = new \app\modules\v1\Module('v1', $this->app);
        $controller = new \app\modules\v1\controllers\PlayerController('player', $module);
        $result = $controller->runAction('view', ['id' => 42]);
        $this->assertSame(42, (int) $result['id']);
        $this->assertInstanceOf(\app\modules\v1\models\User::class, $this->app->user->identity);
        $this->assertSame(42, (int) $this->app->user->identity->id);
        $this->assertFalse($this->app->user->enableSession);
        $this->assertFalse($this->app->user->enableAutoLogin);
        $this->app->request->headers->remove('Authorization');
        $this->assertHttp(401, function () use ($controller) { $controller->runAction('view', ['id' => 42]); });
        $this->app->request->headers->set('Authorization', 'Bearer ' . $this->token(42));
        $this->assertHttp(401, function () use ($controller) { $controller->runAction('view', ['id' => 42]); });
        $this->app->request->headers->set('Authorization', 'Bearer ' . $this->tamper($token, 43));
        $this->assertHttp(401, function () use ($controller) { $controller->runAction('view', ['id' => 42]); });
        $this->authenticate($adminLogin);
        $this->assertSame($adminLogin['user'], $this->auth->getUser());
    }

    public function testLegacyTokensWithoutDomainAndSignedTokensWithoutExpiryAreRejected(): void
    {
        $now = new \DateTimeImmutable('-1 minute');
        $jwt = $this->app->jwt;
        $configuration = $jwt->getConfiguration();
        foreach ([false, true] as $withoutExpiry) {
            $builder = $jwt->getBuilder()->issuedAt($now)->canOnlyBeUsedAfter($now)->withClaim('uid', 42);
            $builder = $withoutExpiry ? $builder->withClaim('identityDomain', 'v2-user') : $builder->expiresAt(new \DateTimeImmutable('+1 hour'));
            $token = $builder->getToken($configuration->signer(), $configuration->signingKey())->toString();
            $this->assertNull(User::findIdentityByAccessToken($token));
            $this->assertNull(\app\modules\v1\models\User::findIdentityByAccessToken($token));
            $this->app->request->setBodyParams(['refreshToken' => $token]);
            $this->assertHttp(401, function () { (new SiteController('site', $this->app))->actionRefreshToken(); });
        }
    }

    public function testV1RefreshDoesNotRecordBearerCredentialsInSharedDebugCache(): void
    {
        $helper = new ScanTestHelper();
        $this->app->set('helper', $helper);
        $this->app->request->setBodyParams(['refreshToken' => \app\modules\v1\models\User::findOne(42)->token()['refreshToken']]);
        (new \app\modules\v1\controllers\CommonController('common', $this->app))->actionRefreshToken();
        $this->assertSame(0, $helper->records);
    }

    public function testCreationAndApprovalAreThrottled(): void
    {
        for ($index = 0; $index < 15; $index++) { $this->scan->createTicket(); }
        $this->assertHttp(429, function () { $this->scan->createTicket(); });
        $ticket = $this->app->db->createCommand('SELECT id FROM admin_scan_login_ticket ORDER BY created_at DESC LIMIT 1')->queryScalar();
        for ($index = 0; $index < 30; $index++) { $this->assertHttp(403, function () use ($ticket) { $this->scan->approve($ticket, str_repeat('0', 64), 42); }); }
        $this->assertHttp(429, function () use ($ticket) { $this->scan->approve($ticket, str_repeat('0', 64), 42); });
    }

    public function testFailedRefreshPreservesCurrentTicketAndHidesDatabaseDetails(): void
    {
        $ticket = $this->scan->createTicket();
        $this->app->db->pdo->exec("CREATE TRIGGER reject_scan_insert AFTER INSERT ON admin_scan_login_ticket BEGIN SELECT RAISE(FAIL, 'private-database-error'); END");
        try { $this->scan->createTicket(); $this->fail('Expected database failure'); } catch (HttpException $exception) {
            $this->assertSame(503, $exception->statusCode);
            $this->assertStringNotContainsString('private-database-error', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
        $this->assertSame('pending', $this->row($ticket)['status']);
        $this->assertSame(1, (int) $this->app->db->createCommand('SELECT COUNT(*) FROM admin_scan_login_ticket')->queryScalar());
    }

    public function testAuthorizationDatabaseFailureDoesNotLeakPrivateSql(): void
    {
        $ticket = $this->scan->createTicket();
        $this->app->db->createCommand()->dropTable('user')->execute();
        $this->assertHttp(503, function () use ($ticket) { $this->scan->approve($ticket['ticketId'], $this->query($ticket)['secret'], 42); });
    }

    public function testConsumeFailureRollsBackAndDoesNotIssueToken(): void
    {
        $ticket = $this->scan->createTicket();
        $this->scan->approve($ticket['ticketId'], $this->query($ticket)['secret'], 42);
        $this->app->db->pdo->exec("CREATE TRIGGER reject_scan_consume AFTER UPDATE ON admin_scan_login_ticket WHEN NEW.status = 'consumed' BEGIN SELECT RAISE(FAIL, 'private-consume-error'); END");
        $this->assertHttp(503, function () use ($ticket) { $this->scan->consume($ticket['ticketId']); });
        $this->assertSame('approved', $this->row($ticket)['status']);
        $this->assertNull($this->auth->getUser());
    }

    public function testLostOwnerLockAbortsTicketTransactionBeforeCommit(): void
    {
        $this->tokenStore->ownerLockLost = true;
        $this->assertHttp(503, function () { $this->scan->createTicket(); });
        $this->assertSame(0, (int) $this->app->db->createCommand('SELECT COUNT(*) FROM admin_scan_login_ticket')->queryScalar());
    }

    public function testLostOwnerLockRollsBackSingleTicketCancellation(): void
    {
        $ticket = $this->scan->createTicket();
        $this->tokenStore->ownerLockLost = true;
        $this->assertHttp(503, function () use ($ticket) { $this->scan->cancel($ticket['ticketId']); });
        $this->assertSame('pending', $this->row($ticket)['status']);
    }

    public function testLostOwnerLockRollsBackCurrentTicketCancellation(): void
    {
        $ticket = $this->scan->createTicket();
        $this->tokenStore->ownerLockLost = true;
        $this->assertHttp(503, function () { $this->scan->cancelCurrentTicket(); });
        $this->assertSame('pending', $this->row($ticket)['status']);
    }

    public function testOldTicketsArePrunedAndMigrationCanBeReversed(): void
    {
        $ticket = $this->scan->createTicket();
        $this->now += 86400 + 300;
        $this->scan->createTicket();
        $this->assertSame(1, (int) $this->app->db->createCommand('SELECT COUNT(*) FROM admin_scan_login_ticket')->queryScalar());
        $this->assertHttp(404, function () use ($ticket) { $this->scan->status($ticket['ticketId']); });
        $migration = new \m261011_120000_create_admin_scan_login_ticket_table(['db' => $this->app->db, 'compact' => true]);
        $migration->safeDown();
        $this->assertNull($this->app->db->schema->getTableSchema('admin_scan_login_ticket', true));
    }

    public function testApproveNeverCreatesAdminBearerOrPersistsYiiSessionIdentity(): void
    {
        $this->app->user->enableSession = true;
        $this->app->user->enableAutoLogin = true;
        $this->app->user->setIdentity(User::findOne(42));
        $this->app->session->set('__id', 42);
        $ticket = $this->scan->createTicket();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->app->request->setBodyParams(['secret' => $this->query($ticket)['secret']]);
        $this->assertHttp(401, function () use ($ticket) { $this->webLogin()->runAction('approve', ['id' => $ticket['ticketId']]); });
        $this->app->request->headers->set('Authorization', 'Bearer ' . $this->token(42));
        $this->webLogin()->runAction('approve', ['id' => $ticket['ticketId']]);
        $this->assertFalse($this->app->user->enableSession);
        $this->assertFalse($this->app->user->enableAutoLogin);
        $this->app->request->headers->remove('Authorization');
        $this->assertNull($this->auth->getUser());
        $this->assertHttp(401, function () use ($ticket) { $this->webLogin()->runAction('approve', ['id' => $ticket['ticketId']]); });
    }

    public function testCancellationIsIdempotentAndKeepsTerminalState(): void
    {
        $ticket = $this->scan->createTicket();
        $this->assertSame('cancelled', $this->scan->cancel($ticket['ticketId'])['status']);
        $this->assertSame('cancelled', $this->scan->cancel($ticket['ticketId'])['status']);
        $ticket = $this->scan->createTicket();
        $this->now += 300;
        $this->assertSame('expired', $this->scan->cancel($ticket['ticketId'])['status']);
        $ticket = $this->scan->createTicket();
        $this->scan->approve($ticket['ticketId'], $this->query($ticket)['secret'], 42);
        $this->scan->consume($ticket['ticketId']);
        $this->assertSame('consumed', $this->scan->cancel($ticket['ticketId'])['status']);
    }

    public function testConfiguredRoutesAndOptionalRedisIndependence(): void
    {
        $configuration = require dirname(__DIR__, 4) . '/files/api/config/web.php';
        foreach ([['POST', 'admin-api/scan-login/tickets', 'admin/scan-login-create', []], ['GET', 'admin-api/scan-login/tickets/' . str_repeat('a', 32), 'admin/scan-login-status', ['id' => str_repeat('a', 32)]], ['POST', 'admin-api/scan-login/tickets/' . str_repeat('a', 32) . '/consume', 'admin/scan-login-consume', ['id' => str_repeat('a', 32)]], ['POST', 'admin-api/scan-login/tickets/' . str_repeat('a', 32) . '/cancel', 'admin/scan-login-cancel', ['id' => str_repeat('a', 32)]], ['POST', 'v2/web-login/tickets/' . str_repeat('a', 32) . '/approve', 'v2/web-login/approve', ['id' => str_repeat('a', 32)]]] as $route) {
            $_SERVER['REQUEST_METHOD'] = $route[0];
            $this->app->request->setPathInfo($route[1]);
            $manager = Yii::createObject($configuration['components']['urlManager']);
            $this->assertFalse($manager->cache);
            $this->assertSame([$route[2], $route[3]], $manager->parseRequest($this->app->request));
        }
    }

    private function query(array $ticket): array { parse_str(parse_url($ticket['qrText'], PHP_URL_QUERY), $query); return $query; }
    private function authenticate(array $login): void { $this->app->request->headers->set('Authorization', 'Bearer ' . $login['token']['accessToken']); }
    private function v2Route(string $method, string $path, array $body = [], int $userId = 42)
    {
        $configuration = require dirname(__DIR__, 4) . '/files/api/config/web.php';
        $this->app->setModule('v2', $configuration['modules']['v2']);
        $this->app->set('urlManager', array_merge($configuration['components']['urlManager'], ['cache' => false]));
        $this->app->request->setPathInfo($path);
        $this->app->request->setBodyParams($body);
        $this->app->request->setQueryParams([]);
        $this->app->request->headers->set('Authorization', 'Bearer ' . $this->token($userId));
        $this->app->response->setStatusCode(200);
        $_SERVER['REQUEST_METHOD'] = $method;
        $route = $this->app->urlManager->parseRequest($this->app->request);
        $this->assertIsArray($route, $path);
        return $this->app->runAction($route[0], $route[1]);
    }
    private function row(array $ticket): array { return $this->app->db->createCommand('SELECT * FROM admin_scan_login_ticket WHERE id=:id', [':id' => $ticket['ticketId']])->queryOne(); }
    private function admin(): AdminController { return new AdminController('admin', $this->app, ['auth' => $this->auth, 'scanLogin' => $this->scan, 'devices' => $this->devices, 'catalog' => $this->catalog]); }
    private function webLogin(): WebLoginController { return new WebLoginController('web-login', $this->app, ['scanLogin' => $this->scan]); }
    private function token(int $id, string $expires = '+1 hour'): string { return User::findOne($id)->generateAccessToken(new \DateTimeImmutable('-2 hour'), new \DateTimeImmutable($expires)); }
    private function tamper(string $token, int $id): string { $parts = explode('.', $token); $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true); $payload['uid'] = $id; $parts[1] = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '='); return implode('.', $parts); }
    private function assertHttp(int $status, callable $action): void
    {
        try { $action(); $this->fail('Expected HTTP ' . $status); } catch (HttpException $exception) { $this->assertSame($status, $exception->statusCode, $exception->getMessage()); }
    }
}

class ScanTestWechat extends \yii\base\Component
{
    private $response;
    public function __construct(array $response) { $this->response = $response; parent::__construct(); }
    public function miniApp() { return $this; }
    public function getUtils() { return $this; }
    public function codeToSession($code) { return $this->response; }
}

class ScanTestHelper extends \yii\base\Component
{
    public $records = 0;
    public function record() { $this->records++; }
}

class ScanOwnerLockTestStore extends \tests\fixtures\InMemoryAdminTokenStore
{
    public int $ownerLockCalls = 0;
    public function withOwnerLock(string $ownerHash, callable $callback)
    {
        $this->ownerLockCalls++;
        return parent::withOwnerLock($ownerHash, $callback);
    }
}
