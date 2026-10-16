<?php

namespace app\components;

use Yii;
use yii\base\Component;
use yii\db\Query;
use yii\web\HttpException;

/** A QR secret can approve a ticket; only its creating browser can consume it. */
class AdminScanLoginService extends Component
{
    private const TABLE = '{{%admin_scan_login_ticket}}';
    private const LIFETIME = 300;
    public $auth;
    public $origin;
    public $clock;

    public function init(): void
    {
        parent::init();
        $this->auth = $this->auth ?? new AdminAuthService();
    }

    public function isEnabled(): bool
    {
        return $this->canonicalOrigin() !== null;
    }

    public function createTicket(): array
    {
        $this->requireEnabled();
        $ownerHash = $this->requireOwnerHash();
        $this->throttle('create', Yii::$app->request->userIP ?? 'unknown', 15);
        $id = bin2hex(random_bytes(16));
        $secret = bin2hex(random_bytes(32));
        $now = $this->now();
        $origin = $this->canonicalOrigin();
        $this->auth->tokens->store->withOwnerLock($ownerHash, function () use ($ownerHash, $id, $secret, $now, $origin) {
            $this->database(function () use ($ownerHash, $id, $secret, $now, $origin) {
                Yii::$app->db->transaction(function () use ($ownerHash, $id, $secret, $now, $origin) {
                    Yii::$app->db->createCommand()->update(self::TABLE, ['status' => 'cancelled'], [
                        'and', ['owner_hash' => $ownerHash, 'audience' => $origin, 'status' => ['pending', 'approved']], ['>', 'expires_at', $now],
                    ])->execute();
                    // Keep recent terminal tickets for deterministic status replies;
                    // prune older records so repeated use cannot grow storage forever.
                    Yii::$app->db->createCommand()->delete(self::TABLE, ['<=', 'expires_at', $now - 86400])->execute();
                    Yii::$app->db->createCommand()->update(self::TABLE, ['status' => 'expired'], ['and', ['status' => ['pending', 'approved']], ['<=', 'expires_at', $now]])->execute();
                    Yii::$app->db->createCommand()->insert(self::TABLE, [
                        'id' => $id, 'secret_hash' => hash('sha256', $secret),
                        'owner_hash' => $ownerHash,
                        'audience' => $origin, 'status' => 'pending',
                        'created_at' => $now, 'expires_at' => $now + self::LIFETIME,
                    ])->execute();
                    $this->auth->tokens->store->assertOwnerLock($ownerHash);
                });
            });
        });
        return [
            'ticketId' => $id,
            'qrText' => 'bujiaban-admin-login://v1/' . $id . '?secret=' . $secret . '&origin=' . rawurlencode($origin),
            'expiresAt' => gmdate('c', $now + self::LIFETIME),
        ];
    }

    public function status(string $id): array
    {
        $ownerHash = $this->requireOwnerHash();
        return $this->database(function () use ($id, $ownerHash) {
            $ticket = $this->ticket($id);
            $this->requireOwner($ticket, $ownerHash);
            $this->requireAudience($ticket);
            return $this->state($ticket);
        });
    }

    public function approve(string $id, string $secret, int $userId): array
    {
        $this->requireEnabled();
        $this->throttle('approve', $userId . ':' . (Yii::$app->request->userIP ?? 'unknown'), 30);
        $this->auth->requireWechatUser($userId);
        return $this->database(function () use ($id, $secret, $userId) {
            return Yii::$app->db->transaction(function () use ($id, $secret, $userId) {
                $ticket = $this->ticket($id);
                $this->requireAudience($ticket);
                if (!preg_match('/^[a-f0-9]{64}$/D', $secret) || !hash_equals($ticket['secret_hash'], hash('sha256', $secret))) {
                    throw new HttpException(403, '无效的扫码登录凭据');
                }
                $this->requireState($ticket, 'pending');
                $this->auth->requireWechatUser($userId);
                $changed = Yii::$app->db->createCommand()->update(self::TABLE, [
                    'status' => 'approved', 'approved_user_id' => $userId, 'approved_at' => $this->now(),
                ], ['and', ['id' => $id, 'status' => 'pending', 'audience' => $this->canonicalOrigin()], ['>', 'expires_at', $this->now()]])->execute();
                if ($changed !== 1) { throw new HttpException(409, '二维码状态已变化，请刷新'); }
                return ['status' => 'approved'];
            });
        });
    }

