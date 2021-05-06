<?php declare(strict_types=1);

namespace Lmc\Cqrs\Handler\Fixture;

use Lmc\Cqrs\Types\Base\AbstractQueryHandler;
use Lmc\Cqrs\Types\QueryInterface;
use Lmc\Cqrs\Types\ValueObject\OnErrorInterface;
use Lmc\Cqrs\Types\ValueObject\OnSuccessInterface;

/**
 * @phpstan-extends AbstractQueryHandler<string, string>
 */
class DummyQueryHandler extends AbstractQueryHandler
{
    public function supports(QueryInterface $query): bool
    {
        return $query->getRequestType() === DummyQuery::class;
    }

    public function handle(QueryInterface $query, OnSuccessInterface $onSuccess, OnErrorInterface $onError): void
    {
        if (!$this->assertIsSupported(DummyQuery::class, $query, $onError)) {
            return;
        }

        $onSuccess($query->createRequest());
    }
}
