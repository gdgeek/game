<?php

namespace app\modules\v1\models;

use Yii;

/**
 * This is the model class for table "record".
 *
 * @property int $id
 * @property int $device_id
 * @property int $player_id
 * @property string $created_at
 * @property string|null $updated_at
 * @property string|null $gift
 * @property string|null $award
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
            [['device_id', 'player_id', 'created_at'], 'required'],
            [['device_id', 'player_id'], 'integer'],
            [['created_at', 'updated_at', 'gift', 'award'], 'safe'],
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
            'device_id' => 'Device ID',
            'player_id' => 'Player ID',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
            'gift' => 'Gift',
            'award' => 'Award',
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
}
