<?php declare(strict_types=1);

namespace Lmc\Cqrs\Handler;

use Lmc\Cqrs\Handler\Fixture\DummyCommand;
use Lmc\Cqrs\Handler\Fixture\DummySendCommandHandler;
use Lmc\Cqrs\Handler\Fixture\ImpureTranslationDecoder;
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

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

    #[Test]
    public function shouldSendCommand(): void
    {
        $this->commandSender->addHandler(new DummySendCommandHandler(), PrioritizedItem::PRIORITY_MEDIUM);

        $dummyCommand = new DummyCommand('fresh-data');

        $this->commandSender->send(
            $dummyCommand,
            new OnSuccessCallback(fn ($data) => $this->assertSame('fresh-data', $data)),
            new OnErrorCallback(fn (\Throwable $error) => $this->fail($error->getMessage())),
        );
    }

    #[Test]
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
            new OnErrorCallback($this->ignore()),
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
            $this->assertHandledBy(DummySendCommandHandler::class, 'string', $item->getHandledBy());
            $this->assertSame([], $item->getDecodedBy());
        }
    }

    #[Test]
    public function shouldNotProfileNotProfileableCommand(): void
    {
        $dummyCommand = new DummyCommand('fresh-data');

        $this->commandSender->send(
            $dummyCommand,
            new OnSuccessCallback($this->ignore()),
            new OnErrorCallback($this->ignore()),
        );

        $this->assertCount(0, $this->profilerBag);
    }

    #[Test]
    public function shouldNotProfileWithoutProfilerBag(): void
    {
        $profilerId = 'some-profiler-id';
        $dummyCommand = new DummyCommand('fresh-data');

        $this->commandSenderWithoutFeatures->send(
            new ProfileableCommandAdapter($dummyCommand, $profilerId),
            new OnSuccessCallback($this->ignore()),
            new OnErrorCallback($this->ignore()),
        );

        $this->assertCount(0, $this->profilerBag);
    }

    #[Test]
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

    #[Test]
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
            $this->assertHandledBy(DummySendCommandHandler::class, 'string', $item->getHandledBy());
        }
    }

    #[Test]
    public function shouldSendCommandDecodeResponseAndProfileIt(): void
    {
        $profilerId = 'some-profiler-id';
        $commandBody = 'some-command-data';
        $dummyCommand = new DummyCommand('fresh-data');

        $this->commandSender->addHandler(new DummySendCommandHandler(), PrioritizedItem::PRIORITY_MEDIUM);

        $this->assertCount(0, $this->profilerBag);

        $decodedResponse = $this->commandSender->sendAndReturn(
            new ProfileableCommandAdapter($dummyCommand, $profilerId, ['body' => $commandBody]),
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
            $this->assertHandledBy(DummySendCommandHandler::class, 'string', $item->getHandledBy());
        }
    }

    #[Test]
    public function shouldThrowExceptionOnSendAndDecodeWithoutAnyHandler(): void
    {
        $command = new DummyCommand('fresh-data');

        $this->expectException(NoSendCommandHandlerUsedException::class);

        $this->commandSender->sendAndReturn($command);
    }

    #[Test]
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
                OnErrorInterface $onError,
            ): void {
                throw new \Exception(sprintf('Method %s should not be called.', __METHOD__));
            }
        };

        $this->commandSender->addHandler(new DummySendCommandHandler(), PrioritizedItem::PRIORITY_HIGHEST);
        $this->commandSender->addHandler($failHandler, PrioritizedItem::PRIORITY_MEDIUM);

        $response = $this->commandSender->sendAndReturn($command);

        $this->assertSame('fresh-data', $response);
    }

    #[Test]
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
            $this->assertHandledBy(DummySendCommandHandler::class, 'string', $item->getHandledBy());
            $this->assertSame(
                [
                    'Lmc\Cqrs\Types\Decoder\CallbackResponseDecoder<string, string>',
                    'Lmc\Cqrs\Types\Decoder\CallbackResponseDecoder<string, string>',
                    'Lmc\Cqrs\Types\Decoder\CallbackResponseDecoder<string, string>',
                ],
                $item->getDecodedBy(),
            );
        }
    }

    #[Test]
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
            $this->assertHandledBy(DummySendCommandHandler::class, 'string', $item->getHandledBy());
            $this->assertSame(
                ['Lmc\Cqrs\Types\Decoder\CallbackResponseDecoder<string, DecodedValue<string>>'],
                $item->getDecodedBy(),
            );
        }
    }

    #[Test]
    public function shouldSendConsequentCommand(): void
    {
        $commandA = new ProfileableCommandAdapter(new DummyCommand('response-A'), 'command-A');
        $commandB = new ProfileableCommandAdapter(new DummyCommand('response-B'), 'command-B');

        $this->commandSender->addHandler(new DummySendCommandHandler(), PrioritizedItem::PRIORITY_MEDIUM);

        $decoderA = new CallbackResponseDecoder(
            fn (string $response) => $response === 'response-A',
            fn (string $responseA) => sprintf('%s:%s', $responseA, $this->commandSender->sendAndReturn($commandB)[0]),
        );

        $decoderB = new CallbackResponseDecoder(
            fn (string $response) => $response === 'response-B',
            fn (string $response) => [sprintf('decoded:%s', $response)],
        );

        $this->commandSender->addDecoder($decoderA, PrioritizedItem::PRIORITY_HIGHEST);
        $this->commandSender->addDecoder($decoderB, PrioritizedItem::PRIORITY_HIGHEST);

        $response = $this->commandSender->sendAndReturn($commandA);

        $this->assertSame('response-A:decoded:response-B', $response);

        foreach ($this->profilerBag->getIterator() as $profilerItem) {
            $this->assertCount(1, $profilerItem->getDecodedBy());
        }
    }

    #[Test]
    #[DataProvider('provideVerbosity')]
    public function shouldUseProfilerBagVerbosity(
        string $verbosity,
        bool $withDecoder,
        array $expectedAdditionalData,
    ): void {
        $this->profilerBag->setVerbosity($verbosity);

        $profilerId = 'some-profiler-id';
        $commandBody = 'some-command-data';
        $dummyCommand = new DummyCommand('fresh-data');

        $expectedResponse = $withDecoder
            ? 'translated[cs]: fresh-data'
            : 'fresh-data';
        $expectedDecoders = $withDecoder
            ? [ImpureTranslationDecoder::class . '<string, string>']
            : [];

        $expectedAdditionalData['body'] = $commandBody;

        $this->commandSender->addHandler(new DummySendCommandHandler(), PrioritizedItem::PRIORITY_MEDIUM);
        if ($withDecoder) {
            $this->commandSender->addDecoder(new ImpureTranslationDecoder('cs'), PrioritizedItem::PRIORITY_MEDIUM);
        }

        $this->assertCount(0, $this->profilerBag);

        $decodedResponse = $this->commandSender->sendAndReturn(
            new ProfileableCommandAdapter($dummyCommand, $profilerId, ['body' => $commandBody]),
        );

        $this->assertSame($expectedResponse, $decodedResponse);

        $this->assertCount(1, $this->profilerBag);

        foreach ($this->profilerBag as $item) {
            $this->assertSame($profilerId, $item->getProfilerId());
            $this->assertEquals($expectedAdditionalData, $item->getAdditionalData());
            $this->assertSame(ProfilerItem::TYPE_COMMAND, $item->getItemType());
            $this->assertSame(ProfileableCommandAdapter::class, $item->getType());
            $this->assertNull($item->getCacheKey());
            $this->assertNull($item->isStoredInCache());
            $this->assertSame($expectedResponse, $item->getResponse());
            $this->assertNull($item->getError());
            $this->assertHandledBy(DummySendCommandHandler::class, 'string', $item->getHandledBy());
            $this->assertSame($expectedDecoders, $item->getDecodedBy());
        }
    }

    public static function provideVerbosity(): array
    {
        return [
            // verbosity, withDecoder, expected
            'default' => [
                ProfilerBag::VERBOSITY_NORMAL,
                false,
                [],
            ],
            'verbose' => [
                ProfilerBag::VERBOSITY_VERBOSE,
                false,
                [
                    'CQRS.verbose' => [
                        [
                            'handled by' => DummySendCommandHandler::class,
                            'response' => 'string',
                        ],
                        [
                            'start decoding response' => 'string',
                        ],
                    ],
                ],
            ],
            'debug' => [
                ProfilerBag::VERBOSITY_DEBUG,
                false,
                [
                    'CQRS.debug' => [
                        [
                            'handled by' => DummySendCommandHandler::class,
                            'response' => 'fresh-data',
                        ],
                        [
                            'start decoding response' => 'string',
                        ],
                    ],
                ],
            ],
            'default with decoder' => [
                ProfilerBag::VERBOSITY_NORMAL,
                true,
                [],
            ],
            'verbose with decoder' => [
                ProfilerBag::VERBOSITY_VERBOSE,
                true,
                [
                    'CQRS.verbose' => [
                        [
                            'handled by' => DummySendCommandHandler::class,
                            'response' => 'string',
                        ],
                        [
                            'start decoding response' => 'string',
                        ],
                        [
                            'loop' => 0,
                            'decoder' => ImpureTranslationDecoder::class,
                            'response' => 'string',
                            'decoded response' => 'string',
                        ],
                    ],
                ],
            ],
            'debug with decoder' => [
                ProfilerBag::VERBOSITY_DEBUG,
                true,
                [
                    'CQRS.debug' => [
                        [
                            'handled by' => DummySendCommandHandler::class,
                            'response' => 'fresh-data',
                        ],
                        [
                            'start decoding response' => 'string',
                        ],
                        [
                            'loop' => 0,
                            'trying decoder' => ImpureTranslationDecoder::class,
                        ],
                        [
                            'loop' => 0,
                            'decoder' => ImpureTranslationDecoder::class,
                            'supports response' => 'string',
                        ],
                        [
                            'loop' => 0,
                            'decoder' => ImpureTranslationDecoder::class,
                            'response' => 'fresh-data',
                            'decoded response' => 'translated[cs]: fresh-data',
                        ],
                    ],
                ],
            ],
        ];
    }
}
