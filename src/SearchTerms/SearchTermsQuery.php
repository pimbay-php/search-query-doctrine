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
 * Applies a ParsedSearchTerms as `andWhere()` conditions against one column: `equals`/`likes` OR-grouped,
 * `notEquals`/`notLikes` AND-grouped. Predicates are raw strings — DBAL 4 removed `ExpressionBuilder`.
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
            $conditions[] = $this->negation(\sprintf('%s != :%s%d', $column, $paramPrefix, ++$i), $column, $config);
            $qb->setParameter($paramPrefix.$i, $value);
        }
        foreach ($parsed->notLikes as $value) {
            $conditions[] = $this->negation(
                \sprintf('%s NOT LIKE :%s%d %s', $column, $paramPrefix, ++$i, $escapeClause),
                $column,
                $config,
            );
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

    /**
     * `value != 'red'` is NULL, not TRUE, for a row with no value, and WHERE keeps only TRUE — so a bare
     * negation drops every such row, which is not what `-red` means.
     */
    private function negation(string $predicate, string $column, SearchTermsConfig $config): string
    {
        return $config->ignoredTermsMatchNull ? \sprintf('(%s OR %s IS NULL)', $predicate, $column) : $predicate;
    }

    private function toLikePattern(string $value, SearchTermsConfig $config): string
    {
        // Escaping up front would neutralise any marker SqlHelper also escapes — `%`, `_` or the
        // escape character itself — turning the caller's wildcard into a literal that never matches.
        $escaped = implode('%', array_map(SqlHelper::escapeLike(...), $this->splitOnMarkers($value, $config)));

        return $config->anywhere ? '%'.$escaped : $escaped;
    }

    /**
     * Neither `preg_split()` nor a hand-advanced cursor, for reasons that outlive this method — read
     * docs/DECISIONS.md before changing the loop or the `0 !==` comparison below.
     *
     * @return list<string>
     */
    private function splitOnMarkers(string $value, SearchTermsConfig $config): array
    {
        $chunks = [];
        $chunk = '';
        $skip = 0;

        foreach (str_split($value) as $offset => $character) {
            // `> 0` would be equivalent — the counter is never negative — but it makes a wrong starting
            // value unobservable, and so unkillable by any test.
            if (0 !== $skip) {
                --$skip;

                continue;
            }

            $marker = $this->markerAt($value, $offset, $config->likeMarkers);

            if (null === $marker) {
                $chunk .= $character;

                continue;
            }

            $chunks[] = $chunk;
            $chunk = '';
            $skip = \strlen($marker) - 1;
        }

        $chunks[] = $chunk;

        return $chunks;
    }

    /**
     * SearchTermsConfig hands the markers over longest-first, so the first hit here is the longest one and
     * a marker that prefixes another never shadows it.
     *
     * @param list<string> $markers
     */
    private function markerAt(string $value, int $offset, array $markers): ?string
    {
        foreach ($markers as $marker) {
            if (substr($value, $offset, \strlen($marker)) === $marker) {
                return $marker;
            }
        }

        return null;
    }
}
