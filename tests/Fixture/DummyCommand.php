<?php declare(strict_types=1);

namespace Lmc\Cqrs\Handler\Fixture;

use Lmc\Cqrs\Types\CommandInterface;

/**
 * @phpstan-implements CommandInterface<string>
 */
class DummyCommand implements CommandInterface
{
    public function __construct(private string $response)
    {
    }

    public function getRequestType(): string
    {
        return self::class;
    }

    public function createRequest(): string
    {
        return $this->response;
    }

    public function __toString(): string
    {
        return $this->response;
    }
}
