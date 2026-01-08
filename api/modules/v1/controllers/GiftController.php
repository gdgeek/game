<?php

namespace app\modules\v1\controllers;

use Yii;
use app\modules\v1\helper\DeviceFingerprintAuth;
use yii\rest\ActiveController;
use yii\data\ActiveDataProvider;
use bizley\jwt\JwtHttpBearerAuth;
use yii\filters\auth\CompositeAuth;

class GiftController extends ActiveController
{
    public $modelClass = 'app\modules\v1\models\Gift';
    public function behaviors()
    {

        $behaviors = parent::behaviors();
        $behaviors['authenticator'] = [
        'class' => CompositeAuth::class,
        'authMethods' => [
            JwtHttpBearerAuth::class,
        ],
        'except' => ['options'],
        ];

        return $behaviors;
    }

    public function actions()
    {
        $actions = parent::actions();

        // 禁用默认的 `index` 动作
        unset($actions['index']);

        return $actions;
    }
    public function actionIndex()
    {
        $awardId = Yii::$app->request->get('award_id');

        $query = $this->modelClass::find();

        if ($awardId !== null) {
            $query->andWhere(['award_id' => $awardId]);
        }

        return new ActiveDataProvider([
          'query' => $query,
        ]);
    }
}
