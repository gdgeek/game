<?php

namespace tests\unit\components;

use app\components\AdminGameCatalog;
use app\components\AdminSceneFetcher;
use app\modules\v2\helper\A1Server;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\caching\ArrayCache;
use yii\web\Application;
use yii\web\HttpException;

class AdminSceneFetcherTest extends TestCase
{
    protected function setUp(): void
    {
        Yii::setAlias('@app', dirname(__DIR__, 3));
    }

    public function testReadsAllPagesUsingFixedA1UrlsAndCaseInsensitiveHeaders(): void
    {
        $requests = [];
        $first = $this->page([$this->scene(1), $this->scene(2)], [
            'x-pagination-total-count' => '3',
            'X-PAGINATION-PAGE-COUNT' => '2',
            'X-Pagination-Per-Page' => '2',
            'Link' => '<https://untrusted.invalid/checkin?page=2>; rel="next"',
        ]);
        $second = $this->page(['data' => ['items' => [$this->scene(3)]]], [
            'X-Pagination-Total-Count' => '3',
            'X-Pagination-Page-Count' => '2',
            'X-Pagination-Per-Page' => '2',
        ]);
        $fetcher = $this->fetcher([$first, $second], $requests);

        $this->assertSame([$this->scene(1), $this->scene(2), $this->scene(3)], $fetcher->fetch());
        $this->assertCount(2, $requests);
        foreach ($requests as $index => $request) {
            $this->assertSame(A1Server::BASE_URL . '/checkin', strtok($request['url'], '?'));
            parse_str(parse_url($request['url'], PHP_URL_QUERY), $query);
            $this->assertSame((string) ($index + 1), $query['page']);
            $this->assertSame('15', $query['per-page']);
            $this->assertSame('verse_id,name', $query['expand']);
        }
    }

    public function testDerivesPageCountFromTotalAndActualPageSize(): void
    {
        $requests = [];
        $headers = ['X-Pagination-Total-Count' => '3', 'X-Pagination-Per-Page' => '2'];
        $fetcher = $this->fetcher([
            $this->page([$this->scene(1), $this->scene(2)], $headers),
            $this->page([$this->scene(3)], $headers),
        ], $requests);

        $this->assertCount(3, $fetcher->fetch());
        $this->assertCount(2, $requests);
    }

    public function testMissingPaginationHeadersMeansOnePageAndSupportsNestedEnvelopes(): void
    {
        $requests = [];
        $payload = ['results' => ['data' => ['items' => ['data' => ['results' => [$this->scene(1)]]]]]];
        $fetcher = $this->fetcher([$this->page($payload)], $requests);

        $this->assertSame([$this->scene(1)], $fetcher->fetch());
        $this->assertCount(1, $requests);
    }

    public function testZeroCountAndZeroPagesReturnsAnEmptyList(): void
    {
        $requests = [];
        $fetcher = $this->fetcher([$this->page([], [
            'X-Pagination-Total-Count' => '0',
            'X-Pagination-Page-Count' => '0',
            'X-Pagination-Per-Page' => '15',
        ])], $requests);

        $this->assertSame([], $fetcher->fetch());
        $this->assertCount(1, $requests);
    }

    public function testRejectsExcessivePageCountBeforeFetchingMorePages(): void
    {
        $requests = [];
        $fetcher = $this->fetcher([$this->page([$this->scene(1)], [
            'X-Pagination-Total-Count' => '2',
            'X-Pagination-Page-Count' => '2',
            'X-Pagination-Per-Page' => '1',
        ])], $requests, ['maxPages' => 1]);

        try {
            $fetcher->fetch();
            $this->fail('The catalog must not return a partial list when the page limit is exceeded.');
        } catch (\RuntimeException $e) {
            $this->assertCount(1, $requests);
        }
    }

    public function testLaterHttpFailureDoesNotReturnPartialScenes(): void
    {
        $requests = [];
        $fetcher = $this->fetcher([
            $this->page([$this->scene(1)], ['X-Pagination-Page-Count' => '2']),
            ['status' => 503, 'headers' => [], 'body' => 'unavailable'],
        ], $requests);

        $this->expectException(\RuntimeException::class);
        $fetcher->fetch();
    }

    public function testLaterTransportFailureDoesNotReturnPartialScenes(): void
    {
        $requests = [];
        $fetcher = $this->fetcher([
            $this->page([$this->scene(1)], ['X-Pagination-Page-Count' => '2']),
            new \RuntimeException('Connection failed.'),
        ], $requests);

        $this->expectException(\RuntimeException::class);
        $fetcher->fetch();
    }

    public function testRejectsInvalidJsonOnTheFirstPage(): void
    {
        $requests = [];
        $fetcher = $this->fetcher([['status' => 200, 'headers' => [], 'body' => '{invalid']], $requests);

        $this->expectException(\JsonException::class);
        $fetcher->fetch();
    }

