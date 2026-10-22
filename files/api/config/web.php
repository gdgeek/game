<?php

$params = require __DIR__ . '/params.php';
$db = require __DIR__ . '/db.php';

$config = [
    'id' => 'restful',
    'basePath' => dirname(__DIR__),
    'timeZone' => 'Asia/Shanghai',
    'bootstrap' => ['log'],
    'controllerNamespace' => 'app\controllers',
    'aliases' => [
        '@bower' => '@vendor/bower-asset',
        '@npm' => '@vendor/npm-asset',
    ],
    'modules' => [
        'v1' => [
            'class' => 'app\modules\v1\Module',
        ],
        'v2' => [
            'class' => 'app\modules\v2\Module',
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
        'healthService' => [
            'class' => \app\components\HealthService::class,
        ],
        'adminAuthRedis' => [
            'class' => \yii\redis\Connection::class,
            'hostname' => getenv('ADMIN_AUTH_REDIS_HOST') ?: getenv('REDIS_HOST'),
            'port' => (int) (getenv('ADMIN_AUTH_REDIS_PORT') ?: (getenv('REDIS_PORT') ?: 6379)),
            'database' => (int) (getenv('ADMIN_AUTH_REDIS_DB') !== false && getenv('ADMIN_AUTH_REDIS_DB') !== '' ? getenv('ADMIN_AUTH_REDIS_DB') : (getenv('REDIS_DB') ?: 0)),
            'password' => getenv('ADMIN_AUTH_REDIS_PASSWORD') ?: (getenv('REDIS_PASSWORD') ?: null),
            'connectionTimeout' => 1.5, 'dataTimeout' => 1.5, 'retries' => 0,
        ],
        'redis' => [
            'class' => 'yii\redis\Connection',
            'hostname' => getenv('REDIS_HOST'),
            'port' => getenv('REDIS_PORT'),
            'database' => getenv('REDIS_DB'),
        ],
        'secret' => [
            'class' => \app\modules\v1\components\Secret::class,
            'id' => getenv('SECRET_ID'),
            'key' => getenv('SECRET_KEY'),
            'cloud' => [
                'bucket' => getenv('COS_BUCKETS_BUCKET'),
                'region' => getenv('COS_BUCKETS_REGION'),
            ],
        ],
        'wechat' => [
            'class' => \app\modules\v1\components\Wechat::class,
            'app_id' => getenv('WECHAT_APP_ID'),
            'secret' => getenv('WECHAT_SECRET'),
            'token' => getenv('WECHAT_TOKEN'),
            'aes_key' => getenv('WECHAT_AES_KEY'),
            'mch_id' => getenv('WECHAT_MCH_ID'),
            'private_key' => getenv('WECHAT_PRIVATE_KEY'), // path to your PRIVATE key, you can start the path with @ to indicate this is a Yii alias
            'certificate' => getenv('WECHAT_CERTIFICATE'), // path to your CERTIFICATE key, you can start the path with @ to indicate this is a Yii alias
            'pub_key_id' => getenv('WECHAT_PUB_KEY_ID'),
            'pub_key_path' => getenv('WECHAT_PUB_KEY'),
            'secret_key' => getenv('WECHAT_SECRET_KEY'), // v3 API secret key
        ],
        'adminJwt' => [
            'class' => \bizley\jwt\Jwt::class,
            'signer' => \bizley\jwt\Jwt::ES256,
            'signingKey' => [
                'key' => getenv('ADMIN_JWT_KEY') ?: '/var/www/.ssh/jwt-key.pem',
                'method' => \bizley\jwt\Jwt::METHOD_FILE,
            ],
            'verifyingKey' => [
                'key' => getenv('ADMIN_JWT_PUB_KEY') ?: '/var/www/.ssh/jwt-key-pub.pem',
                'method' => \bizley\jwt\Jwt::METHOD_FILE,
            ],
        ],
        'jwt' => [
            'class' => \bizley\jwt\Jwt::class,
            'signer' => \bizley\jwt\Jwt::HS256,
            'signingKey' => [
                'key' => getenv('JWT_KEY'), // path to your PRIVATE key, you can start the path with @ to indicate this is a Yii alias
                'passphrase' => '', // omit it if you are not adding any passphrase
                'method' => \bizley\jwt\Jwt::METHOD_FILE,
            ],
            'validationConstraints' => static function (\bizley\jwt\Jwt $jwt) {
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
            //  'class' => 'yii\caching\FileCache',
            'class' => 'yii\redis\Cache',
            'redis' => [
                'class' => 'yii\redis\Connection',
                'hostname' => getenv('REDIS_HOST'),
                'port' => getenv('REDIS_PORT'),
                'database' => getenv('REDIS_DB'),
            ]
        ],
        'helper' => [
            'class' => 'app\components\Helper',
        ],
        'user' => [
            'identityClass' => 'app\modules\v2\models\User',
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
            'class' => \app\components\UrlManager::class,
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'enableStrictParsing' => true,
            'rules' => [
                'GET health' => 'health/index',
                'HEAD health' => 'health/index',

                'GET admin-api/session' => 'admin/session',
                'POST admin-api/login' => 'admin/login',
                'POST admin-api/refresh' => 'admin/refresh',
                'POST admin-api/logout' => 'admin/logout',
                'POST admin-api/scan-login/tickets' => 'admin/scan-login-create',
                'GET admin-api/scan-login/tickets/<id:[a-f0-9]{32}>' => 'admin/scan-login-status',
                'POST admin-api/scan-login/tickets/<id:[a-f0-9]{32}>/consume' => 'admin/scan-login-consume',
                'POST admin-api/scan-login/tickets/<id:[a-f0-9]{32}>/cancel' => 'admin/scan-login-cancel',
                'POST v2/web-login/tickets/<id:[a-f0-9]{32}>/approve' => 'v2/web-login/approve',
                'GET admin-api/devices' => 'admin/devices',
                'DELETE admin-api/devices/<id:\\d+>' => 'admin/delete-device',
                'GET admin-api/games' => 'admin/games',
                'PUT admin-api/devices/<id:\\d+>/game' => 'admin/set-game',
                'PUT admin-api/devices/<id:\\d+>/configuration' => 'admin/set-configuration',

                // Swagger API 文档路由
                'GET swagger' => 'swagger/index',
                'GET swagger/json-schema' => 'swagger/json-schema',

                [
                    'pattern' => 'apple-app-site-association',
                    'route' => 'site/apple-app-site-association',
                    'suffix' => ''
                ],
                [
                    'class' => 'yii\rest\UrlRule',
                    'controller' => 'v1/common',
                    'pluralize' => false,
                    'extraPatterns' => [
                        'GET test' => 'test',
                        'POST sign-in' => 'sign-in',
                        'POST sign-up' => 'sign-up',
                        'POST refresh-token' => 'refresh-token',
                    ],
                ],
                [
                    'class' => 'yii\rest\UrlRule',
                    'controller' => 'v1/system',
                    'pluralize' => false,
                    'extraPatterns' => [

                        'GET player-info' => 'player-info',
                        'GET ready-game' => 'ready-game',
                        'GET ready' => 'ready',
                        'GET reset-shop' => 'reset-shop',
                        'POST give' => 'give',
                        'POST close-record' => 'close-record',
                        'POST deduct-points' => 'deduct-points',
                    ],
                ],
                [
                    'class' => 'yii\rest\UrlRule',
                    'controller' => 'v1/wechat-pay',
                    'pluralize' => false,
                    'extraPatterns' => [
                        'GET info' => 'info',
                        'POST wxpay-order' => 'wxpay-order',
                        'POST notify' => 'notify',
                        'GET notify' => 'notify',
                        'GET wxpay-query-order-by-out-trade-no' => 'wxpay-query-order-by-out-trade-no',
                        'GET wxpay-query-order-by-transaction-id' => 'wxpay-query-order-by-transaction-id',
                        'POST refund-notify' => 'refund-notify',
                        'GET wxpay-query-refund' => 'wxpay-query-refund',
                        'POST wxpay-refund' => 'wxpay-refund',
                    ],
                ],
                [
                    'class' => 'yii\rest\UrlRule',
                    'controller' => 'v1/we-chat',
                    'pluralize' => false,
                    'extraPatterns' => [
                        'POST openid' => 'openid',
                        'GET info' => 'info',
                    ],
                ],
                [
                    'class' => 'yii\rest\UrlRule',
                    'controller' => 'v1/tencent-cloud',
                    'pluralize' => false,
                    'extraPatterns' => [
                        'GET token' => 'token',
                        'GET cloud' => 'cloud',
                        'GET store' => 'store',
                    ],
                ],
                [
                    'class' => 'yii\rest\UrlRule',
                    'controller' => 'v1/q-cloud',
                    'pluralize' => false,
                    'extraPatterns' => [
                        'GET object-url' => 'object-url'
                    ],
                ],
                [
                    'class' => 'yii\rest\UrlRule',
                    'controller' => 'v1/web',
                    'pluralize' => false,
                    'extraPatterns' => [
                        'GET async-routes' => 'async-routes',
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
                        'GET restart' => 'restart',
                        'POST restart' => 'restart',
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
                    'controller' => 'v1/checkin',
                    'pluralize' => false,
                    'extraPatterns' => [
                        'POST ready' => 'ready',
                        'GET status' => 'status',
                        'DELETE close' => 'close',
                        'POST status-linked' => 'status-linked',
                        'POST status-ready' => 'status-ready',
                        'POST status-over' => 'status-over',
                        'POST upload' => 'upload',
                        'GET files' => 'files',
                        'POST report' => 'report',
                    ],
                ],
                [
                    'class' => 'yii\rest\UrlRule',
                    'controller' => 'v1/local',
                    'pluralize' => false,
                    'extraPatterns' => [
                        'POST refresh' => 'refresh',
                        'GET refresh' => 'refresh',
                        'GET log' => 'log',
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
                    'controller' => ['v1/device', 'v1/shop', 'v1/player', 'v1/record', 'v1/award', 'v1/gift'],
                    'pluralize' => false,
                ],
                [
                    'class' => 'yii\rest\UrlRule',
                    'controller' => 'v2/server',
                    'pluralize' => false,
                    'extraPatterns' => [
                        'POST refresh' => 'refresh',
                        'POST device' => 'device',
                        'POST file' => 'file',
                        'POST applet' => 'applet',
                        'GET refresh' => 'refresh',
                        'GET log' => 'log',
                        'GET test' => 'test',
                        'GET info' => 'info',
                        'GET scenes' => 'scenes',
                        'GET test' => 'test',
                    ],
                ],
                [
                    'class' => 'yii\rest\UrlRule',
                    'controller' => 'v2/applet',
                    'pluralize' => false,
                    'extraPatterns' => [
                        'POST refresh' => 'refresh',
                    ],
                ],
                [
                    'class' => 'yii\rest\UrlRule',
                    'controller' => 'v2/wechat-pay',
                    'pluralize' => false,
                    'extraPatterns' => [
                        'POST wxpay-order' => 'wxpay-order',
                        'POST notify' => 'notify',
                        'GET notify' => 'notify',
                        'GET wxpay-query-order-by-out-trade-no' => 'wxpay-query-order-by-out-trade-no',
                        'GET wxpay-query-order-by-transaction-id' => 'wxpay-query-order-by-transaction-id',
                        'POST refund-notify' => 'refund-notify',
                        'GET wxpay-query-refund' => 'wxpay-query-refund',
                        'POST wxpay-refund' => 'wxpay-refund',
                    ],
                ],

                [
                    'class' => 'yii\rest\UrlRule',
                    'controller' => 'v2/wechat',
                    'pluralize' => false,
                    'extraPatterns' => [
                        'GET info' => 'info',
                        'POST bind-phone' => 'bind-phone',
                        'POST profile' => 'profile',
                    ],
                ],

                [
                    'class' => 'yii\rest\UrlRule',
                    'controller' => 'v2/site',
                    'pluralize' => false,
                    'extraPatterns' => [
                        'POST login' => 'login',
                        'POST refresh-token' => 'refresh-token',
                    ],
                ],
                [
                    'class' => 'yii\rest\UrlRule',
                    'controller' => 'v2/device',
                    'pluralize' => true,
                    'extraPatterns' => [
                        'GET manage' => 'manage',
                        'POST assign' => 'assign',
                        'POST <device_id>/assign' => 'assign',
                        'DELETE <device_id>/assign/<user_id>' => 'unassign',
                    ],
                ],
                [
                    'class' => 'yii\rest\UrlRule',
                    'controller' => 'v2/setup',
                    'pluralize' => true,
                ],
                [
                    'class' => 'yii\rest\UrlRule',
                    'controller' => 'v2/file',
                    'pluralize' => true,
                    'extraPatterns' => [
                        'GET list' => 'list',
                    ],
                ],
                [
                    'class' => 'yii\rest\UrlRule',
                    'controller' => 'v2/tencent-cloud',
                    'pluralize' => false,
                    'extraPatterns' => [
                        'GET token' => 'token',
                        'GET cloud' => 'cloud',
                        'GET store' => 'store',
                    ],
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

// Disable cookie identities before debug/gii bootstrap can restore a PHP session.
// V1/V2 keep their existing user configuration and Bearer identity domain.
$adminRequestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if (is_string($adminRequestPath) && preg_match('~^/(?:index\.php/)?admin-api(?:/|$)~D', $adminRequestPath)) {
    $config['components']['user']['enableSession'] = false;
    $config['components']['user']['enableAutoLogin'] = false;
}

return $config;
