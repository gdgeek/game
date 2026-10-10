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
        // Keep health checks reachable even when the Redis route cache is down.
        if ($request instanceof Request && $request->pathInfo === 'health') {
            $this->cache = false;
        }

        parent::init();
    }
}
