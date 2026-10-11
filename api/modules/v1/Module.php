<?php

namespace app\modules\v1;

use app\modules\v1\models\User;
use Yii;

class Module extends \yii\base\Module
{
    public function beforeAction($action)
    {
        // The application defaults to v2 identities. Every v1 Bearer filter
        // must resolve the player table and must not read/write browser cookies.
        $user = Yii::$app->user;
        $user->enableSession = false;
        $user->enableAutoLogin = false;
        $user->setIdentity(null);
        $user->identityClass = User::class;
        return parent::beforeAction($action);
    }
}
