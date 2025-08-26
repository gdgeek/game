<?php

namespace app\modules\v2\models;

use Yii;

/**
 * This is the model class for table "device".
 *
 * @property int $id
 * @property string|null $uuid
 * @property string|null $tag
 * @property string $created_at
 * @property string $updated_at
 * @property string|null $ip
 * @property string|null $setup
 */
class Device extends \yii\db\ActiveRecord
{
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
            [['created_at', 'updated_at', 'setup'], 'safe'],
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
            'setup' => 'Setup',
        ];
    }
}
