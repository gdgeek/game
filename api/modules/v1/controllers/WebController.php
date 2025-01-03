<?php

namespace app\modules\v1\controllers;
use Yii;
use yii\rest\Controller;
use app\modules\v1\models\Device;
use app\modules\v1\helper\PlayerFingerprintAuth;
use app\modules\v1\models\Game;
use app\modules\v1\models\Award;
use app\modules\v1\models\Player;

use bizley\jwt\JwtHttpBearerAuth;
use yii\filters\auth\CompositeAuth;

//root，
//管理员， （可以查看所有信息） Administrator
//店长，（可以修改店家信息） Manager 
//工作人员， （可以修改设备信息） Manager
//玩家 Player
class WebController extends Controller
{

  //public $modelClass = 'app\modules\v1\models\Manager';
  public function behaviors()
  {
      
      $behaviors = parent::behaviors();
  
            
      $behaviors['authenticator'] = [
        'class' => CompositeAuth::class,
        'authMethods' => [
            JwtHttpBearerAuth::class,
        ],
        'except' => ['options'],
      ];
      return $behaviors;
  }

  
  
  
  public function actionAsyncRoutes(){
    $response = [
      "success" => true,
      "data" => [
          [
              "path" => "/permission",
              "meta" => [
                  "title" => "权限管理",
                  "icon" => "ep:lollipop",
                  "rank" => 10
              ],
              "children" => [
                  [
                      "path" => "/permission/page/index",
                      "name" => "PermissionPage",
                      "meta" => [
                          "title" => "页面权限",
                          "roles" => ["admin", "common"]
                      ]
                  ],
                  [
                      "path" => "/permission/button",
                      "meta" => [
                          "title" => "按钮权限",
                          "roles" => ["admin", "common"]
                      ],
                      "children" => [
                          [
                              "path" => "/permission/button/router",
                              "component" => "permission/button/index",
                              "name" => "PermissionButtonRouter",
                              "meta" => [
                                  "title" => "路由返回按钮权限",
                                  "auths" => [
                                      "permission:btn:add",
                                      "permission:btn:edit",
                                      "permission:btn:delete"
                                  ]
                              ]
                          ],
                          [
                              "path" => "/permission/button/login",
                              "component" => "permission/button/perms",
                              "name" => "PermissionButtonLogin",
                              "meta" => [
                                  "title" => "登录接口返回按钮权限"
                              ]
                          ]
                      ]
                  ]
              ]
          ]
      ]
    ];
    return $response;
    //echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  }
}