<?php declare(strict_types=1);

namespace Lmc\Cqrs\Handler;

use Lmc\Cqrs\Handler\Core\CommonCQRSTrait;
use Lmc\Cqrs\Handler\Handler\GetCachedHandler;
use Lmc\Cqrs\Types\Decoder\ResponseDecoderInterface;
use Lmc\Cqrs\Types\Exception\NoQueryHandlerUsedException;
use Lmc\Cqrs\Types\Feature\CacheableInterface;
use Lmc\Cqrs\Types\Feature\ProfileableInterface;
use Lmc\Cqrs\Types\QueryFetcherInterface;
use Lmc\Cqrs\Types\QueryHandlerInterface;
use Lmc\Cqrs\Types\QueryInterface;
use Lmc\Cqrs\Types\Utils;
use Lmc\Cqrs\Types\ValueObject\OnErrorCallback;
use Lmc\Cqrs\Types\ValueObject\OnErrorInterface;
use Lmc\Cqrs\Types\ValueObject\OnSuccessCallback;
use Lmc\Cqrs\Types\ValueObject\OnSuccessInterface;
use Lmc\Cqrs\Types\ValueObject\PrioritizedItem;
use Lmc\Cqrs\Types\ValueObject\ProfilerItem;
use Psr\Cache\CacheItemPoolInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\Stopwatch\Stopwatch;

/**
 * @phpstan-template Request
 * @phpstan-template Response
 * @phpstan-template DecodedResponse
 * @phpstan-implements QueryFetcherInterface<Request, DecodedResponse>
 */
class QueryFetcher implements QueryFetcherInterface
{
    use CommonCQRSTrait;

    /**
     * @phpstan-var PrioritizedItem<QueryHandlerInterface<mixed, mixed>>[]
     * @var PrioritizedItem[]
     */
    private array $handlers = [];

    /**
     * @phpstan-var ?DecodedResponse
     * @var ?mixed
     */
    private $lastSuccess;

    private bool $isCacheEnabled;
    private ?CacheItemPoolInterface $cache;

    /**
     * Custom Handler(s) priority defaults to 50 (medium)
     *
     * Handler can be set by an array of Handler and a priority
     * For instance:
     *  - [ new MyTopMostHandler(), PrioritizedItem::PRIORITY_HIGHEST ]
     *  - [ new FallbackHandler(), PrioritizedItem::PRIORITY_LOW ]
     *
     * Or it can be PrioritizedItem instance
     *  - new PrioritizedItem(new MyTopMostHandler(), PrioritizedItem::PRIORITY_HIGHEST)
     *
     *
     * Custom Decoder(s) priority defaults to 50 (medium)
     *
     * Decoder can be set by an array of Decoder and a priority
     * For instance:
     *  - [ new MyTopMostDecoder(), PrioritizedItem::PRIORITY_HIGHEST ]
     *  - [ new FallbackDecoder(), PrioritizedItem::PRIORITY_LOW ]
     *
     * Or it can be PrioritizedItem instance
     *  - new PrioritizedItem(new MyTopMostDecoder(), PrioritizedItem::PRIORITY_HIGHEST)
     *
     * @param iterable<QueryHandlerInterface<mixed, mixed>|iterable|PrioritizedItem<QueryHandlerInterface<mixed, mixed>>> $customHandlers
     * @param iterable<ResponseDecoderInterface<mixed, mixed>|iterable|PrioritizedItem<ResponseDecoderInterface<mixed, mixed>>> $customDecoders
     * @see PrioritizedItem::PRIORITY_MEDIUM
     */
    public function __construct(
        bool $isCacheEnabled,
        ?CacheItemPoolInterface $cache,
        ?ProfilerBag $profilerBag,
        iterable $customHandlers = [],
        iterable $customDecoders = []
    ) {
        if ($isCacheEnabled && $cache === null) {
            throw new \InvalidArgumentException('Cache pool must be set if cache is enabled.');
        }

        $this->isCacheEnabled = $isCacheEnabled;
        $this->cache = $cache;
        $this->profilerBag = $profilerBag;

        $this->register($customHandlers, [$this, 'addHandler']);
        $this->register($customDecoders, [$this, 'addDecoder']);

        if ($cache) {
            $this->addHandler(new GetCachedHandler($cache), PrioritizedItem::PRIORITY_HIGH);
        }
    }

