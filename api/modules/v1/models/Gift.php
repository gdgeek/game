<?php

namespace app\modules\v1\models;

use Yii;

/**
 * This is the model class for table "gift".
 *
 * @property int $id
 * @property int $shop_id
 * @property float|null $price
 * @property string|null $info
 * @property string $created_at
 * @property string|null $updated_at
 *
 * @property Shop $shop
 */
class Gift extends \yii\db\ActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'gift';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['shop_id', 'created_at'], 'required'],
            [['shop_id'], 'integer'],
            [['price'], 'number'],
            [['info', 'created_at', 'updated_at'], 'safe'],
            [['shop_id'], 'exist', 'skipOnError' => true, 'targetClass' => Shop::class, 'targetAttribute' => ['shop_id' => 'id']],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'shop_id' => 'Shop ID',
            'price' => 'Price',
            'info' => 'Info',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
        ];
    }

    /**
     * Gets query for [[Shop]].
     *
     * @return \yii\db\ActiveQuery
     */
    public function getShop()
    {
        return $this->hasOne(Shop::class, ['id' => 'shop_id']);
    }
}
