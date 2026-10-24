<?php
namespace app\components;

use Yii;
use yii\base\Component;
use yii\web\HttpException;
use yii\web\UnauthorizedHttpException;
use Lcobucci\JWT\Validation\Constraint\SignedWith;

/** Browser administrator credentials have their own domain, separate from v1/v2 tokens. */
class AdminTokenService extends Component
{
    public const DOMAIN = 'game-admin';
    public const SESSION_SECONDS = 28800;
    public const ACCESS_SECONDS = 10800;
    public $store;
    public $jwt = 'adminJwt';
    private $signingConfiguration;
    public $issuer;
    public $clock;

    public function init(): void
    {
        parent::init();
        $this->store = $this->store ?? new AdminTokenStore();
    }

    public function issue(array $identity): array
    {
        $now = $this->now();
        $sid = bin2hex(random_bytes(16));
        $deadline = $now + self::SESSION_SECONDS;
        $refresh = $sid . '.' . bin2hex(random_bytes(32));
        $token = $this->pack($sid, $refresh, $deadline);
        $this->store->create($sid, ['identity' => $identity, 'issuer' => $this->issuerValue(), 'expiresAt' => $deadline, 'refreshHash' => hash('sha256', $refresh)], $now);
        return $token;
    }

    public function identityFromRequest(): ?array
    {
        $header = Yii::$app->request->headers->get('Authorization');
        if ($header === null || $header === '') { return null; }
        $sid = $this->accessSid($header);
        $record = $this->store->get($sid, $this->issuerValue());
        if (!$record || ($record['issuer'] ?? null) !== $this->issuerValue() || !is_int($record['expiresAt'] ?? null) || $record['expiresAt'] <= $this->now()
            || !is_array($record['identity'] ?? null)) { throw $this->invalid(); }
        return $record['identity'];
    }

    /** Validate current account eligibility before the one-use compare-and-swap. */
    public function refresh(string $refresh, ?callable $identityValidator = null): array
    {
        $sid = $this->refreshSid($refresh);
        $record = $this->store->get($sid, $this->issuerValue());
        if (!$record || ($record['issuer'] ?? null) !== $this->issuerValue() || !is_int($record['expiresAt'] ?? null) || $record['expiresAt'] <= $this->now()
            || !is_array($record['identity'] ?? null) || !is_string($record['refreshHash'] ?? null)
            || !hash_equals($record['refreshHash'], hash('sha256', $refresh))) { throw $this->invalid(); }
        if ($identityValidator !== null && !$identityValidator($record['identity'])) { throw $this->invalid(); }
        $next = $sid . '.' . bin2hex(random_bytes(32));
        $token = $this->pack($sid, $next, $record['expiresAt']);
        $rotated = $this->store->rotate($sid, hash('sha256', $refresh), hash('sha256', $next), $this->now(), $this->issuerValue());
        if ($rotated === null) { throw $this->invalid(); }
        return ['identity' => $rotated['identity'], 'token' => $token];
    }

    /** A valid access token may revoke its family even after another tab refreshed it. */
    public function revoke(?string $refresh = null): void
    {
        $header = Yii::$app->request->headers->get('Authorization');
        if ($header !== null && $header !== '') {
            try { $sid = $this->accessSid($header); }
            catch (UnauthorizedHttpException $e) { if ($refresh === null) { throw $e; } }
            if (isset($sid)) { $this->store->revoke($sid, null, $this->issuerValue()); return; }
        }
        if ($refresh !== null && $refresh !== '') {
            $sid = $this->refreshSid($refresh);
            $issuer = $this->issuerValue();
            if (!$this->store->revoke($sid, hash('sha256', $refresh), $issuer) && $this->store->get($sid, $issuer) !== null) {
                throw $this->invalid();
            }
        }
    }

