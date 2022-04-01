<?php declare(strict_types=1);

namespace Lmc\Cqrs\Handler;

use Lmc\Cqrs\Handler\Core\CommonCQRSTrait;
use Lmc\Cqrs\Handler\Core\FetchContext;
use Lmc\Cqrs\Handler\Handler\GetCachedHandler;
use Lmc\Cqrs\Types\Decoder\ImpureResponseDecoderInterface;
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

/**
 * @phpstan-template Request
 * @phpstan-template Response
 * @phpstan-template DecodedResponse
 *
 * @phpstan-type Handler QueryHandlerInterface<Request, Response>
 * @phpstan-type Context FetchContext<Request, Handler, DecodedResponse>
 *
 * @phpstan-implements QueryFetcherInterface<Request, DecodedResponse>
 */
class QueryFetcher implements QueryFetcherInterface
{
    /** @phpstan-use CommonCQRSTrait<Context, Handler> */
    use CommonCQRSTrait;

    /**
     * @phpstan-var PrioritizedItem<QueryHandlerInterface<mixed, mixed>>[]
     * @var PrioritizedItem[]
     */
    private array $handlers = [];

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
        $context = new FetchContext($query);

        foreach ($this->iterateHandlers($filter) as $handler) {
            if ($handler->supports($query)) {
                $handler->prepare($query);
            }
        }

        if ($query instanceof ProfileableInterface) {
            $this->startProfileQuery($query, $context);
        }

        foreach ($this->iterateHandlers($filter) as $handler) {
            if (!$this->isCacheEnabled() && $handler instanceof GetCachedHandler) {
                continue;
            }

            if (!$handler->supports($query)) {
                continue;
            }

            $handler->handle(
                $query,
                new OnSuccessCallback(function ($response) use ($context, $handler): void {
                    $this->setIsHandled($handler, $context, $response);
                    $context->setResponse($response);
                }),
                new OnErrorCallback(function (\Throwable $error) use ($context, $handler): void {
                    $this->setIsHandled($handler, $context, $error);
                    $context->setError($error);
                }),
            );

            if ($context->isHandled() && $context->getError() === null) {
                $this->decodeResponse($context);
            }

            if ($context->isHandled() && $query instanceof ProfileableInterface) {
                $this->profileQueryFinished($context);
            }

            if ($context->isHandled() && ($error = $context->getError())) {
                $onError($error);

                return;
            }

            if ($context->isHandled()) {
                $response = $context->getResponse();
                if ($query instanceof CacheableInterface && $this->shouldCacheResponse($context)) {
                    $this->cacheSuccess($query, $context, $response);
                }

                $onSuccess($response);

                return;
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

    /** @phpstan-param Context $context */
    private function startProfileQuery(ProfileableInterface $query, FetchContext $context): void
    {
        if ($this->profilerBag) {
            $key = $context->getKey();

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

            $context->startStopwatch();
        }
    }

    /**
     * @phpstan-template T
     * @phpstan-template U
     * @phpstan-param Context $context
     * @phpstan-param ResponseDecoderInterface<T, U> $decoder
     * @phpstan-param T $currentResponse
     * @phpstan-return U
     * @param mixed $currentResponse
     */
    private function getDecodedResponse(
        FetchContext $context,
        ResponseDecoderInterface $decoder,
        $currentResponse
    ) {
        $query = $context->getInitiator();

        if ($query instanceof CacheableInterface && $decoder instanceof ImpureResponseDecoderInterface) {
            $this->debug($context->getKey(), fn () => [
                'impure decoder' => Utils::getType($decoder),
                'try cache response before decoding' => $currentResponse,
            ]);

            if ($this->shouldCacheResponse($context)) {
                $this->cacheSuccess($query, $context, $currentResponse);
            }

            $context->setIsAlreadyCached();
        }

        return $decoder->decode($currentResponse);
    }

    /** @phpstan-param Context $context */
    private function shouldCacheResponse(FetchContext $context): bool
    {
        $usedHandler = $context->getUsedHandler();

        return !($usedHandler instanceof GetCachedHandler)
            && !$context->isAlreadyCached();
    }

    /**
     * @phpstan-param Context $context
     * @param mixed $response
     */
    private function cacheSuccess(CacheableInterface $query, FetchContext $context, $response): void
    {
        if ($this->cache
            && $this->isCacheEnabled()
            && ($lifetime = $query->getCacheTime()->getSeconds()) > 0
        ) {
            $this->debug($context->getKey(), fn () => [
                'cache response' => $response,
            ]);

            $cacheItem = $this->cache->getItem($query->getCacheKey()->getHashedKey());
            $cacheItem->expiresAfter($lifetime);
            $cacheItem->set($response);

            $isCached = $this->cache->save($cacheItem);
            $context->setIsAlreadyCached();

            if ($query instanceof ProfileableInterface
                && $this->profilerBag
                && ($profilerItem = $this->profilerBag->get($context->getKey()))
            ) {
                $profilerItem->setIsStoredInCache($isCached, $lifetime);
            }
        }
    }

    /** @phpstan-param Context $context */
    private function profileQueryFinished(FetchContext $context): void
    {
        if ($this->profilerBag && ($profilerItem = $this->profilerBag->get($context->getKey()))) {
            $context->stopStopwatch($profilerItem);

            $query = $context->getInitiator();
            $currentHandler = $context->getUsedHandler();

            $profilerItem->setHandledBy(sprintf(
                '%s<%s>',
                Utils::getType($currentHandler),
                $context->getHandledResponseType()
            ));
            $profilerItem->setDecodedBy($context->getUsedDecoders());

            if ($query instanceof CacheableInterface) {
                $profilerItem->setCacheKey($query->getCacheKey());
                $profilerItem->setIsLoadedFromCache($currentHandler instanceof GetCachedHandler);
            }

            if ($response = $context->getResponse()) {
                $profilerItem->setResponse($response);
            }

            if ($error = $context->getError()) {
                $profilerItem->setError($error);
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
