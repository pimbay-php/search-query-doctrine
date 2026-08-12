<?php

declare(strict_types=1);

namespace PimBay\SearchQuery\Doctrine\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PimBay\SearchQuery\Doctrine\Adapter\OrmSimpleAdapter;
use PimBay\SearchQuery\Doctrine\Tests\Fixture\CallLog;
use PimBay\SearchQuery\Doctrine\Tests\Fixture\Entity\Product;
use PimBay\SearchQuery\Doctrine\Tests\Fixture\OrmFixture;
use PimBay\SearchQuery\Doctrine\Tests\Fixture\SpyOrmQueryBuilder;

final class OrmSimpleAdapterTest extends TestCase
{
    /**
     * @var OrmSimpleAdapter<Product>
     */
    private OrmSimpleAdapter $adapter;

    /**
     * @return iterable<string, array{int, int, string[], bool}>
     */
    public static function pageSliceProvider(): iterable
    {
        yield 'full extra row available signals more' => [0, 2, ['a', 'b'], true];
        yield 'last page exactly full signals no more' => [3, 2, ['d', 'e'], false];
    }

    protected function setUp(): void
    {
        $em = OrmFixture::createEntityManager();
        OrmFixture::seedProducts($em, [
            ['name' => 'a', 'price' => 10],
            ['name' => 'b', 'price' => 20],
            ['name' => 'c', 'price' => 30],
            ['name' => 'd', 'price' => 40],
            ['name' => 'e', 'price' => 50],
        ]);
        $qb = $em->createQueryBuilder()->select('p')->from(Product::class, 'p')->orderBy('p.id');

        $this->adapter = new OrmSimpleAdapter($qb);
    }

    #[Test]
    public function countReturnsTotalRowCountIgnoringLimitAndOffset(): void
    {
        self::assertSame(5, $this->adapter->count());
    }

    #[Test]
    public function headReturnsTheFirstNRowsFromTheStart(): void
    {
        $rows = $this->adapter->head(2);

        self::assertSame(['a', 'b'], array_map(static fn (Product $p) => $p->name, [...$rows]));
    }

    #[Test]
    public function allReturnsEveryRowAsAnIterable(): void
    {
        $rows = iterator_to_array($this->adapter->all());

        self::assertSame(['a', 'b', 'c', 'd', 'e'], array_map(static fn (Product $p) => $p->name, $rows));
    }

    #[Test]
    public function pageViewReturnsTheRequestedWindowPlusTotalCount(): void
    {
        $chunk = $this->adapter->pageView(1, 2);

        self::assertSame(['b', 'c'], array_map(static fn (Product $p) => $p->name, [...$chunk->results]));
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

        self::assertSame($expectedNames, array_map(static fn (Product $p) => $p->name, [...$chunk->results]));
        self::assertSame($expectedHasMore, $chunk->hasMore);
    }

    #[Test]
    public function countResetsTheOrderByDqlPartBeforeRunningTheAggregateQuery(): void
    {
        // Doctrine tolerates a stray ORDER BY in an aggregate DQL query on SQLite, so this can't
        // be observed through getSingleScalarResult() alone — spy on the QueryBuilder instead.
        $em = OrmFixture::createEntityManager();
        OrmFixture::seedProducts($em, [['name' => 'a', 'price' => 10]]);

        $qb = new SpyOrmQueryBuilder($em);
        $qb->select('p')->from(Product::class, 'p')->orderBy('p.id');
        $log = new CallLog();
        $qb->resetDqlPartLog = $log;

        (new OrmSimpleAdapter($qb))->count();

        self::assertContains('orderBy', $log->calls);
    }

    #[Test]
    public function cloneQueryDeepClonesTheParametersCollectionSoTheOriginalIsNeverMutated(): void
    {
        $em = OrmFixture::createEntityManager();
        OrmFixture::seedProducts($em, [['name' => 'a', 'price' => 10]]);
        $qb = $em->createQueryBuilder()
            ->select('p')
            ->from(Product::class, 'p')
            ->andWhere('p.price > :minPrice')
            ->setParameter('minPrice', 0);

        $adapter = new OrmSimpleAdapter($qb);
        $reflection = new \ReflectionMethod(OrmSimpleAdapter::class, 'cloneQuery');
        /** @var \Doctrine\ORM\QueryBuilder $clone */
        $clone = $reflection->invoke($adapter);

        self::assertNotSame($qb, $clone);
        self::assertNotSame($qb->getParameters(), $clone->getParameters());
    }

    #[Test]
    public function repeatedCallsAreIndependentBecauseEachOneClonesTheQueryBuilderAndItsParameters(): void
    {
        $first = $this->adapter->pageView(0, 2);
        $second = $this->adapter->pageView(0, 2);

        self::assertSame(
            array_map(static fn (Product $p) => $p->name, [...$first->results]),
            array_map(static fn (Product $p) => $p->name, [...$second->results]),
        );
    }
}
