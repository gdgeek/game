<?php

namespace app\modules\v2\controllers;

use app\modules\v2\models\File;
use yii\rest\ActiveController;
use bizley\jwt\JwtHttpBearerAuth;
use yii\filters\auth\CompositeAuth;
use OpenApi\Annotations as OA;
use app\components\MiniProgramFileService;
use app\modules\v2\models\User;
use Yii;
use yii\web\UnauthorizedHttpException;

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
        // Unlocking is only written after a verified WeChat payment.
        unset($actions['create'], $actions['update'], $actions['delete']);
        $actions['view']['findModel'] = function ($id) {
            return (new MiniProgramFileService())->findOwned($id, $this->currentUser());
        };
        $actions['index']['prepareDataProvider'] = function () {
            return new \yii\data\ActiveDataProvider([
                'query' => (new MiniProgramFileService())->ownedQuery($this->currentUser())->orderBy(['id' => SORT_DESC]),
            ]);
        };
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
     *         required=false,
     *         description="兼容旧客户端；文件归属始终使用当前登录账号",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(response=200, description="文件列表")
     * )
     */
    public function actionList($unionid = null)
    {
        // Keep the old query argument compatible, but never use it as authority.
        return (new MiniProgramFileService())->ownedQuery($this->currentUser())->orderBy(['id' => SORT_DESC])->all();
    }

    private function currentUser(): User
    {
        $user = Yii::$app->user->identity;
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('请重新登录');
        }
        return $user;
    }
}
