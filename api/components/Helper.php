<?php
 namespace app\components;
 use Yii;
 class Helper extends \yii\base\Component
 {
    
   
    public function record($key = 'log_cache')
    {
        $cache = \Yii::$app->cache;
        $cache->set($key , [ "action"=>Yii::$app->controller->action->id, "isGet"=>Yii::$app->request->isGet ,"post" => Yii::$app->request->post(), "get" => Yii::$app->request->get(), "headers" => Yii::$app->request->headers]);
    }
    public function play($key = 'log_cache')
    {
        $cache = \Yii::$app->cache;
        return $cache->get($key);
    }
 }