    public function addHandler(QueryHandlerInterface $handler, int $priority): void
    {
        $this->handlers[] = new PrioritizedItem($handler, $priority);

        uasort($this->handlers, [PrioritizedItem::class, 'compare']);
    }

    public function fetch(QueryInterface $query, OnSuccessInterface $onSuccess, OnErrorInterface $onError): void
    {
        $this->fetchResponse($query, $onSuccess, $onError);
    }

    public function fetchFresh(QueryInterface $query, OnSuccessInterface $onSuccess, OnErrorInterface $onError): void
    {
        $this->fetchResponse($query, $onSuccess, $onError, fn ($handler) => !($handler instanceof GetCachedHandler));
    }

    /**
     * @phpstan-param QueryInterface<Request> $query
     * @phpstan-param OnSuccessInterface<DecodedResponse> $onSuccess
     */
    private function fetchResponse(
        QueryInterface $query,
        OnSuccessInterface $onSuccess,
        OnErrorInterface $onError,
        callable $filter = null
    ): void {
        $this->setIsHandled(false);
        $this->lastSuccess = null;
        $this->lastError = null;

        foreach ($this->iterateHandlers($filter) as $handler) {
            if ($handler->supports($query)) {
                $handler->prepare($query);
            }
        }

        $currentProfileKey = null;
        if ($query instanceof ProfileableInterface) {
            $currentProfileKey = $this->startProfileQuery($query);
        }

        foreach ($this->iterateHandlers($filter) as $handler) {
            if (!$this->isCacheEnabled() && $handler instanceof GetCachedHandler) {
                continue;
            }

            if ($handler->supports($query)) {
                $handler->handle(
                    $query,
                    new OnSuccessCallback(function ($response): void {
                        $this->setIsHandled(true, $response);
                        $this->lastSuccess = $response;
                    }),
                    new OnErrorCallback(function (\Throwable $error): void {
                        $this->setIsHandled(true, $error);
                        $this->lastError = $error;
                    }),
                );

                if ($this->isHandled && $this->lastError === null) {
                    $this->decodeResponse($query, $currentProfileKey);
                }

                if ($this->isHandled && $query instanceof ProfileableInterface) {
                    $this->profileQueryFinished($query, $currentProfileKey, $handler);
                }

                if ($this->isHandled && $this->lastError) {
                    $onError($this->lastError);

                    return;
                }

                if ($this->isHandled) {
                    if (!($handler instanceof GetCachedHandler) && $query instanceof CacheableInterface) {
                        $this->cacheSuccess($query, $currentProfileKey);
                    }

                    $onSuccess($this->lastSuccess);

                    return;
                }
            }
        }

        $onError(NoQueryHandlerUsedException::create($query, $this->getHandlers()));
    }

    /**
     * @phpstan-param QueryInterface<Request> $query
     * @phpstan-return DecodedResponse
     * @throws \Throwable
     * @return mixed
     */
    public function fetchAndReturn(QueryInterface $query)
    {
        return $this->fetchAndReturnQuery($query, [$this, 'fetch']);
    }

    /**
     * @phpstan-param QueryInterface<Request> $query
     * @phpstan-return DecodedResponse
     * @throws \Throwable
     * @return mixed
     */
    public function fetchFreshAndReturn(QueryInterface $query)
    {
        return $this->fetchAndReturnQuery($query, [$this, 'fetchFresh']);
    }

    /**
     * @phpstan-param QueryInterface<Request> $query
     * @phpstan-return DecodedResponse
     * @throws \Throwable
     * @return mixed
     */
    private function fetchAndReturnQuery(QueryInterface $query, callable $fetch)
    {
        $response = null;

        $fetch(
            $query,
            new OnSuccessCallback(function ($decodedResponse) use (&$response): void {
                $response = $decodedResponse;
            }),
            OnErrorCallback::throwOnError()
        );

        return $response;
    }

