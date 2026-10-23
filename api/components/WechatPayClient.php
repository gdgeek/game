<?php

namespace app\components;

use EasyWeChat\Kernel\Exceptions\BadResponseException;
use EasyWeChat\Pay\Client;
use EasyWeChat\Pay\Validator;
use Nyholm\Psr7\Response;
use Symfony\Contracts\HttpClient\ResponseInterface;

class WechatPayClient extends Client
{
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $response = parent::request($method, $url, $options);
        if (!$this->isV3Request($url)) {
            return $response;
        }

        $status = $response->getStatusCode();
        // A caller using toArray(false) must never treat an HTTP error as a payment result.
        if ($status < 200 || $status >= 300) {
            throw new BadResponseException('WeChat Pay API request failed.', $status);
        }

        // Validate the exact bytes before decoding; getContent(false) keeps the body buffered.
        (new Validator($this->merchant))->validate(new Response(
            $status,
            $response->getHeaders(false),
            $response->getContent(false)
        ));

        return $response;
    }
}
