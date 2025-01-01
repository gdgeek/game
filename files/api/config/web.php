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
    
    'as cors' => [
        'class' => \yii\filters\Cors::className(),
        'cors' => [
            'Origin' => ['*'],
            'Access-Control-Request-Method' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'],
            'Access-Control-Request-Headers' => ['*'],
            'Access-Control-Allow-Credentials' => null,
            'Access-Control-Max-Age' => 86400,
            'Access-Control-Expose-Headers' => [
                'X-Pagination-Total-Count',
                'X-Pagination-Page-Count',
                'X-Pagination-Current-Page',
                'X-Pagination-Per-Page',
            ],
        ],
    ],
    'components' => [
        'jwt' => [
            'class' => \bizley\jwt\Jwt::class,
            'signer' => \bizley\jwt\Jwt::HS256,
            'signingKey' => [
                'key' =>  getenv('JWT_KEY'), // path to your PRIVATE key, you can start the path with @ to indicate this is a Yii alias
                'passphrase' => '', // omit it if you are not adding any passphrase
                'method' => \bizley\jwt\Jwt::METHOD_FILE,
            ],
            'validationConstraints'=> static function (\bizley\jwt\Jwt $jwt) {
                $config = $jwt->getConfiguration();
                return [
                    new \Lcobucci\JWT\Validation\Constraint\SignedWith($config->signer(), $config->verificationKey()),
                    new \Lcobucci\JWT\Validation\Constraint\LooseValidAt(
                        new \Lcobucci\Clock\SystemClock(new \DateTimeZone(\Yii::$app->timeZone)),
                        new \DateInterval('PT10S')
                    ),
                ];
            }
        ],
        'request' => [
            'csrfParam' => '_csrf-api',
            'parsers' => [
                'application/json' => 'yii\web\JsonParser',
            ],
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
            'identityClass' => 'app\modules\v1\models\User',
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
                        'POST test' => 'test',
                        'GET test' => 'test',
                    ],
                ],
                [
                    'class' => 'yii\rest\UrlRule',
                    'controller' => 'v1/common',
                    'pluralize' => false,
                    'extraPatterns' => [
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
