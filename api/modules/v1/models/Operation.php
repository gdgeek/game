<?php

namespace app\modules\v1\models;

use Yii;

/**
 * This is the model class for table "operation".
 *
 * @property int $id
 * @property int $shop_id
 * @property int $pool
 * @property int $income
 * @property int $turnover
 *
 * @property Shop $shop
 */
class Operation extends \yii\db\ActiveRecord
{

    public function fields()
    {
        $fields = parent::fields();
        unset($fields['shop_id']);
        unset($fields['id']);
        return $fields;
    }
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'operation';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['shop_id'], 'required'],
            [['shop_id', 'pool', 'income', 'turnover'], 'integer'],
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
            'pool' => 'Pool',
            'income' => 'Income',
            'turnover' => 'Turnover',
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
