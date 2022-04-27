<?php declare(strict_types=1);

namespace Lmc\Cqrs\Handler\Core;

use Lmc\Cqrs\Types\CommandInterface;

/**
 * @internal
 *
 * @phpstan-template Request
 * @phpstan-template Response
 * @phpstan-extends AbstractContext<Request, Response>
 */
class SendContext extends AbstractContext
{
    /** @phpstan-param CommandInterface<Request> $command */
    public function __construct(CommandInterface $command)
    {
        parent::__construct($command);
    }
}
