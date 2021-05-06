<?php declare(strict_types=1);

namespace Lmc\Cqrs\Handler;

use Lmc\Cqrs\Handler\Core\CommonCQRSTrait;
use Lmc\Cqrs\Types\CommandInterface;
use Lmc\Cqrs\Types\CommandSenderInterface;
use Lmc\Cqrs\Types\Decoder\ResponseDecoderInterface;
use Lmc\Cqrs\Types\Exception\NoSendCommandHandlerUsedException;
use Lmc\Cqrs\Types\Feature\ProfileableInterface;
use Lmc\Cqrs\Types\SendCommandHandlerInterface;
use Lmc\Cqrs\Types\ValueObject\OnErrorCallback;
use Lmc\Cqrs\Types\ValueObject\OnErrorInterface;
use Lmc\Cqrs\Types\ValueObject\OnSuccessCallback;
use Lmc\Cqrs\Types\ValueObject\OnSuccessInterface;
use Lmc\Cqrs\Types\ValueObject\PrioritizedItem;
use Lmc\Cqrs\Types\ValueObject\ProfilerItem;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\Stopwatch\Stopwatch;

/**
 * @phpstan-template Request
 * @phpstan-template Response
 * @phpstan-template DecodedResponse
 * @phpstan-implements CommandSenderInterface<Request, DecodedResponse>
 */
class CommandSender implements CommandSenderInterface
{
    use CommonCQRSTrait;

    /**
     * @phpstan-var PrioritizedItem<SendCommandHandlerInterface<mixed, mixed>>[]
     * @var PrioritizedItem[]
     */
    private array $handlers = [];

    /**
     * @phpstan-var ?DecodedResponse
     * @var ?mixed
     */
    private $lastSuccess;

    /**
     * Custom Handler(s) priority defaults to 50 (medium)
     *
     * Handler can be set by an array of Handler and a priority
     * For instance:
     *  - [ new MyTopMostHandler(), PrioritizedItem::PRIORITY_HIGHEST ]
     *  - [ new FallbackHandler(), PrioritizedItem::PRIORITY_LOW ]
     *
     * Or it can be PrioritizedItem instance
     *  - new PrioritizedItem(new MyTopMostHandler(), PrioritizedItem::PRIORITY_HIGHEST)
     *
     *
     * Custom Decoder(s) priority defaults to 50 (medium)
     *
     * Decoder can be set by an array of Decoder and a priority
     * For instance:
     *  - [ new MyTopMostDecoder(), PrioritizedItem::PRIORITY_HIGHEST ]
     *  - [ new FallbackDecoder(), PrioritizedItem::PRIORITY_LOW ]
     *
     * Or it can be PrioritizedItem instance
     *  - new PrioritizedItem(new MyTopMostDecoder(), PrioritizedItem::PRIORITY_HIGHEST)
     *
     * @param iterable<SendCommandHandlerInterface<mixed, mixed>|iterable|PrioritizedItem<SendCommandHandlerInterface<mixed, mixed>>> $customHandlers
     * @param iterable<ResponseDecoderInterface<mixed, mixed>|iterable|PrioritizedItem<ResponseDecoderInterface<mixed, mixed>>> $customDecoders
     * @see PrioritizedItem::PRIORITY_MEDIUM
     */
    public function __construct(
        ?ProfilerBag $profilerBag,
        iterable $customHandlers = [],
        iterable $customDecoders = []
    ) {
        $this->profilerBag = $profilerBag;

        $this->register($customHandlers, [$this, 'addHandler']);
        $this->register($customDecoders, [$this, 'addDecoder']);
    }

    public function addHandler(SendCommandHandlerInterface $handler, int $priority): void
    {
        $this->handlers[] = new PrioritizedItem($handler, $priority);

        uasort($this->handlers, [PrioritizedItem::class, 'compare']);
    }

    public function send(CommandInterface $command, OnSuccessInterface $onSuccess, OnErrorInterface $onError): void
    {
        $this->setIsHandled(false);
        $this->lastSuccess = null;
        $this->lastError = null;

        foreach ($this->iterateHandlers() as $handler) {
            if ($handler->supports($command)) {
                $handler->prepare($command);
            }
        }

        $currentProfileKey = null;
        if ($command instanceof ProfileableInterface) {
            $currentProfileKey = $this->startProfileCommand($command);
        }

        foreach ($this->iterateHandlers() as $handler) {
            if ($handler->supports($command)) {
                $handler->handle(
                    $command,
                    new OnSuccessCallback(function ($response): void {
                        $this->setIsHandled(true);
                        $this->lastSuccess = $response;
                    }),
                    new OnErrorCallback(function (\Throwable $error): void {
                        $this->setIsHandled(true);
                        $this->lastError = $error;
                    }),
                );

                if ($this->isHandled && $this->lastError === null) {
                    $this->decodeResponse();
                }

                if ($this->isHandled && $command instanceof ProfileableInterface) {
                    $this->profileCommandFinished($command, $currentProfileKey, $handler);
                }

                if ($this->isHandled && $this->lastError) {
                    $onError($this->lastError);

                    return;
                }

                if ($this->isHandled && $this->lastSuccess) {
                    $onSuccess($this->lastSuccess);

                    return;
                }
            }
        }

        $onError(NoSendCommandHandlerUsedException::create($command, $this->handlers));
    }

    /**
     * @phpstan-param CommandInterface<Request> $command
     * @phpstan-return DecodedResponse
     * @throws \Throwable
     * @return mixed
     */
    public function sendAndReturn(CommandInterface $command)
    {
        $response = null;

        $this->send(
            $command,
            new OnSuccessCallback(function ($decodedResponse) use (&$response): void {
                $response = $decodedResponse;
            }),
            OnErrorCallback::throwOnError()
        );

        return $response;
    }

    private function iterateHandlers(): array
    {
        return array_map(
            fn (PrioritizedItem $PrioritizedItem) => $PrioritizedItem->getItem(),
            $this->handlers
        );
    }

    private function startProfileCommand(ProfileableInterface $command): UuidInterface
    {
        $key = Uuid::uuid4();
        if ($this->profilerBag) {
            $this->profilerBag->add(
                $key,
                new ProfilerItem(
                    $command->getProfilerId(),
                    $command->getProfilerData(),
                    ProfilerItem::TYPE_COMMAND,
                    get_class($command)
                )
            );

            $this->stopwatch = new Stopwatch();
            $this->stopwatch->start($key->toString());
        }

        return $key;
    }

    /** @phpstan-param SendCommandHandlerInterface<Request, Response> $currentHandler */
    private function profileCommandFinished(
        ProfileableInterface $command,
        ?UuidInterface $currentProfilerKey,
        SendCommandHandlerInterface $currentHandler
    ): void {
        if ($this->profilerBag && $currentProfilerKey && ($item = $this->profilerBag->get($currentProfilerKey))) {
            if ($this->stopwatch) {
                $elapsed = $this->stopwatch->stop($currentProfilerKey->toString());

                $item->setDuration((int) $elapsed->getDuration());
            }

            $item->setHandledBy(get_class($currentHandler));
            $item->setDecodedBy($this->lastUsedDecoders);

            if ($this->lastSuccess) {
                $item->setResponse($this->lastSuccess);
            }

            if ($this->lastError) {
                $item->setError($this->lastError);
            }
        }
    }
}
