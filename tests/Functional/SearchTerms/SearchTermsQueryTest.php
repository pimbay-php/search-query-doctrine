<?php

declare(strict_types=1);

namespace PimBay\SearchQuery\Doctrine\Tests\Functional\SearchTerms;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PimBay\SearchQuery\Doctrine\SearchTerms\SearchTermsQuery;
use PimBay\SearchQuery\Doctrine\Tests\Fixture\DbalFixture;
use PimBay\SearchQuery\SearchTerms\ParsedSearchTerms;
use PimBay\SearchQuery\SearchTerms\SearchTermsConfig;

/**
 * One divergence from MariaDB is deliberately not exercised here: MariaDB treats `\` as the
 * implicit LIKE escape character, which is what SqlHelper::escapeLike() assumes; SQLite requires
 * an explicit `LIKE ... ESCAPE '\'` clause (which SearchTermsQuery never adds, since it targets
 * MariaDB) to honor that same escaping, so a literal `%`/`_` in a search value can't be exercised
 * end-to-end against SQLite here. The escaped parameter values themselves are already covered
 * exactly in tests/Unit/SearchTerms/SearchTermsQueryTest.php.
 */
final class SearchTermsQueryTest extends TestCase
{
    private \Doctrine\DBAL\Connection $connection;

    /**
     * @return iterable<string, array{ParsedSearchTerms, ?SearchTermsConfig, string[]}>
     */
    public static function filterScenarioProvider(): iterable
    {
        yield 'equals matches exact value only' => [
            new ParsedSearchTerms(equals: ['red shirt'], notEquals: [], likes: [], notLikes: []),
            null,
            ['red shirt'],
        ];

        yield 'equals with multiple values is OR-combined' => [
            new ParsedSearchTerms(equals: ['red shirt', 'green hat'], notEquals: [], likes: [], notLikes: []),
            null,
            ['red shirt', 'green hat'],
        ];

        // anywhere only ever adds a *leading* wildcard — a "contains" match additionally needs
        // the user's own likeChar marker (see the contains-style scenario below).
        yield 'like with anywhere true matches values ending in the term' => [
            new ParsedSearchTerms(equals: [], notEquals: [], likes: ['shirt'], notLikes: []),
            null,
            ['red shirt', 'blue shirt'],
        ];

        yield 'like with anywhere false requires an exact match' => [
            new ParsedSearchTerms(equals: [], notEquals: [], likes: ['red'], notLikes: []),
            new SearchTermsConfig(anywhere: false),
            ['red'],
        ];

        // The raw search term embeds its own leading/trailing likeChar markers.
        yield 'contains-style like matches a substring anywhere in the field' => [
            new ParsedSearchTerms(equals: [], notEquals: [], likes: ['*red*'], notLikes: []),
            null,
            ['red shirt', 'red hat', 'red'],
        ];

        yield 'starts-with-style like uses a trailing marker only' => [
            new ParsedSearchTerms(equals: [], notEquals: [], likes: ['red*'], notLikes: []),
            new SearchTermsConfig(anywhere: false),
            ['red shirt', 'red hat', 'red'],
        ];

        yield 'notEquals excludes the exact value' => [
            new ParsedSearchTerms(equals: [], notEquals: ['red'], likes: [], notLikes: []),
            null,
            ['red shirt', 'blue shirt', 'red hat', 'green hat'],
        ];

        yield 'notLike excludes any row matching the contains pattern' => [
            new ParsedSearchTerms(equals: [], notEquals: [], likes: [], notLikes: ['*hat*']),
            null,
            ['red shirt', 'blue shirt', 'red'],
        ];

        // "ends in 'shirt' or 'hat'" AND "does not contain 'green'".
        yield 'combined positive and negative groups are both applied' => [
            new ParsedSearchTerms(equals: [], notEquals: [], likes: ['shirt', 'hat'], notLikes: ['*green*']),
            null,
            ['red shirt', 'blue shirt', 'red hat'],
        ];

        yield 'empty parsed terms returns every row unfiltered' => [
            new ParsedSearchTerms([], [], [], []),
            null,
            ['red shirt', 'blue shirt', 'red hat', 'green hat', 'red'],
        ];
    }

    protected function setUp(): void
    {
        $this->connection = DbalFixture::createConnection();
        DbalFixture::seedProducts($this->connection, [
            ['id' => 1, 'name' => 'red shirt', 'price' => 10],
            ['id' => 2, 'name' => 'blue shirt', 'price' => 20],
            ['id' => 3, 'name' => 'red hat', 'price' => 30],
            ['id' => 4, 'name' => 'green hat', 'price' => 40],
            ['id' => 5, 'name' => 'red', 'price' => 50],
        ]);
    }

    /**
     * @param string[] $expectedNames
     */
    #[Test]
    #[DataProvider('filterScenarioProvider')]
    public function filtersRowsAccordingToTheParsedSearchTerms(
        ParsedSearchTerms $parsed,
        ?SearchTermsConfig $config,
        array $expectedNames,
    ): void {
        self::assertSame($expectedNames, $this->filteredNames($parsed, $config));
    }

    /**
     * @return string[]
     */
    private function filteredNames(ParsedSearchTerms $parsed, ?SearchTermsConfig $config = null): array
    {
        $qb = DbalFixture::productQueryBuilder($this->connection)->orderBy('id');

        (new SearchTermsQuery())->apply($qb, 'name', $parsed, 'p', $config ?? new SearchTermsConfig());

        return array_map(
            static function (mixed $name): string {
                self::assertIsString($name);

                return (string) $name;
            },
            array_column($qb->fetchAllAssociative(), 'name'),
        );
    }
}
