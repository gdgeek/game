<?php

namespace app\modules\v1\components;

use app\components\WechatPayApplication;
use yii\base\Component;
use yii\base\InvalidConfigException;

class Wechat extends Component
{
    public $app_id;
    public $secret;
    public $token;
    public $aes_key;

    public ?string $mch_id;

    public ?string $private_key;
    public ?string $certificate;

    public ?string $secret_key;
    public ?string $pub_key_id;
    public ?string $pub_key_path;

    /** @var array|string JSON list or array of additional platform certificate paths. */
    public $platform_certificates = [];

    public function payApp()
    {
        $platformCerts = $this->platformCerts();
        $config = [
            'mch_id' => $this->mch_id,


            'app_id' => $this->app_id,

            // 商户证书
            'private_key' => $this->private_key,
            'certificate' => $this->certificate,

            // v3 API 秘钥
            'secret_key' => $this->secret_key,

            // v2 API 秘钥
            //'v2_secret_key' => '26db3e15cfedb44abfbb5fe94fxxxxx',

            // Keep explicit IDs for both the public key and old platform certificates.
            'platform_certs' => $platformCerts,

            /**
             * 接口请求相关配置，超时时间等，具体可用参数请参考：
             * https://github.com/symfony/symfony/blob/5.3/src/Symfony/Contracts/HttpClient/HttpClientInterface.php
             */
            'http' => [
                'throw' => true, // 状态码非 200、300 时是否抛出异常，默认为开启
                'timeout' => 5.0,
                // A default header persists across every request on the same client.
                'headers' => ['Wechatpay-Serial' => $this->pub_key_id],
                // 如果你在国外想要覆盖默认的 url 的时候才使用，根据不同的模块配置不同的 base_uri
                // 'base_uri' => 'https://api.mch.weixin.qq.com/',
            ],
        ];
        return new WechatPayApplication($config);
    }

    private function platformCerts(): array
    {
        if (empty($this->pub_key_id) || empty($this->pub_key_path)) {
            throw new InvalidConfigException('WeChat Pay verification key ID and path are required.');
        }

        $paths = $this->platform_certificates;
        if (is_string($paths)) {
            try {
                $paths = json_decode($paths, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $error) {
                throw new InvalidConfigException('WECHAT_PLATFORM_CERTS must be a JSON array of certificate paths.');
            }
        }
        if (!is_array($paths) || !array_is_list($paths)) {
            throw new InvalidConfigException('WECHAT_PLATFORM_CERTS must be a list of certificate paths.');
        }

        $certs = [$this->pub_key_id => $this->pub_key_path];
        foreach ($paths as $path) {
            if (!is_string($path) || $path === '' || !is_file($path) || !is_readable($path)) {
                throw new InvalidConfigException('A WeChat Pay platform certificate file is not readable.');
            }
            $contents = file_get_contents($path);
            $certificate = $contents === false ? false : @openssl_x509_parse($contents);
            if ($certificate === false || empty($certificate['serialNumberHex'])) {
                throw new InvalidConfigException('An additional WeChat Pay platform certificate is not a valid X509 certificate.');
            }
            $serial = strtoupper($certificate['serialNumberHex']);
            if (isset($certs[$serial]) && $certs[$serial] !== $path) {
                throw new InvalidConfigException('Duplicate WeChat Pay verification key ID.');
            }
            $certs[$serial] = $path;
        }
        return $certs;
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
