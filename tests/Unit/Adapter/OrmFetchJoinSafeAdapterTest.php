<?php

declare(strict_types=1);

namespace PimBay\SearchQuery\Doctrine\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PimBay\SearchQuery\Doctrine\Adapter\OrmFetchJoinSafeAdapter;
use PimBay\SearchQuery\Doctrine\Tests\Fixture\Entity\Product;
use PimBay\SearchQuery\Doctrine\Tests\Fixture\OrmFixture;

final class OrmFetchJoinSafeAdapterTest extends TestCase
{
    /**
     * @var OrmFetchJoinSafeAdapter<Product>
     */
    private OrmFetchJoinSafeAdapter $adapter;

    /**
     * @return iterable<string, array{int, int, int, string[]}>
     */
    public static function pageViewProvider(): iterable
    {
        yield 'first page' => [0, 2, 2, ['a', 'b']];
        yield 'second (remaining) page' => [2, 2, 1, ['c']];
    }

    /**
     * @return iterable<string, array{?bool, ?bool}>
     */
    public static function useOutputWalkersProvider(): iterable
    {
        yield 'null leaves the paginator default untouched' => [null, null];
        yield 'true enables output walkers' => [true, true];
        yield 'false disables output walkers' => [false, false];
    }

    protected function setUp(): void
    {
        $em = OrmFixture::createEntityManager();
        // Product "a" and "b" each have 2+ tags — a naive LIMIT/OFFSET over the joined SQL rows
        // (one SQL row per product/tag pair) would both miscount and truncate mid-collection
        // without the Paginator's fetchJoinCollection handling; that's exactly what this
        // adapter exists to prevent.
        OrmFixture::seedProducts($em, [
            ['name' => 'a', 'price' => 10, 'tags' => ['red', 'small', 'sale']],
            ['name' => 'b', 'price' => 20, 'tags' => ['red', 'large']],
            ['name' => 'c', 'price' => 30, 'tags' => []],
        ]);

        $qb = $em->createQueryBuilder()
            ->select('p', 't')
            ->from(Product::class, 'p')
            ->leftJoin('p.tags', 't')
            ->orderBy('p.id');

        $this->adapter = new OrmFetchJoinSafeAdapter($qb);
    }

    #[Test]
    public function countReturnsTheNumberOfRootEntitiesNotTheNumberOfJoinedRows(): void
    {
        self::assertSame(3, $this->adapter->count());
    }

    #[Test]
    public function pageViewReturnsFullyHydratedCollectionsWithoutDuplicateRoots(): void
    {
        $chunk = $this->adapter->pageView(0, 10);

        self::assertCount(3, $chunk->results);
        self::assertSame(3, $chunk->totalCount);

        $byName = [];
        foreach ($chunk->results as $product) {
            $byName[$product->name] = array_map(static fn ($t) => $t->name, $product->tags->toArray());
        }

        self::assertSame(['red', 'small', 'sale'], $byName['a']);
        self::assertSame(['red', 'large'], $byName['b']);
        self::assertSame([], $byName['c']);
    }

    /**
     * @param string[] $expectedNames
     */
    #[Test]
    #[DataProvider('pageViewProvider')]
    public function pageViewPaginatesByRootEntityNotByJoinedRow(int $offset, int $size, int $expectedCount, array $expectedNames): void
    {
        $chunk = $this->adapter->pageView($offset, $size);

        self::assertCount($expectedCount, $chunk->results);
        self::assertSame(3, $chunk->totalCount);
        self::assertSame($expectedNames, array_map(static fn (Product $p) => $p->name, [...$chunk->results]));
    }

    #[Test]
    #[DataProvider('useOutputWalkersProvider')]
    public function paginatorReflectsTheConfiguredUseOutputWalkersSetting(?bool $useOutputWalkers, ?bool $expected): void
    {
        $paginator = $this->buildPaginator($useOutputWalkers);

        self::assertSame($expected, $paginator->getUseOutputWalkers());
    }

    #[Test]
    public function cloneQueryReturnsAGenuinelyDistinctQueryBuilderInstance(): void
    {
        $em = OrmFixture::createEntityManager();
        OrmFixture::seedProducts($em, [['name' => 'x', 'price' => 1, 'tags' => ['t']]]);
        $qb = $em->createQueryBuilder()->select('p', 't')->from(Product::class, 'p')->leftJoin('p.tags', 't');

        /** @var OrmFetchJoinSafeAdapter<Product> $adapter */
        $adapter = new OrmFetchJoinSafeAdapter($qb);
        $clone = $this->invokeCloneQuery($adapter);

        self::assertNotSame($qb, $clone);
    }

    #[Test]
    public function pageViewNeverMutatesTheOriginalQueryBuildersOffsetOrLimit(): void
    {
        $em = OrmFixture::createEntityManager();
        OrmFixture::seedProducts($em, [
            ['name' => 'x', 'price' => 1, 'tags' => ['t']],
            ['name' => 'y', 'price' => 2, 'tags' => []],
        ]);
        $qb = $em->createQueryBuilder()->select('p', 't')->from(Product::class, 'p')->leftJoin('p.tags', 't');

        (new OrmFetchJoinSafeAdapter($qb))->pageView(1, 1);

        self::assertSame(0, $qb->getFirstResult());
        self::assertNull($qb->getMaxResults());
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

    /**
     * @return \Doctrine\ORM\Tools\Pagination\Paginator<Product>
     */
    private function buildPaginator(?bool $useOutputWalkers): \Doctrine\ORM\Tools\Pagination\Paginator
    {
        $em = OrmFixture::createEntityManager();
        OrmFixture::seedProducts($em, [['name' => 'x', 'price' => 1, 'tags' => ['t']]]);
        $qb = $em->createQueryBuilder()->select('p', 't')->from(Product::class, 'p')->leftJoin('p.tags', 't');

        /** @var OrmFetchJoinSafeAdapter<Product> $adapter */
        $adapter = new OrmFetchJoinSafeAdapter($qb, $useOutputWalkers);
        $reflection = new \ReflectionMethod(OrmFetchJoinSafeAdapter::class, 'paginator');

        /** @var \Doctrine\ORM\Tools\Pagination\Paginator<Product> $paginator */
        $paginator = $reflection->invoke($adapter, $qb);

        return $paginator;
    }

    /**
     * @param OrmFetchJoinSafeAdapter<Product> $adapter
     */
    private function invokeCloneQuery(OrmFetchJoinSafeAdapter $adapter): \Doctrine\ORM\QueryBuilder
    {
        $reflection = new \ReflectionMethod(OrmFetchJoinSafeAdapter::class, 'cloneQuery');

        /** @var \Doctrine\ORM\QueryBuilder $queryBuilder */
        $queryBuilder = $reflection->invoke($adapter);

        return $queryBuilder;
    }
}
