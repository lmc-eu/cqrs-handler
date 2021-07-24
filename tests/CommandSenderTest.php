<?php declare(strict_types=1);

namespace Lmc\Cqrs\Handler;

use Lmc\Cqrs\Handler\Fixture\DummyCommand;
use Lmc\Cqrs\Handler\Fixture\DummySendCommandHandler;
use Lmc\Cqrs\Handler\Fixture\ProfileableCommandAdapter;
use Lmc\Cqrs\Types\CommandInterface;
use Lmc\Cqrs\Types\CommandSenderInterface;
use Lmc\Cqrs\Types\Decoder\CallbackResponseDecoder;
use Lmc\Cqrs\Types\Exception\NoSendCommandHandlerUsedException;
use Lmc\Cqrs\Types\SendCommandHandlerInterface;
use Lmc\Cqrs\Types\ValueObject\DecodedValue;
use Lmc\Cqrs\Types\ValueObject\OnErrorCallback;
use Lmc\Cqrs\Types\ValueObject\OnErrorInterface;
use Lmc\Cqrs\Types\ValueObject\OnSuccessCallback;
use Lmc\Cqrs\Types\ValueObject\OnSuccessInterface;
use Lmc\Cqrs\Types\ValueObject\PrioritizedItem;
use Lmc\Cqrs\Types\ValueObject\ProfilerItem;

class CommandSenderTest extends AbstractTestCase
{
    /** @var CommandSenderInterface<mixed, mixed> */
    private CommandSenderInterface $commandSender;
    /** @var CommandSenderInterface<mixed, mixed> */
    private CommandSenderInterface $commandSenderWithoutFeatures;

    private ProfilerBag $profilerBag;

    protected function setUp(): void
    {
        $this->profilerBag = new ProfilerBag();

        $this->commandSender = new CommandSender($this->profilerBag);
        $this->commandSenderWithoutFeatures = new CommandSender(null);
    }

    /**
     * @test
     */
    public function shouldSendCommand(): void
    {
        $this->commandSender->addHandler(new DummySendCommandHandler(), PrioritizedItem::PRIORITY_MEDIUM);

        $dummyCommand = new DummyCommand('fresh-data');

        $this->commandSender->send(
            $dummyCommand,
            new OnSuccessCallback(fn ($data) => $this->assertSame('fresh-data', $data)),
            new OnErrorCallback(fn (\Throwable $error) => $this->fail($error->getMessage()))
        );
    }

    /**
     * @test
     */
    public function shouldProfileGivenCommand(): void
    {
        $profilerId = 'some-profiler-id';
        $commandBody = 'some-command-data';
        $dummyCommand = new DummyCommand('fresh-data');

        $this->commandSender->addHandler(new DummySendCommandHandler(), PrioritizedItem::PRIORITY_MEDIUM);

        $this->assertCount(0, $this->profilerBag);

        $this->commandSender->send(
            new ProfileableCommandAdapter($dummyCommand, $profilerId, ['body' => $commandBody]),
            new OnSuccessCallback($this->ignore()),
            new OnErrorCallback($this->ignore())
        );

        $this->assertCount(1, $this->profilerBag);

        foreach ($this->profilerBag as $item) {
            $this->assertSame($profilerId, $item->getProfilerId());
            $this->assertSame(['body' => $commandBody], $item->getAdditionalData());
            $this->assertSame(ProfilerItem::TYPE_COMMAND, $item->getItemType());
            $this->assertSame(ProfileableCommandAdapter::class, $item->getType());
            $this->assertNull($item->getCacheKey());
            $this->assertNull($item->isLoadedFromCache());
            $this->assertNull($item->isStoredInCache());
            $this->assertSame('fresh-data', $item->getResponse());
            $this->assertNull($item->getError());
            $this->assertSame(DummySendCommandHandler::class, $item->getHandledBy());
            $this->assertSame([], $item->getDecodedBy());
        }
    }

    /**
     * @test
     */
    public function shouldNotProfileNotProfileableCommand(): void
    {
        $dummyCommand = new DummyCommand('fresh-data');

        $this->commandSender->send(
            $dummyCommand,
            new OnSuccessCallback($this->ignore()),
            new OnErrorCallback($this->ignore())
        );

        $this->assertCount(0, $this->profilerBag);
    }

    /**
     * @test
     */
    public function shouldNotProfileWithoutProfilerBag(): void
    {
        $profilerId = 'some-profiler-id';
        $dummyCommand = new DummyCommand('fresh-data');

        $this->commandSenderWithoutFeatures->send(
            new ProfileableCommandAdapter($dummyCommand, $profilerId),
            new OnSuccessCallback($this->ignore()),
            new OnErrorCallback($this->ignore())
        );

        $this->assertCount(0, $this->profilerBag);
    }

