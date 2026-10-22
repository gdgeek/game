<?php

namespace app\components;

use Yii;
use yii\base\Component;
use yii\web\HttpException;
use yii\web\UnauthorizedHttpException;

/** Administrator Bearer tokens preserve password and WeChat permission domains. */
class AdminAuthService extends Component
{
    private const ATTEMPT_WINDOW = 60;
    private const MAX_ATTEMPTS = 5;

    public $username;
    public $passwordHash;
    public $cache;
    public $tokens;

    public function init(): void
    {
        parent::init();
        $this->username = $this->username ?? (getenv('ADMIN_USERNAME') ?: 'dirui');
        $this->passwordHash = $this->passwordHash ?? (getenv('ADMIN_PASSWORD_HASH') ?: '$2y$12$3RAuTkI52n8qXYippmstTOyJbMslkfdtsRYghaxUSKlKP7g1u8c2.');
        $this->tokens = $this->tokens ?? new AdminTokenService();
        // This cache remains available for scan-login throttles; password limits use shared auth storage.
        $this->cache = $this->cache ?? new \yii\caching\FileCache(['cachePath' => '@runtime/admin-auth-cache']);
    }

    public function getUser(): ?array
    {
        $identity = $this->tokens->identityFromRequest();
        if ($identity === null) { return null; }
        if (!$this->isCurrentIdentity($identity)) { throw new UnauthorizedHttpException('管理登录凭据无效或已过期，请重新登录'); }
        return $this->publicUser($identity);
    }

    public function login(string $username, string $password): array
    {
        // Reserve before checking credentials so a saturated window still blocks
        // the next request, including a correct password, as before.
        $issuer = $this->tokens->getIssuer();
        // A new window policy must not inherit a cached fifteen-minute lock.
        $attemptIp = (Yii::$app->request->userIP ?? 'unknown') . ':window:' . self::ATTEMPT_WINDOW;
        $attempt = $this->tokens->store->reservePasswordAttempt($attemptIp, self::MAX_ATTEMPTS, self::ATTEMPT_WINDOW, $issuer);
        if (!$attempt['allowed']) {
            Yii::$app->response->headers->set('Retry-After', (string) max(1, $attempt['retryAfter']));
            throw new HttpException(429, '登录尝试过于频繁，请稍后重试');
        }
        // Always verify the hash, including when the username is incorrect.
        $validPassword = strlen($password) <= 1024 && password_verify($password, $this->passwordHash);
        if (!hash_equals($this->username, $username) || !$validPassword) {
            throw new UnauthorizedHttpException('用户名或密码错误');
        }

        $this->tokens->store->clearPasswordAttempts($attemptIp, $issuer);
        $this->cancelBrowserTicket();
        return $this->issueIdentity(['source' => 'password', 'username' => $this->username, 'credentialFingerprint' => hash('sha256', $this->passwordHash)]);
    }

    public function refresh(string $refreshToken): array
    {
        $result = $this->tokens->refresh($refreshToken, function (array $identity) { return $this->isCurrentIdentity($identity); });
        return ['user' => $this->publicUser($result['identity']), 'token' => $result['token'], 'authMode' => 'jwt'];
    }

    public function logout(?string $refreshToken = null): void
    {
        $this->cancelBrowserTicket();
        $this->tokens->revoke($refreshToken);
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
        $this->cancelBrowserTicket();
        return $this->issueIdentity(['source' => 'wechat', 'wechatUserId' => $id, 'username' => 'wechat:' . $id]);
    }

    /** Null means password administrator; a user ID means control-scoped access. */
    public function requireDeviceUserId(): ?int
    {
        $identity = $this->tokens->identityFromRequest();
        if ($identity === null || !$this->isCurrentIdentity($identity)) { throw new UnauthorizedHttpException('请先登录设备管理后台'); }
        return $identity['source'] === 'password' ? null : $identity['wechatUserId'];
    }

    private function isCurrentIdentity(array $identity): bool
    {
        if (($identity['source'] ?? null) === 'password') {
            return is_string($identity['username'] ?? null) && hash_equals($this->username, $identity['username'])
                && is_string($identity['credentialFingerprint'] ?? null)
                && hash_equals(hash('sha256', $this->passwordHash), $identity['credentialFingerprint']);
        }
        return ($identity['source'] ?? null) === 'wechat' && is_int($identity['wechatUserId'] ?? null)
            && $identity['wechatUserId'] > 0 && ($identity['username'] ?? null) === 'wechat:' . $identity['wechatUserId']
            && $this->isWechatUser($identity['wechatUserId']);
    }
    private function publicUser(array $identity): array
    {
        return ['username' => $identity['username'], 'role' => $identity['source'] === 'password' ? 'admin' : 'device_manager'];
    }
    private function issueIdentity(array $identity): array
    {
        return ['user' => $this->publicUser($identity), 'token' => $this->tokens->issue($identity), 'authMode' => 'jwt'];
    }
    private function cancelBrowserTicket(): void
    {
        if (Yii::$app->request->headers->get('X-Admin-Scan-Owner') !== null) {
            (new AdminScanLoginService(['auth' => $this]))->cancelCurrentTicket();
        }
    }
}
