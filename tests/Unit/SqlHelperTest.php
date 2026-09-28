<?php

declare(strict_types=1);

namespace PimBay\SearchQuery\Doctrine\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PimBay\SearchQuery\Doctrine\SqlHelper;

final class SqlHelperTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function likeProvider(): iterable
    {
        yield 'no special chars' => ['hello', 'hello'];
        yield 'underscore' => ['a_b', 'a~_b'];
        yield 'percent' => ['a%b', 'a~%b'];
        yield 'both' => ['100%_off', '100~%~_off'];
        yield 'literal escape char neutralized first' => ['a~b', 'a~~b'];
        yield 'escape char plus wildcard, order matters' => ['a~_b', 'a~~~_b'];
        yield 'a literal backslash is left alone' => ['a\\b', 'a\\b'];
        yield 'empty string' => ['', ''];
    }

    #[Test]
    #[DataProvider('likeProvider')]
    public function escapesLikeWildcardsAndTheEscapeCharItself(string $input, string $expected): void
    {
        self::assertSame($expected, SqlHelper::escapeLike($input));
    }

    #[Test]
    public function likeEscapeClauseRendersTheEscapeCharAsAQuotedSqlLiteral(): void
    {
        self::assertSame("ESCAPE '~'", SqlHelper::likeEscapeClause());
    }

    #[Test]
    public function theEscapeClauseCarriesNothingAStringLiteralWouldItselfEscape(): void
    {
        // A backslash would be an unterminated literal on MySQL/MariaDB, and the doubled form that fixes
        // this two is rejected by PostgreSQL and SQLite — the bug this escape character exists to avoid.
        self::assertSame(3, \strlen(str_replace('ESCAPE ', '', SqlHelper::likeEscapeClause())));
        self::assertStringNotContainsString('\\', SqlHelper::likeEscapeClause());
        self::assertStringNotContainsString("''", SqlHelper::likeEscapeClause());
    }
}
