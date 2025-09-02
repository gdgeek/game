<?php

namespace app\modules\v2\models;

use yii\base\Model;
use yii\data\ActiveDataProvider;
use app\modules\v2\models\Device;

/**
 * DeviceSearch represents the model behind the search form of `app\modules\v2\models\Device`.
 */
class DeviceSearch extends Device
{
    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['id'], 'integer'],
            [['uuid', 'tag', 'created_at', 'updated_at', 'ip', 'setup'], 'safe'],
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
        $query = Device::find();

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
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ]);

        $query->andFilterWhere(['like', 'uuid', $this->uuid])
            ->andFilterWhere(['like', 'tag', $this->tag])
            ->andFilterWhere(['like', 'ip', $this->ip])
            ->andFilterWhere(['like', 'setup', $this->setup]);

        return $dataProvider;
    }
}
