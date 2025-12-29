<?php

namespace app\modules\v2\models;

use yii\base\Model;
use yii\data\ActiveDataProvider;
use app\modules\v2\models\File;
use OpenApi\Annotations as OA;

/**
 * FileSearch represents the model behind the search form of `app\modules\v2\models\File`.
 *
 * @OA\Schema(
 *     schema="FileSearch",
 *     title="文件搜索",
 *     description="文件搜索模型"
 * )
 */
class FileSearch extends File
{
    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['id', 'size', 'unlocked'], 'integer'],
            [['unionid', 'key', 'type', 'md5', 'bucket', 'created_at'], 'safe'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function scenarios()
    {
        // bypass scenarios() implementation in the parent class
        return Model::scenarios();
    }

    /**
     * Creates data provider instance with search query applied
     *
     * @param array $params
     *
     * @return ActiveDataProvider
     */
    public function search($params)
    {
        $query = File::find();

        // add conditions that should always apply here

        $dataProvider = new ActiveDataProvider([
            'query' => $query,
        ]);

        $this->load($params);

        if (!$this->validate()) {
            // uncomment the following line if you do not want to return any records when validation fails
            // $query->where('0=1');
            return $dataProvider;
        }

        // grid filtering conditions
        $query->andFilterWhere([
            'id' => $this->id,
            'size' => $this->size,
            'created_at' => $this->created_at,
            'unlocked' => $this->unlocked,
        ]);

        $query->andFilterWhere(['like', 'unionid', $this->unionid])
            ->andFilterWhere(['like', 'key', $this->key])
            ->andFilterWhere(['like', 'type', $this->type])
            ->andFilterWhere(['like', 'md5', $this->md5])
            ->andFilterWhere(['like', 'bucket', $this->bucket]);

        return $dataProvider;
    }
}
