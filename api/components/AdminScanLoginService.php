<?php

namespace app\components;

use Yii;
use yii\base\Component;
use yii\db\Query;
use yii\web\HttpException;

/** A QR secret can approve a ticket; only its creating browser can consume it. */
class AdminScanLoginService extends Component
{
    public const OWNER_KEY = 'game.admin.scan.owner';
    public const CURRENT_KEY = 'game.admin.scan.current';
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
        $session = $this->auth->session(); // Hold the PHP session lock through mutation.
        $this->throttle('create', Yii::$app->request->userIP ?? 'unknown', 15);
        if (!$session->has(self::OWNER_KEY)) {
            $session->set(self::OWNER_KEY, bin2hex(random_bytes(32)));
        }
        $id = bin2hex(random_bytes(16));
        $secret = bin2hex(random_bytes(32));
        $now = $this->now();
        $origin = $this->canonicalOrigin();
        $this->database(function () use ($session, $id, $secret, $now, $origin) {
            Yii::$app->db->transaction(function () use ($session, $id, $secret, $now, $origin) {
                $this->cancelStoredCurrentTicket();
                // Keep recent terminal tickets for deterministic status replies;
                // prune older records so repeated use cannot grow storage forever.
                Yii::$app->db->createCommand()->delete(self::TABLE, ['<=', 'expires_at', $now - 86400])->execute();
                Yii::$app->db->createCommand()->update(self::TABLE, ['status' => 'expired'], ['and', ['status' => ['pending', 'approved']], ['<=', 'expires_at', $now]])->execute();
                Yii::$app->db->createCommand()->insert(self::TABLE, [
                    'id' => $id, 'secret_hash' => hash('sha256', $secret),
                    'owner_hash' => hash('sha256', $session->get(self::OWNER_KEY)),
                    'audience' => $origin, 'status' => 'pending',
                    'created_at' => $now, 'expires_at' => $now + self::LIFETIME,
                ])->execute();
            });
        });
        $session->set(self::CURRENT_KEY, $id);
        return [
            'ticketId' => $id,
            'qrText' => 'bujiaban-admin-login://v1/' . $id . '?secret=' . $secret . '&origin=' . rawurlencode($origin),
            'expiresAt' => gmdate('c', $now + self::LIFETIME),
        ];
    }

    public function status(string $id): array
    {
        $this->auth->session();
        return $this->database(function () use ($id) {
            $ticket = $this->ticket($id);
            $this->requireOwner($ticket);
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
        $session = $this->auth->session();
        $userId = $this->database(function () use ($id, $session) {
            return Yii::$app->db->transaction(function () use ($id, $session) {
                $ticket = $this->ticket($id);
                $this->requireOwner($ticket);
                $this->requireAudience($ticket);
                // Check terminal states before current-ticket binding so repeats
                // return a deterministic expired/consumed/cancelled error.
                $this->requireState($ticket, 'approved');
                if ($session->get(self::CURRENT_KEY) !== $id) { throw new HttpException(409, '二维码已被刷新'); }
                $userId = (int) $ticket['approved_user_id'];
                $this->auth->requireWechatUser($userId);
                $changed = Yii::$app->db->createCommand()->update(self::TABLE, ['status' => 'consumed', 'consumed_at' => $this->now()], [
                    'and', ['id' => $id, 'status' => 'approved', 'owner_hash' => $ticket['owner_hash'], 'audience' => $this->canonicalOrigin(), 'approved_user_id' => $userId], ['>', 'expires_at', $this->now()],
                ])->execute();
                if ($changed !== 1) { throw new HttpException(409, '二维码已使用或已过期'); }
                return $userId;
            });
        });
        // No login happens on polling or approval. The database transition wins
        // once; only then may this explicitly requested POST rotate the session.
        $session->remove(self::CURRENT_KEY);
        return $this->auth->loginWechat($userId);
    }

    public function cancel(string $id): array
    {
        $session = $this->auth->session();
        return $this->database(function () use ($id, $session) {
            $ticket = $this->ticket($id);
            $this->requireOwner($ticket);
            $this->requireAudience($ticket);
            Yii::$app->db->createCommand()->update(self::TABLE, ['status' => 'cancelled'], ['id' => $id, 'status' => ['pending', 'approved']])->execute();
            if ($session->get(self::CURRENT_KEY) === $id) { $session->remove(self::CURRENT_KEY); }
            return $this->state($this->ticket($id));
        });
    }

    public function cancelCurrentTicket(): void
    {
        $session = $this->auth->session();
        $this->cancelStoredCurrentTicket();
        $session->remove(self::CURRENT_KEY);
    }

    private function cancelStoredCurrentTicket(): void
    {
        $session = $this->auth->session();
        $id = $session->get(self::CURRENT_KEY);
        if (!is_string($id)) { return; }
        $this->database(function () use ($id, $session) {
            $owner = $session->get(self::OWNER_KEY);
            if (is_string($owner)) {
                Yii::$app->db->createCommand()->update(self::TABLE, ['status' => 'cancelled'], ['id' => $id, 'owner_hash' => hash('sha256', $owner), 'status' => ['pending', 'approved']])->execute();
            }
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

    private function requireOwner(array $ticket): void
    {
        $owner = Yii::$app->session->get(self::OWNER_KEY);
        if (!is_string($owner) || !hash_equals($ticket['owner_hash'], hash('sha256', $owner))) { throw new HttpException(404, '二维码不存在'); }
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
