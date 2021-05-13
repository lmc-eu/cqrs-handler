<?php declare(strict_types=1);

namespace Lmc\Cqrs\Handler\Fixture;

use Lmc\Cqrs\Types\Feature\ProfileableInterface;
use Lmc\Cqrs\Types\QueryInterface;

/**
 * @phpstan-template Request
 * @phpstan-implements QueryInterface<Request>
 */
class ProfileableQueryAdapter implements QueryInterface, ProfileableInterface
{
    /** @phpstan-var QueryInterface<Request> */
    private QueryInterface $query;
    private string $profilerKey;
    private ?array $profilerData;

    /** @phpstan-param QueryInterface<Request> $query */
    public function __construct(QueryInterface $query, string $profilerKey, ?array $profilerData = null)
    {
        $this->query = $query;
        $this->profilerKey = $profilerKey;
        $this->profilerData = $profilerData;
    }

    public function getProfilerId(): string
    {
        return $this->profilerKey;
    }

    public function getProfilerData(): ?array
    {
        return $this->profilerData;
    }

    public function getRequestType(): string
    {
        return $this->query->getRequestType();
    }

    public function createRequest()
    {
        return $this->query->createRequest();
    }

    public function __toString(): string
    {
        return $this->query->__toString();
    }
}
