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

namespace PimBay\SearchQuery\Doctrine;

final class SqlHelper
{
    /**
     * Deliberately not a backslash and deliberately not configurable: a backslash has no spelling
     * that is valid on MySQL/MariaDB and on PostgreSQL/SQLite at once. See docs/DECISIONS.md.
     */
    private const string LIKE_ESCAPE_CHAR = '~';

    /**
     * Neutralizes a literal escape character first, then escapes `_`/`%` — reversing the order
     * would double-escape an escape character already present in the value.
     */
    public static function escapeLike(string $like): string
    {
        $escaped = str_replace(self::LIKE_ESCAPE_CHAR, self::LIKE_ESCAPE_CHAR.self::LIKE_ESCAPE_CHAR, $like);

        return str_replace(['_', '%'], [self::LIKE_ESCAPE_CHAR.'_', self::LIKE_ESCAPE_CHAR.'%'], $escaped);
    }

    /**
     * Always emitted, on every engine: SQLite, SQL Server and Oracle define no implicit escape character at all,
     * and MySQL/MariaDB's implicit one is the backslash this class does not use.
     */
    public static function likeEscapeClause(): string
    {
        return \sprintf("ESCAPE '%s'", self::LIKE_ESCAPE_CHAR);
    }

    public static function contains(string $value): string
    {
        return '%'.self::escapeLike($value).'%';
    }

    public static function startsWith(string $value): string
    {
        return self::escapeLike($value).'%';
    }

    public static function endsWith(string $value): string
    {
        return '%'.self::escapeLike($value);
    }
}
