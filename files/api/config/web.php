<?php

$params = require __DIR__ . '/params.php';
$db = require __DIR__ . '/db.php';

$config = [
    'id' => 'restful',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'controllerNamespace' => 'app\controllers',
    'aliases' => [
        '@bower' => '@vendor/bower-asset',
        '@npm'   => '@vendor/npm-asset',
    ],
    'modules' => [
        'v1' => [
            'class' => 'app\modules\v1\Module',
        ],
    ],
    'components' => [
        'request' => [
            // !!! insert a secret key in the following (if it is empty) - this is required by cookie validation
            'cookieValidationKey' => '1IGWolYN-GxNJpfxx84J24XhP2iFh4GZ',
        ],
        'cache' => [
            'class' => 'yii\caching\FileCache',
        ],
        'helper' => [
            'class' => 'app\components\Helper',
        ],
        'user' => [
            'identityClass' => 'app\models\User',
            'enableAutoLogin' => true,
        ],
        'errorHandler' => [
            'errorAction' => 'site/error',
        ],
        'mailer' => [
            'class' => \yii\symfonymailer\Mailer::class,
            'viewPath' => '@app/mail',
            // send all mails to a file by default.
            'useFileTransport' => true,
        ],
        'log' => [
            'traceLevel' => YII_DEBUG ? 3 : 0,
            'targets' => [
                [
                    'class' => 'yii\log\FileTarget',
                    'levels' => ['error', 'warning'],
                ],
            ],
        ],
        'db' => $db,
        
        'urlManager' => [
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'enableStrictParsing' => true,
            'rules' => [
                
                [
                    'pattern' => 'apple-app-site-association',
                    'route' => 'site/apple-app-site-association',
                    'suffix' => ''
                ],
                [
                    'class' => 'yii\rest\UrlRule',
                    'controller' => 'v1/player',
                    'pluralize' => false,
                    'extraPatterns' => [
                        'GET sign-in' => 'sign-in',
                        'GET sign-up' => 'sign-up',
                        'POST sign-in' => 'sign-in',
                        'POST sign-up' => 'sign-up',
                    ],
                ],
                [
                    'class' => 'yii\rest\UrlRule',
                    'controller' => 'v1/game',
                    'pluralize' => false,
                    'extraPatterns' => [
                        'GET device' => 'device',
                        'POST device' => 'device',
                        'GET ready' => 'ready',
                        'POST ready' => 'ready',
                        'GET start' => 'start',
                        'POST start' => 'start',
                        'GET finish' => 'finish',
                        'POST finish' => 'finish',
                    ],
                ],
                [
                    'class' => 'yii\rest\UrlRule',
                    'controller' => 'v1/helper',
                    'pluralize' => false,
                    'extraPatterns' => [
                        'GET print' => 'print',
                        'GET test' => 'test',
                    ],
                ],
                [
                    'class' => 'yii\rest\UrlRule',
                    'controller' => 'v1/manager',
                    'pluralize' => false,
                    'extraPatterns' => [
                        'POST login' => 'login',
                        'GET login' => 'login',
                    ],
                ],
                [
                    'class' => 'yii\rest\UrlRule',
                    'controller' => 'v1/device',
                    'pluralize' => false,
                ],
            ],
        ],
        
    ],
    'params' => $params,
];
/*

  public function actionDeviceRegister(){
    

  }
  public function actionGameReady(){

  }
  public function actionGameStart(){

  }
  public function actionGameOver(){

    
*/

if (YII_ENV_DEV) {
    // configuration adjustments for 'dev' environment
    $config['bootstrap'][] = 'debug';
    $config['modules']['debug'] = [
        'class' => 'yii\debug\Module',
        // uncomment the following to add your IP if you are not connecting from localhost.
        //'allowedIPs' => ['127.0.0.1', '::1'],
    ];
    
    $config['bootstrap'][] = 'gii';
    $config['modules']['gii'] = [
        'class' => 'yii\gii\Module',
        // uncomment the following to add your IP if you are not connecting from localhost.
        'allowedIPs' => ['*', '::1'],
    ];
}

return $config;
