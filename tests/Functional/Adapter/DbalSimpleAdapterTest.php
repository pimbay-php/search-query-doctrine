<?php

declare(strict_types=1);

namespace PimBay\SearchQuery\Doctrine\Tests\Functional\Adapter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PimBay\SearchQuery\Doctrine\Adapter\DbalIdentityAdapter;
use PimBay\SearchQuery\Doctrine\Adapter\DbalSimpleAdapter;
use PimBay\SearchQuery\Doctrine\SearchTerms\SearchTermsQuery;
use PimBay\SearchQuery\Doctrine\Tests\Fixture\DbalFixture;
use PimBay\SearchQuery\SearchTerms\ParsedSearchTerms;
use PimBay\SearchQuery\SearchTerms\SearchTermsConfig;

final class DbalSimpleAdapterTest extends TestCase
{
    private const int TOTAL_ROWS = 23;

    private \Doctrine\DBAL\Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DbalFixture::createConnection();

        $rows = [];
        for ($i = 1; $i <= self::TOTAL_ROWS; ++$i) {
            $rows[] = ['id' => $i, 'name' => \sprintf('product-%02d', $i), 'price' => $i * 10];
        }
        DbalFixture::seedProducts($this->connection, $rows);
    }

    #[Test]
    public function walkingEveryPageWithoutOverlapOrGapsReconstructsTheFullSeededSet(): void
    {
        $adapter = new DbalSimpleAdapter($this->queryBuilder());
        $pageSize = 7;
        $seenNames = [];

        for ($offset = 0; $offset < self::TOTAL_ROWS; $offset += $pageSize) {
            $chunk = $adapter->pageView($offset, $pageSize);
            self::assertSame(self::TOTAL_ROWS, $chunk->totalCount);

            foreach ($chunk->results as $row) {
                $name = $row['name'];

                self::assertIsString($name);

                $seenNames[] = (string) $name;
            }
        }

        self::assertCount(self::TOTAL_ROWS, $seenNames);
        self::assertSame($seenNames, array_unique($seenNames));
    }

    #[Test]
    public function walkingEveryPageViaPageSliceMarksHasMoreCorrectlyOnTheLastPage(): void
    {
        $adapter = new DbalSimpleAdapter($this->queryBuilder());
        $pageSize = 10;

        $first = $adapter->pageSlice(0, $pageSize);
        self::assertCount($pageSize, $first->results);
        self::assertTrue($first->hasMore);

        $second = $adapter->pageSlice($pageSize, $pageSize);
        self::assertCount($pageSize, $second->results);
        self::assertTrue($second->hasMore);

        $third = $adapter->pageSlice($pageSize * 2, $pageSize);
        self::assertCount(self::TOTAL_ROWS - $pageSize * 2, $third->results);
        self::assertFalse($third->hasMore);
    }

    #[Test]
    public function pageSizeThatDividesTheSetExactlyStillReportsNoMoreOnTheFinalPage(): void
    {
        // 23 rows is prime, so exercise the exact-fit boundary using a search filter that
        // narrows to a set divisible by the page size instead.
        $adapter = new DbalSimpleAdapter($this->queryBuilder());
        $qb = $this->queryBuilder();
        (new SearchTermsQuery())->apply(
            $qb,
            'name',
            new ParsedSearchTerms(equals: [], notEquals: [], likes: ['product-0*'], notLikes: []),
            'p',
            new SearchTermsConfig(anywhere: false),
        );
        $filtered = new DbalSimpleAdapter($qb);

        self::assertSame(9, $filtered->count());

        $chunk = $filtered->pageSlice(0, 9);
        self::assertCount(9, $chunk->results);
        self::assertFalse($chunk->hasMore);
    }

    #[Test]
    public function identityAdapterIdsMatchThePagedRowsForTheSameFilter(): void
    {
        $qb = $this->queryBuilder();
        (new SearchTermsQuery())->apply(
            $qb,
            'name',
            new ParsedSearchTerms(equals: [], notEquals: [], likes: ['*product-1*'], notLikes: []),
            'p',
            new SearchTermsConfig(),
        );

        $adapter = new DbalIdentityAdapter($qb, 'id');

        // product-10..product-19 → 10 matches (product-01 doesn't contain "product-1").
        self::assertSame(10, \count($adapter->ids()));
        self::assertSame(
            $adapter->ids(),
            array_column(iterator_to_array($adapter->pageView(0, 20)->results), 'id'),
        );
    }

    private function queryBuilder(): \Doctrine\DBAL\Query\QueryBuilder
    {
        return DbalFixture::productQueryBuilder($this->connection)->orderBy('id');
    }
}
