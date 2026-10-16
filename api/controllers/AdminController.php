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
    public $enableCsrfValidation = false;
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
                    'session' => ['GET'], 'login' => ['POST'], 'refresh' => ['POST'], 'logout' => ['POST'],
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
        // V1/V2 retain their own user component and credentials. Admin requests
        // never restore a browser cookie identity through Yii's user component.
        Yii::$app->user->enableSession = false;
        Yii::$app->user->enableAutoLogin = false;
        if (!parent::beforeAction($action)) {
            return false;
        }
        if (!in_array($action->id, ['session', 'login', 'refresh', 'logout', 'scan-login-create', 'scan-login-status', 'scan-login-consume', 'scan-login-cancel'], true) && $this->auth->getUser() === null) {
            throw new UnauthorizedHttpException('请先登录管理后台');
        }
        return true;
    }

    public function actionSession(): array
    {
        return ['user' => $this->auth->getUser(), 'scanLoginEnabled' => $this->scanLogin->isEnabled(), 'authMode' => 'jwt'];
    }

    public function actionScanLoginCreate(): array { return $this->scanLogin->createTicket(); }
    public function actionScanLoginStatus(string $id): array { return $this->scanLogin->status($id); }
    public function actionScanLoginCancel(string $id): array { return $this->scanLogin->cancel($id); }
    public function actionScanLoginConsume(string $id): array
    {
        return $this->scanLogin->consume($id);
    }

    public function actionLogin(): array
    {
        $body = Yii::$app->request->bodyParams;
        if (!is_array($body) || !is_string($body['username'] ?? null) || !is_string($body['password'] ?? null)
            || strlen($body['username']) > 255 || strlen($body['password']) > 1024) {
            throw new UnprocessableEntityHttpException('请输入用户名和密码');
        }
        return $this->auth->login($body['username'], $body['password']);
    }

    public function actionRefresh(): array
    {
        $refresh = Yii::$app->request->getBodyParam('refreshToken');
        if (!is_string($refresh) || $refresh === '') { throw new UnprocessableEntityHttpException('请提供刷新令牌'); }
        return $this->auth->refresh($refresh);
    }

    public function actionLogout(): array
    {
        $refresh = Yii::$app->request->getBodyParam('refreshToken');
        if ($refresh !== null && !is_string($refresh)) { throw new UnprocessableEntityHttpException('刷新令牌格式不正确'); }
        $this->auth->logout($refresh);
        return ['user' => null, 'authMode' => 'jwt'];
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
