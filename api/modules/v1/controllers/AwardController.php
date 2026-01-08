<?php

namespace app\modules\v1\controllers;

use Yii;
use app\modules\v1\helper\DeviceFingerprintAuth;
use yii\rest\ActiveController;
use yii\data\ActiveDataProvider;
use bizley\jwt\JwtHttpBearerAuth;
use yii\filters\auth\CompositeAuth;

class AwardController extends ActiveController
{
    public $modelClass = 'app\modules\v1\models\Award';
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
        $shopId = Yii::$app->request->get('shop_id');

        $query = $this->modelClass::find();

        if ($shopId !== null) {
            $query->andWhere(['shop_id' => $shopId]);
        }

        return new ActiveDataProvider([
            'query' => $query,
        ]);
    }
}
