<?php

namespace app\modules\v2\models;

use yii\behaviors\TimestampBehavior;
use yii\db\Expression;
use OpenApi\Annotations as OA;

// 1先上传文件，并把文件储存在本地。 文件包括地址，时间，以及文件md5 和 key 以及 用户openid或者 uniid,

// 2通过 id 可以看到文件列表

/**
 * @OA\Schema(
 *     schema="RecodeFile",
 *     title="文件记录",
 *     description="Redis 文件记录模型"
 * )
 */
class RecodeFile extends \yii\redis\ActiveRecord
{
    /**
     * @return array 定义 Redis 键名前缀
     */
    public static function primaryKey()
    {
        return ['token'];
    }


    /**
     * @return array 定义模型的属性
     */
    public function attributes()
    {
        return ['token', 'key', 'created_at', 'updated_at', 'file_id'];
    }
}
