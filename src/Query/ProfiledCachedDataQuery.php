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
    private string $profilerId;
    private ?array $profilerData;

    /**
     * @phpstan-param callable(): Data $createData
     */
    public function __construct(
        callable $createData,
        CacheKey $cacheKey,
        CacheTime $cacheTime,
        string $profilerId,
        ?array $profilerData = null
    ) {
        parent::__construct($createData, $cacheKey, $cacheTime);
        $this->profilerId = $profilerId;
        $this->profilerData = $profilerData;
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
