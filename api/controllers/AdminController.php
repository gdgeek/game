<?php

namespace app\controllers;

use app\components\AdminAuthService;
use app\components\AdminDeviceService;
use app\components\AdminGameCatalog;
use app\components\AdminScanLoginService;
use Yii;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\Response;
use yii\web\UnauthorizedHttpException;
use yii\web\UnprocessableEntityHttpException;

class AdminController extends Controller
{
    public $auth;
    public $devices;
    public $catalog;
    public $scanLogin;

    public function init(): void
    {
        parent::init();
        $this->auth = $this->auth ?? new AdminAuthService();
        $this->scanLogin = $this->scanLogin ?? new AdminScanLoginService(['auth' => $this->auth]);
        $this->catalog = $this->catalog ?? new AdminGameCatalog();
        $this->devices = $this->devices ?? new AdminDeviceService(['catalog' => $this->catalog, 'auth' => $this->auth]);
    }

    public function behaviors(): array
    {
        return [
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'session' => ['GET'], 'login' => ['POST'], 'logout' => ['POST'],
                    'devices' => ['GET'], 'games' => ['GET'], 'set-game' => ['PUT'],
                    'scan-login-create' => ['POST'], 'scan-login-status' => ['GET'],
                    'scan-login-consume' => ['POST'], 'scan-login-cancel' => ['POST'],
                ],
            ],
        ];
    }

    public function beforeAction($action): bool
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        Yii::$app->response->headers->set('Cache-Control', 'no-store');
        Yii::$app->request->csrfCookie = array_merge(Yii::$app->request->csrfCookie, [
            'httpOnly' => true, 'sameSite' => 'Lax', 'secure' => Yii::$app->request->isSecureConnection,
        ]);
        if (!parent::beforeAction($action)) {
            return false;
        }
        if (!in_array($action->id, ['session', 'login', 'logout', 'scan-login-create', 'scan-login-status', 'scan-login-consume', 'scan-login-cancel'], true) && $this->auth->getUser() === null) {
            throw new UnauthorizedHttpException('请先登录管理后台');
        }
        return true;
    }

    public function actionSession(): array
    {
        return ['user' => $this->auth->getUser(), 'csrfToken' => Yii::$app->request->getCsrfToken(), 'scanLoginEnabled' => $this->scanLogin->isEnabled()];
    }

    public function actionScanLoginCreate(): array { return $this->scanLogin->createTicket(); }
    public function actionScanLoginStatus(string $id): array { return $this->scanLogin->status($id); }
    public function actionScanLoginCancel(string $id): array { return $this->scanLogin->cancel($id); }
    public function actionScanLoginConsume(string $id): array
    {
        $user = $this->scanLogin->consume($id);
        return ['user' => $user, 'csrfToken' => Yii::$app->request->getCsrfToken(true)];
    }

    public function actionLogin(): array
    {
        $body = Yii::$app->request->bodyParams;
        if (!is_array($body) || !is_string($body['username'] ?? null) || !is_string($body['password'] ?? null)
            || strlen($body['username']) > 255 || strlen($body['password']) > 1024) {
            throw new UnprocessableEntityHttpException('请输入用户名和密码');
        }
        $user = $this->auth->login($body['username'], $body['password']);
        return ['user' => $user, 'csrfToken' => Yii::$app->request->getCsrfToken(true)];
    }

    public function actionLogout(): array
    {
        $this->auth->logout();
        return ['user' => null, 'csrfToken' => Yii::$app->request->getCsrfToken(true)];
    }

    public function actionDevices(): array
    {
        return $this->devices->getDevices();
    }

    public function actionGames(): array
    {
        return $this->catalog->getGames();
    }

    public function actionSetGame($id): array
    {
        $gameId = Yii::$app->request->getBodyParam('gameId');
        if (!is_int($gameId) || $gameId <= 0) {
            throw new UnprocessableEntityHttpException('gameId 必须为有效的游戏编号');
        }
        return $this->devices->assignGame((int) $id, $gameId);
    }
}
