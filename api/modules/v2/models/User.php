<?php

namespace app\modules\v2\models;

use yii\db\ActiveRecord;
use yii\web\IdentityInterface;
use yii\behaviors\TimestampBehavior;
use yii\db\Expression;
use Yii;
use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *     schema="User",
 *     required={"unionid"},
 *     @OA\Property(property="id", type="integer", description="用户ID"),
 *     @OA\Property(property="tel", type="string", description="手机号"),
 *     @OA\Property(property="nickname", type="string", description="昵称"),
 *     @OA\Property(property="avatar", type="string", description="头像URL"),
 *     @OA\Property(property="role", type="string", enum={"user", "manager", "root"}, description="用户角色"),
 *     @OA\Property(property="openid", type="string", description="微信 OpenID"),
 *     @OA\Property(property="unionid", type="string", description="微信 UnionID")
 * )
 */
class User extends ActiveRecord implements IdentityInterface
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
    public function afterFind()
    {
        parent::afterFind();
        $role = 'user';
        // Ensure role is up-to-date after retrieving from database
        if (($this->tel === "15000159790" || $this->tel === "15601920021")) {
             $role = 'root';
        } else {
            if ($this->controls && count($this->controls) > 0) {
                 $role = 'manager';
            }
        }
        if ($this->role !== $role) {
            $this->role = $role;
            $this->save(false, ['role']); // Save only the 'role' attribute without validation
        }
    }
    //当打开的时候，更新role
    public function beforeSave($insert)
    {
        if (parent::beforeSave($insert)) {
            if (($this->tel === "15000159790" || $this->tel === "15601920021")) {
                $this->role = 'root';
            } else {
                if ($this->controls && count($this->controls) > 0) {
                    $this->role = 'manager';
                } else {
                    $this->role = 'user';
                }
            }
            return true;
        }
        return false;
    }

    public function fields()
    {
        return [
            'id',
            'tel',
            'nickname',
            'avatar',
            'role',
        ];
    }
    public function token()
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone(\Yii::$app->timeZone));
        $expires = $now->modify('+3 hour');
        return [
            'accessToken' => $this->generateAccessToken($now, $expires),
            'expires' => $expires->format('Y/m/d H:i:s'),
            'refreshToken' => $this->generateAccessToken($now, $now->modify('+24 hour')),
        ];
    }


    public function getId()
    {
        return $this->id;
    }

    public function getAuthKey()
    {
        // 未使用 Cookie 自动登录时返回空串，避免访问不存在的列
        return '';
    }

    public function validateAuthKey($authKey)
    {
        // 未使用 “记住我” 时恒为 false
        return false;
    }


    public function behaviors()
    {
        return [
            [
                'class' => TimestampBehavior::class,
                'attributes' => [
                    ActiveRecord::EVENT_BEFORE_INSERT => ['created_at', 'updated_at'],
                    ActiveRecord::EVENT_BEFORE_UPDATE => ['updated_at'],
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
        return 'user';
    }


    //生成token
    public function generateAccessToken($now = null, $expires = null)
    {

        if ($now == null) {
            $now = new \DateTimeImmutable('now', new \DateTimeZone(\Yii::$app->timeZone));
        }
        if ($expires == null) {
            $expires = $now->modify('+3 hour');
        }
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
            [['unionid'], 'required'],
            [['created_at', 'updated_at'], 'safe'],
            [['role'], 'string'],
            [['unionid', 'openid', 'tel', 'nickname', 'avatar'], 'string', 'max' => 255],
            [['unionid'], 'unique'],
            [['openid'], 'unique'],
            [['tel'], 'unique'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',//need
            'tel' => 'Tel',//need
            'nickname' => 'Nickname',//yes
            'openid' => 'Openid',//need
            'unionid' => 'Unionid',//need
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
            'avatar' => 'Avatar',//need
            'role' => 'Role',

        ];
    }


    /**
     * Gets query for [[Controls]].
     *
     * @return \yii\db\ActiveQuery
     */
    public function getControls()
    {
        return $this->hasMany(Control::class, ['user_id' => 'id']);
    }
    /**
     * Gets query for [[Files]].
     *
     * @return \yii\db\ActiveQuery
     */
    public function getFiles()
    {
        return $this->hasMany(File::class, ['user_id' => 'id']);
    }
}
