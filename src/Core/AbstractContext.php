<?php declare(strict_types=1);

namespace Lmc\Cqrs\Handler\Core;

use Lmc\Cqrs\Types\CommandInterface;
use Lmc\Cqrs\Types\Decoder\ResponseDecoderInterface;
use Lmc\Cqrs\Types\QueryInterface;
use Lmc\Cqrs\Types\Utils;
use Lmc\Cqrs\Types\ValueObject\ProfilerItem;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\Stopwatch\Stopwatch;

/**
 * @internal
 *
 * @phpstan-template Initiator
 * @phpstan-template Handler
 * @phpstan-template Response
 */
abstract class AbstractContext
{
    private UuidInterface $key;
    /**
     * @phpstan-var Initiator
     * @var QueryInterface|CommandInterface
     */
    private $initiator;

    private bool $isHandled = false;
    /** @phpstan-var Handler|null */
    private $usedHandler;
    private ?string $handledResponseType = null;

    /** @var string[] */
    private array $usedDecoders = [];

    private ?Stopwatch $stopwatch = null;

    private ?\Throwable $error = null;
    /**
     * @phpstan-var ?Response
     * @var ?mixed
     */
    private $response;

    /**
     * @phpstan-param Initiator $initiator
     * @param QueryInterface|CommandInterface $initiator
     */
    public function __construct($initiator)
    {
        $this->initiator = $initiator;
        $this->key = Uuid::uuid4();
    }

    /**
     * @phpstan-return Initiator
     * @return QueryInterface|CommandInterface
     */
    public function getInitiator()
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

    /**
     * @phpstan-param Handler|null $usedHandler
     * @param mixed $usedHandler
     */
    public function setUsedHandler($usedHandler): void
    {
        $this->usedHandler = $usedHandler;
    }

    /**
     * @phpstan-return Handler|null
     * @return mixed
     */
    public function getUsedHandler()
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

    /**
     * @phpstan-param ?Response $response
     * @param ?mixed $response
     */
    public function setResponse($response): void
    {
        $this->response = $response;
    }

    /**
     * @phpstan-return ?Response
     * @return ?mixed
     */
    public function getResponse()
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
     * @param mixed $currentResponse
     * @param mixed $decodedResponse
     */
    public function addUsedDecoder(ResponseDecoderInterface $decoder, $currentResponse, $decodedResponse): void
    {
        $this->usedDecoders[] = sprintf(
            '%s<%s, %s>',
            get_class($decoder),
            Utils::getType($currentResponse),
            Utils::getType($decodedResponse)
        );
    }

    public function getUsedDecoders(): array
    {
        return $this->usedDecoders;
    }
}
