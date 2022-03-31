<?php declare(strict_types=1);

namespace Lmc\Cqrs\Handler;

use Lmc\Cqrs\Types\ValueObject\ProfilerItem;
use Ramsey\Uuid\UuidInterface;

/** @phpstan-implements \IteratorAggregate<string, ProfilerItem> */
class ProfilerBag implements \IteratorAggregate, \Countable
{
    public const VERBOSITY_NORMAL = '';
    public const VERBOSITY_VERBOSE = 'verbose';
    public const VERBOSITY_DEBUG = 'debug';

    private const VERBOSITY = [
        self::VERBOSITY_NORMAL,
        self::VERBOSITY_VERBOSE,
        self::VERBOSITY_DEBUG,
    ];

    private string $verbosity = self::VERBOSITY_NORMAL;

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

    public function setVerbosity(string $verbosity): void
    {
        $this->verbosity = in_array($verbosity, self::VERBOSITY, true)
            ? $verbosity
            : $this->verbosity;
    }

    public function getVerbosity(): string
    {
        return $this->verbosity;
    }
}
