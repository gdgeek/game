<?php

namespace app\modules\v2\helper;

use Yii;

/**
 * 场景服务器辅助类。
 *
 * 保留 A1Server 类名以兼容现有调用方；默认上游已切换为 Y1 B0，
 * 可通过 SCENE_SERVER_BASE_URL 覆盖为 Y1 B1 或开发环境。
 */
class A1Server
{
    private const SERVER_PATH = '/v1/server';

    /**
     * 生产环境场景服务器基础 URL
     */
    const BASE_URL = 'https://y1.b0.01xr.com/v1/server';

    /**
     * 返回当前环境配置的场景服务器基础 URL。
     */
    public static function baseUrl(): string
    {
        $configured = getenv('SCENE_SERVER_BASE_URL');
        if ($configured === false || trim($configured) === '') {
            $configured = $_ENV['SCENE_SERVER_BASE_URL'] ?? self::BASE_URL;
        }
        $baseUrl = rtrim(trim((string) $configured), '/');
        $parts = parse_url($baseUrl);

        $isHttps = is_array($parts) && ($parts['scheme'] ?? '') === 'https';
        $isInternalY1Service = is_array($parts)
            && ($parts['scheme'] ?? '') === 'http'
            && ($parts['host'] ?? '') === 'y1-p'
            && ($parts['port'] ?? null) === 8080;
        $path = is_array($parts) ? rtrim((string) ($parts['path'] ?? ''), '/') : '';

        if ($baseUrl === ''
            || !is_array($parts)
            || (!$isHttps && !$isInternalY1Service)
            || empty($parts['host'])
            || ($path !== '' && $path !== self::SERVER_PATH)
            || isset($parts['query'])
            || isset($parts['fragment'])
            || isset($parts['user'])
            || isset($parts['pass'])) {
            throw new \InvalidArgumentException('SCENE_SERVER_BASE_URL must be an HTTPS host root or the internal http://y1-p:8080 service, optionally ending in /v1/server, without credentials or query parameters.');
        }

        return $path === '' ? $baseUrl . self::SERVER_PATH : $baseUrl;
    }

    /**
     * 转发签到请求到场景服务器
     * 
     * 此方法将当前请求的参数转发到场景服务器的签到接口，
     * 并返回场景服务器的响应结果。
     * 
     * @return array|string 返回场景服务器的响应数据，JSON 格式会被解析为数组
     * @throws \yii\web\HttpException 当请求失败时抛出异常
     */
    public static function forwardCheckinRequest()
    {
        // 缓存键
        $baseUrl = self::baseUrl();
        $cacheKey = 'scene_server_checkin_request_' . sha1($baseUrl);
        $cacheDuration = 30; // 缓存30秒

        // 尝试从缓存获取
        $cache = Yii::$app->cache;
        $cachedData = $cache->get($cacheKey);
        if ($cachedData !== false) {
            return $cachedData;
        }

        // 外部接口地址
        $targetUrl = $baseUrl . '/checkin?expand=verse_id,name';

        // 获取请求参数
        // $params = Yii::$app->request->get();


        // 使用 cURL 转发请求
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $targetUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        // 转发请求头（可选）
        $headers = [];
        // $headers[] = 'Authorization: Bearer YOUR_TOKEN';
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        //curl_close($ch);

        if ($error) {
            throw new \yii\web\HttpException(500, '转发请求失败: ' . $error);
        }

        // 设置响应状态码
        Yii::$app->response->statusCode = $httpCode;

        // 尝试解析 JSON 响应
        $data = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            // 缓存成功的响应结果
            $cache->set($cacheKey, $data, $cacheDuration);

            return $data;
        }

        // 如果不是 JSON，直接返回原始响应
        Yii::$app->response->format = \yii\web\Response::FORMAT_RAW;
        return $response;
    }

    /**
     * 通用的场景服务器请求转发方法
     * 
     * @param string $endpoint API 端点路径（不包含基础 URL）
     * @param array $params 请求参数
     * @param string $method HTTP 方法（GET, POST 等）
     * @param array $headers 额外的请求头
     * @return array|string 返回服务器响应
     * @throws \yii\web\HttpException 当请求失败时抛出异常
     */
    public static function forwardRequest(string $endpoint, array $params = [], string $method = 'GET', array $headers = [])
    {
        $targetUrl = self::baseUrl() . '/' . ltrim($endpoint, '/');

        $ch = curl_init();

        if ($method === 'GET' && !empty($params)) {
            $targetUrl .= '?' . http_build_query($params);
        }

        curl_setopt($ch, CURLOPT_URL, $targetUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        // 设置 HTTP 方法
        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if (!empty($params)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
                $headers[] = 'Content-Type: application/json';
            }
        }

        // 设置请求头
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \yii\web\HttpException(500, '场景服务器请求失败: ' . $error);
        }

        // 设置响应状态码
        Yii::$app->response->statusCode = $httpCode;

        // 尝试解析 JSON 响应
        $data = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $data;
        }

        // 如果不是 JSON，直接返回原始响应
        Yii::$app->response->format = \yii\web\Response::FORMAT_RAW;
        return $response;
    }
}
