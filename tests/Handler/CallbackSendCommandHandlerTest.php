<?php declare(strict_types=1);

namespace Lmc\Cqrs\Handler\Handler;

use Lmc\Cqrs\Handler\AbstractTestCase;
use Lmc\Cqrs\Handler\Command\ProfiledDataCommand;
use Lmc\Cqrs\Types\CommandInterface;
use Lmc\Cqrs\Types\ValueObject\OnErrorCallback;
use Lmc\Cqrs\Types\ValueObject\OnSuccessCallback;

class CallbackSendCommandHandlerTest extends AbstractTestCase
{
    /** @phpstan-var CallbackSendCommandHandler<mixed> */
    private CallbackSendCommandHandler $handler;

    protected function setUp(): void
    {
        $this->handler = new CallbackSendCommandHandler();
    }

    /**
     * @param CommandInterface<callable(): mixed> $command
     *
     * @dataProvider provideCallableCommand
     * @test
     */
    public function shouldSupportCallableCommand(CommandInterface $command): void
    {
        $this->assertTrue($this->handler->supports($command));
    }

    /**
     * @param CommandInterface<callable(): mixed> $command
     *
     * @dataProvider provideCallableCommand
     * @test
     */
    public function shouldHandleCallableCommand(CommandInterface $command, mixed $expectedResult): void
    {
        $this->handler->handle(
            $command,
            new OnSuccessCallback(fn ($data) => $this->assertSame($expectedResult, $data)),
            new OnErrorCallback(fn (\Throwable $e) => $this->fail($e->getMessage())),
        );
    }

    public function provideCallableCommand(): array
    {
        return [
            // command, expectedResult
            'ProfiledDataCommand<callable<array>>' => [
                new ProfiledDataCommand(fn () => ['fresh-data'], 'profilerId'),
                ['fresh-data'],
            ],
        ];
    }
}
