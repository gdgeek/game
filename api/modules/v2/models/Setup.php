<?php

namespace app\modules\v2\models;

use Yii;
use yii\behaviors\TimestampBehavior;
use yii\db\Expression;
use OpenApi\Annotations as OA;

/**
 * This is the model class for table "setup".
 *
 * @property int $id
 * @property float|null $money
 * @property string|null $slogans
 * @property string|null $pictures
 * @property string|null $thumbs
 * @property string|null $title
 * @property int|null $scene_id
 * @property int|null $device_id
 * @property string|null $shots
 * @property string|null $updated_at
 *
 * @property Device $device
 *
 * @OA\Schema(
 *     schema="Setup",
 *     title="设置",
 *     description="设置信息模型"
 * )
 */
class Setup extends \yii\db\ActiveRecord
{
    public function behaviors()
    {
        return [
            [
                'class' => TimestampBehavior::class,
                'attributes' => [
                    \yii\db\BaseActiveRecord::EVENT_BEFORE_INSERT => ['updated_at'],
                    \yii\db\BaseActiveRecord::EVENT_BEFORE_UPDATE => ['updated_at'],
                ],
                'value' => new Expression('NOW()'),
            ]
        ];
    }
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'setup';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['scene_id', 'device_id'], 'integer'],
            [['money'], 'number'],
            [['slogans', 'pictures', 'thumbs', 'shots', 'updated_at'], 'safe'],
            [['title'], 'string', 'max' => 255],
            [['device_id'], 'exist', 'skipOnError' => true, 'targetClass' => Device::class, 'targetAttribute' => ['device_id' => 'id']],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'money' => 'Money',
            'slogans' => 'Slogans',
            'pictures' => 'Pictures',
            'thumbs' => 'Thumbs',
            'title' => 'Title',
            'scene_id' => 'Scene ID',
            'device_id' => 'Device ID',
            'shots' => 'Shots',
            'updated_at' => 'Updated At',
        ];
    }

    /**
     * Gets query for [[Device]].
     *
     * @return \yii\db\ActiveQuery
     */
    public function getDevice()
    {
        return $this->hasOne(Device::class, ['id' => 'device_id']);
    }

    public function getData()
    {
        return [
            'money' => $this->money,
            'slogans' => $this->slogans,
            'thumbs' => $this->thumbs,
            'shots' => $this->shots,
        ];
    }

    /**
     * 获取默认信息配置
     * 
     * 从 A1Server 获取场景数据，并使用第一个场景的 scene_id 作为默认值
     * 
     * @return array 默认信息配置数组
     */
    public static function DefaultInfo(): array
    {
        // 默认的 scene_id
        $defaultSceneId = 626;

        try {
            // 尝试从 A1Server 获取场景数据
            $scenesData = \app\modules\v2\helper\A1Server::forwardCheckinRequest();

            // 检查返回的数据是否为数组且不为空
            if (is_array($scenesData) && !empty($scenesData)) {
                // 如果有 data 字段，使用 data 中的第一个元素
                if (isset($scenesData['data']) && is_array($scenesData['data']) && !empty($scenesData['data'])) {
                    $firstScene = $scenesData['data'][0];
                    if (isset($firstScene['scene_id'])) {
                        $defaultSceneId = (int)$firstScene['scene_id'];
                    }
                }
                // 如果直接是场景数组，使用第一个元素
                elseif (isset($scenesData[0]) && isset($scenesData[0]['scene_id'])) {
                    $defaultSceneId = (int)$scenesData[0]['scene_id'];
                }
            }
        } catch (\Exception $e) {
            // 如果获取失败，记录错误并使用默认值
            Yii::error("获取 A1Server 场景数据失败: " . $e->getMessage(), __METHOD__);
        }

        return [
            'title' => '未知',
            'pictures' => [
                'https://game-1251022382.cos.ap-nanjing.myqcloud.com/picture/t1.png',
                'https://game-1251022382.cos.ap-nanjing.myqcloud.com/picture/t2.png',
                'https://game-1251022382.cos.ap-nanjing.myqcloud.com/picture/t3.png',
                'https://game-1251022382.cos.ap-nanjing.myqcloud.com/picture/t4.png',
            ],
            'scene_id' => $defaultSceneId,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
    }
    public static function DefaultData(): array
    {
        $data = [

            'money' => 0,
            'slogans' => [
                '我在这里很想你',
                '今天也要加油鸭',
                '阳光正好，微风不燥',
                '记录每一刻，热爱每一天'
            ],

            'thumbs' => [
                'https://game-1251022382.cos.ap-nanjing.myqcloud.com/picture/t1.webp',
                'https://game-1251022382.cos.ap-nanjing.myqcloud.com/picture/t2.webp',
                'https://game-1251022382.cos.ap-nanjing.myqcloud.com/picture/t3.webp',
                'https://game-1251022382.cos.ap-nanjing.myqcloud.com/picture/t4.webp',
            ],
            'shots' => [1, 5, 10, 20],
        ];
        return $data;
    }
    public static function Create($device, $data, $info)
    {
        $setup = new self();
        $setup->device_id = $device->id;
        //  throw new \yii\web\HttpException(400, $device->id);
        $setup->money = $data['money'];
        $setup->slogans = $data['slogans'];
        $setup->thumbs = $data['thumbs'];
        $setup->shots = $data['shots'];


        $setup->title = $info['title'];
        $setup->scene_id = $info['scene_id'];
        $setup->pictures = $info['pictures'];

        $setup->save();

        return $setup;
    }
    public function getInfo()
    {
        return [
            'title' => $this->title,
            'pictures' => $this->pictures,
            'scene_id' => $this->scene_id,
            'updated_at' => $this->updated_at,
        ];
    }
}
