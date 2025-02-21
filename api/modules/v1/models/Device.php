<?php

namespace app\modules\v1\models;

use yii\behaviors\TimestampBehavior;
use yii\db\Expression;
use Yii;

/**
 * This is the model class for table "device".
 *
 * @property int $id
 * @property int|null $shop_id
 * @property string|null $uuid
 * @property string|null $tag
 * @property string $created_at
 * @property string $updated_at
 *
 * @property Record[] $records
 * @property Shop $shop
 */
class Device extends \yii\db\ActiveRecord
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

    public  function fields()
    {
       $fields = parent::fields();
       $fields['status'] = function ($model) {
        if($this->shop_id == null){
            //未设置，一个英文词
            return 'unset';
        }
        $record = $model->record;
        if($record == null){
            //未使用，一个英文词
            return 'unused';
        }
        return $record->status;
       };
       unset($fields['created_at']);
       unset($fields['updated_at']);
       return $fields;
    }
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
          //  [['status'], 'string'],
            [['created_at', 'updated_at'], 'safe'],
            [['uuid', 'tag', 'ip'], 'string', 'max' => 255],
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
            'tag' => 'Tag',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
            'ip' => 'Ip',
        ];
    }

    /**
     * Gets query for [[Records]].
     *
     * @return \yii\db\ActiveQuery
     */
    public function getRecord()
    {
        return $this->hasOne(Record::class, ['device_id' => 'id']);
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
