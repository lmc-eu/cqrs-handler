<?php declare(strict_types=1);

namespace Lmc\Cqrs\Handler\Core;

use Lmc\Cqrs\Types\QueryInterface;

/**
 * @internal
 *
 * @phpstan-template Request
 * @phpstan-template Response
 * @phpstan-extends AbstractContext<Request, Response>
 */
class FetchContext extends AbstractContext
{
    private bool $isAlreadyCached = false;

    /** @phpstan-param QueryInterface<Request> $query */
    public function __construct(QueryInterface $query)
    {
        parent::__construct($query);
    }

    public function setIsAlreadyCached(): void
    {
        $this->isAlreadyCached = true;
    }

    public function isAlreadyCached(): bool
    {
        return $this->isAlreadyCached;
    }
}
