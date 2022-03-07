<?php declare(strict_types=1);

namespace Lmc\Cqrs\Handler\Core;

use Lmc\Cqrs\Types\CommandInterface;
use Lmc\Cqrs\Types\Decoder\ResponseDecoderInterface;
use Lmc\Cqrs\Types\QueryHandlerInterface;
use Lmc\Cqrs\Types\QueryInterface;
use Lmc\Cqrs\Types\SendCommandHandlerInterface;
use Lmc\Cqrs\Types\Utils;
use Lmc\Cqrs\Types\ValueObject\ProfilerItem;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\Stopwatch\Stopwatch;

/**
 * @internal
 *
 * @phpstan-template Request
 * @phpstan-template Response
 */
abstract class AbstractContext
{
    private UuidInterface $key;
    /** @phpstan-var QueryInterface<Request>|CommandInterface<Request> */
    private QueryInterface|CommandInterface $initiator;

    private bool $isHandled = false;
    /** @phpstan-var QueryHandlerInterface<Request, Response>|SendCommandHandlerInterface<Request, Response>|null */
    private QueryHandlerInterface|SendCommandHandlerInterface|null $usedHandler;
    private ?string $handledResponseType = null;

    /** @var string[] */
    private array $usedDecoders = [];

    private ?Stopwatch $stopwatch = null;

    private ?\Throwable $error = null;
    /** @phpstan-var ?Response */
    private mixed $response = null;

    /** @phpstan-param QueryInterface<Request>|CommandInterface<Request> $initiator */
    public function __construct(QueryInterface|CommandInterface $initiator)
    {
        $this->initiator = $initiator;
        $this->key = Uuid::uuid4();
    }

    /** @phpstan-return QueryInterface<Request>|CommandInterface<Request> */
    public function getInitiator(): QueryInterface|CommandInterface
    {
        return $this->initiator;
    }

    public function getKey(): UuidInterface
    {
        return $this->key;
    }

    public function startStopwatch(): void
    {
        $this->stopwatch = $this->stopwatch ?? new Stopwatch();
        $this->stopwatch->start($this->getKey()->toString());
    }

    public function stopStopwatch(ProfilerItem $profilerItem): void
    {
        if ($this->stopwatch) {
            $elapsed = $this->stopwatch->stop($this->getKey()->toString());
            $profilerItem->setDuration((int) $elapsed->getDuration());
        }
    }

    public function setIsHandled(bool $isHandled): void
    {
        $this->isHandled = $isHandled;
    }

    public function isHandled(): bool
    {
        return $this->isHandled;
    }

    /** @phpstan-param QueryHandlerInterface<Request, Response>|SendCommandHandlerInterface<Request, Response>|null $usedHandler */
    public function setUsedHandler(QueryHandlerInterface|SendCommandHandlerInterface|null $usedHandler): void
    {
        $this->usedHandler = $usedHandler;
    }

    /** @phpstan-return QueryHandlerInterface<Request, Response>|SendCommandHandlerInterface<Request, Response>|null */
    public function getUsedHandler(): QueryHandlerInterface|SendCommandHandlerInterface|null
    {
        return $this->usedHandler;
    }

    public function setHandledResponseType(string $handledResponseType): void
    {
        $this->handledResponseType = $handledResponseType;
    }

    public function getHandledResponseType(): ?string
    {
        return $this->handledResponseType;
    }

    /** @phpstan-param ?Response $response */
    public function setResponse(mixed $response): void
    {
        $this->response = $response;
    }

    /** @phpstan-return ?Response */
    public function getResponse(): mixed
    {
        return $this->response;
    }

    public function setError(?\Throwable $error): void
    {
        $this->error = $error;
    }

    public function getError(): ?\Throwable
    {
        return $this->error;
    }

    /**
     * @phpstan-template T
     * @phpstan-template U
     *
     * @phpstan-param ResponseDecoderInterface<T, U> $decoder
     * @phpstan-param T $currentResponse
     * @phpstan-param U $decodedResponse
     */
    public function addUsedDecoder(ResponseDecoderInterface $decoder, mixed $currentResponse, mixed $decodedResponse): void
    {
        $this->usedDecoders[] = sprintf(
            '%s<%s, %s>',
            get_class($decoder),
            Utils::getType($currentResponse),
            Utils::getType($decodedResponse),
        );
    }

    public function getUsedDecoders(): array
    {
        return $this->usedDecoders;
    }
}
