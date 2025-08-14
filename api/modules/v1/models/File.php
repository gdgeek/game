<?php

namespace app\modules\v1\models;
use yii\db\BaseActiveRecord;
use yii\behaviors\AttributesBehavior;
use yii\behaviors\BlameableBehavior;
use yii\behaviors\TimestampBehavior;
use yii\db\Expression;
use yii\caching\TagDependency;
use yii\helpers\ArrayHelper;
use Yii;

/**
 * This is the model class for table "file".
 *
 * @property int $id
 * @property string $url
 * @property string|null $md5
 * @property string|null $type
 * @property string|null $etag
 * @property string|null $size
 * @property string $created_at
 */
class File extends \yii\db\ActiveRecord
{


    public function behaviors()
    {
        return [
            [
                'class' => TimestampBehavior::class,
                'attributes' => [
                    BaseActiveRecord::EVENT_BEFORE_INSERT => ['created_at'],
                ],
                'value' => new Expression('NOW()'),
            ],
            [
                'class' => AttributesBehavior::class,
                'attributes' => [
                    'size' => [
                        BaseActiveRecord::EVENT_BEFORE_INSERT => [$this, 'getFileSize'],
                    ],
                    'type' => [
                        BaseActiveRecord::EVENT_BEFORE_INSERT => [$this, 'getFileType'],
                    ],

                ],
            ],
        ];
    }


    private $header = null;
    private function getFileHeader()
    {
        if (isset($this->url) && $this->header == null) {
            $this->header = get_headers($this->url, true);
        }
        return $this->header;
    }
    public function getFileSize()
    {
        $header = $this->getFileHeader();
        if (isset($header)) {
            $filesize = round(ArrayHelper::getValue($header, 'Content-Length', 0), 2);
            return $filesize;
        }
        return null;
    }
    public function getFileETag()
    {
        $header = $this->getFileHeader();
        if (isset($header)) {
            return json_decode(ArrayHelper::getValue($header, 'ETag'));
        }
        return null;
    }
    public function getFileType()
    {
        $header = $this->getFileHeader();
        if (isset($header)) {
            return ArrayHelper::getValue($header, 'Content-Type', 'application/octet-stream');
        }
        return 'application/octet-stream';

    }

    
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
            [['url'], 'required'],
            [['etag', 'created_at'], 'safe'],
            [['url', 'md5', 'type', 'size'], 'string', 'max' => 255],
            [['url'], 'unique'],
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
            'url' => 'Url',
            'md5' => 'Md5',
            'type' => 'Type',
            'etag' => 'Etag',
            'size' => 'Size',
            'created_at' => 'Created At',
        ];
    }
}
