<?php
namespace tests\unit\components;
use app\components\AdminTokenStore;
use app\components\AdminTokenService;
use bizley\jwt\Jwt;
use yii\web\Application;
use PHPUnit\Framework\TestCase;
use yii\redis\Connection;
use yii\web\HttpException;
use Yii;
require_once dirname(__DIR__,3) . '/components/AdminTokenStore.php';
require_once dirname(__DIR__,3) . '/components/AdminTokenService.php';

class AdminTokenStoreTest extends TestCase
{
    private $redis;
    private $store;
    private $prefix;
    protected function setUp(): void {
        if (!getenv('ADMIN_AUTH_REDIS_HOST')) { $this->markTestSkipped('Set ADMIN_AUTH_REDIS_HOST/PORT/DB to run real Redis atomic authentication tests.'); }
        $this->redis = new Connection(['hostname'=>getenv('ADMIN_AUTH_REDIS_HOST'),'port'=>(int)(getenv('ADMIN_AUTH_REDIS_PORT') ?: 6379),'database'=>(int)(getenv('ADMIN_AUTH_REDIS_DB') ?: 15),'connectionTimeout'=>1,'dataTimeout'=>2,'retries'=>0]);
        $this->prefix='game:test:admin:auth:' . bin2hex(random_bytes(8)) . ':';
        $this->store=new AdminTokenStore(['redis'=>$this->redis,'keyPrefix'=>$this->prefix]);
    }
    protected function tearDown(): void {
        if (!$this->redis) { return; }
        $keys=$this->redis->executeCommand('KEYS',[$this->prefix . '*']);
        if ($keys) { $this->redis->executeCommand('DEL',$keys); } $this->redis->close();
    }
    private function record(): array { return ['issuer'=>'https://c.4mr.cn','identity'=>['source'=>'password','username'=>'test'],'expiresAt'=>time()+3600,'refreshHash'=>hash('sha256','first')]; }
    public function testIndependentStoresSeeTheSameRecordAndAtomicRevocation(): void {
        $sid=bin2hex(random_bytes(16));$record=$this->record();$this->store->create($sid,$record,time());
        $other=new AdminTokenStore(['redis'=>$this->redis,'keyPrefix'=>$this->prefix]);
        $this->assertSame($record,$other->get($sid));
        $this->assertFalse($other->revoke($sid,hash('sha256','wrong')));
        $this->assertTrue($other->revoke($sid,$record['refreshHash']));$this->assertNull($this->store->get($sid));
    }
    public function testConcurrentRefreshProcessesHaveExactlyOneWinner(): void {
        $sid=bin2hex(random_bytes(16));$record=$this->record();$this->store->create($sid,$record,time());
        $left=$this->worker(['rotate',$this->prefix,$sid,$record['refreshHash'],hash('sha256','left'),(string)time(),'']);
        $right=$this->worker(['rotate',$this->prefix,$sid,$record['refreshHash'],hash('sha256','right'),(string)time(),'']);
        $codes=[proc_close($left),proc_close($right)];sort($codes);$this->assertSame([0,3],$codes);
        $stored=$this->store->get($sid);$this->assertSame($record['expiresAt'],$stored['expiresAt']);
        $this->assertContains($stored['refreshHash'],[hash('sha256','left'),hash('sha256','right')]);
    }
    public function testOwnerMutexSerializesSeparateProcessesHoldingLongerThanOneSecond(): void {
        $path=tempnam(sys_get_temp_dir(),'game-owner-lock-');$owner=hash('sha256','test-owner');
        try {
            $left=$this->worker(['lock',$this->prefix,$owner,'left','','1500',$path]);usleep(75000);
            $right=$this->worker(['lock',$this->prefix,$owner,'right','','0',$path]);
            $this->assertSame(0,proc_close($left));$this->assertSame(0,proc_close($right));
            $this->assertSame('left-begin\nleft-end\nright-begin\nright-end\n',file_get_contents($path));
        } finally { unlink($path); }
    }
    public function testConcurrentPasswordFailureReservationsCannotExceedSharedLimit(): void {
        $workers=[];
        for ($i=0;$i<10;$i++) { $workers[]=$this->worker(['attempt',$this->prefix,'203.0.113.10','5','900','']); }
        $codes=array_map('proc_close',$workers);$allowed=count(array_filter($codes,static function($code){return $code===0;}));
        $this->assertSame(5,$allowed,'Concurrent nodes must share one atomic five-attempt budget.');
    }
    public function testPasswordAttemptBudgetsArePartitionedByIssuer(): void {
        for ($i=0;$i<5;$i++) { $this->assertTrue($this->store->reservePasswordAttempt('203.0.113.11',5,900,'https://issuer-a.example')['allowed']); }
        $this->assertFalse($this->store->reservePasswordAttempt('203.0.113.11',5,900,'https://issuer-a.example')['allowed']);
        $this->assertTrue($this->store->reservePasswordAttempt('203.0.113.11',5,900,'https://issuer-b.example')['allowed']);
    }
    public function testOwnerLockHasLeaseLongerThanTwoMinuteDatabaseBudget(): void {
        $this->assertGreaterThanOrEqual(120,$this->store->ownerLockSeconds);
    }
    public function testOwnerLockGuardAtomicallyRenewsTheLease(): void {
        $owner=hash('sha256','renew-owner');$this->store->ownerLockSeconds=2;
        $this->store->withOwnerLock($owner,function() use($owner){usleep(1200000);$this->store->assertOwnerLock($owner);$ttl=$this->redis->executeCommand('PTTL',[$this->prefix.'owner-lock:'.$owner]);$this->assertGreaterThanOrEqual(1900,(int)$ttl);});
    }
    public function testRedisFailureIsSanitizedWithoutChainedExceptionOrLoggedCredentials(): void {
        $bad=new Connection(['hostname'=>'127.0.0.1','port'=>1,'database'=>15,'password'=>'test-private-auth-secret','connectionTimeout'=>0.1,'dataTimeout'=>0.1,'retries'=>0]);
        $store=new AdminTokenStore(['redis'=>$bad,'keyPrefix'=>$this->prefix]);
        $messages=Yii::getLogger()->messages;Yii::getLogger()->messages=[];
        try {
            $store->get(str_repeat('a',32));$this->fail('Unavailable Redis must fail closed.');
        } catch (HttpException $e) {
            $this->assertSame(503,$e->statusCode);$this->assertNull($e->getPrevious());
            $safe=serialize(Yii::getLogger()->messages).$e->getMessage();
            $this->assertStringNotContainsString('test-private-auth-secret',$safe);
        } finally { Yii::getLogger()->messages=$messages;$bad->close(); }
    }
    public function testIndependentEs256NodesShareActiveIdentityRefreshAndImmediateLogout(): void {
        $originalApp = Yii::$app; $originalAliases = Yii::$aliases; $app = null;
        try {
            $pair = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
            $this->assertNotFalse($pair); $this->assertTrue(openssl_pkey_export($pair, $private));
            $public = openssl_pkey_get_details($pair)['key'];
            $jwtConfig = ['class' => Jwt::class, 'signer' => Jwt::ES256,
                'signingKey' => ['key' => $private, 'method' => Jwt::METHOD_PLAIN],
                'verifyingKey' => ['key' => $public, 'method' => Jwt::METHOD_PLAIN]];
            $app = new Application(['id' => 'admin-real-redis-es256-test', 'basePath' => dirname(__DIR__, 3),
                'components' => ['request' => ['cookieValidationKey' => 'test'], 'adminJwt' => $jwtConfig]]);
            $issuer = 'https://es256-node-test.example';
            $left = new AdminTokenService(['store' => $this->store, 'issuer' => $issuer]);
            $right = new AdminTokenService(['store' => new AdminTokenStore(['redis' => $this->redis, 'keyPrefix' => $this->prefix]),
                'jwt' => new Jwt(array_diff_key($jwtConfig, ['class' => true])), 'issuer' => $issuer]);
            $identity = ['source' => 'password', 'username' => 'test', 'credentialFingerprint' => 'fixture'];
            $first = $left->issue($identity);
            $this->assertSame('ES256', $app->adminJwt->parse($first['accessToken'])->headers()->get('alg'));
            $app->request->headers->set('Authorization', 'Bearer ' . $first['accessToken']);
            $this->assertSame($identity, $right->identityFromRequest());
            $sid = $app->adminJwt->parse($first['accessToken'])->claims()->get('sid');
            $this->assertNull($this->store->get($sid, 'https://another-environment.example'));
            $next = $right->refresh($first['refreshToken']);
            $this->assertSame($first['refreshExpires'], $next['token']['refreshExpires']);
            // A valid access token can still revoke after another node rotated refresh.
            $left->revoke($first['refreshToken']);
            $app->request->headers->set('Authorization', 'Bearer ' . $next['token']['accessToken']);
            try { $right->identityFromRequest(); $this->fail('Logout must immediately revoke the other node.'); }
            catch (HttpException $e) { $this->assertSame(401, $e->statusCode); }
        } finally {
            if ($app) { $app->errorHandler->unregister(); } Yii::$app = $originalApp; Yii::$aliases = $originalAliases;
        }
    }
    private function worker(array $arguments) {
        $process=proc_open(array_merge([PHP_BINARY,dirname(__DIR__,2).'/fixtures/AdminTokenStoreWorker.php'],$arguments),[0=>['pipe','r'],1=>['file','/dev/null','a'],2=>['file','/dev/null','a']],$pipes);
        fclose($pipes[0]);return $process;
    }
}
