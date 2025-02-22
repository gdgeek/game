<?php
namespace app\modules\v1\models;
use yii\db\ActiveRecord;
use yii\web\IdentityInterface;
use yii\behaviors\TimestampBehavior;
use yii\db\Expression;
use Yii;
class User extends yii\db\ActiveRecord implements IdentityInterface
{
    public static function findIdentity($id)
    {
        return static::findOne(['id' => $id]);
    }

    public static function findIdentityByAccessToken($token, $type = null)
    {
        $claims = Yii::$app->jwt->parse($token)->claims();
        $uid = $claims->get('uid');
        $user = static::findIdentity($uid);
        return $user;
    }


    public function token()
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone(\Yii::$app->timeZone));
        $expires = $now->modify('+3 hour');
        return [
            'accessToken' => $this->generateAccessToken($now, $expires),
            'expires' => $expires->format('Y-m-d H:i:s'),
            'refreshToken' => $this->generateAccessToken($now, $now->modify('+24 hour')),
        ];
    }
    public function getRole(){
      
        $role = 'player';
        $manager = $this->manager;
        if($manager != null){
            $role = $manager->type;
        }
        /*else if($this->tel=='15000159790' || $this->tel=='15601920021'){

            $manager = new Manager();
            $manager->type = 'root';
            $manager->player_id = $this->id;
            $manager->save();
            //$this->manager = $manager;
            $role = $manager->type;
        }*/
       
        return $role;
    }
    public function getPlayer(){
       
        return [
            'id'=> $this->id,
            'openId'=> $this->openId,
            'tel'=> $this->tel,  
            'recharge'=> $this->recharge,
            'nickname'=> $this->nickname,
            'avatar'=> $this->avatar,
            'points'=> $this->points,
            'cost'=> $this->cost,
            'times'=> $this->times,
            'grade'=> $this->grade,
            'role'=> $this->role,
        ];
    }
    //人员管理 root
    //系统管理 admin
    //shop 管理，店长
    // 运行系统的人员 staff
    public function getId()
    {
        return $this->id;
    }

    public function getAuthKey()
    {
        $token = PlayerToken::find()->where(['player_id'=>$this->id])->one();
        if($token == null){
            $token = PlayerToken::GenerateRefreshToken($this->id);
        }
        return  $token->refresh_token;
    }  
    
    public function validateAuthKey($authKey)
    {
        return $this->getAuthKey() === $authKey;
    }
    

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
     * @param \Lcobucci\JWT\Token $token
     */
    public static function findByToken($token)
    {
        $claims = Yii::$app->jwt->parse($token)->claims();
        $uid = $claims->get('uid');
        $user = static::findIdentity( $uid);
        // $user->token = $token;
        return $user;
    }

    //生成token
    public function generateAccessToken($now = new \DateTimeImmutable('now', new \DateTimeZone(\Yii::$app->timeZone)), $expires = $now->modify('+3 hour'))
    {
        $token = Yii::$app->jwt->getBuilder()
            ->issuedBy(Yii::$app->request->hostInfo)
            ->issuedAt($now) // Configures the time that the token was issue (iat claim)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($expires) // Configures the expiration time of the token (exp claim)
            ->withClaim('uid', $this->id) // Configures a new claim, called "uid"
            ->getToken(
                Yii::$app->jwt->getConfiguration()->signer(),
                Yii::$app->jwt->getConfiguration()->signingKey()
            );
        return (string) $token->toString();
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

   /**
    * Gets query for [[Managers]]. 
    * 
    * @return \yii\db\ActiveQuery 
    */ 
   public function getManager() 
   { 
      return $this->hasOne(Manager::class, ['player_id' => 'id']);//->one(); 
     /*
       if($manager == null && ($this->tel=='15000159790' || $this->tel=='15601920021')){

           $manager = new Manager();
           $manager->type = 'root';
           $manager->player_id = $this->id;
           if($manager->validate()){
                $manager->save();
           }else{
               throw new \yii\web\HttpException(400, 'Invalid parameters'.json_encode($manager->errors));
           }
           return $manager;
          
       }
       return $manager;*/
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


    
}