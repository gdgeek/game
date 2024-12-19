<?php

namespace app\modules\v1\models;

use Yii;

/**
 * This is the model class for table "shop".
 *
 * @property int $id
 * @property float|null $income
 * @property float|null $rate
 * @property string|null $info
 *
 * @property Daily[] $dailies
 * @property Device[] $devices
 * @property Gift[] $gifts
 */
class Shop extends \yii\db\ActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'shop';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['income', 'rate'], 'number'],
            [['info'], 'safe'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'income' => 'Income',
            'rate' => 'Rate',
            'info' => 'Info',
        ];
    }

    /**
     * Gets query for [[Dailies]].
     *
     * @return \yii\db\ActiveQuery
     */
    public function getDailies()
    {
        return $this->hasMany(Daily::class, ['shop_id' => 'id']);
    }

    /**
     * Gets query for [[Devices]].
     *
     * @return \yii\db\ActiveQuery
     */
    public function getDevices()
    {
        return $this->hasMany(Device::class, ['shop_id' => 'id']);
    }

    /**
     * Gets query for [[Gifts]].
     *
     * @return \yii\db\ActiveQuery
     */
    public function getGifts()
    {
        return $this->hasMany(Gift::class, ['shop_id' => 'id']);
    }
}