    /**
     * @test
     */
    public function shouldSendCommandAndDecodeResponse(): void
    {
        $this->commandSender->addHandler(new DummySendCommandHandler(), PrioritizedItem::PRIORITY_MEDIUM);

        $dummyCommand = new DummyCommand('fresh-data');
        $decoder = new CallbackResponseDecoder(
            fn (string $response, $initiator) => is_string($response),
            fn (string $response) => sprintf('decoded:%s', $response),
        );

        $this->commandSender->addDecoder($decoder, 50);
        $decodedResponse = $this->commandSender->sendAndReturn($dummyCommand);

        $this->assertSame('decoded:fresh-data', $decodedResponse);
    }

    /**
     * @test
     */
    public function shouldProfileOriginalResponse(): void
    {
        $profilerId = 'some-profiler-id';

        $this->commandSender->addHandler(new DummySendCommandHandler(), PrioritizedItem::PRIORITY_MEDIUM);

        $dummyCommand = new ProfileableCommandAdapter(new DummyCommand('fresh-data'), $profilerId);
        $decoder = new CallbackResponseDecoder(
            fn (string $response, $initiator) => is_string($response),
            fn (string $response) => sprintf('decoded:%s', $response),
        );

        $this->commandSender->addDecoder($decoder, 50);
        $decodedResponse = $this->commandSender->sendAndReturn($dummyCommand);

        $this->assertSame('decoded:fresh-data', $decodedResponse);

        $this->assertCount(1, $this->profilerBag);

        foreach ($this->profilerBag as $item) {
            $this->assertSame($profilerId, $item->getProfilerId());
            $this->assertSame(ProfilerItem::TYPE_COMMAND, $item->getItemType());
            $this->assertSame('decoded:fresh-data', $item->getResponse());
            $this->assertSame(DummySendCommandHandler::class, $item->getHandledBy());
        }
    }

    /**
     * @test
     */
    public function shouldSendCommandDecodeResponseAndProfileIt(): void
    {
        $profilerId = 'some-profiler-id';
        $commandBody = 'some-command-data';
        $dummyCommand = new DummyCommand('fresh-data');

        $this->commandSender->addHandler(new DummySendCommandHandler(), PrioritizedItem::PRIORITY_MEDIUM);

        $this->assertCount(0, $this->profilerBag);

        $decodedResponse = $this->commandSender->sendAndReturn(
            new ProfileableCommandAdapter($dummyCommand, $profilerId, ['body' => $commandBody])
        );

        $this->assertSame('fresh-data', $decodedResponse);

        $this->assertCount(1, $this->profilerBag);

        foreach ($this->profilerBag as $item) {
            $this->assertSame($profilerId, $item->getProfilerId());
            $this->assertSame(['body' => $commandBody], $item->getAdditionalData());
            $this->assertSame(ProfilerItem::TYPE_COMMAND, $item->getItemType());
            $this->assertSame(ProfileableCommandAdapter::class, $item->getType());
            $this->assertNull($item->getCacheKey());
            $this->assertNull($item->isStoredInCache());
            $this->assertSame('fresh-data', $item->getResponse());
            $this->assertNull($item->getError());
            $this->assertSame(DummySendCommandHandler::class, $item->getHandledBy());
        }
    }

    /**
     * @test
     */
    public function shouldThrowExceptionOnSendAndDecodeWithoutAnyHandler(): void
    {
        $command = new DummyCommand('fresh-data');

        $this->expectException(NoSendCommandHandlerUsedException::class);

        $this->commandSender->sendAndReturn($command);
    }

    /**
     * @test
     */
    public function shouldNotUseMoreThanOneHandler(): void
    {
        $command = new DummyCommand('fresh-data');

        $failHandler = new class() implements SendCommandHandlerInterface {
            public function supports(CommandInterface $command): bool
            {
                return true;
            }

            public function prepare(CommandInterface $command): CommandInterface
            {
                return $command;
            }

            public function handle(
                CommandInterface $command,
                OnSuccessInterface $onSuccess,
                OnErrorInterface $onError
            ): void {
                throw new \Exception(sprintf('Method %s should not be called.', __METHOD__));
            }
        };

        $this->commandSender->addHandler(new DummySendCommandHandler(), PrioritizedItem::PRIORITY_HIGHEST);
        $this->commandSender->addHandler($failHandler, PrioritizedItem::PRIORITY_MEDIUM);

        $response = $this->commandSender->sendAndReturn($command);

        $this->assertSame('fresh-data', $response);
    }

