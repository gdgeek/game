<?php

namespace app\modules\v1\models;

use Yii;

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
            [['created_at', 'player_id', 'device_id'], 'required'],
            [['created_at', 'updated_at', 'award', 'startTime', 'endTime', 'game'], 'safe'],
            [['points', 'player_id', 'device_id'], 'integer'],
            [['status'], 'string'],
            [['device_id'], 'exist', 'skipOnError' => true, 'targetClass' => Device::class, 'targetAttribute' => ['device_id' => 'id']],
            [['player_id'], 'exist', 'skipOnError' => true, 'targetClass' => Player::class, 'targetAttribute' => ['player_id' => 'id']],
        ];
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
            'award' => 'Award',
            'points' => 'Points',
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
        $game = new Game();
        $game->award->points = $this->points;
        
        if(isset($this->award['s'])){
            $game->award->s = $this->award['s'];
        }
        if(isset($this->award['m'])){
            $game->award->m = $this->award['m'];
        }
        if(isset($this->award['l'])){
            $game->award->l = $this->award['l'];
        }
        if(isset($this->award['xl'])){
            $game->award->xl = $this->award['xl'];
        }
       // $game->award = $this->award;
        $game->secodes = 60;
        return $game;
    }
}
