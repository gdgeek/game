<?php

namespace app\modules\v1\models;

use yii\behaviors\TimestampBehavior;
use yii\db\Expression;
use Yii;

/**
 * This is the model class for table "shop".
 *
 * @property int $id
 * @property float|null $income
 * @property float|null $rate
 * @property string|null $info
 * @property string $created_at
 * @property string $updated_at
 * @property string|null $tag
 * @property int|null $price
 *
 * @property Daily[] $dailies
 * @property Device[] $devices
 * @property Gift[] $gifts
 * @property Manager[] $managers
 */
class Shop extends \yii\db\ActiveRecord
{

    public function behaviors()
    {
        return [
            [
                'class' => TimestampBehavior::class,
                'attributes' => [
                    \yii\db\ActiveRecord::EVENT_BEFORE_INSERT => ['created_at', 'updated_at'],
                    \yii\db\ActiveRecord::EVENT_BEFORE_UPDATE => ['updated_at'],
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
        return 'shop';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['income', 'rate'], 'number'],
            [['info', 'created_at', 'updated_at'], 'safe'],
            [['price','play_time'], 'integer'],
            [['tag'], 'string', 'max' => 255],
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
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
            'tag' => 'Tag',
            'price' => 'Price',
            'play_time' => 'Play Time',
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
