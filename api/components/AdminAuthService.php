<?php

namespace app\components;

use Yii;
use yii\base\Component;
use yii\caching\FileCache;
use yii\web\HttpException;
use yii\web\UnauthorizedHttpException;

/** Separate administrator identity; never authenticates a WeChat user. */
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
            || !hash_equals($this->username, $identity['username'])
            || !is_int($identity['expiresAt'])
            || $identity['expiresAt'] <= time()) {
            $this->session()->remove(self::SESSION_KEY);
            return null;
        }
        return ['username' => $identity['username']];
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
        $session->regenerateID(true);
        $session->set(self::SESSION_KEY, ['username' => $this->username, 'expiresAt' => $now + self::SESSION_SECONDS]);
        return ['username' => $this->username];
    }

    public function logout(): void
    {
        $session = $this->session();
        $session->remove(self::SESSION_KEY);
        $session->regenerateID(true);
    }

    private function session(): \yii\web\Session
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
