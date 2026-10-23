<?php

namespace app\components;

use EasyWeChat\Kernel\Contracts\Server as ServerInterface;
use EasyWeChat\Pay\Application;
use EasyWeChat\Pay\Client;
use EasyWeChat\Pay\Server;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class WechatPayApplication extends Application
{
    public function getClient(): Client|HttpClientInterface
    {
        if (!$this->client) {
            $this->client = (new WechatPayClient(
                $this->getMerchant(),
                $this->getHttpClient(),
                (array) $this->config->get('http', [])
            ))->setPresets($this->config->all());
        }
        return $this->client;
    }

    public function getServer(): Server|ServerInterface
    {
        if (!$this->server) {
            $this->server = new WechatPayServer($this->getMerchant(), $this->getRequest());
        }
        return $this->server;
    }
}
