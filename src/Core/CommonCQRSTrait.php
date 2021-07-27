<?php declare(strict_types=1);

namespace Lmc\Cqrs\Handler\Core;

use Lmc\Cqrs\Handler\ProfilerBag;
use Lmc\Cqrs\Types\CommandInterface;
use Lmc\Cqrs\Types\Decoder\ResponseDecoderInterface;
use Lmc\Cqrs\Types\Feature\ProfileableInterface;
use Lmc\Cqrs\Types\QueryInterface;
use Lmc\Cqrs\Types\Utils;
use Lmc\Cqrs\Types\ValueObject\DecodedValue;
use Lmc\Cqrs\Types\ValueObject\PrioritizedItem;
use Symfony\Component\Stopwatch\Stopwatch;

trait CommonCQRSTrait
{
    /**
     * @phpstan-var PrioritizedItem<ResponseDecoderInterface<mixed, mixed>>[]
     * @var PrioritizedItem[]
     */
    private array $decoders = [];

    private ?ProfilerBag $profilerBag;

    private bool $isHandled;
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

    private function setIsHandled(bool $isHandled): void
    {
        $this->isHandled = $isHandled;
    }

    /** @param CommandInterface<mixed>|QueryInterface<mixed> $initiator */
    private function decodeResponse($initiator): void
    {
        $currentResponse = $this->lastSuccess;

        $profilerId = $initiator instanceof ProfileableInterface
            ? $initiator->getProfilerId()
            : null;

        if ($profilerId) {
            $this->lastUsedDecoders[$profilerId] = [];
        }

        foreach ($this->decoders as $decoderItem) {
            $decoder = $decoderItem->getItem();

            if ($decoder->supports($currentResponse, $initiator)) {
                $decodedResponse = $decoder->decode($currentResponse);

                if ($decodedResponse instanceof DecodedValue) {
                    $decodedResponse = $decodedResponse->getValue();

                    if ($profilerId) {
                        $this->lastUsedDecoders[$profilerId][] = sprintf(
                            '%s<%s, DecodedValue<%s>>',
                            get_class($decoder),
                            Utils::getType($currentResponse),
                            Utils::getType($decodedResponse)
                        );
                    }
                    $currentResponse = $decodedResponse;

                    break;
                }

                if ($profilerId) {
                    $this->lastUsedDecoders[$profilerId][] = sprintf(
                        '%s<%s, %s>',
                        get_class($decoder),
                        Utils::getType($currentResponse),
                        Utils::getType($decodedResponse)
                    );
                }
                $currentResponse = $decodedResponse;
            }
        }

        $this->lastSuccess = $currentResponse;
    }
}
