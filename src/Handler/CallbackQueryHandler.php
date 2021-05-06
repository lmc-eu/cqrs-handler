<?php declare(strict_types=1);

namespace Lmc\Cqrs\Handler\Handler;

use Lmc\Cqrs\Types\Base\AbstractQueryHandler;
use Lmc\Cqrs\Types\QueryInterface;
use Lmc\Cqrs\Types\ValueObject\OnErrorInterface;
use Lmc\Cqrs\Types\ValueObject\OnSuccessInterface;

/**
 * @phpstan-template Data
 * @phpstan-extends AbstractQueryHandler<callable(): Data, Data>
 */
class CallbackQueryHandler extends AbstractQueryHandler
{
    public function supports(QueryInterface $query): bool
    {
        return in_array($query->getRequestType(), ['callable', 'Closure', 'callback'], true);
    }

    /**
     * @phpstan-param QueryInterface<callable(): Data> $query
     * @phpstan-param OnSuccessInterface<Data> $onSuccess
     */
    public function handle(QueryInterface $query, OnSuccessInterface $onSuccess, OnErrorInterface $onError): void
    {
        if (!$this->assertIsSupported('"callable", "Closure" or "callback"', $query, $onError)) {
            return;
        }

        try {
            $onSuccess(call_user_func($query->createRequest()));
        } catch (\Throwable $e) {
            $onError($e);
        }
    }
}
