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

namespace PimBay\SearchQuery\Doctrine\SearchTerms;

use Doctrine\DBAL\Query\QueryBuilder as DbalQueryBuilder;
use Doctrine\ORM\QueryBuilder as OrmQueryBuilder;
use PimBay\SearchQuery\Doctrine\SqlHelper;
use PimBay\SearchQuery\SearchTerms\ParsedSearchTerms;
use PimBay\SearchQuery\SearchTerms\SearchTermsConfig;
use PimBay\SearchQuery\SearchTerms\SearchTermsParser;

/**
 * Applies a ParsedSearchTerms as `andWhere()` conditions against one column. `equals`/`likes` are
 * OR-grouped; `notEquals`/`notLikes` are AND-grouped.
 *
 * Predicates are raw strings passed to `andWhere()`, not `ExpressionBuilder::andX()`/`orX()` — removed in DBAL 4.
 */
final readonly class SearchTermsQuery
{
    public function __construct(
        private SearchTermsParser $parser = new SearchTermsParser(),
    ) {
    }

    public function apply(
        DbalQueryBuilder|OrmQueryBuilder $qb,
        string $column,
        ParsedSearchTerms $parsed,
        string $paramPrefix,
        SearchTermsConfig $config,
    ): void {
        $escapeClause = SqlHelper::likeEscapeClause();
        $i = 0;
        $conditions = [];

        foreach ($parsed->equals as $value) {
            $conditions[] = \sprintf('%s = :%s%d', $column, $paramPrefix, ++$i);
            $qb->setParameter($paramPrefix.$i, $value);
        }
        foreach ($parsed->likes as $value) {
            $conditions[] = \sprintf('%s LIKE :%s%d %s', $column, $paramPrefix, ++$i, $escapeClause);
            $qb->setParameter($paramPrefix.$i, $this->toLikePattern($value, $config));
        }

        if ([] !== $conditions) {
            $qb->andWhere('('.implode(' OR ', $conditions).')');
        }

        $conditions = [];

        foreach ($parsed->notEquals as $value) {
            $conditions[] = \sprintf('%s != :%s%d', $column, $paramPrefix, ++$i);
            $qb->setParameter($paramPrefix.$i, $value);
        }
        foreach ($parsed->notLikes as $value) {
            $conditions[] = \sprintf('%s NOT LIKE :%s%d %s', $column, $paramPrefix, ++$i, $escapeClause);
            $qb->setParameter($paramPrefix.$i, $this->toLikePattern($value, $config));
        }

        if ([] !== $conditions) {
            $qb->andWhere('('.implode(' AND ', $conditions).')');
        }
    }

    public function applyString(
        DbalQueryBuilder|OrmQueryBuilder $qb,
        string $column,
        string $text,
        string $paramPrefix,
        SearchTermsConfig $config,
    ): void {
        $this->apply($qb, $column, $this->parser->parseString($text, $config), $paramPrefix, $config);
    }

    private function toLikePattern(string $value, SearchTermsConfig $config): string
    {
        $escaped = str_replace($config->likeChar, '%', SqlHelper::escapeLike($value));

        return $config->anywhere ? '%'.$escaped : $escaped;
    }
}
