<?php declare(strict_types=1);

namespace Lmc\Cqrs\Handler\Handler;

use Lmc\Cqrs\Types\Base\AbstractQueryHandler;
use Lmc\Cqrs\Types\Feature\CacheableInterface;
use Lmc\Cqrs\Types\QueryInterface;
use Lmc\Cqrs\Types\ValueObject\OnErrorInterface;
use Lmc\Cqrs\Types\ValueObject\OnSuccessInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * @phpstan-template Request
 * @phpstan-template Response
 * @phpstan-extends AbstractQueryHandler<Request, Response>
 */
class GetCachedHandler extends AbstractQueryHandler
{
    private CacheItemPoolInterface $cache;

    public function __construct(CacheItemPoolInterface $cache)
    {
        $this->cache = $cache;
    }

    /** @phpstan-param QueryInterface<Request> $query */
    public function supports(QueryInterface $query): bool
    {
        return $query instanceof CacheableInterface && $query->getCacheTime()->getSeconds() > 0;
    }

    /**
     * @phpstan-param QueryInterface<Request> $query
     * @phpstan-param OnSuccessInterface<Response> $onSuccess
     */
    public function handle(QueryInterface $query, OnSuccessInterface $onSuccess, OnErrorInterface $onError): void
    {
        if (!$this->assertIsSupported(CacheableInterface::class, $query, $onError)) {
            return;
        }

        try {
            if ($query instanceof CacheableInterface) {
                $key = $query->getCacheKey()->getHashedKey();

                if ($this->cache->hasItem($key) && ($cachedItem = $this->cache->getItem($key))->isHit()) {
                    $onSuccess($cachedItem->get());
                }
            }
        } catch (\Throwable $e) {
            $onError($e);
        }
    }
}
