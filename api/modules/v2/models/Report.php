<?php
namespace app\modules\v2\models;


use yii\behaviors\TimestampBehavior;
use yii\db\Expression;
use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *     schema="Report",
 *     title="报告",
 *     description="Redis 报告模型"
 * )
 */
class Report extends \yii\redis\ActiveRecord
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
        return ['token', 'uuid', 'device_id', 'status', 'data', 'created_at', 'updated_at'];
    }
}
