<?php

namespace app\modules\v2\controllers;
use app\modules\v2\models\Control;
use app\modules\v2\models\File;
use yii\rest\ActiveController;
use app\modules\v2\models\FileSearch;
use app\modules\v2\helper\RootAuth;
use Yii;
use app\modules\v2\models\Setup;

use bizley\jwt\JwtHttpBearerAuth;
use yii\filters\auth\CompositeAuth;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *     name="设置",
 *     description="系统设置接口"
 * )
 */
class SetupController extends ActiveController
{
  public $modelClass = 'app\modules\v2\models\Setup';
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

   
}
