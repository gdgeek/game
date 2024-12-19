<?php

namespace app\modules\v1\models;

use Yii;

/**
 * This is the model class for table "daily".
 *
 * @property int $id
 * @property int $shop_id
 * @property float|null $income
 * @property float|null $expenses
 * @property string $date
 *
 * @property Shop $shop
 */
class Daily extends \yii\db\ActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'daily';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['shop_id', 'date'], 'required'],
            [['shop_id'], 'integer'],
            [['income', 'expenses'], 'number'],
            [['date'], 'safe'],
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
            'income' => 'Income',
            'expenses' => 'Expenses',
            'date' => 'Date',
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
