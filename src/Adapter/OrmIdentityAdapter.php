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
use PimBay\SearchQuery\Adapter\IdentifiableAdapter;

/**
 * OrmSimpleAdapter plus `ids()`. `$idField` is a DQL path (e.g. `'r.id'`), not a bare column name,
 * and doesn't have to be the entity's primary key.
 *
 * @template T of object
 *
 * @extends OrmSimpleAdapter<T>
 *
 * @implements IdentifiableAdapter<int|string>
 */
final readonly class OrmIdentityAdapter extends OrmSimpleAdapter implements IdentifiableAdapter
{
    public function __construct(
        QueryBuilder $queryBuilder,
        private string $idField,
    ) {
        parent::__construct($queryBuilder);
    }

    public function ids(): array
    {
        /** @var array<int|string> $ids */
        $ids = $this->cloneQuery()
            ->select($this->idField)
            ->getQuery()
            ->getSingleColumnResult();

        return $ids;
    }
}
