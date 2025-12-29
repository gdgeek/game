<?php
namespace app\modules\v2\controllers;
use Yii;
use yii\rest\Controller;
use app\modules\v2\models\User;
use app\modules\v2\helper\RootAuth;
use app\modules\v2\models\Control;
use yii\web\Response;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *     name="管理员",
 *     description="管理员相关接口"
 * )
 */
class RootController extends Controller
{

  public function behaviors()
  {

    $behaviors = parent::behaviors();
    $behaviors['authenticator'] = [
      'class' => RootAuth::class,
    ];
    return $behaviors;
  }
  public function actionAssign()
  {
    $phone = Yii::$app->request->post('phone');
    $device_id = Yii::$app->request->post('device_id');
    $user = User::findOne(['tel' => $phone]);
    if($user)
    {
      $control = new Control();
      $control->device_id = $device_id;
      $control->user_id = $user->id;
      $control->save();
      $user->save(); // to trigger beforeSave and update role
      return ['message' => 'Device assigned successfully', 'success' => true, 'data' => $control];
    }

    throw new \yii\web\NotFoundHttpException('User not found');
  }




}