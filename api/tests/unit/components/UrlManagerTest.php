<?php

namespace tests\unit\components;

use app\components\UrlManager;
use yii\caching\ArrayCache;

class UrlManagerTest extends \Codeception\Test\Unit
{
    public function testOrdinaryRequestsReuseTheConfiguredRulesCache()
    {
        $this->setRequestUrl('/swagger');
        $cache = new UrlManagerProbeCache();
        \Yii::$app->set('cache', $cache);

        $first = new UrlManager($this->managerConfig());
        $this->assertSame($cache, $first->cache);
        $this->assertGreaterThan(0, $cache->reads);
        $this->assertGreaterThan(0, $cache->writes);
        $reads = $cache->reads;
        $writes = $cache->writes;

        $second = new UrlManager($this->managerConfig());

        $this->assertGreaterThan($reads, $cache->reads);
        $this->assertSame($writes, $cache->writes, 'The second manager should reuse the stored rules');
        $this->assertSame(['swagger/index', []], $second->parseRequest(\Yii::$app->request));
    }

    public function testHealthGetSkipsFailedCacheDuringDebugAndGiiBootstrap()
    {
        $this->assertHealthBootstrapSkipsFailedCache('GET', '/health?probe=1');
    }

    public function testHealthHeadSkipsFailedCacheDuringDebugAndGiiBootstrap()
    {
        $this->assertHealthBootstrapSkipsFailedCache('HEAD', '/health');
    }

    private function assertHealthBootstrapSkipsFailedCache(string $method, string $url): void
    {
        $previousMethod = $_SERVER['REQUEST_METHOD'] ?? null;
        $_SERVER['REQUEST_METHOD'] = $method;
        try {
            $this->setRequestUrl($url);
            $cache = new UrlManagerProbeCache(['failOnAccess' => true]);
            \Yii::$app->set('cache', $cache);
            \Yii::$app->set('urlManager', ['class' => UrlManager::class] + $this->managerConfig());
            \Yii::$app->setModule('debug', ['class' => \yii\debug\Module::class]);
            \Yii::$app->setModule('gii', ['class' => \yii\gii\Module::class]);

            // Both real modules initialize URL rules before request dispatch in dev mode.
            \Yii::$app->getModule('debug')->bootstrap(\Yii::$app);
            \Yii::$app->getModule('gii')->bootstrap(\Yii::$app);

            $this->assertSame('health', \Yii::$app->request->pathInfo);
            $this->assertFalse(\Yii::$app->urlManager->cache);
            $this->assertSame(0, $cache->reads);
            $this->assertSame(0, $cache->writes);
            $this->assertSame(['health/index', []], \Yii::$app->urlManager->parseRequest(\Yii::$app->request));
        } finally {
            if ($previousMethod === null) {
                unset($_SERVER['REQUEST_METHOD']);
            } else {
                $_SERVER['REQUEST_METHOD'] = $previousMethod;
            }
        }
    }

    private function setRequestUrl(string $url): void
    {
        \Yii::$app->request->setScriptUrl('/index.php');
        \Yii::$app->request->setBaseUrl('');
        \Yii::$app->request->setUrl($url);
        \Yii::$app->request->setPathInfo(null);
    }

    private function managerConfig(): array
    {
        return [
            'enablePrettyUrl' => true,
            'enableStrictParsing' => true,
            'showScriptName' => false,
            'rules' => [
                'GET health' => 'health/index',
                'HEAD health' => 'health/index',
                'GET swagger' => 'swagger/index',
            ],
        ];
    }
}

class UrlManagerProbeCache extends ArrayCache
{
    public int $reads = 0;
    public int $writes = 0;
    public bool $failOnAccess = false;

    protected function getValue($key)
    {
        ++$this->reads;
        if ($this->failOnAccess) {
            throw new \RuntimeException('Configured cache is unavailable');
        }

        return parent::getValue($key);
    }

    protected function setValue($key, $value, $duration)
    {
        ++$this->writes;
        if ($this->failOnAccess) {
            throw new \RuntimeException('Configured cache is unavailable');
        }

        return parent::setValue($key, $value, $duration);
    }
}
