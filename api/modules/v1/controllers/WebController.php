<?php

namespace app\modules\v1\controllers;
use Yii;
use yii\rest\Controller;
use app\modules\v1\models\Device;
use app\modules\v1\helper\PlayerFingerprintAuth;
use app\modules\v1\models\Game;
use app\modules\v1\models\AwardType;
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
    $permission = new \stdClass();
    $permission->path = "/permission";
    $permission->meta = new \stdClass();
    $permission->meta->title = "权限管理";
    $permission->meta->icon = "ep:lollipop";
    $permission->meta->rank = 10;

    $permission->children = [];

    $child1 = new \stdClass();
    $child1->path = "/permission/page/index";
    $child1->name = "PermissionPage";
    $child1->meta = new \stdClass();
    $child1->meta->title = "页面权限";
    $child1->meta->roles = ["root", "admin", "staff"];

    $child2 = new \stdClass();
    $child2->path = "/permission/button";
    $child2->meta = new \stdClass();
    $child2->meta->title = "按钮权限";
    $child2->meta->roles = ["root", "admin", "staff"];
    $child2->children = [];

    $subChild1 = new \stdClass();
    $subChild1->path = "/permission/button/router";
    $subChild1->component = "permission/button/index";
    $subChild1->name = "PermissionButtonRouter";
    $subChild1->meta = new \stdClass();
    $subChild1->meta->title = "路由返回按钮权限";
    $subChild1->meta->auths = [
        "permission:btn:add",
        "permission:btn:edit",
        "permission:btn:delete"
    ];

    $subChild2 = new \stdClass();
    $subChild2->path = "/permission/button/login";
    $subChild2->component = "permission/button/perms";
    $subChild2->name = "PermissionButtonLogin";
    $subChild2->meta = new \stdClass();
    $subChild2->meta->title = "登录接口返回按钮权限";

    $child2->children[] = $subChild1;
    $child2->children[] = $subChild2;

    $permission->children[] = $child1;
    $permission->children[] = $child2;
    $data =[
        $permission,
        $permission,
        $permission,
    ];
    $response = [
      "success" => true,
      "data" => [],
    ];
    return $response;
    //echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  }
}