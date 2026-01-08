<?php

namespace app\modules\v1\models;

use yii\behaviors\TimestampBehavior;
use yii\db\Expression;
use Yii;

/**
 * This is the model class for table "player".
 *
 * @property int $id
 * @property string $tel
 * @property string|null $nickname
 * @property float|null $recharge
 * @property float|null $give
 * @property float|null $cost
 * @property int|null $times
 * @property int|null $grade
 * @property int|null $points
 * @property string $created_at
 * @property string|null $updated_at
 * @property string|null $openId
 * @property string|null $avatar
 * @property string|null $info
 *
 * @property Record[] $records
 */
class Player extends \yii\db\ActiveRecord
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
        return 'player';
    }



    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['tel'], 'required'],
            [['recharge', 'cost', 'give'], 'number'],
            [['times', 'grade', 'points'], 'integer'],
            [['created_at', 'updated_at', 'info'], 'safe'],
            [['tel', 'nickname', 'openId', 'avatar'], 'string', 'max' => 255],
            [['tel'], 'unique'],
            [['openId'], 'unique'],
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
            'give' => 'Give',
            'cost' => 'Cost',
            'times' => 'Times',
            'grade' => 'Grade',
            'points' => 'Points',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
            'openId' => 'Openid',
            'avatar' => 'Avatar',
            'info' => 'Info',
        ];
    }
    public function extraFields()
    {
        return [
            'role' => function () {
                return $this->role;
            },
        ];
    }
   /**
    * Gets query for [[Managers]].
    *
    * @return \yii\db\ActiveQuery
    */
    public function getManager()
    {
        return $this->hasOne(Manager::class, ['player_id' => 'id']);
    }

    /**
     * Gets query for [[Records]].
     *
     * @return \yii\db\ActiveQuery
     */
    public function getRecords()
    {
        return $this->hasMany(Record::class, ['player_id' => 'id']);
    }


    public function getRole()
    {

        $role = 'player';
        $manager = $this->manager;
        if ($manager != null) {
            $role = $manager->type;
        }

        return $role;
    }
}
