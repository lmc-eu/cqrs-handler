<?php declare(strict_types=1);

namespace Lmc\Cqrs\Handler\Fixture;

use Lmc\Cqrs\Types\CommandInterface;
use Lmc\Cqrs\Types\Feature\ProfileableInterface;

/**
 * @phpstan-template Request
 * @phpstan-implements CommandInterface<Request>
 */
class ProfileableCommandAdapter implements CommandInterface, ProfileableInterface
{
    /** @phpstan-var CommandInterface<Request> */
    private CommandInterface $command;
    private string $profilerKey;
    private ?array $profilerData;

    /** @phpstan-param CommandInterface<Request> $command */
    public function __construct(CommandInterface $command, string $profilerKey, ?array $profilerData = null)
    {
        $this->command = $command;
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
        return $this->command->getRequestType();
    }

    public function createRequest()
    {
        return $this->command->createRequest();
    }

    public function __toString(): string
    {
        return $this->command->__toString();
    }
}
