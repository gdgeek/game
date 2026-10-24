<?php
namespace app\components;

use Yii;
use yii\base\Component;
use yii\web\HttpException;

/** Dedicated authentication state; never changes the application's business Redis. */
class AdminTokenStore extends Component
{
    public $redis = 'adminAuthRedis';
    public $keyPrefix = 'game:admin:auth:';
    public $ownerLockSeconds = 120;
    public $ownerWaitMilliseconds = 5000;
    private $heldOwnerLocks = [];

    public function create(string $sid, array $record, int $now): void
    {
        $result = $this->command('SET', [$this->sidKey($sid, $record['issuer'] ?? null), json_encode($record, JSON_THROW_ON_ERROR), 'EX', max(1, $record['expiresAt'] - $now), 'NX']);
        if ($result !== true && $result !== 'OK') { throw $this->unavailable(); }
    }
    public function get(string $sid, ?string $issuer = null): ?array
    {
        $value = $this->command('GET', [$this->sidKey($sid, $issuer)]);
        if ($value === null || $value === false) { return null; }
        return $this->decode($value);
    }
    public function rotate(string $sid, string $expected, string $next, int $now, ?string $issuer = null): ?array
    {
        $script = <<<'LUA'
local raw=redis.call('GET',KEYS[1])
if not raw then return false end
local row=cjson.decode(raw)
if row.expiresAt<=tonumber(ARGV[3]) or row.refreshHash~=ARGV[1] then return false end
row.refreshHash=ARGV[2]
local result=cjson.encode(row)
redis.call('SET',KEYS[1],result,'EX',math.max(1,row.expiresAt-tonumber(ARGV[3])))
return result
LUA;
        $value = $this->command('EVAL', [$script, 1, $this->sidKey($sid, $issuer), $expected, $next, $now]);
        return $value === null || $value === false ? null : $this->decode($value);
    }
    public function revoke(string $sid, ?string $expected = null, ?string $issuer = null): bool
    {
        if ($expected === null) { return (int) $this->command('DEL', [$this->sidKey($sid, $issuer)]) > 0; }
        $script = <<<'LUA'
local raw=redis.call('GET',KEYS[1])
if not raw then return 0 end
local row=cjson.decode(raw)
if row.refreshHash~=ARGV[1] then return 0 end
return redis.call('DEL',KEYS[1])
LUA;
        return (int) $this->command('EVAL', [$script, 1, $this->sidKey($sid, $issuer), $expected]) > 0;
    }

    /** Atomically reserve one of the shared per-IP failed password attempts. */
    public function reservePasswordAttempt(string $ip, int $limit, int $windowSeconds, ?string $issuer = null): array
    {
        $issuerHash = hash('sha256', $issuer ?? (getenv('ADMIN_AUTH_ISSUER') ?: (getenv('ADMIN_SCAN_LOGIN_ORIGIN') ?: 'https://c.4mr.cn')));
        $key = $this->keyPrefix . 'issuer:' . $issuerHash . ':password-attempts:' . hash('sha256', $ip);
        $script = <<<'LUA'
local count=tonumber(redis.call('GET',KEYS[1]) or '0')
local ttl=redis.call('TTL',KEYS[1])
if count>=tonumber(ARGV[1]) then return {0,count,math.max(1,ttl)} end
count=redis.call('INCR',KEYS[1])
if count==1 then redis.call('EXPIRE',KEYS[1],tonumber(ARGV[2])); ttl=tonumber(ARGV[2]) else ttl=redis.call('TTL',KEYS[1]) end
return {1,count,math.max(1,ttl)}
LUA;
        $result = $this->command('EVAL', [$script, 1, $key, $limit, $windowSeconds]);
        if (!is_array($result) || count($result) !== 3) { throw $this->unavailable(); }
        return ['allowed' => (int) $result[0] === 1, 'attempts' => (int) $result[1], 'retryAfter' => (int) $result[2]];
    }

    public function clearPasswordAttempts(string $ip, ?string $issuer = null): void
    {
        $issuerHash = hash('sha256', $issuer ?? (getenv('ADMIN_AUTH_ISSUER') ?: (getenv('ADMIN_SCAN_LOGIN_ORIGIN') ?: 'https://c.4mr.cn')));
        $this->command('DEL', [$this->keyPrefix . 'issuer:' . $issuerHash . ':password-attempts:' . hash('sha256', $ip)]);
    }

    /** Serialize current-ticket transitions by anonymous browser owner across nodes. */
    public function withOwnerLock(string $ownerHash, callable $callback)
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $ownerHash)) { throw $this->unavailable(); }
        $key = $this->keyPrefix . 'owner-lock:' . $ownerHash;
        $owner = bin2hex(random_bytes(16));
        $deadline = microtime(true) + $this->ownerWaitMilliseconds / 1000;
        do {
            $locked = $this->command('SET', [$key, $owner, 'NX', 'PX', (int) ($this->ownerLockSeconds * 1000)]);
            if ($locked === true || $locked === 'OK') { break; }
            if (microtime(true) >= $deadline) { throw $this->unavailable(); }
            usleep(10000);
        } while (true);
        $this->heldOwnerLocks[$ownerHash] = $owner;
        try { return $callback(); }
        finally {
            unset($this->heldOwnerLocks[$ownerHash]);
            $this->command('EVAL', ["if redis.call('GET',KEYS[1])==ARGV[1] then return redis.call('DEL',KEYS[1]) else return 0 end", 1, $key, $owner]);
        }
    }

    /** Fail the in-flight DB transaction if its lease expired or was replaced. */
    public function assertOwnerLock(string $ownerHash): void
    {
        $owner = $this->heldOwnerLocks[$ownerHash] ?? null;
        if (!is_string($owner) || $owner === '') { throw $this->unavailable(); }
        $key = $this->keyPrefix . 'owner-lock:' . $ownerHash;
        $script = "if redis.call('GET',KEYS[1])==ARGV[1] then redis.call('PEXPIRE',KEYS[1],ARGV[2]); return 1 else return 0 end";
        if ((int) $this->command('EVAL', [$script, 1, $key, $owner, (int) ($this->ownerLockSeconds * 1000)]) !== 1) { throw $this->unavailable(); }
    }
    private function sidKey(string $sid, ?string $issuer = null): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $sid)) { throw $this->unavailable(); }
        return $this->keyPrefix . 'issuer:' . hash('sha256', $issuer ?? (getenv('ADMIN_AUTH_ISSUER') ?: (getenv('ADMIN_SCAN_LOGIN_ORIGIN') ?: 'https://c.4mr.cn'))) . ':sid:' . $sid;
    }
    private function decode($value): array
    {
        try { $record = json_decode($value, true, 512, JSON_THROW_ON_ERROR); if (!is_array($record)) { throw new \RuntimeException(); } return $record; }
        catch (\Throwable $exception) { throw $this->unavailable(); }
    }
    private function command(string $name, array $arguments)
    {
        set_error_handler(static function () { error_clear_last(); return true; });
        try {
            $redis = is_string($this->redis) ? Yii::$app->get($this->redis) : $this->redis;
            return $redis->executeCommand($name, $arguments);
        } catch (\Throwable $exception) {
            error_clear_last();
            Yii::error('Administrator authentication storage is unavailable.', __METHOD__);
            throw $this->unavailable();
        } finally { restore_error_handler(); }
    }
    private function unavailable(): HttpException { return new HttpException(503, '管理认证服务暂时不可用，请稍后重试'); }
}
