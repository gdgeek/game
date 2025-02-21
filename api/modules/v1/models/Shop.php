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
            [['created_at', 'updated_at'], 'safe'],
            [['price','play_time','rate','advanced'], 'integer'],
            [['tag'], 'string', 'max' => 255],
        ];
    }
   
    public function fields()
    {
       $fields = parent::fields();
       unset($fields['created_at'],$fields['updated_at']);
   
       return $fields;
    }

    public function extraFields()
    {
        return ['gifts','awards','operation'];
    }
    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'rate' => 'Rate',//收益率
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
            'tag' => 'Tag',
            'advanced' => 'Advanced',
            'price' => 'Price',//单价
            'play_time' => 'Play Time',
        ];
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
       
        return Gift::find()
            ->joinWith('award')
            ->where(['award.shop_id' => $this->id])
            ->all();
    }

    /**
    * Gets query for [[Operations]].
    *
    * @return \yii\db\ActiveQuery
    */
    public function getOperation()
    {
        $operation = $this->hasOne(Operation::class, ['shop_id' => 'id'])->one();
        if(!$operation){
            $operation = new Operation();
            $operation->shop_id = $this->id;
           // $operation->pool = $this->price;
            $operation->save();
        }
        return  $operation;
    }

    /**
    * Gets query for [[Gains]]. 
    * 
    * @return \yii\db\ActiveQuery 
    */ 
   public function getGains() 
   { 
       return $this->hasMany(Gain::class, ['shop_id' => 'id']); 
   } 

     /**
     * Gets query for [[Awards]].
     *
     * @return \yii\db\ActiveQuery
     */
    public function getAwards()
    {
        return $this->hasMany(Award::class, ['shop_id' => 'id']);
    }
  
}
