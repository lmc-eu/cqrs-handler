<?php declare(strict_types=1);

namespace Lmc\Cqrs\Handler;

use Lmc\Cqrs\Types\ValueObject\ProfilerItem;
use Ramsey\Uuid\UuidInterface;

/** @phpstan-implements \IteratorAggregate<string, ProfilerItem> */
class ProfilerBag implements \IteratorAggregate, \Countable
{
    /** @var array<string, ProfilerItem> */
    private array $bag;

    public function __construct()
    {
        $this->bag = [];
    }

    public function add(UuidInterface $key, ProfilerItem $profilerItem): void
    {
        $this->bag[$key->toString()] = $profilerItem;
    }

    public function get(UuidInterface $key): ?ProfilerItem
    {
        return $this->bag[$key->toString()] ?? null;
    }

    public function getBag(): array
    {
        return $this->bag;
    }

    /** @return iterable<ProfilerItem> */
    public function getIterator(): iterable
    {
        yield from $this->bag;
    }

    public function count(): int
    {
        return count($this->bag);
    }
}
