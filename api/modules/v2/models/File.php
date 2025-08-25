<?php

namespace app\modules\v2\models;
use yii\web\BadRequestHttpException;
use Yii;

/**
 * This is the model class for table "file".
 *
 * @property int $id
 * @property string $key
 * @property string|null $type
 * @property string|null $md5
 * @property int|null $size
 * @property string|null $bucket
 * @property string $created_at
 */
// 引入 COS SDK (需: composer require qcloud/cos-sdk-v5:^2.0)
use Qcloud\Cos\Client;

class File extends \yii\db\ActiveRecord
{

    public static function GetClient(string $key, int $expires = 900, ?string $method = 'GET')
    {
        $method = strtoupper($method ?? 'GET');
        if (!in_array($method, ['GET', 'HEAD', 'PUT'], true)) {
            throw new BadRequestHttpException('method 仅支持 GET/HEAD/PUT');
        }
        if ($expires < 1 || $expires > 86400) {
            throw new BadRequestHttpException('expires 必须在 1~86400 之间');
        }

        if (!class_exists(Client::class)) {
            throw new BadRequestHttpException('未安装 COS SDK，请先执行: composer require qcloud/cos-sdk-v5:^2.0');
        }

        $cloud = Yii::$app->secret->cloud ?? [];
        $region = $cloud['region'] ?? 'ap-nanjing';


        $key = ltrim($key, characters: '/');
        if ($key === '') {
            throw new BadRequestHttpException('key 不能为空');
        }

        // 默认使用永久密钥
        $secretId = Yii::$app->secret->id ?? null;
        $secretKey = Yii::$app->secret->key ?? null;
        $sessionToken = null;

        if (!$secretId || !$secretKey) {
            throw new BadRequestHttpException('缺少主账号密钥配置');
        }


        $config = [
            'region' => $region,
            'schema' => 'https',
            'credentials' => [
                'secretId' => $secretId,
                'secretKey' => $secretKey,
            ],
        ];
        if ($sessionToken) {
            $config['credentials']['token'] = $sessionToken;
        }

        $client = new Client($config);
        return $client;
    }
    public static function GetObjectUrl(string $key, Client $client, int $expires = 900, ?string $method = 'GET')
    {

        $cloud = Yii::$app->secret->cloud ?? [];
        $bucket = $cloud['bucket'] ?? null;

        if (!$bucket) {
            throw new BadRequestHttpException('缺少 bucket 配置');
        }
        $key = ltrim($key, '/');
        if ($key === '') {
            throw new BadRequestHttpException('key 不能为空');
        }

        $options = [
            'Method' => $method,
        ];

        // 覆写响应头 (下载场景)
        $overrideQueryKeys = [
            'response-content-type',
            'response-content-disposition',
            'response-content-language',
            'response-cache-control',
            'response-expires',
            'response-content-encoding',
        ];
        foreach ($overrideQueryKeys as $qk) {
            $v = Yii::$app->request->get($qk);
            if ($v !== null) {
                $options['Params'][$qk] = $v;
            }
        }

        if ($ct = Yii::$app->request->get('contentType')) {
            $options['Headers']['Content-Type'] = $ct;
        }
        if ($disp = Yii::$app->request->get('disposition')) {
            $options['Params']['response-content-disposition'] = $disp;
        }

        try {
            // $expires 可直接为整型秒数
            $url = $client->getObjectUrl($bucket, $key, $expires, $options);
        } catch (\Throwable $e) {
            throw new BadRequestHttpException('生成 URL 失败: ' . $e->getMessage());
        }
        return $url;

    }


    public function getUrl()
    {
        $key = $this->key;
        $client = self::GetClient($key);
        return self::getObjectUrl($key, $client);
    }

    public static function Create(string $key, string|null $unionid = null)
    {

        $file = File::find()->where(['key' => $key])->one();
        if ($file) {
            return $file;
        }

        $file = new self();
        $client = self::GetClient($key);

        $cloud = Yii::$app->secret->cloud ?? [];
        $bucket = $cloud['bucket'] ?? null;
        $result = $client->headObject([
            'Bucket' => $bucket,
            'Key' => $key,
            'Headers' => ['x-cos-meta-need-md5' => '1'] // 强制返回 MD5
        ]);
        //Wed, 28 May 2025 06:58:30 GMT 这个格式如何换算成'Y-m-d H:i:s'
        $file->created_at = date('Y-m-d H:i:s', strtotime($result['LastModified'] ?? ''));

        //  return $result;
        $file->key = $key;
        if ($unionid) {
            $file->unionid = $unionid;
        }
        $file->type = $result['ContentType'] ?? null;
        // $file->md5 = $result['ETag'] ?? null;
        $file->size = $result['ContentLength'] ?? null;
        $file->bucket = $bucket;
        if (isset($result['x-cos-hash-md5'])) {
            $file->md5 = $result['x-cos-hash-md5'];
        } else {
            // 简单上传场景：ETag 去引号即为 MD5
            $md5 = trim($result['ETag'], '"');
            $file->md5 = $md5;
        }


        return $file;
    }
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'file';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['key'], 'required'],
            [['size', 'unlocked', 'user_id'], 'integer'],
            [['created_at'], 'safe'],
            [['unionid', 'key', 'type', 'md5', 'bucket', 'title'], 'string', 'max' => 255],
            [['key'], 'unique'],
            [['md5'], 'unique'],
            [['user_id'], 'exist', 'skipOnError' => true, 'targetClass' => User::class, 'targetAttribute' => ['user_id' => 'id']],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'unionid' => 'Unionid',
            'key' => 'Key',
            'type' => 'Type',
            'md5' => 'Md5',
            'size' => 'Size',
            'bucket' => 'Bucket',
            'created_at' => 'Created At',
            'unlocked' => 'Unlocked',
            'title' => 'Title',
            'user_id' => 'User ID',
        ];
    }
    /** 
     * Gets query for [[User]]. 
     * 
     * @return \yii\db\ActiveQuery 
     */
    public function getUser()
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }
}
