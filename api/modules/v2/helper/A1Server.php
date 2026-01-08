<?php

namespace app\modules\v2\helper;

use Yii;

/**
 * A1Server 辅助类
 * 
 * 用于处理与 A1 服务器的通信和请求转发
 */
class A1Server
{
    /**
     * A1 服务器基础 URL
     */
    const BASE_URL = 'https://a1.voxel.cn/v1/server';

    /**
     * 转发签到请求到 A1 服务器
     * 
     * 此方法将当前请求的参数转发到 A1 服务器的签到接口，
     * 并返回 A1 服务器的响应结果。
     * 
     * @return array|string 返回 A1 服务器的响应数据，JSON 格式会被解析为数组
     * @throws \yii\web\HttpException 当请求失败时抛出异常
     */
    public static function forwardCheckinRequest()
    {
        // 外部接口地址
        $targetUrl = self::BASE_URL . '/checkin?expand=verse_id,name';

        // 获取请求参数
        $params = Yii::$app->request->get();

        // 构建带参数的 URL（目标 URL 已包含查询参数，使用 & 追加）
        if (!empty($params)) {
            $targetUrl .= '&' . http_build_query($params);
        }

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
        curl_close($ch);

        if ($error) {
            throw new \yii\web\HttpException(500, '转发请求失败: ' . $error);
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

    /**
     * 通用的 A1 服务器请求转发方法
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
        $targetUrl = self::BASE_URL . '/' . ltrim($endpoint, '/');

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
            throw new \yii\web\HttpException(500, 'A1服务器请求失败: ' . $error);
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
