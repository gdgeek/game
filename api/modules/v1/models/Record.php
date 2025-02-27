<?php

namespace app\modules\v1\models;

use Yii;

use yii\behaviors\TimestampBehavior;
use yii\db\Expression;
/**
 * This is the model class for table "record".
 *
 * @property int $id
 * @property string $created_at
 * @property string|null $updated_at
 * @property string|null $award
 * @property int|null $points
 * @property string|null $startTime
 * @property string|null $endTime
 * @property int $player_id
 * @property int $device_id
 * @property string $status
 * @property string|null $game
 *
 * @property Device $device
 * @property Player $player
 */
class Record extends \yii\db\ActiveRecord
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
        return 'record';
    }
    
    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['player_id', 'device_id'], 'required'],
            [['created_at', 'updated_at', 'startTime', 'endTime', 'game'], 'safe'],
            [['player_id', 'device_id'], 'integer'],
            [['status'], 'string'],
            [['device_id'], 'exist', 'skipOnError' => true, 'targetClass' => Device::class, 'targetAttribute' => ['device_id' => 'id']],
            [['player_id'], 'exist', 'skipOnError' => true, 'targetClass' => Player::class, 'targetAttribute' => ['player_id' => 'id']],
        ];
    }
    public function extraFields()
    {
        return [
            'device'=> function(){
                return $this->device;
            },
            'player'=>function(){
                return $this->user->player;
            },
        ];
    }


    public function getUser()
     {
        return $this->hasOne(User::class, ['id' => 'player_id']);

     }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
       //     'award' => 'Award',
         //   'points' => 'Points',
            'startTime' => 'Start Time',
            'endTime' => 'End Time',
            'player_id' => 'Player ID',
            'device_id' => 'Device ID',
            'status' => 'Status',
            'game' => 'Game',
        ];
    }

    /**
     * Gets query for [[Device]].
     *
     * @return \yii\db\ActiveQuery
     */
    public function getDevice()
    {
        return $this->hasOne(Device::class, ['id' => 'device_id']);
    }

    public function getShop()
    {
        return $this->hasOne(Shop::class, ['id' => 'shop_id'])->via('device');
    }
    /**
     * Gets query for [[Player]].
     *
     * @return \yii\db\ActiveQuery
     */
    public function getPlayer()
    {
        return $this->hasOne(Player::class, ['id' => 'player_id']);
    }
  
    public function getTest(){
        $game = new Game(10,10);
        
      //  $game->award->points = 12;
        
     
        return $game;
    }
}
