<?php

namespace app\modules\v1\models;

use Yii;

/**
 * This is the model class for table "award".
 *
 * @property int $id
 * @property int $shop_id
 * @property int $price
 * @property string $type
 *
 * @property Shop $shop
 */
class Award extends \yii\db\ActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'award';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['shop_id'], 'required'],
            [['shop_id', 'price'], 'integer'],
            //price 初始化 100
            [['price'], 'default', 'value' => 100],
            [['type'], 'string'],
            [['shop_id'], 'exist', 'skipOnError' => true, 'targetClass' => Shop::class, 'targetAttribute' => ['shop_id' => 'id']],
            //让给shop_id 和 type 是唯一的
            [['shop_id', 'type'], 'unique', 'targetAttribute' => ['shop_id', 'type']],
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
            'type' => 'Type',
        ];
    }
    public function getGifts()
    {
        return $this->hasMany(Gift::class, ['award_id' => 'id']);
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
//php yii migrate/create drop_info_column_from_gift_table --fields="info:json"
//php yii migrate/create add_uuid_column_tag_column_to_gift_table --fields="uuid:string,tag:string"
