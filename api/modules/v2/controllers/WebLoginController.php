<?php

namespace app\modules\v2\controllers;

use app\components\AdminScanLoginService;
use app\modules\v2\models\User;
use bizley\jwt\JwtHttpBearerAuth;
use Yii;
use yii\filters\VerbFilter;
use yii\rest\Controller;
use yii\web\Response;
use yii\web\UnprocessableEntityHttpException;

class WebLoginController extends Controller
{
    public $scanLogin;

    public function init(): void
    {
        parent::init();
        $this->scanLogin = $this->scanLogin ?? new AdminScanLoginService();
    }

    public function behaviors(): array
    {
        $behaviors = parent::behaviors();
        $behaviors['verbs'] = ['class' => VerbFilter::class, 'actions' => ['approve' => ['POST']]];
        $behaviors['authenticator'] = [
            'class' => JwtHttpBearerAuth::class,
            'throwException' => false,
            // Validate the current Bearer on every call. This callback deliberately
            // avoids loginByAccessToken(), which can persist a Yii web identity.
            'auth' => static function ($token) {
                $identity = User::findIdentityByAccessToken($token->toString());
                Yii::$app->user->setIdentity($identity);
                return $identity;
            },
        ];
        return $behaviors;
    }

    public function beforeAction($action): bool
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        Yii::$app->response->headers->set('Cache-Control', 'no-store');
        Yii::$app->user->enableSession = false;
        Yii::$app->user->enableAutoLogin = false;
        Yii::$app->user->setIdentity(null);
        return parent::beforeAction($action);
    }

    public function actionApprove(string $id): array
    {
        $secret = Yii::$app->request->getBodyParam('secret');
        if (!is_string($secret) || !preg_match('/^[a-f0-9]{64}$/D', $secret)) {
            throw new UnprocessableEntityHttpException('扫码登录凭据格式无效');
        }
        $identity = Yii::$app->user->identity;
        if (!$identity instanceof User) { throw new \yii\web\UnauthorizedHttpException('请重新登录微信'); }
        return [
            'success' => true, 'message' => '已确认管理后台登录',
            'data' => $this->scanLogin->approve($id, $secret, (int) $identity->id),
        ];
    }
}
