<?php

namespace app\modules\v2\models;

use Yii;

use yii\behaviors\TimestampBehavior;
use yii\db\Expression;
use yii\db\ActiveRecord;
use app\modules\v2\models\User;
use OpenApi\Annotations as OA;

/**
 * This is the model class for table "device".
 *
 * @property int $id
 * @property string|null $uuid
 * @property string|null $tag
 * @property string $created_at
 * @property string $updated_at
 * @property string|null $ip
 * @property Setup $setup
 *
 * @OA\Schema(
 *     schema="Device",
 *     title="设备",
 *     description="设备信息模型"
 * )
 */
class Device extends ActiveRecord
{

    public function behaviors()
    {
        return [
            [
                'class' => TimestampBehavior::class,
                'attributes' => [
                    \yii\db\BaseActiveRecord::EVENT_BEFORE_INSERT => ['created_at', 'updated_at'],
                    \yii\db\BaseActiveRecord::EVENT_BEFORE_UPDATE => ['updated_at'],
                ],
                'value' => new Expression('NOW()'),
            ]
        ];
    }

    public function fields()
    {
        $fields = parent::fields();

        // Remove fields that you don't want to expose
        //unset($fields['created_at'], $fields['updated_at']);

        return $fields;
    }
    public function extraFields()
    {

        return [
            'admin' => function ($model) {
                //拿到所有control 关联的 user，然后返回user数组，通过查询
                return User::find()
                    ->innerJoin('control', 'control.user_id = user.id')
                    ->where(['control.device_id' => $model->id])
                    ->all();
            },
            'setup' => function ($model) {
                if($model->setup == null){
                    $setup = new Setup();
                    $setup->device_id = $model->id;
                    $setup->save();
                    return $setup;
                }
                return $model->setup;
            }
        ];
    }

    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'device';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['created_at', 'updated_at'], 'safe'],
            [['uuid', 'tag', 'ip'], 'string', 'max' => 255],
            [['uuid'], 'unique'],
            [['tag'], 'unique'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'uuid' => 'Uuid',
            'tag' => 'Tag',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
            'ip' => 'Ip',
        ];
    }

   

     /**
     * Gets query for [[Setup]].
     *
     * @return \yii\db\ActiveQuery
     */
    public function getSetup()
    {
        return $this->hasOne(Setup::class, ['device_id' => 'id']);
    }
}
