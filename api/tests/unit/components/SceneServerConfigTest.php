<?php

namespace tests\unit\components;

use app\modules\v2\helper\A1Server;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/modules/v2/helper/A1Server.php';

class SceneServerConfigTest extends TestCase
{
    private $originalEnv;

    protected function setUp(): void
    {
        $this->originalEnv = getenv('SCENE_SERVER_BASE_URL');
    }

    protected function tearDown(): void
    {
        if ($this->originalEnv === false) {
            putenv('SCENE_SERVER_BASE_URL');
        } else {
            putenv('SCENE_SERVER_BASE_URL=' . $this->originalEnv);
        }
    }

    public function testDefaultsToTheB0Y1ProductionSceneServer(): void
    {
        putenv('SCENE_SERVER_BASE_URL');

        $this->assertSame('https://y1.b0.01xr.com/v1/server', A1Server::baseUrl());
    }

    public function testUsesConfiguredB0ProductionSceneServerAndRemovesTrailingSlash(): void
    {
        putenv('SCENE_SERVER_BASE_URL=https://y1.b0.01xr.com/');

        $this->assertSame('https://y1.b0.01xr.com/v1/server', A1Server::baseUrl());
    }

    public function testUsesConfiguredB1ProductionSceneServer(): void
    {
        putenv('SCENE_SERVER_BASE_URL=https://y1.b1.01xr.com');

        $this->assertSame('https://y1.b1.01xr.com/v1/server', A1Server::baseUrl());
    }

    public function testUsesConfiguredB0DevelopmentSceneServer(): void
    {
        putenv('SCENE_SERVER_BASE_URL=https://y1.d.b0.01xr.com');

        $this->assertSame('https://y1.d.b0.01xr.com/v1/server', A1Server::baseUrl());
    }

    public function testUsesConfiguredB1DevelopmentSceneServer(): void
    {
        putenv('SCENE_SERVER_BASE_URL=https://y1.d.b1.01xr.com');

        $this->assertSame('https://y1.d.b1.01xr.com/v1/server', A1Server::baseUrl());
    }

    public function testAllowsTheInternalY1DockerService(): void
    {
        putenv('SCENE_SERVER_BASE_URL=http://y1-p:8080/');

        $this->assertSame('http://y1-p:8080/v1/server', A1Server::baseUrl());
    }

    public function testKeepsAnAlreadyQualifiedServerPathForCompatibility(): void
    {
        putenv('SCENE_SERVER_BASE_URL=http://y1-p:8080/v1/server');

        $this->assertSame('http://y1-p:8080/v1/server', A1Server::baseUrl());
    }

    public function testRejectsAConfiguredSceneServerThatIsNotHttps(): void
    {
        putenv('SCENE_SERVER_BASE_URL=http://localhost:8080/v1/server');

        $this->expectException(\InvalidArgumentException::class);
        A1Server::baseUrl();
    }

    public function testRejectsAConfiguredSceneServerWithAQueryString(): void
    {
        putenv('SCENE_SERVER_BASE_URL=https://y1.b0.01xr.com/v1/server?scope=checkin');

        $this->expectException(\InvalidArgumentException::class);
        A1Server::baseUrl();
    }
}
