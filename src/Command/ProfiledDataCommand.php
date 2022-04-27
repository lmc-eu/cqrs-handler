<?php declare(strict_types=1);

namespace Lmc\Cqrs\Handler\Command;

use Lmc\Cqrs\Types\CommandInterface;
use Lmc\Cqrs\Types\Feature\ProfileableInterface;

/**
 * @phpstan-template Data
 * @phpstan-implements CommandInterface<callable(): Data>
 */
class ProfiledDataCommand implements CommandInterface, ProfileableInterface
{
    /**
     * @phpstan-var callable(): Data
     * @var callable
     */
    private $createData;

    /**
     * @phpstan-param callable(): Data $createData
     */
    public function __construct(callable $createData, private string $profilerId, private ?array $profilerData = null)
    {
        $this->createData = $createData;
    }

    final public function getRequestType(): string
    {
        return 'callable';
    }

    final public function createRequest(): callable
    {
        return $this->createData;
    }

    public function getProfilerId(): string
    {
        return $this->profilerId;
    }

    public function getProfilerData(): ?array
    {
        return $this->profilerData;
    }

    public function __toString(): string
    {
        return static::class;
    }
}
