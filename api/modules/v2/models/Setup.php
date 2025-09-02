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
 * @property string|null $shot
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
            [['slogans', 'pictures', 'thumbs', 'shot'], 'safe'],
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
            'shot' => 'Shot',
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
}
