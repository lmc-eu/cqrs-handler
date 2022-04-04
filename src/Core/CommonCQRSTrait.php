<?php declare(strict_types=1);

namespace Lmc\Cqrs\Handler\Core;

use Lmc\Cqrs\Handler\ProfilerBag;
use Lmc\Cqrs\Types\Decoder\ResponseDecoderInterface;
use Lmc\Cqrs\Types\Utils;
use Lmc\Cqrs\Types\ValueObject\DecodedValueInterface;
use Lmc\Cqrs\Types\ValueObject\PrioritizedItem;

/**
 * @internal
 *
 * @phpstan-template Context
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

    /**
     * @phpstan-param Handler $handler
     * @phpstan-param Context $context
     * @param mixed $handler
     * @param mixed $response
     */
    private function setIsHandled($handler, AbstractContext $context, $response = null): void
    {
        $context->setIsHandled(true);

            $context->setUsedHandler($handler);
            $context->setHandledResponseType(Utils::getType($response));
    }

    /**
     * @phpstan-param Context $context
     */
    private function decodeResponse(AbstractContext $context): void
    {
        $initiator = $context->getInitiator();
        $currentResponse = $context->getResponse();

        foreach ($this->decoders as $decoderItem) {
            $decoder = $decoderItem->getItem();

            if (!$decoder->supports($currentResponse, $initiator)) {
                continue;
            }

            $decodedResponse = $this->getDecodedResponse($context, $decoder, $currentResponse);

            $context->addUsedDecoder($decoder, $currentResponse, $decodedResponse);
            $continueDecoding = true;

            if ($decodedResponse instanceof DecodedValueInterface) {
                $continueDecoding = false;
                $decodedResponse = $decodedResponse->getValue();
            }

            $currentResponse = $decodedResponse;

            if (!$continueDecoding) {
                break;
            }
        }

        $context->setResponse($currentResponse);
    }
}
