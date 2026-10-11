<?php

namespace app\components;

use Yii;
use yii\base\Component;
use yii\caching\FileCache;
use yii\web\HttpException;
use yii\web\UnauthorizedHttpException;

/** Password administrators and WeChat device managers share a safe browser session. */
class AdminAuthService extends Component
{
    public const SESSION_KEY = 'game.admin.identity';
    private const SESSION_SECONDS = 28800;
    private const ATTEMPT_WINDOW = 900;
    private const MAX_ATTEMPTS = 5;

    public $username;
    public $passwordHash;
    public $cache;
    private $sessionPrepared = false;

    public function init(): void
    {
        parent::init();
        $this->username = $this->username ?? (getenv('ADMIN_USERNAME') ?: 'dirui');
        $this->passwordHash = $this->passwordHash ?? (getenv('ADMIN_PASSWORD_HASH') ?: '$2y$12$C6bpwclb.JM.9bhsTvVYR..TbbTRDm2bbGraU/hdgXeRx0jOO5YQq');
        // Authentication must keep working when the optional Redis service is absent.
        $this->cache = $this->cache ?? new FileCache(['cachePath' => '@runtime/admin-auth-cache']);
    }

    public function getUser(): ?array
    {
        $identity = $this->session()->get(self::SESSION_KEY);
        if (!is_array($identity)
            || !isset($identity['username'], $identity['expiresAt'])
            || !is_string($identity['username'])
            || !is_int($identity['expiresAt'])
            || $identity['expiresAt'] <= time()) {
            $this->session()->remove(self::SESSION_KEY);
            return null;
        }
        if (($identity['source'] ?? 'password') === 'wechat') {
            if (!is_int($identity['wechatUserId'] ?? null)
                || $identity['username'] !== 'wechat:' . $identity['wechatUserId']
                || !$this->isWechatUser($identity['wechatUserId'])) {
                $this->session()->remove(self::SESSION_KEY);
                return null;
            }
        } elseif (($identity['source'] ?? 'password') !== 'password' || !hash_equals($this->username, $identity['username'])) {
            $this->session()->remove(self::SESSION_KEY);
            return null;
        }
        return ['username' => $identity['username'], 'role' => ($identity['source'] ?? 'password') === 'wechat' ? 'device_manager' : 'admin'];
    }

    public function login(string $username, string $password): array
    {
        // Limit by IP so changing the supplied username does not bypass the limit.
        $key = ['admin-login-attempts', Yii::$app->request->userIP ?? 'unknown'];
        $attempts = $this->cache->get($key);
        $now = time();
        if (!is_array($attempts) || ($attempts['expiresAt'] ?? 0) <= $now) {
            $attempts = ['count' => 0, 'expiresAt' => $now + self::ATTEMPT_WINDOW];
        }
        if ($attempts['count'] >= self::MAX_ATTEMPTS) {
            Yii::$app->response->headers->set('Retry-After', (string) max(1, $attempts['expiresAt'] - $now));
            throw new HttpException(429, '登录尝试过于频繁，请稍后重试');
        }

        // Always verify the hash, including when the username is incorrect.
        $validPassword = strlen($password) <= 1024 && password_verify($password, $this->passwordHash);
        if (!hash_equals($this->username, $username) || !$validPassword) {
            $attempts['count']++;
            $this->cache->set($key, $attempts, max(1, $attempts['expiresAt'] - $now));
            throw new UnauthorizedHttpException('用户名或密码错误');
        }

        $this->cache->delete($key);
        $session = $this->session();
        (new AdminScanLoginService(['auth' => $this]))->cancelCurrentTicket();
        $session->regenerateID(true);
        $session->set(self::SESSION_KEY, ['source' => 'password', 'username' => $this->username, 'expiresAt' => $now + self::SESSION_SECONDS]);
        return ['username' => $this->username, 'role' => 'admin'];
    }

    public function logout(): void
    {
        $session = $this->session();
        (new AdminScanLoginService(['auth' => $this]))->cancelCurrentTicket();
        $session->remove(self::SESSION_KEY);
        $session->regenerateID(true);
    }

    public function isWechatUser(int $id): bool
    {
        if ($id <= 0) { return false; }
        try {
            return (new \yii\db\Query())->from('user')->where(['id' => $id])->exists();
        } catch (\yii\db\Exception $exception) {
            Yii::error('WeChat device manager identity lookup failed.', __METHOD__);
            throw new HttpException(503, '用户身份暂时不可用，请稍后重试');
        }
    }

    public function requireWechatUser(int $id): void
    {
        if (!$this->isWechatUser($id)) { throw new \yii\web\ForbiddenHttpException('微信账号已失效，请重新登录'); }
    }

    public function loginWechat(int $id): array
    {
        $this->requireWechatUser($id);
        $session = $this->session();
        (new AdminScanLoginService(['auth' => $this]))->cancelCurrentTicket();
        $session->regenerateID(true);
        $username = 'wechat:' . $id;
        $session->set(self::SESSION_KEY, ['source' => 'wechat', 'wechatUserId' => $id, 'username' => $username, 'expiresAt' => time() + self::SESSION_SECONDS]);
        return ['username' => $username, 'role' => 'device_manager'];
    }

    /** Null means password administrator; a user ID means control-scoped access. */
    public function requireDeviceUserId(): ?int
    {
        $user = $this->getUser();
        if ($user === null) { throw new UnauthorizedHttpException('请先登录设备管理后台'); }
        if ($user['role'] === 'admin') { return null; }
        return $this->session()->get(self::SESSION_KEY)['wechatUserId'];
    }

    /** Open and retain the browser session lock while changing login state. */
    public function session(): \yii\web\Session
    {
        $session = Yii::$app->session;
        if (!$this->sessionPrepared) {
            // Bootstrap components may have already opened the shared PHP session.
            // Reopen it to apply cookie/INI settings while preserving all identities.
            if ($session->isActive) {
                $session->close();
            }
            $session->setTimeout(self::SESSION_SECONDS);
            $session->setUseStrictMode(true);
            $session->setCookieParams(array_merge($session->getCookieParams(), [
                'httponly' => true,
                'secure' => Yii::$app->request->isSecureConnection,
                'samesite' => 'Lax',
            ]));
            $this->sessionPrepared = true;
        }
        $session->open();
        return $session;
    }
}
