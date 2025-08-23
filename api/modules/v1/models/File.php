<?php

namespace app\modules\v1\models;

use Yii;

/**
 * This is the model class for table "file".
 *
 * @property int $id
 * @property string|null $unionid
 * @property string $key
 * @property string|null $type
 * @property string|null $md5
 * @property int|null $size
 * @property string|null $bucket
 * @property string $created_at
 */
class File extends \yii\db\ActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'file';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['key'], 'required'],
            [['size'], 'integer'],
            [['created_at'], 'safe'],
            [['unionid', 'key', 'type', 'md5', 'bucket'], 'string', 'max' => 255],
            [['key'], 'unique'],
            [['md5'], 'unique'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'unionid' => 'Unionid',
            'key' => 'Key',
            'type' => 'Type',
            'md5' => 'Md5',
            'size' => 'Size',
            'bucket' => 'Bucket',
            'created_at' => 'Created At',
        ];
    }
}
