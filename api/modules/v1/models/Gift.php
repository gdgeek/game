<?php

namespace app\modules\v1\models;

use yii\behaviors\TimestampBehavior;
use yii\db\Expression;
use Yii;

/**
 * This is the model class for table "gift".
 *
 * @property int $id
 * @property string $created_at
 * @property string|null $updated_at
 * @property string|null $info
 * @property int $award_id
 * @property string|null $uuid
 * @property string|null $tag
 * @property string|null $picture
 *
 * @property Award $award
 * @property Gain[] $gains
 */
class Gift extends \yii\db\ActiveRecord
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
        return 'gift';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['award_id'], 'required'],
            [['created_at', 'updated_at', 'info'], 'safe'],
            [['award_id'], 'integer'],
            [['uuid', 'tag', 'picture'], 'string', 'max' => 255],
            [['uuid'], 'unique'],
            [['award_id'], 'exist', 'skipOnError' => true, 'targetClass' => Award::class, 'targetAttribute' => ['award_id' => 'id']],
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
            'info' => 'Info',
            'award_id' => 'Award ID',
            'uuid' => 'Uuid',
            'tag' => 'Tag',
            'picture' => 'Picture',
        ];
    }

    /**
     * Gets query for [[Award]].
     *
     * @return \yii\db\ActiveQuery
     */
    public function getAward()
    {
        return $this->hasOne(Award::class, ['id' => 'award_id']);
    }

    /**
     * Gets query for [[Gains]].
     *
     * @return \yii\db\ActiveQuery
     */
    public function getGains()
    {
        return $this->hasMany(Gain::class, ['gift_id' => 'id']);
    }
}
