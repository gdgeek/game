<?php

namespace app\modules\v2\controllers;

use yii\rest\ActiveController;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *     name="玩家",
 *     description="玩家管理接口"
 * )
 */
class PlayerController extends ActiveController
{
  public $modelClass = 'app\modules\v2\models\User';
  public function behaviors()
  {
    $behaviors = parent::behaviors();
    /*
       //RootAuth
       $behaviors['authenticator'] = [
         'class' => RootAuth::class,
         'except' => ['options'],
       ];
  */
    return $behaviors;
  }

  //列表所有不是guest的用户
  public function actionAdmin()
  {
    $query = $this->modelClass::find()->where(['!=', 'role', 'user']);
    return $query->all();
  }
}
