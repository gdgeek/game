<?php

namespace app\modules\v1\models;

use Yii;

use yii\behaviors\TimestampBehavior;
use yii\db\Expression;
/**
 * This is the model class for table "player_token".
 *
 * @property int $id
 * @property string $created_at
 * @property string $updated_at
 * @property string $expires_at
 * @property string $refresh_token
 * @property int $player_id
 *
 * @property Player $player
 */
class PlayerToken extends \yii\db\ActiveRecord
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
        return 'player_token';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['expires_at', 'refresh_token', 'player_id'], 'required'],
            [['created_at', 'updated_at', 'expires_at'], 'safe'],
            [['player_id'], 'integer'],
            [['refresh_token'], 'string', 'max' => 255],
            [['player_id'], 'exist', 'skipOnError' => true, 'targetClass' => Player::class, 'targetAttribute' => ['player_id' => 'id']],
        ];
    }

    public static function GenerateRefreshToken($playerId, $expirySeconds = 86400)
    {

       $playerToken = PlayerToken::find()->where(['player_id' => $playerId])->one();
       
        if(!$playerToken){
            $playerToken = new PlayerToken();
            $playerToken->player_id = $playerId;
        }
        
        return $playerToken;
      
    }
    public function getIsExpired(){
        return strtotime($this->expires_at) < time();
    }
    

    public static function findByRefreshToken($token)
    {
        return static::findOne(['refresh_token' => $token]);
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
            'expires_at' => 'Expires At',
            'refresh_token' => 'Refresh Token',
            'player_id' => 'Player ID',
        ];
    }


    public function refresh($expirySeconds = 86400)
    {
        $this->refresh_token = Yii::$app->security->generateRandomString();
        $this->expires_at = date('Y-m-d H:i:s', time() + $expirySeconds);
        if($this->validate() && $this->save()){
            return $this;
        }
        throw new \yii\web\HttpException(400, 'Invalid parameters'.json_encode($this->errors));
    
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
    public function getUser()
    {
        return $this->hasOne(User::class, ['id' => 'player_id']);
    }
}