    public function consume(string $id): array
    {
        $this->requireEnabled();
        $ownerHash = $this->requireOwnerHash();
        $userId = $this->auth->tokens->store->withOwnerLock($ownerHash, function () use ($id, $ownerHash) {
            return $this->database(function () use ($id, $ownerHash) {
                return Yii::$app->db->transaction(function () use ($id, $ownerHash) {
                    $ticket = $this->ticket($id);
                    $this->requireOwner($ticket, $ownerHash);
                    $this->requireAudience($ticket);
                    $this->requireState($ticket, 'approved');
                    $userId = (int) $ticket['approved_user_id'];
                    $this->auth->requireWechatUser($userId);
                    $changed = Yii::$app->db->createCommand()->update(self::TABLE, ['status' => 'consumed', 'consumed_at' => $this->now()], [
                        'and', ['id' => $id, 'status' => 'approved', 'owner_hash' => $ticket['owner_hash'], 'audience' => $this->canonicalOrigin(), 'approved_user_id' => $userId], ['>', 'expires_at', $this->now()],
                    ])->execute();
                    if ($changed !== 1) { throw new HttpException(409, '二维码已使用或已过期'); }
                    $this->auth->tokens->store->assertOwnerLock($ownerHash);
                    return $userId;
                });
            });
        });
        return $this->auth->loginWechat($userId);
    }

    public function cancel(string $id): array
    {
        $ownerHash = $this->requireOwnerHash();
        return $this->auth->tokens->store->withOwnerLock($ownerHash, function () use ($id, $ownerHash) {
            return $this->database(function () use ($id, $ownerHash) {
                return Yii::$app->db->transaction(function () use ($id, $ownerHash) {
                    $ticket = $this->ticket($id);
                    $this->requireOwner($ticket, $ownerHash);
                    $this->requireAudience($ticket);
                    Yii::$app->db->createCommand()->update(self::TABLE, ['status' => 'cancelled'], ['id' => $id, 'owner_hash' => $ownerHash, 'audience' => $this->canonicalOrigin(), 'status' => ['pending', 'approved']])->execute();
                    $state = $this->state($this->ticket($id));
                    $this->auth->tokens->store->assertOwnerLock($ownerHash);
                    return $state;
                });
            });
        });
    }

    public function cancelCurrentTicket(): void
    {
        $ownerHash = $this->ownerHash();
        $origin = $this->canonicalOrigin();
        if ($ownerHash === null || $origin === null) { return; }
        $this->auth->tokens->store->withOwnerLock($ownerHash, function () use ($ownerHash, $origin): void {
            $this->database(function () use ($ownerHash, $origin): void {
                Yii::$app->db->transaction(function () use ($ownerHash, $origin): void {
                    Yii::$app->db->createCommand()->update(self::TABLE, ['status' => 'cancelled'], [
                        'and', ['owner_hash' => $ownerHash, 'audience' => $origin, 'status' => ['pending', 'approved']], ['>', 'expires_at', $this->now()],
                    ])->execute();
                    $this->auth->tokens->store->assertOwnerLock($ownerHash);
                });
            });
        });
    }

