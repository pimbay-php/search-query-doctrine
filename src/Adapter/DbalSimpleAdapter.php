<?php

declare(strict_types=1);

/**
 * This file is part of the PimBay Search Query library.
 *
 * @author Jan Sarmir <sarmir@pimbay.dev>
 * @link   https://pimbay.dev
 *
 * For the full license information, see the LICENSE file.
 */

namespace PimBay\SearchQuery\Doctrine\Adapter;

use Doctrine\DBAL\Query\QueryBuilder;
use PimBay\SearchQuery\Adapter\AllAdapter;
use PimBay\SearchQuery\Adapter\CountableAdapter;
use PimBay\SearchQuery\Adapter\HeadableAdapter;
use PimBay\SearchQuery\Page\PageAdapter;
use PimBay\SearchQuery\Page\PageChunk;
use PimBay\SearchQuery\Slice\SliceAdapter;
use PimBay\SearchQuery\Slice\SliceChunk;

/**
 * Adapts a Doctrine DBAL QueryBuilder — no CursorAdapter, no fetch-joined to-many collection support (n/a for DBAL).
 *
 * @implements PageAdapter<array<string, mixed>>
 * @implements SliceAdapter<array<string, mixed>>
 * @implements HeadableAdapter<array<string, mixed>>
 * @implements AllAdapter<array<string, mixed>>
 */
readonly class DbalSimpleAdapter implements PageAdapter, SliceAdapter, CountableAdapter, HeadableAdapter, AllAdapter
{
    public function __construct(
        private QueryBuilder $queryBuilder,
    ) {
    }

    public function count(): int
    {
        $clone = $this->cloneQuery();
        $clone->resetOrderBy();

        /** @var int|string $count */
        $count = $clone->select('COUNT(*)')->fetchOne();

        return (int) $count;
    }

    public function head(int $size): iterable
    {
        return $this->cloneQuery()
            ->setFirstResult(0)
            ->setMaxResults($size)
            ->fetchAllAssociative();
    }

    public function all(): iterable
    {
        return $this->cloneQuery()->executeQuery()->iterateAssociative();
    }

    public function pageView(int $offset, int $size): PageChunk
    {
        $results = $this->cloneQuery()
            ->setFirstResult($offset)
            ->setMaxResults($size)
            ->fetchAllAssociative();

        return new PageChunk($results, $this->count());
    }

    public function pageSlice(int $offset, int $size): SliceChunk
    {
        $results = $this->cloneQuery()
            ->setFirstResult($offset)
            ->setMaxResults($size + 1)
            ->fetchAllAssociative();

        $hasMore = \count($results) > $size;

        if ($hasMore) {
            array_pop($results);
        }

        return new SliceChunk($results, $hasMore);
    }

    protected function cloneQuery(): QueryBuilder
    {
        return clone $this->queryBuilder;
    }
}
