<?php declare(strict_types=1);

namespace Lmc\Cqrs\Handler\Fixture;

use Lmc\Cqrs\Types\Feature\CacheableInterface;
use Lmc\Cqrs\Types\QueryInterface;
use Lmc\Cqrs\Types\ValueObject\CacheKey;
use Lmc\Cqrs\Types\ValueObject\CacheTime;

/**
 * @phpstan-template Request
 * @phpstan-implements QueryInterface<Request>
 */
class CacheableQueryAdapter implements QueryInterface, CacheableInterface
{
    /** @phpstan-param QueryInterface<Request> $query */
    public function __construct(
        private QueryInterface $query,
        private CacheKey $cacheKey,
        private CacheTime $cacheTime,
    ) {
    }

    public function getCacheKey(): CacheKey
    {
        return $this->cacheKey;
    }

    public function getCacheTime(): CacheTime
    {
        return $this->cacheTime;
    }

    public function getRequestType(): string
    {
        return $this->query->getRequestType();
    }

    public function createRequest(): mixed
    {
        return $this->query->createRequest();
    }

    public function __toString(): string
    {
        return $this->query->__toString();
    }
}
