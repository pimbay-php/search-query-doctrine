<?php

declare(strict_types=1);

namespace PimBay\SearchQuery\Doctrine\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PimBay\SearchQuery\Doctrine\Adapter\DbalSimpleAdapter;
use PimBay\SearchQuery\Doctrine\Tests\Fixture\DbalFixture;

final class DbalSimpleAdapterTest extends TestCase
{
    private DbalSimpleAdapter $adapter;

    /**
     * @return iterable<string, array{int, int, string[]}>
     */
    public static function pageViewProvider(): iterable
    {
        yield 'within range' => [1, 2, ['b', 'c']];
        yield 'past the end' => [100, 2, []];
    }

    /**
     * @return iterable<string, array{int, int, string[], bool}>
     */
    public static function pageSliceProvider(): iterable
    {
        yield 'full extra row available signals more' => [0, 2, ['a', 'b'], true];
        yield 'last page exactly full signals no more' => [3, 2, ['d', 'e'], false];
        yield 'past the end is empty with no more' => [100, 2, [], false];
    }

    protected function setUp(): void
    {
        $connection = DbalFixture::createConnection();
        DbalFixture::seedProducts($connection, [
            ['id' => 1, 'name' => 'a', 'price' => 10],
            ['id' => 2, 'name' => 'b', 'price' => 20],
            ['id' => 3, 'name' => 'c', 'price' => 30],
            ['id' => 4, 'name' => 'd', 'price' => 40],
            ['id' => 5, 'name' => 'e', 'price' => 50],
        ]);
        $qb = DbalFixture::productQueryBuilder($connection)->orderBy('id');

        $this->adapter = new DbalSimpleAdapter($qb);
    }

    #[Test]
    public function countReturnsTotalRowCountIgnoringLimitAndOffset(): void
    {
        self::assertSame(5, $this->adapter->count());
    }

    #[Test]
    public function headReturnsTheFirstNRowsFromTheStart(): void
    {
        $rows = iterator_to_array($this->adapter->head(2));

        self::assertSame(['a', 'b'], array_column($rows, 'name'));
    }

    #[Test]
    public function allReturnsEveryRowAsAnIterable(): void
    {
        $rows = iterator_to_array($this->adapter->all());

        self::assertSame(['a', 'b', 'c', 'd', 'e'], array_column($rows, 'name'));
    }

    /**
     * @param string[] $expectedNames
     */
    #[Test]
    #[DataProvider('pageViewProvider')]
    public function pageViewReturnsTheRequestedWindowPlusTheRealTotalCount(int $offset, int $size, array $expectedNames): void
    {
        $chunk = $this->adapter->pageView($offset, $size);

        self::assertSame($expectedNames, array_column(iterator_to_array($chunk->results), 'name'));
        self::assertSame(5, $chunk->totalCount);
    }

    /**
     * @param string[] $expectedNames
     */
    #[Test]
    #[DataProvider('pageSliceProvider')]
    public function pageSliceReportsHasMoreCorrectly(int $offset, int $size, array $expectedNames, bool $expectedHasMore): void
    {
        $chunk = $this->adapter->pageSlice($offset, $size);

        self::assertSame($expectedNames, array_column(iterator_to_array($chunk->results), 'name'));
        self::assertSame($expectedHasMore, $chunk->hasMore);
    }

    #[Test]
    public function countStripsOrderByEvenWhenItReferencesAColumnNotInTheAggregateSelect(): void
    {
        // If resetOrderBy() were skipped, the leftover ORDER BY (on a column that only exists in
        // the original SELECT list, not in `SELECT COUNT(*)`) would make SQLite fail the query —
        // a real, observable failure mode, not just a cosmetic difference in generated SQL.
        $connection = DbalFixture::createConnection();
        DbalFixture::seedProducts($connection, [
            ['id' => 1, 'name' => 'a', 'price' => 10],
            ['id' => 2, 'name' => 'b', 'price' => 20],
        ]);
        $qb = DbalFixture::productQueryBuilder($connection)->orderBy('sort_rank_alias_not_a_real_column');

        $adapter = new DbalSimpleAdapter($qb);

        self::assertSame(2, $adapter->count());
    }

    #[Test]
    public function repeatedCallsAreIndependentBecauseEachOneClonesTheQueryBuilder(): void
    {
        $first = $this->adapter->pageView(0, 2);
        $second = $this->adapter->pageView(0, 2);

        self::assertSame(array_column(iterator_to_array($first->results), 'name'), array_column(iterator_to_array($second->results), 'name'));
    }
}
