<?php

namespace app\modules\v2\models;

use Yii;

/**
 * This is the model class for table "setup".
 *
 * @property int $id
 * @property int|null $money
 * @property string|null $slogans
 * @property string|null $pictures
 * @property string|null $thumbs
 * @property string|null $shots
 * @property string|null $title
 * @property int|null $scene_id
 * @property int|null $device_id
 *
 * @property Device $device
 */
class Setup extends \yii\db\ActiveRecord
{
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
            [['slogans', 'pictures', 'thumbs', 'shots'], 'safe'],
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
            'shots' => 'Shots',
            'title' => 'Title',
            'scene_id' => 'Scene ID',
            'device_id' => 'Device ID',
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

    public static function DefaultInfo(): array
    {
        return [
            'title' => '未知',
            'pictures' => [
                'https://game-1251022382.cos.ap-nanjing.myqcloud.com/picture/t1.png',
                'https://game-1251022382.cos.ap-nanjing.myqcloud.com/picture/t2.png',
                'https://game-1251022382.cos.ap-nanjing.myqcloud.com/picture/t3.png',
                'https://game-1251022382.cos.ap-nanjing.myqcloud.com/picture/t4.png',
            ],
            'scene_id' => 0,
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
        ];
    }
}
