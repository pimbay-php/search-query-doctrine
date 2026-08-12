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

use Doctrine\ORM\QueryBuilder;
use PimBay\SearchQuery\Adapter\AllAdapter;
use PimBay\SearchQuery\Adapter\CountableAdapter;
use PimBay\SearchQuery\Adapter\HeadableAdapter;
use PimBay\SearchQuery\Page\PageAdapter;
use PimBay\SearchQuery\Page\PageChunk;
use PimBay\SearchQuery\Slice\SliceAdapter;
use PimBay\SearchQuery\Slice\SliceChunk;

/**
 * Adapts a Doctrine ORM QueryBuilder — no CursorAdapter, no fetch-joined to-many collection support.
 *
 * @template T of object
 *
 * @implements PageAdapter<T>
 * @implements SliceAdapter<T>
 * @implements HeadableAdapter<T>
 * @implements AllAdapter<T>
 */
readonly class OrmSimpleAdapter implements PageAdapter, SliceAdapter, CountableAdapter, HeadableAdapter, AllAdapter
{
    public function __construct(
        private QueryBuilder $queryBuilder,
    ) {
    }

    public function count(): int
    {
        $clone = $this->cloneQuery();
        $clone->resetDQLPart('orderBy');

        return (int) $clone->select(\sprintf('COUNT(%s)', $clone->getRootAliases()[0]))
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function head(int $size): iterable
    {
        /** @var iterable<(int|string), T> $result */
        $result = $this->cloneQuery()
            ->setFirstResult(0)
            ->setMaxResults($size)
            ->getQuery()
            ->toIterable();

        return $result;
    }

    public function all(): iterable
    {
        /** @var iterable<(int|string), T> $result */
        $result = $this->cloneQuery()->getQuery()->toIterable();

        return $result;
    }

    public function pageView(int $offset, int $size): PageChunk
    {
        /** @var list<T> $results */
        $results = $this->cloneQuery()
            ->setFirstResult($offset)
            ->setMaxResults($size)
            ->getQuery()
            ->getResult();

        return new PageChunk($results, $this->count());
    }

    public function pageSlice(int $offset, int $size): SliceChunk
    {
        /** @var list<T> $results */
        $results = $this->cloneQuery()
            ->setFirstResult($offset)
            ->setMaxResults($size + 1)
            ->getQuery()
            ->getResult();

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