    private function iterateHandlers(callable $filter = null): array
    {
        $handlers = array_map(
            fn (PrioritizedItem $PrioritizedItem) => $PrioritizedItem->getItem(),
            $this->handlers
        );

        return $filter
            ? array_filter($handlers, $filter)
            : $handlers;
    }

    private function startProfileQuery(ProfileableInterface $query): UuidInterface
    {
        $key = Uuid::uuid4();
        if ($this->profilerBag) {
            $profilerItem = new ProfilerItem(
                $query->getProfilerId(),
                $query->getProfilerData(),
                ProfilerItem::TYPE_QUERY,
                get_class($query)
            );

            if ($query instanceof CacheableInterface) {
                $profilerItem->setCacheKey($query->getCacheKey());
                $profilerItem->setIsStoredInCache(false, null);
            }

            $this->profilerBag->add($key, $profilerItem);

            $this->stopwatch = $this->stopwatch ?? new Stopwatch();
            $this->stopwatch->start($key->toString());
        }

        return $key;
    }

    /** @phpstan-param QueryHandlerInterface<Request, Response> $currentHandler */
    private function profileQueryFinished(
        ProfileableInterface $query,
        ?UuidInterface $currentProfilerKey,
        QueryHandlerInterface $currentHandler
    ): void {
        if ($this->profilerBag && $currentProfilerKey && ($profilerItem = $this->profilerBag->get($currentProfilerKey))) {
            if ($this->stopwatch) {
                $elapsed = $this->stopwatch->stop($currentProfilerKey->toString());

                $profilerItem->setDuration((int) $elapsed->getDuration());
            }

            $profilerItem->setHandledBy(sprintf(
                '%s<%s>',
                Utils::getType($currentHandler),
                $this->handledResponseType
            ));
            $profilerItem->setDecodedBy($this->lastUsedDecoders[$currentProfilerKey->toString()] ?? []);

            if ($query instanceof CacheableInterface) {
                $profilerItem->setCacheKey($query->getCacheKey());
                $profilerItem->setIsLoadedFromCache($currentHandler instanceof GetCachedHandler);
            }

            if ($this->lastSuccess) {
                $profilerItem->setResponse($this->lastSuccess);
            }

            if ($this->lastError) {
                $profilerItem->setError($this->lastError);
            }
        }
    }

    private function cacheSuccess(CacheableInterface $query, ?UuidInterface $currentProfilerKey): void
    {
        if ($this->cache
            && $this->isCacheEnabled()
            && ($lifetime = $query->getCacheTime()->getSeconds()) > 0
        ) {
            $cacheItem = $this->cache->getItem($query->getCacheKey()->getHashedKey());
            $cacheItem->expiresAfter($lifetime);
            $cacheItem->set($this->lastSuccess);

            $isCached = $this->cache->save($cacheItem);

            if ($query instanceof ProfileableInterface
                && $this->profilerBag
                && $currentProfilerKey
                && ($profilerItem = $this->profilerBag->get($currentProfilerKey))
            ) {
                $profilerItem->setIsStoredInCache($isCached, $lifetime);
            }
        }
    }

    public function enableCache(): void
    {
        $this->isCacheEnabled = true;
    }

    public function disableCache(): void
    {
        $this->isCacheEnabled = false;
    }

    public function isCacheEnabled(): bool
    {
        return $this->isCacheEnabled;
    }

    public function invalidateQueryCache(QueryInterface $query): bool
    {
        if ($query instanceof CacheableInterface) {
            return $this->invalidateCacheItem($query->getCacheKey()->getHashedKey());
        }

        return false;
    }

    public function invalidateCacheItem(string $cacheKeyHash): bool
    {
        if ($this->cache && $this->cache->hasItem($cacheKeyHash)) {
            $result = $this->cache->deleteItem($cacheKeyHash);

            if ($this->profilerBag) {
                foreach ($this->profilerBag as $item) {
                    if (($cacheKey = $item->getCacheKey()) && $cacheKey->getHashedKey() === $cacheKeyHash) {
                        $item->setIsStoredInCache(false, null);
                    }
                }
            }

            return $result;
        }

        return false;
    }
}
