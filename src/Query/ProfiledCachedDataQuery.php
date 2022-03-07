<?php declare(strict_types=1);

namespace Lmc\Cqrs\Handler\Query;

use Lmc\Cqrs\Types\Feature\ProfileableInterface;
use Lmc\Cqrs\Types\ValueObject\CacheKey;
use Lmc\Cqrs\Types\ValueObject\CacheTime;

/**
 * @phpstan-template Data
 * @phpstan-extends CachedDataQuery<Data>
 */
class ProfiledCachedDataQuery extends CachedDataQuery implements ProfileableInterface
{
    /**
     * @phpstan-param callable(): Data $createData
     */
    public function __construct(
        callable $createData,
        CacheKey $cacheKey,
        CacheTime $cacheTime,
        private string $profilerId,
        private ?array $profilerData = null,
    ) {
        parent::__construct($createData, $cacheKey, $cacheTime);
    }

    public function getProfilerId(): string
    {
        return $this->profilerId;
    }

    public function getProfilerData(): ?array
    {
        return $this->profilerData;
    }
}