    private function accessSid(string $header): string
    {
        if (!preg_match('/^Bearer ([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+)$/D', $header, $match)) { throw $this->invalid(); }
        $configuration = $this->configuration();
        $issuer = $this->issuerValue();
        try {
            $token = $configuration->parser()->parse($match[1]);
            if (!$configuration->validator()->validate($token, new SignedWith($configuration->signer(), $configuration->verificationKey()))) { throw $this->invalid(); }
            $claims = $token->claims();
            $sid = $claims->get('sid', null);
            $issued = $claims->get('iat', null); $notBefore = $claims->get('nbf', null); $expires = $claims->get('exp', null);
            if ($claims->get('iss', null) !== $issuer || $claims->get('identityDomain', null) !== self::DOMAIN || $claims->get('tokenUse', null) !== 'access'
                || $claims->get('aud', null) !== [self::DOMAIN] || $claims->get('sub', null) !== $sid
                || !is_string($sid) || !preg_match('/^[a-f0-9]{32}$/D', $sid)
                || !$issued instanceof \DateTimeImmutable || !$notBefore instanceof \DateTimeImmutable || !$expires instanceof \DateTimeImmutable
                || $issued->getTimestamp() > $this->now() || $notBefore->getTimestamp() > $this->now()
                || $expires->getTimestamp() <= $this->now() || $expires->getTimestamp() <= $issued->getTimestamp()
                || $expires->getTimestamp() - $issued->getTimestamp() > self::ACCESS_SECONDS) { throw $this->invalid(); }
            return $sid;
        } catch (\Lcobucci\JWT\Signer\InvalidKeyProvided $exception) {
            throw new HttpException(503, '管理认证服务暂时不可用，请稍后重试');
        } catch (\Throwable $exception) { throw $this->invalid(); }
    }

    private function refreshSid(string $refresh): string
    {
        if (!preg_match('/^([a-f0-9]{32})\.[a-f0-9]{64}$/D', $refresh, $match)) { throw $this->invalid(); }
        return $match[1];
    }

    private function pack(string $sid, string $refresh, int $deadline): array
    {
        $now = $this->now();
        if ($deadline <= $now) { throw $this->invalid(); }
        $expires = min($deadline, $now + self::ACCESS_SECONDS);
        $configuration = $this->configuration();
        try {
            $token = $configuration->builder()->issuedBy($this->issuerValue())->permittedFor(self::DOMAIN)->relatedTo($sid)->identifiedBy(bin2hex(random_bytes(16)))
                ->issuedAt(new \DateTimeImmutable('@' . $now))->canOnlyBeUsedAfter(new \DateTimeImmutable('@' . $now))
                ->expiresAt(new \DateTimeImmutable('@' . $expires))->withClaim('identityDomain', self::DOMAIN)
                ->withClaim('tokenUse', 'access')->withClaim('sid', $sid)
                ->getToken($configuration->signer(), $configuration->signingKey());
            return ['accessToken' => $token->toString(), 'refreshToken' => $refresh, 'expires' => gmdate('c', $expires), 'refreshExpires' => gmdate('c', $deadline)];
        } catch (\Throwable $exception) {
            Yii::error('Administrator token signing is unavailable.', __METHOD__);
            throw new HttpException(503, '管理认证服务暂时不可用，请稍后重试');
        }
    }
    private function configuration(): \Lcobucci\JWT\Configuration
    {
        if ($this->signingConfiguration !== null) { return $this->signingConfiguration; }
        try {
            $jwt = is_string($this->jwt) ? Yii::$app->get($this->jwt) : $this->jwt;
            return $this->signingConfiguration = $jwt->getConfiguration();
        } catch (\Throwable $exception) {
            Yii::error('Administrator signing authority is unavailable.', __METHOD__);
            throw new HttpException(503, '管理认证服务暂时不可用，请稍后重试');
        }
    }
    private function issuerValue(): string
    {
        $issuer = $this->issuer ?? (getenv('ADMIN_AUTH_ISSUER') ?: (getenv('ADMIN_SCAN_LOGIN_ORIGIN') ?: 'https://c.4mr.cn'));
        if (!is_string($issuer) || $issuer === '' || strlen($issuer) > 255 || preg_match('/[\x00-\x20\x7f]/', $issuer)) {
            throw new HttpException(503, '管理认证服务暂时不可用，请稍后重试');
        }
        return $issuer;
    }
    public function getIssuer(): string { return $this->issuerValue(); }
    private function now(): int { return $this->clock ? (int) call_user_func($this->clock) : time(); }
    private function invalid(): UnauthorizedHttpException { return new UnauthorizedHttpException('管理登录凭据无效或已过期，请重新登录'); }
}
