<?php declare(strict_types=1);

namespace Lmc\Cqrs\Handler;

use Lmc\Cqrs\Handler\Fixture\CacheableProfileableQueryAdapter;
use Lmc\Cqrs\Handler\Fixture\CacheableQueryAdapter;
use Lmc\Cqrs\Handler\Fixture\DummyQuery;
use Lmc\Cqrs\Handler\Fixture\DummyQueryHandler;
use Lmc\Cqrs\Handler\Fixture\ImpureTranslationDecoder;
use Lmc\Cqrs\Handler\Fixture\ProfileableQueryAdapter;
use Lmc\Cqrs\Handler\Handler\GetCachedHandler;
use Lmc\Cqrs\Types\Decoder\CallbackResponseDecoder;
use Lmc\Cqrs\Types\Exception\CqrsExceptionInterface;
use Lmc\Cqrs\Types\Exception\NoQueryHandlerUsedException;
use Lmc\Cqrs\Types\QueryFetcherInterface;
use Lmc\Cqrs\Types\QueryHandlerInterface;
use Lmc\Cqrs\Types\QueryInterface;
use Lmc\Cqrs\Types\ValueObject\CacheKey;
use Lmc\Cqrs\Types\ValueObject\CacheTime;
use Lmc\Cqrs\Types\ValueObject\DecodedValue;
use Lmc\Cqrs\Types\ValueObject\OnErrorCallback;
use Lmc\Cqrs\Types\ValueObject\OnErrorInterface;
use Lmc\Cqrs\Types\ValueObject\OnSuccessCallback;
use Lmc\Cqrs\Types\ValueObject\OnSuccessInterface;
use Lmc\Cqrs\Types\ValueObject\PrioritizedItem;
use Lmc\Cqrs\Types\ValueObject\ProfilerItem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class QueryFetcherTest extends AbstractTestCase
{
    /** @phpstan-var QueryFetcherInterface<mixed, mixed> */
    private QueryFetcherInterface $queryFetcher;
    /** @phpstan-var QueryFetcherInterface<mixed, mixed> */
    private QueryFetcherInterface $queryFetcherWithoutFeatures;

    private CacheItemPoolInterface $cache;
    private ProfilerBag $profilerBag;

    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter();
        $this->profilerBag = new ProfilerBag();

        $this->queryFetcher = new QueryFetcher(true, $this->cache, $this->profilerBag);
        $this->queryFetcherWithoutFeatures = new QueryFetcher(false, null, null);
    }

    #[Test]
    public function shouldHaveDefaultHandlers(): void
    {
        $this->assertNotEmpty($this->queryFetcher->getHandlers());
        $this->assertEmpty($this->queryFetcherWithoutFeatures->getHandlers());
    }

    #[Test]
    public function shouldNotFetchNotCacheableQueryFromCache(): void
    {
        $key = new CacheKey('some-key');
        $cachedValue = 'cached-value';

        $this->prepareCachedData($key, $cachedValue);

        $dummyQuery = new DummyQuery('fresh-data');

        $this->queryFetcher->fetch(
            $dummyQuery,
            new OnSuccessCallback(
                fn ($data) => $this->fail('This should not be called, since DummyQuery is not cacheable.')
            ),
            new OnErrorCallback(function (\Throwable $error): void {
                $this->assertInstanceOf(CqrsExceptionInterface::class, $error);
                $this->assertInstanceOf(NoQueryHandlerUsedException::class, $error);
            }),
        );
    }

    #[Test]
    public function shouldFetchQueryDataFromCache(): void
    {
        $key = new CacheKey('some-key');
        $cachedValue = 'cached-value';

        $this->prepareCachedData($key, $cachedValue);

        $dummyQuery = new DummyQuery('fresh-data');

        $this->queryFetcher->fetch(
            new CacheableQueryAdapter($dummyQuery, $key, CacheTime::oneMinute()),
            new OnSuccessCallback(fn ($data) => $this->assertSame($cachedValue, $data)),
            new OnErrorCallback(fn (\Throwable $error) => $this->fail($error->getMessage())),
        );
    }

    #[Test]
    public function shouldFetchQueryDataByHandler(): void
    {
        $this->queryFetcher->addHandler(new DummyQueryHandler(), PrioritizedItem::PRIORITY_LOWEST);

        $dummyQuery = new DummyQuery('fresh-data');

        $this->queryFetcher->fetch(
            $dummyQuery,
            new OnSuccessCallback(fn ($data) => $this->assertSame('fresh-data', $data)),
            new OnErrorCallback(fn (\Throwable $error) => $this->fail($error->getMessage())),
        );
    }

    #[Test]
    public function shouldFetchQueryDataFromCacheAndNotByHandler(): void
    {
        $key = new CacheKey('some-key');
        $cachedValue = 'cached-value';

        $this->prepareCachedData($key, $cachedValue);

        $this->queryFetcher->addHandler(new DummyQueryHandler(), PrioritizedItem::PRIORITY_MEDIUM);

        $dummyQuery = new DummyQuery('fresh-data');

        $this->queryFetcher->fetch(
            new CacheableQueryAdapter($dummyQuery, $key, CacheTime::oneMinute()),
            new OnSuccessCallback(fn ($data) => $this->assertSame($cachedValue, $data)),
            new OnErrorCallback(fn (\Throwable $error) => $this->fail($error->getMessage())),
        );
    }

    #[Test]
    public function shouldFetchQueryDataFromHandlerWithHigherPriorityAndCacheThem(): void
    {
        $key = new CacheKey('some-key');
        $cachedValue = 'cached-value';

        $this->prepareCachedData($key, $cachedValue);

        $this->queryFetcher->addHandler(new DummyQueryHandler(), PrioritizedItem::PRIORITY_HIGHEST);

        $dummyQuery = new DummyQuery('fresh-data');

        $this->queryFetcher->fetch(
            new CacheableQueryAdapter($dummyQuery, $key, CacheTime::oneMinute()),
            new OnSuccessCallback(fn ($data) => $this->assertSame('fresh-data', $data)),
            new OnErrorCallback(fn (\Throwable $error) => $this->fail($error->getMessage())),
        );

        $item = $this->cache->getItem($key->getHashedKey());
        $this->assertTrue($item->isHit());
        $this->assertSame('fresh-data', $item->get());
    }

    #[Test]
    public function shouldFetchFreshQueryDataAndCacheThem(): void
    {
        $key = new CacheKey('some-key');
        $cachedValue = 'cached-value';

        $this->prepareCachedData($key, $cachedValue);

        $this->queryFetcher->addHandler(new DummyQueryHandler(), PrioritizedItem::PRIORITY_MEDIUM);

        $dummyQuery = new DummyQuery('fresh-data');

        $this->queryFetcher->fetchFresh(
            new CacheableQueryAdapter($dummyQuery, $key, CacheTime::oneMinute()),
            new OnSuccessCallback(fn ($data) => $this->assertSame('fresh-data', $data)),
            new OnErrorCallback(fn (\Throwable $error) => $this->fail($error->getMessage())),
        );

        $item = $this->cache->getItem($key->getHashedKey());
        $this->assertTrue($item->isHit());
        $this->assertSame('fresh-data', $item->get());
    }

    #[Test]
    public function shouldNotFetchQueryDataWithNoCacheGiven(): void
    {
        $key = new CacheKey('some-key');
        $cachedValue = 'cached-value';

        $this->prepareCachedData($key, $cachedValue);

        $dummyQuery = new DummyQuery('fresh-data');

        $this->queryFetcherWithoutFeatures->fetch(
            new CacheableQueryAdapter($dummyQuery, $key, CacheTime::oneMinute()),
            new OnSuccessCallback(fn ($data) => $this->assertSame($cachedValue, $data)),
            new OnErrorCallback(function (\Throwable $error): void {
                $this->assertInstanceOf(CqrsExceptionInterface::class, $error);
                $this->assertInstanceOf(NoQueryHandlerUsedException::class, $error);
            }),
        );
    }

    #[Test]
    public function shouldNotFetchQueryDataWithNoItemInCache(): void
    {
        $key = new CacheKey('some-key');
        $dummyQuery = new DummyQuery('fresh-data');

        $this->queryFetcher->fetch(
            new CacheableQueryAdapter($dummyQuery, $key, CacheTime::oneMinute()),
            new OnSuccessCallback(
                fn ($data) => $this->fail('This should not be called, since there is nothing in cache.')
            ),
            new OnErrorCallback(function (\Throwable $error): void {
                $this->assertInstanceOf(CqrsExceptionInterface::class, $error);
                $this->assertInstanceOf(NoQueryHandlerUsedException::class, $error);
            }),
        );
    }

    #[Test]
    public function shouldNotFetchExpiredData(): void
    {
        $key = new CacheKey('some-key');
        $cachedValue = 'some-expired-value';

        $this->prepareCachedData($key, $cachedValue, new \DateTime('-5 seconds'));

        $dummyQuery = new DummyQuery('fresh-data');

        $this->queryFetcher->fetch(
            new CacheableQueryAdapter($dummyQuery, $key, CacheTime::oneMinute()),
            new OnSuccessCallback(
                fn ($data) => $this->fail('This should not be called, since cache item is expired.')
            ),
            new OnErrorCallback(function (\Throwable $error): void {
                $this->assertInstanceOf(CqrsExceptionInterface::class, $error);
                $this->assertInstanceOf(NoQueryHandlerUsedException::class, $error);
            }),
        );
    }

    #[Test]
    public function shouldNotFetchCachedDataFromCacheWithNoCacheTime(): void
    {
        $key = new CacheKey('some-key');
        $cachedValue = 'cached-value';

        $this->prepareCachedData($key, $cachedValue);

        $dummyQuery = new DummyQuery('fresh-data');

        $this->queryFetcher->fetch(
            new CacheableQueryAdapter($dummyQuery, $key, CacheTime::noCache()),
            new OnSuccessCallback(
                fn ($data) => $this->fail('This should not be called, since there is no-cache time.')
            ),
            new OnErrorCallback(function (\Throwable $error): void {
                $this->assertInstanceOf(CqrsExceptionInterface::class, $error);
                $this->assertInstanceOf(NoQueryHandlerUsedException::class, $error);
            }),
        );
    }

    #[Test]
    public function shouldNotFetchCachedDataFromCacheWithDisabledCacheAndDontChangeThemInCache(): void
    {
        $key = new CacheKey('some-key');
        $cachedValue = 'cached-value';

        $this->prepareCachedData($key, $cachedValue);

        $this->queryFetcher->addHandler(new DummyQueryHandler(), PrioritizedItem::PRIORITY_MEDIUM);

        $query = new CacheableQueryAdapter(new DummyQuery('fresh-data'), $key, CacheTime::oneMinute());

        $this->queryFetcher->fetch(
            $query,
            new OnSuccessCallback(fn ($data) => $this->assertSame($cachedValue, $data)),
            new OnErrorCallback(fn (\Throwable $error) => $this->fail($error->getMessage())),
        );

        $this->queryFetcher->disableCache();
        $this->assertFalse($this->queryFetcher->isCacheEnabled());

        $this->queryFetcher->fetch(
            $query,
            new OnSuccessCallback(fn ($data) => $this->assertSame('fresh-data', $data)),
            new OnErrorCallback(fn (\Throwable $error) => $this->fail($error->getMessage())),
        );

        $this->queryFetcher->enableCache();
        $this->assertTrue($this->queryFetcher->isCacheEnabled());

        $this->queryFetcher->fetch(
            $query,
            new OnSuccessCallback(fn ($data) => $this->assertSame($cachedValue, $data)),
            new OnErrorCallback(fn (\Throwable $error) => $this->fail($error->getMessage())),
        );
    }

    #[Test]
    public function shouldInvalidateCachedItemByQuery(): void
    {
        $profilerId = 'profiler-id';
        $key = new CacheKey('some-key');
        $cachedValue = 'cached-value';

        $this->prepareCachedData($key, $cachedValue);

        $this->queryFetcher->addHandler(new DummyQueryHandler(), PrioritizedItem::PRIORITY_MEDIUM);

        $query = new CacheableProfileableQueryAdapter(
            new DummyQuery('fresh-data'),
            $key,
            CacheTime::oneMinute(),
            $profilerId,
        );

        // fetch from cache
        $this->queryFetcher->fetch(
            $query,
            new OnSuccessCallback(fn ($data) => $this->assertSame($cachedValue, $data)),
            new OnErrorCallback(fn (\Throwable $error) => $this->fail($error->getMessage())),
        );
        $this->assertLastHandledBy(GetCachedHandler::class, 'string', $this->profilerBag->getBag());

        // invalidate cache
        $this->assertTrue($this->queryFetcher->invalidateQueryCache($query));

        // fetch fresh -> save to cache
        $this->queryFetcher->fetch(
            $query,
            new OnSuccessCallback(fn ($data) => $this->assertSame('fresh-data', $data)),
            new OnErrorCallback(fn (\Throwable $error) => $this->fail($error->getMessage())),
        );
        $this->assertLastHandledBy(DummyQueryHandler::class, 'string', $this->profilerBag->getBag());

        // fetch from cache
        $this->queryFetcher->fetch(
            $query,
            new OnSuccessCallback(fn ($data) => $this->assertSame('fresh-data', $data)),
            new OnErrorCallback(fn (\Throwable $error) => $this->fail($error->getMessage())),
        );
        $this->assertLastHandledBy(GetCachedHandler::class, 'string', $this->profilerBag->getBag());

        $this->assertCount(3, $this->profilerBag);
    }

    #[Test]
    public function shouldInvalidateCachedItemByHashedKey(): void
    {
        $profilerId = 'profiler-id';
        $key = new CacheKey('some-key');
        $cachedValue = 'cached-value';

        $this->prepareCachedData($key, $cachedValue);

        $this->queryFetcher->addHandler(new DummyQueryHandler(), PrioritizedItem::PRIORITY_MEDIUM);

        $query = new CacheableProfileableQueryAdapter(
            new DummyQuery('fresh-data'),
            $key,
            CacheTime::oneMinute(),
            $profilerId,
        );

        // fetch from cache
        $this->queryFetcher->fetch(
            $query,
            new OnSuccessCallback(fn ($data) => $this->assertSame($cachedValue, $data)),
            new OnErrorCallback(fn (\Throwable $error) => $this->fail($error->getMessage())),
        );
        $this->assertLastHandledBy(GetCachedHandler::class, 'string', $this->profilerBag->getBag());

        // invalidate cache
        $this->assertTrue($this->queryFetcher->invalidateCacheItem($key->getHashedKey()));

        // fetch fresh -> save to cache
        $this->queryFetcher->fetch(
            $query,
            new OnSuccessCallback(fn ($data) => $this->assertSame('fresh-data', $data)),
            new OnErrorCallback(fn (\Throwable $error) => $this->fail($error->getMessage())),
        );
        $this->assertLastHandledBy(DummyQueryHandler::class, 'string', $this->profilerBag->getBag());

        // fetch from cache
        $this->queryFetcher->fetch(
            $query,
            new OnSuccessCallback(fn ($data) => $this->assertSame('fresh-data', $data)),
            new OnErrorCallback(fn (\Throwable $error) => $this->fail($error->getMessage())),
        );
        $this->assertLastHandledBy(GetCachedHandler::class, 'string', $this->profilerBag->getBag());

        $this->assertCount(3, $this->profilerBag);
    }

    protected function prepareCachedData(CacheKey $key, string $value, \DateTime $expiresAt = null): void
    {
        $item = $this->cache->getItem($key->getHashedKey());
        if ($expiresAt) {
            $item->expiresAt($expiresAt);
        }
        $item->set($value);

        $this->cache->save($item);
    }

    #[Test]
    public function shouldProfileGivenQuery(): void
    {
        $profilerId = 'some-profiler-key';
        $dummyQuery = new DummyQuery('fresh-data');

        $this->assertCount(0, $this->profilerBag);

        $this->queryFetcher->fetch(
            new ProfileableQueryAdapter($dummyQuery, $profilerId),
            new OnSuccessCallback($this->ignore()),
            new OnErrorCallback($this->ignore()),
        );

        $this->assertCount(1, $this->profilerBag);

        foreach ($this->profilerBag as $item) {
            $this->assertSame($profilerId, $item->getProfilerId());
            $this->assertSame([], $item->getAdditionalData());
            $this->assertSame(ProfilerItem::TYPE_QUERY, $item->getItemType());
            $this->assertSame(ProfileableQueryAdapter::class, $item->getType());
            $this->assertNull($item->getCacheKey());
            $this->assertNull($item->isLoadedFromCache());
            $this->assertNull($item->isStoredInCache());
            $this->assertNull($item->getResponse());
            $this->assertNull($item->getError());
            $this->assertNull($item->getHandledBy());
        }
    }

    #[Test]
    public function shouldProfileGivenCacheableQuery(): void
    {
        $profilerId = 'some-profiler-key';
        $cacheKey = new CacheKey('some-cache-key');
        $dummyQuery = new DummyQuery('fresh-data');

        $this->queryFetcher->addHandler(new DummyQueryHandler(), PrioritizedItem::PRIORITY_MEDIUM);

        $this->assertCount(0, $this->profilerBag);

        $this->queryFetcher->fetch(
            new CacheableProfileableQueryAdapter(
                $dummyQuery,
                $cacheKey,
                CacheTime::oneMinute(),
                $profilerId,
            ),
            new OnSuccessCallback($this->ignore()),
            new OnErrorCallback($this->ignore()),
        );

        $this->assertCount(1, $this->profilerBag);

        foreach ($this->profilerBag as $item) {
            $this->assertSame($profilerId, $item->getProfilerId());
            $this->assertSame([], $item->getAdditionalData());
            $this->assertSame(ProfilerItem::TYPE_QUERY, $item->getItemType());
            $this->assertSame(CacheableProfileableQueryAdapter::class, $item->getType());
            $this->assertSame($cacheKey, $item->getCacheKey());
            $this->assertFalse($item->isLoadedFromCache());
            $this->assertTrue($item->isStoredInCache());
            $this->assertSame('fresh-data', $item->getResponse());
            $this->assertNull($item->getError());
            $this->assertHandledBy(DummyQueryHandler::class, 'string', $item->getHandledBy());
            $this->assertSame([], $item->getDecodedBy());
        }
    }

    #[Test]
    public function shouldProfileGivenCacheableQueryFetchedFromCache(): void
    {
        $profilerId = 'some-profiler-key';
        $cacheKey = new CacheKey('some-cache-key');
        $cachedValue = 'cached-value';
        $dummyQuery = new DummyQuery('fresh-data');

        $this->prepareCachedData($cacheKey, $cachedValue);

        $this->queryFetcher->addHandler(new DummyQueryHandler(), PrioritizedItem::PRIORITY_MEDIUM);

        $this->assertCount(0, $this->profilerBag);

        $this->queryFetcher->fetch(
            new CacheableProfileableQueryAdapter(
                $dummyQuery,
                $cacheKey,
                CacheTime::oneMinute(),
                $profilerId,
            ),
            new OnSuccessCallback($this->ignore()),
            new OnErrorCallback($this->ignore()),
        );

        $this->assertCount(1, $this->profilerBag);

        foreach ($this->profilerBag as $item) {
            $this->assertSame($profilerId, $item->getProfilerId());
            $this->assertSame([], $item->getAdditionalData());
            $this->assertSame(ProfilerItem::TYPE_QUERY, $item->getItemType());
            $this->assertSame(CacheableProfileableQueryAdapter::class, $item->getType());
            $this->assertSame($cacheKey, $item->getCacheKey());
            $this->assertTrue($item->isLoadedFromCache());
            $this->assertFalse($item->isStoredInCache());
            $this->assertSame($cachedValue, $item->getResponse());
            $this->assertNull($item->getError());
            $this->assertHandledBy(GetCachedHandler::class, 'string', $item->getHandledBy());
            $this->assertSame([], $item->getDecodedBy());
        }
    }

    #[Test]
    public function shouldNotProfileNotProfileableQuery(): void
    {
        $dummyQuery = new DummyQuery('fresh-data');

        $this->queryFetcher->fetch(
            $dummyQuery,
            new OnSuccessCallback($this->ignore()),
            new OnErrorCallback($this->ignore()),
        );

        $this->assertCount(0, $this->profilerBag);
    }

    #[Test]
    public function shouldNotProfileWithoutProfilerBag(): void
    {
        $profilerId = 'some-profiler-key';
        $dummyQuery = new DummyQuery('fresh-data');

        $this->queryFetcherWithoutFeatures->fetch(
            new ProfileableQueryAdapter($dummyQuery, $profilerId),
            new OnSuccessCallback($this->ignore()),
            new OnErrorCallback($this->ignore()),
        );

        $this->assertCount(0, $this->profilerBag);
    }

    #[Test]
    public function shouldFetchAndDecodeQuery(): void
    {
        $this->queryFetcher->addHandler(new DummyQueryHandler(), PrioritizedItem::PRIORITY_MEDIUM);

        $dummyQuery = new DummyQuery('fresh-data');

        $decoder = new CallbackResponseDecoder(
            fn ($response) => true,
            fn (string $response) => sprintf('decoded:%s', $response),
        );

        $this->queryFetcher->addDecoder($decoder, 50);
        $decodedResponse = $this->queryFetcher->fetchAndReturn($dummyQuery);

        $this->assertSame('decoded:fresh-data', $decodedResponse);
    }

    #[Test]
    public function shouldFetchAndDecodeQueryAndProfileOriginalResponse(): void
    {
        $profilerId = 'profiler-id';

        $this->queryFetcher->addHandler(new DummyQueryHandler(), PrioritizedItem::PRIORITY_MEDIUM);

        $dummyQuery = new ProfileableQueryAdapter(new DummyQuery('fresh-data'), $profilerId);
        $decoder = new CallbackResponseDecoder(
            fn ($response) => true,
            fn (string $response) => sprintf('decoded:%s', $response),
        );

        $this->queryFetcher->addDecoder($decoder, 50);
        $decodedResponse = $this->queryFetcher->fetchAndReturn($dummyQuery);

        $this->assertSame('decoded:fresh-data', $decodedResponse);

        $this->assertCount(1, $this->profilerBag);

        foreach ($this->profilerBag as $item) {
            $this->assertSame($profilerId, $item->getProfilerId());
            $this->assertSame('decoded:fresh-data', $item->getResponse());
            $this->assertSame(ProfilerItem::TYPE_QUERY, $item->getItemType());
        }
    }

    #[Test]
    public function shouldFetchFromCacheAndDecodeQueryAndProfileIt(): void
    {
        $profilerId = 'profiler-id';
        $key = new CacheKey('some-key');
        $cachedValue = 'cached-value';

        $this->prepareCachedData($key, $cachedValue);

        $this->queryFetcher->addHandler(new DummyQueryHandler(), PrioritizedItem::PRIORITY_MEDIUM);

        $dummyQuery = new CacheableProfileableQueryAdapter(
            new DummyQuery('fresh-data'),
            $key,
            CacheTime::oneMinute(),
            $profilerId,
        );

        $decoder = new CallbackResponseDecoder(
            fn ($response) => true,
            fn (string $response) => sprintf('decoded:%s', $response),
        );

        $this->queryFetcher->addDecoder($decoder, 50);
        $decodedResponse = $this->queryFetcher->fetchAndReturn($dummyQuery);

        $this->assertSame('decoded:cached-value', $decodedResponse);

        $this->assertCount(1, $this->profilerBag);

        foreach ($this->profilerBag as $item) {
            $this->assertSame($profilerId, $item->getProfilerId());
            $this->assertSame([], $item->getAdditionalData());
            $this->assertSame(ProfilerItem::TYPE_QUERY, $item->getItemType());
            $this->assertSame(CacheableProfileableQueryAdapter::class, $item->getType());
            $this->assertSame($key, $item->getCacheKey());
            $this->assertTrue($item->isLoadedFromCache());
            $this->assertFalse($item->isStoredInCache());
            $this->assertSame('decoded:cached-value', $item->getResponse());
            $this->assertNull($item->getError());
            $this->assertHandledBy(GetCachedHandler::class, 'string', $item->getHandledBy());
            $this->assertSame(
                ['Lmc\Cqrs\Types\Decoder\CallbackResponseDecoder<string, string>'],
                $item->getDecodedBy(),
            );
        }
    }

    #[Test]
    public function shouldFetchFreshQuery(): void
    {
        $key = new CacheKey('some-key');
        $cachedValue = 'cached-value';

        $this->prepareCachedData($key, $cachedValue);

        $this->queryFetcher->addHandler(new DummyQueryHandler(), PrioritizedItem::PRIORITY_MEDIUM);

        $dummyQuery = new CacheableQueryAdapter(new DummyQuery('fresh-data'), $key, CacheTime::oneMinute());

        $decodedResponse = $this->queryFetcher->fetchAndReturn($dummyQuery);

        $this->assertSame('cached-value', $decodedResponse);
    }

    #[Test]
    public function shouldThrowExceptionOnFetchAndDecodeWithoutAnyHandler(): void
    {
        $query = new DummyQuery('fresh-data');

        $this->expectException(NoQueryHandlerUsedException::class);

        $this->queryFetcher->fetchAndReturn($query);
    }

    #[Test]
    public function shouldNotUseMoreThanOneHandler(): void
    {
        $query = new DummyQuery('fresh-data');

        $failHandler = new class() implements QueryHandlerInterface {
            public function supports(QueryInterface $query): bool
            {
                return true;
            }

            public function prepare(QueryInterface $query): QueryInterface
            {
                return $query;
            }

            public function handle(
                QueryInterface $query,
                OnSuccessInterface $onSuccess,
                OnErrorInterface $onError,
            ): void {
                throw new \Exception(sprintf('Method %s should not be called.', __METHOD__));
            }
        };

        $this->queryFetcher->addHandler(new DummyQueryHandler(), PrioritizedItem::PRIORITY_HIGHEST);
        $this->queryFetcher->addHandler($failHandler, PrioritizedItem::PRIORITY_MEDIUM);

        $response = $this->queryFetcher->fetchAndReturn($query);

        $this->assertSame('fresh-data', $response);
    }

    #[Test]
    public function shouldFetchQueryAndUseMultipleDecodersAndCacheTheFinalResult(): void
    {
        $profilerId = 'profiler-id';
        $key = new CacheKey('some-key');
        $expectedResponse = 'decoder:3:decoder:2:decoder:1:fresh-data';

        $this->queryFetcher->addHandler(new DummyQueryHandler(), PrioritizedItem::PRIORITY_MEDIUM);

        $dummyQuery = new CacheableProfileableQueryAdapter(
            new DummyQuery('fresh-data'),
            $key,
            CacheTime::oneMinute(),
            $profilerId,
        );

        $decoder = function (int $i) {
            return new CallbackResponseDecoder(
                fn (string $response, $initiator) => is_string($response),
                fn (string $response) => sprintf('decoder:%d:%s', $i, $response),
            );
        };

        $this->queryFetcher->addDecoder($decoder(2), 60);
        $this->queryFetcher->addDecoder($decoder(1), 70);
        $this->queryFetcher->addDecoder($decoder(3), 50);

        $decodedResponse = $this->queryFetcher->fetchAndReturn($dummyQuery);

        $this->assertSame($expectedResponse, $decodedResponse);

        $this->assertCount(1, $this->profilerBag);

        foreach ($this->profilerBag as $item) {
            $this->assertSame($profilerId, $item->getProfilerId());
            $this->assertSame([], $item->getAdditionalData());
            $this->assertSame(ProfilerItem::TYPE_QUERY, $item->getItemType());
            $this->assertSame(CacheableProfileableQueryAdapter::class, $item->getType());
            $this->assertSame($key, $item->getCacheKey());
            $this->assertFalse($item->isLoadedFromCache());
            $this->assertTrue($item->isStoredInCache());
            $this->assertSame($expectedResponse, $item->getResponse());
            $this->assertNull($item->getError());
            $this->assertHandledBy(DummyQueryHandler::class, 'string', $item->getHandledBy());
            $this->assertSame(
                [
                    'Lmc\Cqrs\Types\Decoder\CallbackResponseDecoder<string, string>',
                    'Lmc\Cqrs\Types\Decoder\CallbackResponseDecoder<string, string>',
                    'Lmc\Cqrs\Types\Decoder\CallbackResponseDecoder<string, string>',
                ],
                $item->getDecodedBy(),
            );
        }
    }

    #[Test]
    public function shouldFetchQueryAndUseOnlyOneDecoder(): void
    {
        $profilerId = 'profiler-id';
        $key = new CacheKey('some-key');
        $expectedResponse = 'final-decoded:fresh-data';

        $this->queryFetcher->addHandler(new DummyQueryHandler(), PrioritizedItem::PRIORITY_MEDIUM);

        $dummyQuery = new CacheableProfileableQueryAdapter(
            new DummyQuery('fresh-data'),
            $key,
            CacheTime::oneMinute(),
            $profilerId,
        );

        $decoder = function (int $i) {
            return new CallbackResponseDecoder(
                'is_string',
                fn (string $response) => sprintf('decoder:%d:%s', $i, $response),
            );
        };

        $finalDecoder = new CallbackResponseDecoder(
            fn (string $response, $initiator) => is_string($response),
            fn (string $response) => new DecodedValue(sprintf('final-decoded:%s', $response))
        );

        $this->queryFetcher->addDecoder($decoder(2), 60);
        $this->queryFetcher->addDecoder($decoder(1), 70);
        $this->queryFetcher->addDecoder($decoder(3), 50);
        $this->queryFetcher->addDecoder($finalDecoder, 90);

        $decodedResponse = $this->queryFetcher->fetchAndReturn($dummyQuery);

        $this->assertSame($expectedResponse, $decodedResponse);

        $this->assertCount(1, $this->profilerBag);

        foreach ($this->profilerBag as $item) {
            $this->assertSame($profilerId, $item->getProfilerId());
            $this->assertSame([], $item->getAdditionalData());
            $this->assertSame(ProfilerItem::TYPE_QUERY, $item->getItemType());
            $this->assertSame(CacheableProfileableQueryAdapter::class, $item->getType());
            $this->assertSame($key, $item->getCacheKey());
            $this->assertFalse($item->isLoadedFromCache());
            $this->assertTrue($item->isStoredInCache());
            $this->assertSame($expectedResponse, $item->getResponse());
            $this->assertNull($item->getError());
            $this->assertHandledBy(DummyQueryHandler::class, 'string', $item->getHandledBy());
            $this->assertSame(
                ['Lmc\Cqrs\Types\Decoder\CallbackResponseDecoder<string, DecodedValue<string>>'],
                $item->getDecodedBy(),
            );
        }
    }

    #[Test]
    public function shouldFetchConsequentQuery(): void
    {
        $queryA = new ProfileableQueryAdapter(new DummyQuery('response-A'), 'query-A');
        $queryB = new ProfileableQueryAdapter(new DummyQuery('response-B'), 'query-B');

        $this->queryFetcher->addHandler(new DummyQueryHandler(), PrioritizedItem::PRIORITY_MEDIUM);

        $decoderA = new CallbackResponseDecoder(
            fn (string $response) => $response === 'response-A',
            fn (string $responseA) => sprintf('%s:%s', $responseA, $this->queryFetcher->fetchAndReturn($queryB)[0]),
        );

        $decoderB = new CallbackResponseDecoder(
            fn (string $response) => $response === 'response-B',
            fn (string $response) => [sprintf('decoded:%s', $response)],
        );

        $this->queryFetcher->addDecoder($decoderA, PrioritizedItem::PRIORITY_HIGHEST);
        $this->queryFetcher->addDecoder($decoderB, PrioritizedItem::PRIORITY_HIGHEST);

        $response = $this->queryFetcher->fetchAndReturn($queryA);

        $this->assertSame('response-A:decoded:response-B', $response);

        foreach ($this->profilerBag->getIterator() as $profilerItem) {
            $this->assertCount(1, $profilerItem->getDecodedBy());
        }
    }

    #[Test]
    public function shouldCacheResponseBeforeDecodingByImpureDecoder(): void
    {
        $onError = new OnErrorCallback(fn (\Throwable $error) => $this->fail($error->getMessage()));
        $expectsValueOnSuccess = fn (string $expectedValue) => new OnSuccessCallback(
            fn ($data) => $this->assertSame($expectedValue, $data)
        );

        $key = new CacheKey('some-key');

        $dummyQuery = new DummyQuery('fresh-data');
        $query = new CacheableQueryAdapter($dummyQuery, $key, CacheTime::oneMinute());

        $translationDecoder = new ImpureTranslationDecoder('cs');

        $this->queryFetcher->addHandler(new DummyQueryHandler(), PrioritizedItem::PRIORITY_MEDIUM);
        $this->queryFetcher->addDecoder($translationDecoder, PrioritizedItem::PRIORITY_MEDIUM);

        $this->queryFetcher->fetch(
            $query,
            $expectsValueOnSuccess('translated[cs]: fresh-data'),
            $onError,
        );

        $item = $this->cache->getItem($key->getHashedKey());
        $this->assertTrue($item->isHit());
        $this->assertSame('fresh-data', $item->get());

        $translationDecoder->changeLanguage('en');

        $this->queryFetcher->fetch(
            $query,
            $expectsValueOnSuccess('translated[en]: fresh-data'),
            $onError,
        );

        $item = $this->cache->getItem($key->getHashedKey());
        $this->assertTrue($item->isHit());
        $this->assertSame('fresh-data', $item->get());
    }

    #[Test]
    #[DataProvider('provideVerbosity')]
    public function shouldUseProfilerBagVerbosity(
        string $verbosity,
        bool $withDecoder,
        array $expectedAdditionalData,
    ): void {
        $this->profilerBag->setVerbosity($verbosity);

        $profilerId = 'some-profiler-key';
        $cacheKey = new CacheKey('some-cache-key');
        $dummyQuery = new DummyQuery('fresh-data');

        $expectedResponse = $withDecoder
            ? 'translated[cs]: fresh-data'
            : 'fresh-data';
        $expectedDecoders = $withDecoder
            ? [ImpureTranslationDecoder::class . '<string, string>']
            : [];

        $this->queryFetcher->addHandler(new DummyQueryHandler(), PrioritizedItem::PRIORITY_MEDIUM);
        if ($withDecoder) {
            $this->queryFetcher->addDecoder(new ImpureTranslationDecoder('cs'), PrioritizedItem::PRIORITY_MEDIUM);
        }

        $this->assertCount(0, $this->profilerBag);

        $this->queryFetcher->fetch(
            new CacheableProfileableQueryAdapter(
                $dummyQuery,
                $cacheKey,
                CacheTime::oneMinute(),
                $profilerId,
            ),
            new OnSuccessCallback($this->ignore()),
            new OnErrorCallback($this->ignore()),
        );

        $this->assertCount(1, $this->profilerBag);

        foreach ($this->profilerBag as $item) {
            $this->assertSame($profilerId, $item->getProfilerId());
            $this->assertSame($expectedAdditionalData, $item->getAdditionalData());
            $this->assertSame(ProfilerItem::TYPE_QUERY, $item->getItemType());
            $this->assertSame(CacheableProfileableQueryAdapter::class, $item->getType());
            $this->assertSame($cacheKey, $item->getCacheKey());
            $this->assertFalse($item->isLoadedFromCache());
            $this->assertTrue($item->isStoredInCache());
            $this->assertSame($expectedResponse, $item->getResponse());
            $this->assertNull($item->getError());
            $this->assertHandledBy(DummyQueryHandler::class, 'string', $item->getHandledBy());
            $this->assertSame($expectedDecoders, $item->getDecodedBy());
        }
    }

    public static function provideVerbosity(): array
    {
        return [
            // verbosity, withDecoder, expected
            'default' => [
                ProfilerBag::VERBOSITY_NORMAL,
                false,
                [],
            ],
            'verbose' => [
                ProfilerBag::VERBOSITY_VERBOSE,
                false,
                [
                    'CQRS.verbose' => [
                        [
                            'handled by' => DummyQueryHandler::class,
                            'response' => 'string',
                        ],
                        [
                            'start decoding response' => 'string',
                        ],
                    ],
                ],
            ],
            'debug' => [
                ProfilerBag::VERBOSITY_DEBUG,
                false,
                [
                    'CQRS.debug' => [
                        [
                            'handled by' => DummyQueryHandler::class,
                            'response' => 'fresh-data',
                        ],
                        [
                            'start decoding response' => 'string',
                        ],
                        [
                            'cache response' => 'fresh-data',
                        ],
                    ],
                ],
            ],
            'default with decoder' => [
                ProfilerBag::VERBOSITY_NORMAL,
                true,
                [],
            ],
            'verbose with decoder' => [
                ProfilerBag::VERBOSITY_VERBOSE,
                true,
                [
                    'CQRS.verbose' => [
                        [
                            'handled by' => DummyQueryHandler::class,
                            'response' => 'string',
                        ],
                        [
                            'start decoding response' => 'string',
                        ],
                        [
                            'loop' => 0,
                            'decoder' => ImpureTranslationDecoder::class,
                            'response' => 'string',
                            'decoded response' => 'string',
                        ],
                    ],
                ],
            ],
            'debug with decoder' => [
                ProfilerBag::VERBOSITY_DEBUG,
                true,
                [
                    'CQRS.debug' => [
                        [
                            'handled by' => DummyQueryHandler::class,
                            'response' => 'fresh-data',
                        ],
                        [
                            'start decoding response' => 'string',
                        ],
                        [
                            'loop' => 0,
                            'trying decoder' => ImpureTranslationDecoder::class,
                        ],
                        [
                            'loop' => 0,
                            'decoder' => ImpureTranslationDecoder::class,
                            'supports response' => 'string',
                        ],
                        [
                            'impure decoder' => ImpureTranslationDecoder::class,
                            'try cache response before decoding' => 'fresh-data',
                        ],
                        [
                            'cache response' => 'fresh-data',
                        ],
                        [
                            'loop' => 0,
                            'decoder' => ImpureTranslationDecoder::class,
                            'response' => 'fresh-data',
                            'decoded response' => 'translated[cs]: fresh-data',
                        ],
                    ],
                ],
            ],
        ];
    }
}
