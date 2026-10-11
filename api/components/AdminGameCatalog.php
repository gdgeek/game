<?php

namespace app\components;

use yii\base\Component;
use yii\caching\FileCache;
use yii\web\HttpException;

/** Read the same A1 scenes as the devices without altering Yii's response. */
class AdminGameCatalog extends Component
{
    public $cache;
    public $loader;
    public $fetcher;

    public function init(): void
    {
        parent::init();
        $this->cache = $this->cache ?? new FileCache(['cachePath' => '@runtime/admin-scenes-cache']);
    }

    public function getGames(): array
    {
        $key = 'admin-a1-games-v1';
        $cached = $this->cache->get($key);
        if (is_array($cached)) {
            return $cached;
        }
        try {
            $payload = $this->loader !== null ? call_user_func($this->loader) : $this->fetchScenes();
            $games = $this->normalize($payload);
        } catch (\Throwable $e) {
            \Yii::warning('Administrator scene catalog is unavailable.', __METHOD__);
            throw new HttpException(503, '游戏列表暂时不可用，请稍后重试');
        }
        $this->cache->set($key, $games, 30);
        return $games;
    }

    private function fetchScenes(): array
    {
        return ($this->fetcher ?? new AdminSceneFetcher())->fetch();
    }

    private function normalize($payload): array
    {
        // A1 may return a list directly or use common pagination/envelope keys.
        for ($depth = 0; $depth < 5 && is_array($payload); $depth++) {
            if ($payload === [] || array_keys($payload) === range(0, count($payload) - 1)) {
                break;
            }
            $unwrapped = false;
            foreach (['data', 'items', 'results'] as $field) {
                if (isset($payload[$field]) && is_array($payload[$field])) {
                    $payload = $payload[$field];
                    $unwrapped = true;
                    break;
                }
            }
            if (!$unwrapped) {
                throw new \UnexpectedValueException('Invalid scene envelope.');
            }
        }
        if (!is_array($payload)) {
            throw new \UnexpectedValueException('Invalid scene list.');
        }
        $games = [];
        foreach ($payload as $scene) {
            if (!is_array($scene) || !isset($scene['verse_id'], $scene['name'])) {
                continue;
            }
            $id = filter_var($scene['verse_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false || !is_string($scene['name']) || trim($scene['name']) === '') {
                continue;
            }
            $games[$id] = [
                'id' => $id,
                'name' => $scene['name'],
                'description' => is_string($scene['description'] ?? null) ? $scene['description'] : '',
                'category' => is_string($scene['category'] ?? null) ? $scene['category'] : '',
            ];
        }
        if ($payload !== [] && $games === []) {
            throw new \UnexpectedValueException('Scene list has no valid scenes.');
        }
        return array_values($games);
    }
}
