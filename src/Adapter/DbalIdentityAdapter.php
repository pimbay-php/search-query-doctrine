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
use PimBay\SearchQuery\Adapter\IdentifiableAdapter;

/**
 * DbalSimpleAdapter plus `ids()`. `$idField` doesn't have to be a primary key.
 *
 * @implements IdentifiableAdapter<int|string>
 */
final readonly class DbalIdentityAdapter extends DbalSimpleAdapter implements IdentifiableAdapter
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
            ->fetchFirstColumn();

        return $ids;
    }
}
