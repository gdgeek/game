<?php

namespace app\modules\v2\controllers;

use app\modules\v2\models\File;
use yii\rest\ActiveController;
use app\modules\v2\models\FileSearch;
use bizley\jwt\JwtHttpBearerAuth;
use yii\filters\auth\CompositeAuth;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *     name="文件",
 *     description="文件管理接口"
 * )
 */
class FileController extends ActiveController
{
    public $modelClass = 'app\modules\v2\models\File';
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
        unset($actions['delete']);
        return $actions;
    }

    /**
     * @OA\Get(
     *     path="/v2/files/list",
     *     tags={"文件"},
     *     summary="获取文件列表",
     *     @OA\Parameter(
     *         name="unionid",
     *         in="query",
     *         required=true,
     *         description="用户 UnionID",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(response=200, description="文件列表")
     * )
     */
    public function actionList($unionid)
    {
        $searchModel = new FileSearch();
        $dataProvider = $searchModel->search(['FileSearch' => ['unionid' => $unionid]]);
        return $dataProvider->getModels();
    }
}
