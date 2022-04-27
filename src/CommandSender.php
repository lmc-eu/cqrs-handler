<?php declare(strict_types=1);

namespace Lmc\Cqrs\Handler;

use Lmc\Cqrs\Handler\Core\CommonCQRSTrait;
use Lmc\Cqrs\Handler\Core\SendContext;
use Lmc\Cqrs\Types\CommandInterface;
use Lmc\Cqrs\Types\CommandSenderInterface;
use Lmc\Cqrs\Types\Decoder\ResponseDecoderInterface;
use Lmc\Cqrs\Types\Exception\NoSendCommandHandlerUsedException;
use Lmc\Cqrs\Types\Feature\ProfileableInterface;
use Lmc\Cqrs\Types\SendCommandHandlerInterface;
use Lmc\Cqrs\Types\Utils;
use Lmc\Cqrs\Types\ValueObject\OnErrorCallback;
use Lmc\Cqrs\Types\ValueObject\OnErrorInterface;
use Lmc\Cqrs\Types\ValueObject\OnSuccessCallback;
use Lmc\Cqrs\Types\ValueObject\OnSuccessInterface;
use Lmc\Cqrs\Types\ValueObject\PrioritizedItem;
use Lmc\Cqrs\Types\ValueObject\ProfilerItem;

/**
 * @phpstan-template Request
 * @phpstan-template Response
 * @phpstan-template DecodedResponse
 *
 * @phpstan-type Handler SendCommandHandlerInterface<Request, Response>
 * @phpstan-type Context SendContext<Request, Response>
 *
 * @phpstan-implements CommandSenderInterface<Request, DecodedResponse>
 */
class CommandSender implements CommandSenderInterface
{
    /** @phpstan-use CommonCQRSTrait<Request, Response, Context, Handler> */
    use CommonCQRSTrait;

    /**
     * @phpstan-var PrioritizedItem<SendCommandHandlerInterface<mixed, mixed>>[]
     * @var PrioritizedItem[]
     */
    private array $handlers = [];

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
        iterable $customDecoders = [],
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
        $context = new SendContext($command);

        foreach ($this->iterateHandlers() as $handler) {
            if ($handler->supports($command)) {
                $handler->prepare($command);
            }
        }

        if ($command instanceof ProfileableInterface) {
            $this->startProfileCommand($command, $context);
        }

        foreach ($this->iterateHandlers() as $handler) {
            if (!$handler->supports($command)) {
                continue;
            }

            $handler->handle(
                $command,
                new OnSuccessCallback(function ($response) use ($context, $handler): void {
                    $this->setIsHandled($handler, $context, $response);
                    $context->setResponse($response);
                }),
                new OnErrorCallback(function (\Throwable $error) use ($context, $handler): void {
                    $this->setIsHandled($handler, $context, $error);
                    $context->setError($error);
                }),
            );

            if ($context->isHandled()) {
                if ($context->getError() === null) {
                    $this->decodeResponse($context);
                }

                if ($command instanceof ProfileableInterface) {
                    $this->profileCommandFinished($context);
                }

                if (($error = $context->getError())) {
                    $onError($error);

                    return;
                }

                $onSuccess($context->getResponse());

                return;
            }
        }

        $onError(NoSendCommandHandlerUsedException::create($command, $this->handlers));
    }

    /**
     * @phpstan-template T
     * @phpstan-template U
     *
     * @phpstan-param Context $context
     * @phpstan-param ResponseDecoderInterface<T, U> $decoder
     * @phpstan-param T $currentResponse
     * @phpstan-return U
     */
    private function getDecodedResponse(
        SendContext $context,
        ResponseDecoderInterface $decoder,
        mixed $currentResponse,
    ) {
        return $decoder->decode($currentResponse);
    }

    /**
     * @phpstan-param CommandInterface<Request> $command
     * @phpstan-return DecodedResponse
     * @throws \Throwable
     */
    public function sendAndReturn(CommandInterface $command): mixed
    {
        $response = null;

        $this->send(
            $command,
            new OnSuccessCallback(function ($decodedResponse) use (&$response): void {
                $response = $decodedResponse;
            }),
            OnErrorCallback::throwOnError(),
        );

        return $response;
    }

    private function iterateHandlers(): array
    {
        return array_map(
            fn (PrioritizedItem $PrioritizedItem) => $PrioritizedItem->getItem(),
            $this->handlers,
        );
    }

    /** @phpstan-param Context $context
     */
    private function startProfileCommand(ProfileableInterface $command, SendContext $context): void
    {
        if ($this->profilerBag) {
            $key = $context->getKey();

            $this->profilerBag->add(
                $key,
                new ProfilerItem(
                    $command->getProfilerId(),
                    $command->getProfilerData(),
                    ProfilerItem::TYPE_COMMAND,
                    get_class($command),
                ),
            );

            $context->startStopwatch();
        }
    }

    /**
     * @phpstan-param Context $context
     */
    private function profileCommandFinished(SendContext $context): void
    {
        if ($this->profilerBag && ($profilerItem = $this->profilerBag->get($context->getKey()))) {
            $context->stopStopwatch($profilerItem);

            $currentHandler = $context->getUsedHandler();

            $profilerItem->setHandledBy(sprintf(
                '%s<%s>',
                Utils::getType($currentHandler),
                $context->getHandledResponseType(),
            ));
            $profilerItem->setDecodedBy($context->getUsedDecoders());

            if ($response = $context->getResponse()) {
                $profilerItem->setResponse($response);
            }

            if (($error = $context->getError())) {
                $profilerItem->setError($error);
            }
        }
    }
}