    public function testRejectsInvalidJsonOnLaterPages(): void
    {
        $requests = [];
        $fetcher = $this->fetcher([
            $this->page([$this->scene(1)], ['X-Pagination-Page-Count' => '2']),
            ['status' => 200, 'headers' => [], 'body' => '{invalid'],
        ], $requests);

        $this->expectException(\JsonException::class);
        $fetcher->fetch();
    }

    public function testRejectsListsThatDoNotMatchTheReportedTotal(): void
    {
        $requests = [];
        $fetcher = $this->fetcher([$this->page([$this->scene(1)], [
            'X-Pagination-Total-Count' => '2',
            'X-Pagination-Page-Count' => '1',
            'X-Pagination-Per-Page' => '15',
        ])], $requests);

        $this->expectException(\UnexpectedValueException::class);
        $fetcher->fetch();
    }

    public function testRejectsChangedPaginationMetadataOnLaterPages(): void
    {
        $requests = [];
        $fetcher = $this->fetcher([
            $this->page([$this->scene(1)], ['X-Pagination-Page-Count' => '2']),
            $this->page([$this->scene(2)], ['X-Pagination-Page-Count' => '3']),
        ], $requests);

        $this->expectException(\UnexpectedValueException::class);
        $fetcher->fetch();
    }

    public function testEachPageUsesTheRemainingTotalTimeBudget(): void
    {
        $this->assertTrue(class_exists(AdminSceneFetcher::class), 'The A1 pagination fetcher must be available.');
        $now = 1000;
        $timeouts = [];
        $fetcher = new AdminSceneFetcher([
            'timeoutMs' => 10,
            'clock' => static function () use (&$now) { return $now; },
            'transport' => function ($url, $timeoutMs) use (&$now, &$timeouts) {
                $timeouts[] = $timeoutMs;
                $now += count($timeouts) === 1 ? 4 : 7;
                return $this->page([$this->scene(count($timeouts))], ['X-Pagination-Page-Count' => '2']);
            },
        ]);

        try {
            $fetcher->fetch();
            $this->fail('A request that exceeds the total time budget must fail.');
        } catch (\RuntimeException $e) {
            $this->assertSame([10, 6], $timeouts);
        }
    }

    public function testCatalogDoesNotCachePartialResultsAndNormalizesFetchedPages(): void
    {
        $requests = [];
        $fetcher = $this->fetcher([
            $this->page([$this->scene(1)], ['X-Pagination-Page-Count' => '2']),
            ['status' => 503, 'headers' => [], 'body' => 'unavailable'],
            $this->page([$this->scene(1)], ['X-Pagination-Page-Count' => '2']),
            $this->page([$this->scene(2)], ['X-Pagination-Page-Count' => '2']),
        ], $requests);
        $catalog = new AdminGameCatalog(['cache' => new ArrayCache(), 'fetcher' => $fetcher]);

        try {
            $catalog->getGames();
            $this->fail('The first failed request must not populate the catalog cache.');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->statusCode);
        }
        $this->assertSame([
            ['id' => 1, 'name' => '游戏 1', 'description' => '', 'category' => ''],
            ['id' => 2, 'name' => '游戏 2', 'description' => '', 'category' => ''],
        ], $catalog->getGames());
        $catalog->getGames();
        $this->assertCount(4, $requests);
    }

    public function testUpstreamHeadersDoNotAlterTheYiiResponse(): void
    {
        $requests = [];
        $fetcher = $this->fetcher([$this->page([], ['X-Pagination-Total-Count' => '0'])], $requests);
        $app = new Application([
            'id' => 'admin-scene-fetcher-test',
            'basePath' => dirname(__DIR__, 3),
            'components' => ['request' => ['cookieValidationKey' => 'scene-fetcher-test']],
        ]);
        $app->response->statusCode = 202;
        $app->response->headers->set('X-Existing', 'keep');

        try {
            $this->assertSame([], $fetcher->fetch());
            $this->assertSame(202, $app->response->statusCode);
            $this->assertSame('html', $app->response->format);
            $this->assertSame(['x-existing' => ['keep']], $app->response->headers->toArray());
        } finally {
            $app->errorHandler->unregister();
            Yii::$app = null;
        }
    }

    private function fetcher(array $responses, array &$requests, array $config = []): AdminSceneFetcher
    {
        $this->assertTrue(class_exists(AdminSceneFetcher::class), 'The A1 pagination fetcher must be available.');
        $config['transport'] = static function ($url, $timeoutMs) use (&$responses, &$requests) {
            $requests[] = ['url' => $url, 'timeoutMs' => $timeoutMs];
            if ($responses === []) {
                throw new \RuntimeException('Unexpected extra page request.');
            }
            $response = array_shift($responses);
            if ($response instanceof \Throwable) {
                throw $response;
            }
            return $response;
        };
        return new AdminSceneFetcher($config);
    }

    private function page(array $payload, array $headers = []): array
    {
        return ['status' => 200, 'headers' => $headers, 'body' => json_encode($payload, JSON_THROW_ON_ERROR)];
    }

    private function scene(int $id): array
    {
        return ['verse_id' => $id, 'name' => '游戏 ' . $id];
    }
}
