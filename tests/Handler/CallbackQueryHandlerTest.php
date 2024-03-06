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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

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
     */
    #[Test]
    #[DataProvider('provideCallableQuery')]
    public function shouldSupportCallableQuery(QueryInterface $query): void
    {
        $this->assertTrue($this->handler->supports($query));
    }

    /**
     * @param QueryInterface<callable(): mixed> $query
     */
    #[Test]
    #[DataProvider('provideCallableQuery')]
    public function shouldHandleCallableQuery(QueryInterface $query, mixed $expectedResult): void
    {
        $this->handler->handle(
            $query,
            new OnSuccessCallback(fn ($data) => $this->assertSame($expectedResult, $data)),
            new OnErrorCallback(fn (\Throwable $e) => $this->fail($e->getMessage())),
        );
    }

    public static function provideCallableQuery(): array
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
