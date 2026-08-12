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
     * @return iterable<string, array{string, string, string}>
     */
    public static function likeProvider(): iterable
    {
        yield 'no special chars' => ['hello', '\\', 'hello'];
        yield 'underscore' => ['a_b', '\\', 'a\\_b'];
        yield 'percent' => ['a%b', '\\', 'a\\%b'];
        yield 'both' => ['100%_off', '\\', '100\\%\\_off'];
        yield 'literal escape char neutralized first' => ['a\\b', '\\', 'a\\\\b'];
        yield 'escape char plus wildcard, order matters' => ['a\\_b', '\\', 'a\\\\\\_b'];
        yield 'custom escape char' => ['a_b', '!', 'a!_b'];
        yield 'empty string' => ['', '\\', ''];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function likeEscapeClauseProvider(): iterable
    {
        yield 'default escape char' => ['\\', "ESCAPE '\\'"];
        yield 'custom escape char' => ['!', "ESCAPE '!'"];
        yield 'escape char containing a single quote is doubled' => ["'", "ESCAPE ''''"];
    }

    #[Test]
    #[DataProvider('likeProvider')]
    public function escapesLikeWildcardsAndTheEscapeCharItself(string $input, string $escapeChar, string $expected): void
    {
        self::assertSame($expected, SqlHelper::escapeLike($input, $escapeChar));
    }

    #[Test]
    public function defaultEscapeCharIsBackslash(): void
    {
        self::assertSame('a\\_b', SqlHelper::escapeLike('a_b'));
    }

    #[Test]
    #[DataProvider('likeEscapeClauseProvider')]
    public function rendersEscapeCharAsAQuotedSqlLiteral(string $escapeChar, string $expected): void
    {
        self::assertSame($expected, SqlHelper::likeEscapeClause($escapeChar));
    }

    #[Test]
    public function likeEscapeClauseDefaultsToBackslash(): void
    {
        self::assertSame("ESCAPE '\\'", SqlHelper::likeEscapeClause());
    }
}
