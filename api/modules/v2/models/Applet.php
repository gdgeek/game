<?php
namespace app\modules\v2\models;


use yii\behaviors\TimestampBehavior;
use yii\db\Expression;
use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *     schema="Applet",
 *     title="小程序实例",
 *     description="小程序相关数据模型"
 * )
 */
class Applet extends \yii\redis\ActiveRecord
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
        return ['token', 'user_id', 'id', 'status', 'data', 'created_at', 'updated_at'];
    }
}
