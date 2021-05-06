<?php declare(strict_types=1);

namespace Lmc\Cqrs\Handler\Handler;

use Lmc\Cqrs\Handler\AbstractTestCase;
use Lmc\Cqrs\Handler\Query\CachedDataQuery;
use Lmc\Cqrs\Handler\Query\ProfiledCachedDataQuery;
use Lmc\Cqrs\Types\QueryInterface;
use Lmc\Cqrs\Types\ValueObject\CacheKey;
use Lmc\Cqrs\Types\ValueObject\CacheTime;
use Lmc\Cqrs\Types\ValueObject\OnErrorCallback;
use Lmc\Cqrs\Types\ValueObject\OnSuccessCallback;

class CallbackQueryHandlerTest extends AbstractTestCase
{
    /** @phpstan-var CallbackQueryHandler<mixed> */
    private CallbackQueryHandler $handler;

    protected function setUp(): void
    {
        $this->handler = new CallbackQueryHandler();
    }

    /**
     * @param QueryInterface<callable(): mixed> $query
     *
     * @dataProvider provideCallableQuery
     * @test
     */
    public function shouldSupportCallableQuery(QueryInterface $query): void
    {
        $this->assertTrue($this->handler->supports($query));
    }

    /**
     * @param QueryInterface<callable(): mixed> $query
     * @param mixed $expectedResult
     *
     * @dataProvider provideCallableQuery
     * @test
     */
    public function shouldHandleCallableQuery(QueryInterface $query, $expectedResult): void
    {
        $this->handler->handle(
            $query,
            new OnSuccessCallback(fn ($data) => $this->assertSame($expectedResult, $data)),
            new OnErrorCallback(fn (\Throwable $e) => $this->fail($e->getMessage()))
        );
    }

    public function provideCallableQuery(): array
    {
        return [
            // query, expectedResult
            'CachedDataQuery<callable<array>>' => [
                new CachedDataQuery(fn () => ['fresh-data'], new CacheKey('key'), CacheTime::oneMinute()),
                ['fresh-data'],
            ],
            'ProfiledCachedDataQuery<callable<array>>' => [
                new ProfiledCachedDataQuery(fn () => ['fresh-data'], new CacheKey('key'), CacheTime::oneMinute(), 'profilerId'),
                ['fresh-data'],
            ],
        ];
    }
}
