<?php

namespace app\components;

use Yii;
use yii\web\Request;

class UrlManager extends \yii\web\UrlManager
{
    public function init()
    {
        $request = Yii::$app->get('request');
        // Debug/gii can initialize the URL manager before request dispatch.
        // Health checks and administrator login must work without optional Redis.
        if ($request instanceof Request) {
            $path = $request->pathInfo;
            if ($path === 'health' || $path === 'admin-api' || strpos($path, 'admin-api/') === 0
                || strpos($path, 'v2/web-login/') === 0) {
                $this->cache = false;
            }
        }

        parent::init();
    }
}
