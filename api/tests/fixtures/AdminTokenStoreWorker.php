<?php
require dirname(__DIR__, 2) . '/vendor/yiisoft/yii2/Yii.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/components/AdminTokenStore.php';
[$script,$mode,$prefix,$sid,$expected,$next,$now,$path] = array_pad($argv,8,'');
$redis = new yii\redis\Connection(['hostname'=>getenv('ADMIN_AUTH_REDIS_HOST'),'port'=>(int)getenv('ADMIN_AUTH_REDIS_PORT'),'database'=>(int)getenv('ADMIN_AUTH_REDIS_DB'),'connectionTimeout'=>1,'dataTimeout'=>2,'retries'=>0]);
$store = new app\components\AdminTokenStore(['redis'=>$redis,'keyPrefix'=>$prefix]);
if ($mode === 'rotate') { exit($store->rotate($sid,$expected,$next,(int)$now) !== null ? 0 : 3); }
if ($mode === 'attempt') { $result=$store->reservePasswordAttempt($sid,(int)$expected,(int)$next); exit($result['allowed'] ? 0 : 3); }
if ($mode === 'lock') {
    $store->withOwnerLock($sid, function () use ($path,$expected,$now) { file_put_contents($path,$expected . '-begin\n',FILE_APPEND); usleep((int)$now*1000); file_put_contents($path,$expected . '-end\n',FILE_APPEND); });
    exit(0);
}
exit(4);
