<?php declare(strict_types=1);

namespace Lmc\Cqrs\Handler\Query;

use Lmc\Cqrs\Types\Feature\CacheableInterface;
use Lmc\Cqrs\Types\QueryInterface;
use Lmc\Cqrs\Types\ValueObject\CacheKey;
use Lmc\Cqrs\Types\ValueObject\CacheTime;

/**
 * @phpstan-template Data
 * @phpstan-implements QueryInterface<callable(): Data>
 */
class CachedDataQuery implements QueryInterface, CacheableInterface
{
    /**
     * @phpstan-var callable(): Data
     * @var callable
     */
    private $createData;

    /**
     * @phpstan-param callable(): Data $createData
     */
    public function __construct(callable $createData, private CacheKey $cacheKey, private CacheTime $cacheTime)
    {
        $this->createData = $createData;
    }

    final public function getRequestType(): string
    {
        return 'callable';
    }

    final public function getCacheKey(): CacheKey
    {
        return $this->cacheKey;
    }

    final public function getCacheTime(): CacheTime
    {
        return $this->cacheTime;
    }

    final public function createRequest(): callable
    {
        return $this->createData;
    }

    public function __toString(): string
    {
        return static::class;
    }
}
