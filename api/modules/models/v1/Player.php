<?php

namespace app\modules\models\v1;

use Yii;

/**
 * This is the model class for table "player".
 *
 * @property int $id
 * @property string $tel
 * @property string|null $nickname
 * @property float|null $recharge
 * @property float|null $cost
 * @property int|null $times
 * @property int|null $grade
 * @property int|null $points
 */
class Player extends \yii\db\ActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'player';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['tel'], 'required'],
            [['recharge', 'cost'], 'number'],
            [['times', 'grade', 'points'], 'integer'],
            [['tel', 'nickname'], 'string', 'max' => 255],
            [['tel'], 'unique'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'tel' => 'Tel',
            'nickname' => 'Nickname',
            'recharge' => 'Recharge',
            'cost' => 'Cost',
            'times' => 'Times',
            'grade' => 'Grade',
            'points' => 'Points',
        ];
    }
}
