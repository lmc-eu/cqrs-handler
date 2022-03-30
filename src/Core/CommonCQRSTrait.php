<?php declare(strict_types=1);

namespace Lmc\Cqrs\Handler\Core;

use Lmc\Cqrs\Handler\ProfilerBag;
use Lmc\Cqrs\Types\Decoder\ResponseDecoderInterface;
use Lmc\Cqrs\Types\Utils;
use Lmc\Cqrs\Types\ValueObject\DecodedValueInterface;
use Lmc\Cqrs\Types\ValueObject\PrioritizedItem;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\Stopwatch\Stopwatch;

/**
 * @phpstan-template Initiator
 * @phpstan-template Handler
 */
trait CommonCQRSTrait
{
    /**
     * @phpstan-var PrioritizedItem<ResponseDecoderInterface<mixed, mixed>>[]
     * @var PrioritizedItem[]
     */
    private array $decoders = [];

    private ?ProfilerBag $profilerBag;

    private bool $isHandled;
    /** @phpstan-var Handler|null */
    private $usedHandler;

    private ?\Throwable $lastError;
    /** @var array<string, string[]> */
    private array $lastUsedDecoders = [];

    private ?Stopwatch $stopwatch;

    public static function getDefaultPriority(): int
    {
        return PrioritizedItem::PRIORITY_MEDIUM;
    }

    /**
     * @phpstan-template Item
     *
     * @phpstan-param iterable<Item|iterable|PrioritizedItem<Item>> $items
     * @phpstan-param callable(Item, int): void $register
     */
    private function register(iterable $items, callable $register): void
    {
        foreach ($items as $item) {
            if ($item instanceof PrioritizedItem) {
                $priority = $item->getPriority();
                $item = $item->getItem();
            } else {
                [$item, $priority] = is_array($item)
                    ? [$item[0], $priority[1] ?? self::getDefaultPriority()]
                    : [$item, self::getDefaultPriority()];
            }

            $register($item, $priority);
        }
    }

    public function getHandlers(): array
    {
        return $this->handlers;
    }

    public function addDecoder(ResponseDecoderInterface $decoder, int $priority): void
    {
        $this->decoders[] = new PrioritizedItem($decoder, $priority);

        uasort($this->decoders, [PrioritizedItem::class, 'compare']);
    }

    public function getDecoders(): array
    {
        return $this->decoders;
    }

    /** @phpstan-param Handler|null $handler */
    private function setIsHandled($handler): void
    {
        $this->isHandled = $handler !== null;
        $this->usedHandler = $handler;
    }

    /** @phpstan-param Initiator $initiator */
    private function decodeResponse($initiator, ?UuidInterface $currentProfileKey): void
    {
        $currentResponse = $this->lastSuccess;

        $profilerKey = $currentProfileKey
            ? $currentProfileKey->toString()
            : null;

        if ($profilerKey) {
            $this->lastUsedDecoders[$profilerKey] = [];
        }

        // $dump = [
        //     'initiator' => Utils::getType($initiator),
        // ];

        $i = -1;
        foreach ($this->decoders as $decoderItem) {
            $i++;
            $decoder = $decoderItem->getItem();
            //$dump['loop_' . $i] = [];
            //$dump['loop_' . $i]['trying decoder'] = Utils::getType($decoder);

            if ($decoder->supports($currentResponse, $initiator)) {
                //$dump['loop_' . $i]['decoder supports response'] = $currentResponse;
                $decodedResponse = $this->getDecodedResponse(
                    $initiator,
                    $currentProfileKey,
                    $decoder,
                    $currentResponse
                );
                //$dump['loop_' . $i]['decoder decoded response'] = $decodedResponse;

                //if (function_exists('dump')) {
                //    call_user_func('dump', [__METHOD__ => $dump]);
                //}

                if ($decodedResponse instanceof DecodedValueInterface) {
                    $decodedResponse = $decodedResponse->getValue();

                    if ($profilerKey) {
                        $this->lastUsedDecoders[$profilerKey][] = sprintf(
                            '%s<%s, DecodedValue<%s>>',
                            get_class($decoder),
                            Utils::getType($currentResponse),
                            Utils::getType($decodedResponse)
                        );
                    }
                    $currentResponse = $decodedResponse;

                    break;
                }

                if ($profilerKey) {
                    $this->lastUsedDecoders[$profilerKey][] = sprintf(
                        '%s<%s, %s>',
                        get_class($decoder),
                        Utils::getType($currentResponse),
                        Utils::getType($decodedResponse)
                    );
                }
                $currentResponse = $decodedResponse;
                //} else {
                //if (function_exists('dump')) {
                //    call_user_func('dump', [__METHOD__ => $dump]);
                //}
            }
        }

        $this->lastSuccess = $currentResponse;
    }
}
