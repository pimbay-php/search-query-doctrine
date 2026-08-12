<?php

declare(strict_types=1);

namespace PimBay\SearchQuery\Doctrine\Tests\Functional\Adapter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PimBay\SearchQuery\Doctrine\Adapter\OrmFetchJoinSafeAdapter;
use PimBay\SearchQuery\Doctrine\Adapter\OrmIdentityAdapter;
use PimBay\SearchQuery\Doctrine\Adapter\OrmSimpleAdapter;
use PimBay\SearchQuery\Doctrine\Tests\Fixture\Entity\Product;
use PimBay\SearchQuery\Doctrine\Tests\Fixture\OrmFixture;

final class OrmSimpleAdapterTest extends TestCase
{
    private const int TOTAL_ROWS = 17;

    private \Doctrine\ORM\EntityManager $em;

    protected function setUp(): void
    {
        $this->em = OrmFixture::createEntityManager();

        $rows = [];
        for ($i = 1; $i <= self::TOTAL_ROWS; ++$i) {
            $rows[] = ['name' => \sprintf('product-%02d', $i), 'price' => $i * 10];
        }
        OrmFixture::seedProducts($this->em, $rows);
    }

    #[Test]
    public function walkingEveryPageWithoutOverlapOrGapsReconstructsTheFullSeededSet(): void
    {
        /** @var OrmSimpleAdapter<Product> $adapter */
        $adapter = new OrmSimpleAdapter($this->productQueryBuilder());
        $pageSize = 5;
        $seenNames = [];

        for ($offset = 0; $offset < self::TOTAL_ROWS; $offset += $pageSize) {
            $chunk = $adapter->pageView($offset, $pageSize);
            self::assertSame(self::TOTAL_ROWS, $chunk->totalCount);

            foreach ($chunk->results as $product) {
                $seenNames[] = $product->name;
            }
        }

        self::assertCount(self::TOTAL_ROWS, $seenNames);
        self::assertSame($seenNames, array_unique($seenNames));
    }

    #[Test]
    public function identityAdapterIdsMatchThePagedRowIds(): void
    {
        /** @var OrmIdentityAdapter<Product> $adapter */
        $adapter = new OrmIdentityAdapter($this->productQueryBuilder(), 'p.id');

        self::assertSame(self::TOTAL_ROWS, \count($adapter->ids()));
        self::assertSame(
            $adapter->ids(),
            array_map(static fn (Product $p) => $p->id, [...$adapter->pageView(0, 100)->results]),
        );
    }

    #[Test]
    public function fetchJoinSafeAdapterPaginatesRootEntitiesAcrossAToManyAssociation(): void
    {
        $em = OrmFixture::createEntityManager();
        $rows = [];
        for ($i = 1; $i <= 12; ++$i) {
            // Vary the tag count per product so joined-row counts differ from root-entity counts.
            $tagCount = $i % 3;
            $rows[] = [
                'name' => \sprintf('item-%02d', $i),
                'price' => $i,
                'tags' => array_map(static fn (int $n) => \sprintf('tag-%d-%d', $i, $n), range(1, $tagCount)),
            ];
        }
        OrmFixture::seedProducts($em, $rows);

        $qb = $em->createQueryBuilder()
            ->select('p', 't')
            ->from(Product::class, 'p')
            ->leftJoin('p.tags', 't')
            ->orderBy('p.id');

        /** @var OrmFetchJoinSafeAdapter<Product> $adapter */
        $adapter = new OrmFetchJoinSafeAdapter($qb);

        self::assertSame(12, $adapter->count());

        $pageSize = 5;
        $seenNames = [];

        for ($offset = 0; $offset < 12; $offset += $pageSize) {
            $chunk = $adapter->pageView($offset, $pageSize);
            self::assertSame(12, $chunk->totalCount);

            foreach ($chunk->results as $product) {
                $seenNames[] = $product->name;
            }
        }

        self::assertCount(12, $seenNames);
        self::assertSame($seenNames, array_unique($seenNames));
    }

    private function productQueryBuilder(): \Doctrine\ORM\QueryBuilder
    {
        return $this->em->createQueryBuilder()->select('p')->from(Product::class, 'p')->orderBy('p.id');
    }
}
