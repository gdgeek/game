<?php
namespace tests\fixtures;

use yii\web\HttpException;

/** Test-only storage with the same compare-and-swap semantics as the Redis Lua scripts. */
class InMemoryAdminTokenStore
{
    public array $records = [];
    public bool $unavailable = false;
    public array $passwordAttempts = [];
    public bool $ownerLockLost = false;
    private function available(): void { if ($this->unavailable) { throw new HttpException(503, '管理认证服务暂时不可用，请稍后重试'); } }
    public function create(string $sid, array $record, int $now): void { $this->available(); $this->records[$sid] = $record; }
    public function get(string $sid, ?string $issuer = null): ?array { $this->available(); return $this->records[$sid] ?? null; }
    public function rotate(string $sid, string $expected, string $next, int $now, ?string $issuer = null): ?array {
        $this->available(); $record = $this->records[$sid] ?? null;
        if (!$record || $record['expiresAt'] <= $now || !hash_equals($record['refreshHash'], $expected)) { return null; }
        $record['refreshHash'] = $next; $this->records[$sid] = $record; return $record;
    }
    public function revoke(string $sid, ?string $expected = null, ?string $issuer = null): bool {
        $this->available(); $record = $this->records[$sid] ?? null;
        if (!$record || ($expected !== null && !hash_equals($record['refreshHash'], $expected))) { return false; }
        unset($this->records[$sid]); return true;
    }
    public function withOwnerLock(string $ownerHash, callable $callback) { $this->available(); return $callback(); }
    public function reservePasswordAttempt(string $ip, int $limit, int $windowSeconds, ?string $issuer = null): array { $this->available(); $key = hash('sha256', ($issuer ?? '') . ':' . $ip); $count = $this->passwordAttempts[$key] ?? 0; if ($count >= $limit) { return ['allowed' => false, 'attempts' => $count, 'retryAfter' => $windowSeconds]; } $this->passwordAttempts[$key] = ++$count; return ['allowed' => true, 'attempts' => $count, 'retryAfter' => $windowSeconds]; }
    public function clearPasswordAttempts(string $ip, ?string $issuer = null): void { $this->available(); unset($this->passwordAttempts[hash('sha256', ($issuer ?? '') . ':' . $ip)]); }
    public function assertOwnerLock(string $ownerHash): void { $this->available(); if ($this->ownerLockLost) { throw new HttpException(503, '管理认证服务暂时不可用，请稍后重试'); } }
}
