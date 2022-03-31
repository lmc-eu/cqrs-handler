<?php declare(strict_types=1);

namespace Lmc\Cqrs\Handler\Core;

use Lmc\Cqrs\Types\CommandInterface;

/**
 * @phpstan-template Request
 * @phpstan-template Handler
 * @phpstan-template DecodedResponse
 * @phpstan-extends AbstractContext<CommandInterface<Request>, Handler, DecodedResponse>
 */
class SendContext extends AbstractContext
{
    /** @phpstan-param CommandInterface<Request> $command */
    public function __construct(CommandInterface $command)
    {
        parent::__construct($command);
    }
}
