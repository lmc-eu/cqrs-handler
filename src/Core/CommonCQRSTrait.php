<?php declare(strict_types=1);

namespace Lmc\Cqrs\Handler\Core;

use Lmc\Cqrs\Handler\ProfilerBag;
use Lmc\Cqrs\Types\Decoder\ResponseDecoderInterface;
use Lmc\Cqrs\Types\Utils;
use Lmc\Cqrs\Types\ValueObject\DecodedValueInterface;
use Lmc\Cqrs\Types\ValueObject\PrioritizedItem;
use Ramsey\Uuid\UuidInterface;

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

        $this->verboseOrDebug(
            $context->getKey(),
            fn () => [
                'handled by' => Utils::getType($handler),
                'response' => $context->getHandledResponseType(),
            ],
            fn () => [
                'handled by' => Utils::getType($handler),
                'response' => $response,
            ]
        );
    }

    /**
     * @phpstan-param Context $context
     */
    private function decodeResponse(AbstractContext $context): void
    {
        $initiator = $context->getInitiator();
        $currentResponse = $context->getResponse();

        $this->verbose($context->getKey(), fn () => [
            'start decoding response' => Utils::getType($currentResponse),
        ]);

        $i = -1;
        foreach ($this->decoders as $decoderItem) {
            $i++;
            $decoder = $decoderItem->getItem();

            $this->debug($context->getKey(), fn () => [
                'loop' => $i,
                'trying decoder' => Utils::getType($decoder),
            ]);

            if (!$decoder->supports($currentResponse, $initiator)) {
                continue;
            }

            $this->debug($context->getKey(), fn () => [
                'loop' => $i,
                'decoder' => Utils::getType($decoder),
                'supports response' => Utils::getType($currentResponse),
            ]);

            $decodedResponse = $this->getDecodedResponse($context, $decoder, $currentResponse);

            $this->verboseOrDebug(
                $context->getKey(),
                fn () => [
                    'loop' => $i,
                    'decoder' => Utils::getType($decoder),
                    'response' => Utils::getType($currentResponse),
                    'decoded response' => Utils::getType($decodedResponse),
                ],
                fn () => [
                    'loop' => $i,
                    'decoder' => Utils::getType($decoder),
                    'response' => $currentResponse,
                    'decoded response' => $decodedResponse,
                ]
            );

            $context->addUsedDecoder($decoder, $currentResponse, $decodedResponse);
            $continueDecoding = true;

            if ($decodedResponse instanceof DecodedValueInterface) {
                $this->verbose($context->getKey(), fn () => [
                    'decoding is finished' => Utils::getType($decodedResponse),
                ]);

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

    private function verboseOrDebug(UuidInterface $profilerKey, callable $createVerboseData, callable $createDebugData): void
    {
        if ($this->profilerBag && ($profilerItem = $this->profilerBag->get($profilerKey))) {
            if ($this->profilerBag->isDebug()) {
                if (!empty($debugData = $createDebugData())) {
                    $debug = $profilerItem->getAdditionalData()['CQRS.debug'] ?? [];
                    $debug[] = $debugData;
                    $profilerItem->setAdditionalData('CQRS.debug', $debug);
                } elseif (!empty($verboseData = $createVerboseData())) {
                    $debug = $profilerItem->getAdditionalData()['CQRS.debug'] ?? [];
                    $debug[] = $verboseData;
                    $profilerItem->setAdditionalData('CQRS.debug', $debug);
                }
            } elseif ($this->profilerBag->isVerbose()) {
                if (!empty($verboseData = $createVerboseData())) {
                    $verbose = $profilerItem->getAdditionalData()['CQRS.verbose'] ?? [];
                    $verbose[] = $verboseData;
                    $profilerItem->setAdditionalData('CQRS.verbose', $verbose);
                }
            }
        }
    }

    /** @phpstan-param callable(): array $createData */
    private function verbose(UuidInterface $profilerKey, callable $createData): void
    {
        $this->verboseOrDebug($profilerKey, $createData, fn () => []);
    }

    /** @phpstan-param callable(): array $createData */
    private function debug(UuidInterface $profilerKey, callable $createData): void
    {
        $this->verboseOrDebug($profilerKey, fn () => [], $createData);
    }
}
