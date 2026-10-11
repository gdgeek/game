<?php

namespace app\modules\v2\helper;

use app\modules\v2\models\User;
use bizley\jwt\JwtHttpBearerAuth;
use Yii;

/** Strict v2 Bearer identities never restore or persist a browser session. */
class DeviceBearerAuth extends JwtHttpBearerAuth
{
    public function init(): void
    {
        parent::init();
        $this->throwException = false;
        $this->auth = static function ($token) {
            $identity = User::findIdentityByAccessToken($token->toString());
            Yii::$app->user->setIdentity($identity);
            return $identity;
        };
    }

    public function authenticate($user, $request, $response): ?\yii\web\IdentityInterface
    {
        $user->enableSession = false;
        $user->enableAutoLogin = false;
        $user->identityClass = User::class;
        $user->setIdentity(null);
        return parent::authenticate($user, $request, $response);
    }
}