    /**
     * @test
     */
    public function shouldSendCommandAndUseMultipleDecoders(): void
    {
        $profilerId = 'profiler-id';
        $expectedResponse = 'decoder:3:decoder:2:decoder:1:fresh-data';

        $this->commandSender->addHandler(new DummySendCommandHandler(), PrioritizedItem::PRIORITY_MEDIUM);

        $command = new ProfileableCommandAdapter(new DummyCommand('fresh-data'), $profilerId);

        $decoder = function (int $i) {
            return new CallbackResponseDecoder(
                fn (string $response, $initiator) => is_string($response),
                fn (string $response) => sprintf('decoder:%d:%s', $i, $response),
            );
        };

        $this->commandSender->addDecoder($decoder(2), 60);
        $this->commandSender->addDecoder($decoder(1), 70);
        $this->commandSender->addDecoder($decoder(3), 50);

        $decodedResponse = $this->commandSender->sendAndReturn($command);

        $this->assertSame($expectedResponse, $decodedResponse);

        $this->assertCount(1, $this->profilerBag);

        foreach ($this->profilerBag as $item) {
            $this->assertSame($profilerId, $item->getProfilerId());
            $this->assertSame([], $item->getAdditionalData());
            $this->assertSame(ProfilerItem::TYPE_COMMAND, $item->getItemType());
            $this->assertSame(ProfileableCommandAdapter::class, $item->getType());
            $this->assertNull($item->getCacheKey());
            $this->assertNull($item->isLoadedFromCache());
            $this->assertNull($item->isStoredInCache());
            $this->assertSame($expectedResponse, $item->getResponse());
            $this->assertNull($item->getError());
            $this->assertSame(DummySendCommandHandler::class, $item->getHandledBy());
            $this->assertSame(
                [
                    'Lmc\Cqrs\Types\Decoder\CallbackResponseDecoder<string, string>',
                    'Lmc\Cqrs\Types\Decoder\CallbackResponseDecoder<string, string>',
                    'Lmc\Cqrs\Types\Decoder\CallbackResponseDecoder<string, string>',
                ],
                $item->getDecodedBy()
            );
        }
    }

    /**
     * @test
     */
    public function shouldSendCommandAndUseOnlyOneDecoder(): void
    {
        $profilerId = 'profiler-id';
        $expectedResponse = 'final-decoded:fresh-data';

        $this->commandSender->addHandler(new DummySendCommandHandler(), PrioritizedItem::PRIORITY_MEDIUM);

        $command = new ProfileableCommandAdapter(new DummyCommand('fresh-data'), $profilerId);

        $decoder = function (int $i) {
            return new CallbackResponseDecoder(
                fn (string $response, $initiator) => is_string($response),
                fn (string $response) => sprintf('decoder:%d:%s', $i, $response),
            );
        };

        $finalDecoder = new CallbackResponseDecoder(
            fn (string $response, $initiator) => is_string($response),
            fn (string $response) => new DecodedValue(sprintf('final-decoded:%s', $response))
        );

        $this->commandSender->addDecoder($decoder(2), 60);
        $this->commandSender->addDecoder($decoder(1), 70);
        $this->commandSender->addDecoder($decoder(3), 50);
        $this->commandSender->addDecoder($finalDecoder, 90);

        $decodedResponse = $this->commandSender->sendAndReturn($command);

        $this->assertSame($expectedResponse, $decodedResponse);

        $this->assertCount(1, $this->profilerBag);

        foreach ($this->profilerBag as $item) {
            $this->assertSame($profilerId, $item->getProfilerId());
            $this->assertSame([], $item->getAdditionalData());
            $this->assertSame(ProfilerItem::TYPE_COMMAND, $item->getItemType());
            $this->assertSame(ProfileableCommandAdapter::class, $item->getType());
            $this->assertNull($item->getCacheKey());
            $this->assertNull($item->isLoadedFromCache());
            $this->assertNull($item->isStoredInCache());
            $this->assertSame($expectedResponse, $item->getResponse());
            $this->assertNull($item->getError());
            $this->assertSame(DummySendCommandHandler::class, $item->getHandledBy());
            $this->assertSame(
                ['Lmc\Cqrs\Types\Decoder\CallbackResponseDecoder<string, DecodedValue<string>>'],
                $item->getDecodedBy()
            );
        }
    }
}
