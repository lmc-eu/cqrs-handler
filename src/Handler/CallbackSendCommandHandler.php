<?php declare(strict_types=1);

namespace Lmc\Cqrs\Handler\Handler;

use Lmc\Cqrs\Types\Base\AbstractSendCommandHandler;
use Lmc\Cqrs\Types\CommandInterface;
use Lmc\Cqrs\Types\ValueObject\OnErrorInterface;
use Lmc\Cqrs\Types\ValueObject\OnSuccessInterface;

/**
 * @phpstan-template Data
 * @phpstan-extends AbstractSendCommandHandler<callable(): Data, Data>
 */
class CallbackSendCommandHandler extends AbstractSendCommandHandler
{
    public function supports(CommandInterface $command): bool
    {
        return in_array($command->getRequestType(), ['callable', 'Closure', 'callback'], true);
    }

    /**
     * @phpstan-param CommandInterface<callable(): Data> $command
     * @phpstan-param OnSuccessInterface<Data> $onSuccess
     */
    public function handle(CommandInterface $command, OnSuccessInterface $onSuccess, OnErrorInterface $onError): void
    {
        if (!$this->assertIsSupported('callable', $command, $onError)) {
            return;
        }

        try {
            $onSuccess(call_user_func($command->createRequest()));
        } catch (\Throwable $e) {
            $onError($e);
        }
    }
}
