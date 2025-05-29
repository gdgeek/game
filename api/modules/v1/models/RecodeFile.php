<?php
namespace app\modules\v1\models;


use yii\behaviors\TimestampBehavior;
use yii\db\Expression;
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
        return ['token', 'key', 'device', 'openid', 'created_at'];
    }
}
