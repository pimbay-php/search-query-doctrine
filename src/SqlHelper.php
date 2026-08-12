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
     * MySQL/MariaDB/PostgreSQL treat this as the implicit default `LIKE` escape character — SQLite,
     * SQL Server, and Oracle don't define an implicit one, so an explicit `ESCAPE` clause (see
     * {@see likeEscapeClause()}) is required on those engines for `escapeLike()`'s output to mean
     * what it says.
     */
    public const string DEFAULT_LIKE_ESCAPE_CHAR = '\\';

    /**
     * Neutralizes a literal `$escapeChar` first, then escapes `_`/`%` — reversing the order would
     * double-escape an existing `$escapeChar` in the value.
     */
    public static function escapeLike(string $like, string $escapeChar = self::DEFAULT_LIKE_ESCAPE_CHAR): string
    {
        $escaped = str_replace($escapeChar, $escapeChar.$escapeChar, $like);

        return str_replace(['_', '%'], [$escapeChar.'_', $escapeChar.'%'], $escaped);
    }

    public static function likeEscapeClause(string $escapeChar = self::DEFAULT_LIKE_ESCAPE_CHAR): string
    {
        return \sprintf("ESCAPE '%s'", str_replace("'", "''", $escapeChar));
    }
}
