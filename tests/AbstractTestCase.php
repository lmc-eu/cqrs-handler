<?php declare(strict_types=1);

namespace Lmc\Cqrs\Handler;

use Lmc\Cqrs\Types\ValueObject\ProfilerItem;
use PHPUnit\Framework\TestCase;

abstract class AbstractTestCase extends TestCase
{
    protected function ignore(): callable
    {
        return function (...$args): void {
            // ignore args
        };
    }

    /** @param ProfilerItem[] $profilerBag */
    protected function assertLastHandledBy(string $expectedHandlerClass, string $expectedResponseType, array $profilerBag): void
    {
        /** @var ProfilerItem $lastProfilerItem */
        $lastProfilerItem = end($profilerBag);

        $this->assertHandledBy($expectedHandlerClass, $expectedResponseType, $lastProfilerItem->getHandledBy());
    }

    protected function assertHandledBy(string $expectedHandlerClass, string $expectedResponseType, string $actual): void
    {
        $expected = sprintf('%s<%s>', $expectedHandlerClass, $expectedResponseType);

        $this->assertSame($expected, $actual);
    }
}
