<?php

namespace app\modules\v1\models;

use Yii;

/**
 * This is the model class for table "device".
 *
 * @property int $id
 * @property int|null $shop_id
 * @property string|null $uuid
 * @property string $status
 * @property string|null $tag
 *
 * @property Record[] $records
 * @property Shop $shop
 */
class Device extends \yii\db\ActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'device';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['shop_id'], 'integer'],
            [['status'], 'string'],
            [['uuid', 'tag'], 'string', 'max' => 255],
            [['uuid'], 'unique'],
            [['tag'], 'unique'],
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
            'uuid' => 'Uuid',
            'status' => 'Status',
            'tag' => 'Tag',
        ];
    }

    /**
     * Gets query for [[Records]].
     *
     * @return \yii\db\ActiveQuery
     */
    public function getRecords()
    {
        return $this->hasMany(Record::class, ['device_id' => 'id']);
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
