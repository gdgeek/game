<?php

namespace app\components;

use Qcloud\Cos\Client;
use Yii;
use yii\base\Component;
use yii\web\HttpException;

/** Signs only saved images from an authorized device, without issuing COS credentials. */
class AdminImagePreviewService extends Component
{
    private const TTL_SECONDS = 300;
    public $devices;

    public function init(): void
    {
        parent::init();
        $this->devices = $this->devices ?? new AdminDeviceService();
    }

    public function getPreviews(int $deviceId): array
    {
        // Authorization and the database read remain outside the signer error boundary.
        $sources = $this->devices->getImageSources($deviceId);
        $objects = [];
        foreach ($sources as $source) {
            $object = $this->cosObject($source);
            if ($object !== null) {
                $objects[] = $object;
            }
        }
        if ($objects === []) {
            return [];
        }

        try {
            $secret = Yii::$app->get('secret', false);
            $cloud = $secret->cloud ?? null;
            $bucket = is_array($cloud) ? ($cloud['bucket'] ?? null) : null;
            $region = is_array($cloud) ? ($cloud['region'] ?? null) : null;
            if (!is_string($bucket) || preg_match('/\A[a-z0-9][a-z0-9-]*-[1-9][0-9]*\z/D', $bucket) !== 1
                || !is_string($region) || preg_match('/\A[a-z]+(?:-[a-z0-9]+)+\z/D', $region) !== 1) {
                throw new \RuntimeException('Unavailable COS configuration.');
            }
            $host = $bucket . '.cos.' . $region . '.myqcloud.com';
            $objects = array_values(array_filter($objects, static fn (array $object): bool => $object['host'] === $host));
            if ($objects === []) {
                return [];
            }
            if (!is_string($secret->id) || trim($secret->id) === '' || !is_string($secret->key) || trim($secret->key) === '') {
                throw new \RuntimeException('Unavailable COS credentials.');
            }
            $client = new Client([
                'region' => $region,
                'schema' => 'https',
                'signHost' => true,
                'timezone' => date_default_timezone_get(),
                'credentials' => ['secretId' => $secret->id, 'secretKey' => $secret->key],
            ]);
            $expiresAt = time() + self::TTL_SECONDS;
            $previews = [];
            foreach ($objects as $object) {
                // This SDK uses strtotime($expires): an absolute timestamp avoids a
                // numeric TTL silently falling back to its thirty-minute default.
                // getObjectUrl always signs GetObject. No request query is forwarded.
                $url = $client->getObjectUrl($bucket, $object['key'], '@' . $expiresAt);
                $previews[] = ['source' => $object['source'], 'url' => $url, 'expiresAt' => $expiresAt];
            }
            return $previews;
        } catch (\Throwable $exception) {
            // Exceptions can contain credentials or signed URLs. Do not log or expose them.
            throw new HttpException(503, '图片预览暂时不可用，请稍后重试');
        }
    }

    private function cosObject(string $source): ?array
    {
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $source) === 1) {
            return null;
        }
        $parts = parse_url($source);
        if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return null;
        }
        $host = strtolower($parts['host'] ?? '');
        if (preg_match('/\A[a-z0-9][a-z0-9-]*-[1-9][0-9]*\.cos\.[a-z]+(?:-[a-z0-9]+)+\.myqcloud\.com\z/D', $host) !== 1) {
            return null;
        }
        $path = $parts['path'] ?? '';
        if ($path === '' || $path[0] !== '/' || preg_match('/%(?![a-f0-9]{2})/i', $path) === 1) {
            return null;
        }
        // Decode once so literal percent sequences remain part of the object key.
        $key = rawurldecode(substr($path, 1));
        if ($key === '' || preg_match('/[\x00-\x1f\x7f\\\\]/', $key) === 1
            || preg_match('~(?:\A|/)\.{1,2}(?:/|\z)~D', $key) === 1) {
            return null;
        }
        // Saved queries/fragments are display metadata only. Old signatures,
        // transforms and response-header overrides never reach the signer.
        return ['source' => $source, 'host' => $host, 'key' => $key];
    }
}
