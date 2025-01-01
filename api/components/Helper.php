<?php
 namespace app\components;
 use Yii;
 class Helper extends \yii\base\Component
 {
    public  $key = 'log_cache';
   
    public function record()
    {
        $cache = \Yii::$app->cache;
        $cache->set($this->key , [ "isGet"=>Yii::$app->request->isGet ,"post" => Yii::$app->request->post(), "get" => Yii::$app->request->get(), "headers" => Yii::$app->request->headers]);
    }
    public function play()
    {
        $cache = \Yii::$app->cache;
        return $cache->get($this->key);
    }
 }