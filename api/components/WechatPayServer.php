<?php

namespace app\components;

use EasyWeChat\Kernel\Message as KernelMessage;
use EasyWeChat\Pay\Message;
use EasyWeChat\Pay\Server;
use EasyWeChat\Pay\Validator;
use Psr\Http\Message\ServerRequestInterface;

class WechatPayServer extends Server
{
    public function getRequestMessage(?ServerRequestInterface $request = null): KernelMessage|Message
    {
        $request ??= $this->getRequest();
        // The SDK decrypts callbacks but does not automatically validate their signatures.
        (new Validator($this->merchant))->validate($request);
        return parent::getRequestMessage($request);
    }
}
