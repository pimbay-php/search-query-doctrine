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
use Doctrine\ORM\Tools\Pagination\Paginator;
use PimBay\SearchQuery\Adapter\CountableAdapter;
use PimBay\SearchQuery\Page\PageAdapter;
use PimBay\SearchQuery\Page\PageChunk;

/**
 * For a QueryBuilder that fetch-joins a to-many association. `PageAdapter`/`CountableAdapter` only.
 *
 * @template T of object
 *
 * @implements PageAdapter<T>
 */
final readonly class OrmFetchJoinSafeAdapter implements PageAdapter, CountableAdapter
{
    public function __construct(
        private QueryBuilder $queryBuilder,
        private ?bool $useOutputWalkers = null,
    ) {
    }

    public function count(): int
    {
        return $this->paginator($this->cloneQuery())->count();
    }

    public function pageView(int $offset, int $size): PageChunk
    {
        $paginator = $this->paginator(
            $this->cloneQuery()->setFirstResult($offset)->setMaxResults($size),
        );

        /** @var list<T> $results */
        $results = iterator_to_array($paginator);

        return new PageChunk($results, $paginator->count());
    }

    /**
     * @return Paginator<T>
     */
    private function paginator(QueryBuilder $qb): Paginator
    {
        /** @var Paginator<T> $paginator */
        $paginator = new Paginator($qb, fetchJoinCollection: true);

        if (null !== $this->useOutputWalkers) {
            $paginator->setUseOutputWalkers($this->useOutputWalkers);
        }

        return $paginator;
    }

    private function cloneQuery(): QueryBuilder
    {
        return clone $this->queryBuilder;
    }
}
