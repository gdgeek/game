<?php

namespace app\modules\v1\components;
use yii\base\Component;

class Wechat extends Component
{

    public $app_id;
    public $secret;
    public $token;
    public $aes_key;

    public ?int  $mch_id;

    public ?string $private_key;
    public ?string $certificate;

    public ?string $secret_key;

    public function payApp()
    {

        $config = [
            'mch_id' => $this->mch_id,

            // 商户证书
            'private_key' => $this->private_key,
            'certificate' => $this->certificate,

            // v3 API 秘钥
            'secret_key' => $this->secret_key,

            // v2 API 秘钥
            //'v2_secret_key' => '26db3e15cfedb44abfbb5fe94fxxxxx',

            // 平台证书：微信支付 APIv3 平台证书，需要使用工具下载
            // 下载工具：https://github.com/wechatpay-apiv3/CertificateDownloader
            'platform_certs' => [
                // 如果是「平台证书」模式
                //    可简写使用平台证书文件绝对路径
                // '/path/to/wechatpay/cert.pem',

                // 如果是「微信支付公钥」模式
                //    使用Key/Value结构， key为微信支付公钥ID，value为微信支付公钥文件绝对路径
                // "{$pubKeyId}" => '/path/to/wechatpay/pubkey.pem',
            ],

            /**
             * 接口请求相关配置，超时时间等，具体可用参数请参考：
             * https://github.com/symfony/symfony/blob/5.3/src/Symfony/Contracts/HttpClient/HttpClientInterface.php
             */
            'http' => [
                'throw' => true, // 状态码非 200、300 时是否抛出异常，默认为开启
                'timeout' => 5.0,
                // 如果你在国外想要覆盖默认的 url 的时候才使用，根据不同的模块配置不同的 base_uri
                // 'base_uri' => 'https://api.mch.weixin.qq.com/',
            ],
        ];
        return new \EasyWeChat\Pay\Application($config);
    }
    public function miniApp()
    {
        $config = [
            'app_id' => $this->app_id,
            'secret' => $this->secret,
            'token' => $this->token,
            'aes_key' => $this->aes_key,// 明文模式请勿填写 EncodingAESKey
            //...
        ];

        return new \EasyWeChat\MiniApp\Application($config);
    }

}

