<?php declare(strict_types=1);

namespace Lmc\Cqrs\Handler\Fixture;

use Lmc\Cqrs\Types\Base\AbstractSendCommandHandler;
use Lmc\Cqrs\Types\CommandInterface;
use Lmc\Cqrs\Types\ValueObject\OnErrorInterface;
use Lmc\Cqrs\Types\ValueObject\OnSuccessInterface;

/**
 * @phpstan-extends AbstractSendCommandHandler<string, string>
 */
class DummySendCommandHandler extends AbstractSendCommandHandler
{
    public function supports(CommandInterface $command): bool
    {
        return $command->getRequestType() === DummyCommand::class;
    }

    public function handle(CommandInterface $command, OnSuccessInterface $onSuccess, OnErrorInterface $onError): void
    {
        if (!$this->assertIsSupported(DummyCommand::class, $command, $onError)) {
            return;
        }

        $onSuccess($command->createRequest());
    }
}
