<?php

namespace app\components;

use app\modules\v2\helper\A1Server;
use yii\base\Component;

/** Fetch a complete A1 catalog without copying upstream headers to Yii. */
class AdminSceneFetcher extends Component
{
    public $perPage = 15;
    public $maxPages = 100;
    public $timeoutMs = 15000;

    /** @var callable|null Receives (URL, remaining milliseconds), returns status/headers/body. */
    public $transport;

    /** @var callable|null Monotonic time in milliseconds; injectable for deadline tests. */
    public $clock;

    public function fetch(): array
    {
        if ($this->perPage < 1 || $this->maxPages < 1 || $this->timeoutMs < 1) {
            throw new \InvalidArgumentException('Invalid scene request limits.');
        }
        $deadline = $this->now() + $this->timeoutMs;
        $scenes = [];
        $pagination = null;
        $pageCount = 1;

        for ($page = 1; $page <= max(1, $pageCount); $page++) {
            $remainingMs = (int) ceil($deadline - $this->now());
            if ($remainingMs <= 0) {
                throw new \RuntimeException('Scene request deadline exceeded.');
            }
            // Build every page from the trusted endpoint; never follow Link URLs.
            $url = A1Server::BASE_URL . '/checkin?' . http_build_query([
                'expand' => 'verse_id,name',
                'page' => $page,
                'per-page' => $this->perPage,
            ], '', '&', PHP_QUERY_RFC3986);
            $response = $this->transport !== null
                ? call_user_func($this->transport, $url, $remainingMs)
                : $this->request($url, $remainingMs);
            if ($this->now() >= $deadline) {
                throw new \RuntimeException('Scene request deadline exceeded.');
            }
            if (!is_array($response)
                || ($response['status'] ?? 0) < 200 || ($response['status'] ?? 0) >= 300
                || !is_string($response['body'] ?? null) || !is_array($response['headers'] ?? null)) {
                throw new \RuntimeException('Scene service failed.');
            }
            $items = $this->unwrap(json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR));
            $metadata = $this->pagination($response['headers']);
            if ($pagination === null) {
                $pagination = $metadata;
                $pageCount = $metadata['pageCount'] ?? 1;
                if ($pageCount > $this->maxPages) {
                    throw new \RuntimeException('Scene page limit exceeded.');
                }
            } else {
                foreach ($metadata as $key => $value) {
                    if ($value !== null && $pagination[$key] !== null && $value !== $pagination[$key]) {
                        throw new \UnexpectedValueException('Scene pagination changed during the request.');
                    }
                }
            }
            $scenes = array_merge($scenes, $items);
        }
        if ($pagination['totalCount'] !== null && count($scenes) !== $pagination['totalCount']) {
            throw new \UnexpectedValueException('Incomplete scene catalog.');
        }
        if ($pageCount === 0 && $scenes !== []) {
            throw new \UnexpectedValueException('Invalid empty scene catalog.');
        }
        if ($this->now() >= $deadline) {
            throw new \RuntimeException('Scene request deadline exceeded.');
        }
        return $scenes;
    }

    private function now(): float
    {
        return $this->clock !== null ? (float) call_user_func($this->clock) : hrtime(true) / 1000000;
    }

    private function pagination(array $headers): array
    {
        $headers = array_change_key_case($headers, CASE_LOWER);
        $metadata = [];
        foreach (['pageCount' => 'page-count', 'totalCount' => 'total-count', 'perPage' => 'per-page'] as $key => $suffix) {
            $name = 'x-pagination-' . $suffix;
            $metadata[$key] = null;
            if (array_key_exists($name, $headers)) {
                $value = $headers[$name];
                if (!is_string($value) && !is_int($value)) {
                    throw new \UnexpectedValueException('Invalid scene pagination header.');
                }
                $number = filter_var(trim((string) $value), FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => $key === 'perPage' ? 1 : 0],
                ]);
                if ($number === false) {
                    throw new \UnexpectedValueException('Invalid scene pagination header.');
                }
                $metadata[$key] = $number;
            }
        }
        if ($metadata['totalCount'] === 0) {
            $derivedPageCount = 0;
        } elseif ($metadata['totalCount'] !== null && $metadata['perPage'] !== null) {
            $derivedPageCount = intdiv($metadata['totalCount'], $metadata['perPage'])
                + ($metadata['totalCount'] % $metadata['perPage'] === 0 ? 0 : 1);
        } else {
            $derivedPageCount = null;
        }
        if ($derivedPageCount !== null) {
            if ($metadata['pageCount'] !== null && $metadata['pageCount'] !== $derivedPageCount) {
                throw new \UnexpectedValueException('Inconsistent scene pagination.');
            }
            $metadata['pageCount'] = $derivedPageCount;
        }
        if ($metadata['pageCount'] === null && ($metadata['totalCount'] !== null || $metadata['perPage'] !== null)) {
            throw new \UnexpectedValueException('Incomplete scene pagination.');
        }
        return $metadata;
    }

    private function unwrap($payload): array
    {
        // Match AdminGameCatalog's existing data/items/results envelope support.
        for ($depth = 0; $depth <= 5 && is_array($payload); $depth++) {
            if ($payload === [] || array_keys($payload) === range(0, count($payload) - 1)) {
                return $payload;
            }
            foreach (['data', 'items', 'results'] as $field) {
                if (isset($payload[$field]) && is_array($payload[$field])) {
                    $payload = $payload[$field];
                    continue 2;
                }
            }
            break;
        }
        throw new \UnexpectedValueException('Invalid scene list.');
    }

    private function request(string $url, int $timeoutMs): array
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new \RuntimeException('Unable to initialize scene request.');
        }
        $headers = [];
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => min(5000, $timeoutMs),
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
                if (preg_match('/^HTTP\//i', $line)) {
                    $headers = [];
                } elseif (strpos($line, ':') !== false) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }
                return strlen($line);
            },
        ]);
        try {
            $body = curl_exec($handle);
            if ($body === false) {
                throw new \RuntimeException('Scene service failed.');
            }
            return ['status' => curl_getinfo($handle, CURLINFO_HTTP_CODE), 'headers' => $headers, 'body' => $body];
        } finally {
            curl_close($handle);
        }
    }
}
