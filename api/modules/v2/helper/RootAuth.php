<?php
namespace app\modules\v2\helper;

use Yii;
use yii\filters\auth\AuthMethod;
use yii\web\UnauthorizedHttpException;
use app\modules\v2\models\Device;
class RootAuth extends \bizley\jwt\JwtHttpBearerAuth
{

  /**
   * @inheritdoc
   */
  public function authenticate($user, $request, $response): ?\yii\web\IdentityInterface
  {
    $identity = parent::authenticate($user, $request, $response);

    if ($identity instanceof \app\modules\v2\models\User && $identity->role == "root") {
      return $identity;
    }
    return null;
  }
}