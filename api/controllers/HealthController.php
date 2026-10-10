<?php

namespace app\controllers;

use app\components\HealthService;
use Yii;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\Response;

class HealthController extends Controller
{
    public function behaviors(): array
    {
        return [
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => ['index' => ['GET', 'HEAD']],
            ],
        ];
    }

    public function actionIndex(): ?array
    {
        $service = Yii::$app->has('healthService') ? Yii::$app->get('healthService') : new HealthService();
        $result = $service->check();
        $response = Yii::$app->response;
        $response->format = Response::FORMAT_JSON;
        $response->statusCode = $result['status'] === 'healthy' ? 200 : 503;
        $response->headers->set('Cache-Control', 'no-store');

        if (Yii::$app->request->isHead) {
            $response->content = '';
            return null;
        }

        return $result;
    }
}