    private function ticket(string $id): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) { throw new HttpException(404, '二维码不存在'); }
        Yii::$app->db->createCommand()->update(self::TABLE, ['status' => 'expired'], ['and', ['id' => $id, 'status' => ['pending', 'approved']], ['<=', 'expires_at', $this->now()]])->execute();
        $ticket = (new Query())->from(self::TABLE)->where(['id' => $id])->one();
        if (!$ticket) { throw new HttpException(404, '二维码不存在'); }
        return $ticket;
    }

    private function requireOwner(array $ticket, string $ownerHash): void
    {
        if (!hash_equals($ticket['owner_hash'], $ownerHash)) { throw new HttpException(404, '二维码不存在'); }
    }

    private function requireOwnerHash(): string
    {
        $ownerHash = $this->ownerHash();
        if ($ownerHash === null) { throw new HttpException(400, '扫码浏览器凭据无效'); }
        return $ownerHash;
    }

    private function ownerHash(): ?string
    {
        $proof = Yii::$app->request->headers->get('X-Admin-Scan-Owner');
        if (!is_string($proof) || !preg_match('/^[a-f0-9]{64}$/D', $proof)) { return null; }
        return hash('sha256', $proof);
    }

    private function requireAudience(array $ticket): void
    {
        if ($this->canonicalOrigin() === null || !hash_equals($ticket['audience'], $this->canonicalOrigin())) { throw new HttpException(403, '二维码目标环境不匹配'); }
    }

    private function requireState(array $ticket, string $expected): void
    {
        if ($ticket['status'] === 'expired') { throw new HttpException(410, '二维码已过期，请刷新'); }
        if ($ticket['status'] !== $expected) { throw new HttpException(409, '二维码未确认、已使用或已取消'); }
    }

    private function state(array $ticket): array { return ['status' => $ticket['status'], 'expiresAt' => gmdate('c', (int) $ticket['expires_at'])]; }
    private function now(): int { return $this->clock ? (int) call_user_func($this->clock) : time(); }
    private function requireEnabled(): void { if (!$this->isEnabled()) { throw new HttpException(503, '扫码登录尚未配置'); } }

    private function canonicalOrigin(): ?string
    {
        $origin = $this->origin ?? (getenv('ADMIN_SCAN_LOGIN_ORIGIN') ?: '');
        if (!is_string($origin) || strlen($origin) > 255 || !preg_match('~^https://([a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+)(?::[1-9][0-9]{0,4})?$~D', $origin)) { return null; }
        $parts = parse_url($origin);
        if (!$parts || isset($parts['port']) && ($parts['port'] === 443 || $parts['port'] > 65535) || !filter_var('https://' . $parts['host'], FILTER_VALIDATE_URL)) { return null; }
        // WHATWG URL normalizes numeric hosts, so reject aliases that the Web
        // origin check would rewrite (octal/hex or shorthand IPv4 addresses).
        if (preg_match('/\.[0-9]+$/D', $parts['host']) && !filter_var($parts['host'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) { return null; }
        return $origin;
    }

    private function throttle(string $action, string $subject, int $limit): void
    {
        $key = ['admin-scan-login', $action, $subject];
        $now = $this->now();
        $attempts = $this->auth->cache->get($key);
        if (!is_array($attempts) || ($attempts['expiresAt'] ?? 0) <= $now) { $attempts = ['count' => 0, 'expiresAt' => $now + 60]; }
        if ($attempts['count'] >= $limit) {
            Yii::$app->response->headers->set('Retry-After', (string) max(1, $attempts['expiresAt'] - $now));
            throw new HttpException(429, '扫码操作过于频繁，请稍后重试');
        }
        $attempts['count']++;
        $this->auth->cache->set($key, $attempts, max(1, $attempts['expiresAt'] - $now));
    }

    private function database(callable $operation)
    {
        try { return $operation(); } catch (\yii\db\Exception $exception) {
            // Do not expose SQL parameters, which can include ticket identifiers.
            Yii::error('Administrator scan-login database operation failed.', __METHOD__);
            throw new HttpException(503, '扫码登录暂时不可用，请稍后重试');
        }
    }
}
