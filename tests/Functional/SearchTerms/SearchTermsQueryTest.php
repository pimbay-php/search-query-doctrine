<?php

declare(strict_types=1);

namespace PimBay\SearchQuery\Doctrine\Tests\Functional\SearchTerms;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PimBay\SearchQuery\Doctrine\SearchTerms\SearchTermsQuery;
use PimBay\SearchQuery\Doctrine\Tests\Fixture\DbalFixture;
use PimBay\SearchQuery\Doctrine\Tests\Fixture\Entity\Product;
use PimBay\SearchQuery\Doctrine\Tests\Fixture\OrmFixture;
use PimBay\SearchQuery\SearchTerms\ParsedSearchTerms;
use PimBay\SearchQuery\SearchTerms\SearchTermsConfig;

/**
 * Every scenario runs on in-memory SQLite and on a real MySQL-family server: SQLite alone accepts the
 * `ESCAPE '\'` that reached 1.0.1. The MySQL half skips when SEARCH_QUERY_MYSQL_DSN is unset.
 */
final class SearchTermsQueryTest extends TestCase
{
    private const string SQLITE = 'sqlite';

    private const string MYSQL = 'mysql';

    /** @var array<int, array{id: int, name: string, price: int}> */
    private const array PRODUCTS = [
        ['id' => 1, 'name' => 'red shirt', 'price' => 10],
        ['id' => 2, 'name' => 'blue shirt', 'price' => 20],
        ['id' => 3, 'name' => 'red hat', 'price' => 30],
        ['id' => 4, 'name' => 'green hat', 'price' => 40],
        ['id' => 5, 'name' => 'red', 'price' => 50],
    ];

    /**
     * @return iterable<string, array{string, ParsedSearchTerms, ?SearchTermsConfig, string[]}>
     */
    public static function engineScenarioProvider(): iterable
    {
        foreach ([self::SQLITE, self::MYSQL] as $engine) {
            foreach (self::filterScenarios() as $name => [$parsed, $config, $expectedNames]) {
                yield $engine.': '.$name => [$engine, $parsed, $config, $expectedNames];
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function engineProvider(): iterable
    {
        yield self::SQLITE => [self::SQLITE];
        yield self::MYSQL => [self::MYSQL];
    }

    /**
     * @param string[] $expectedNames
     */
    #[Test]
    #[DataProvider('engineScenarioProvider')]
    public function filtersRowsAccordingToTheParsedSearchTerms(
        string $engine,
        ParsedSearchTerms $parsed,
        ?SearchTermsConfig $config,
        array $expectedNames,
    ): void {
        $connection = $this->connectionFor($engine);
        DbalFixture::seedProducts($connection, self::PRODUCTS);

        self::assertSame($expectedNames, $this->filteredNames($connection, $parsed, $config));
    }

    #[Test]
    #[DataProvider('engineProvider')]
    public function literalPercentAndUnderscoreAreEscapedNotInterpretedAsWildcards(string $engine): void
    {
        $connection = $this->connectionFor($engine);
        DbalFixture::seedProducts($connection, [
            ['id' => 1, 'name' => 'a_b', 'price' => 10],
            ['id' => 2, 'name' => 'axb', 'price' => 20],
            ['id' => 3, 'name' => '50%off', 'price' => 30],
            ['id' => 4, 'name' => '50-anything-off', 'price' => 40],
        ]);
        $qb = DbalFixture::productQueryBuilder($connection)->orderBy('id');

        (new SearchTermsQuery())->apply(
            $qb,
            'name',
            new ParsedSearchTerms(equals: [], notEquals: [], likes: ['a_b', '50%off'], notLikes: []),
            'p',
            new SearchTermsConfig(anywhere: false),
        );

        self::assertSame(['a_b', '50%off'], array_column($qb->fetchAllAssociative(), 'name'));
    }

    #[Test]
    #[DataProvider('engineProvider')]
    public function negatedTermsKeepRowsWithNoValueUnlessTheStricterReadingIsAskedFor(string $engine): void
    {
        $connection = $this->connectionFor($engine);
        DbalFixture::seedProductsWithNullableName($connection, [
            ['id' => 1, 'name' => 'red', 'price' => 10],
            ['id' => 2, 'name' => 'blue', 'price' => 20],
            ['id' => 3, 'name' => null, 'price' => 30],
        ]);
        $parsed = new ParsedSearchTerms(equals: [], notEquals: ['red'], likes: [], notLikes: []);

        // `-red` reads as "not red", and row 3 has nothing that is red.
        self::assertSame(['2', '3'], $this->filteredIds($connection, $parsed, new SearchTermsConfig()));
        self::assertSame(
            ['2'],
            $this->filteredIds($connection, $parsed, new SearchTermsConfig(ignoredTermsMatchNull: false)),
        );
    }

    /**
     * The ORM driver against real rows. SQLite only — OrmFixture builds an in-memory EntityManager, and the
     * engine-level escaping question is already settled on both engines through the DBAL cases above.
     */
    #[Test]
    public function filtersRealRowsThroughAnOrmQueryBuilderWithLiteralWildcardsEscaped(): void
    {
        $em = OrmFixture::createEntityManager();
        OrmFixture::seedProducts($em, [
            ['name' => 'a_b', 'price' => 10],
            ['name' => 'axb', 'price' => 20],
            ['name' => '50%off', 'price' => 30],
            ['name' => '50-anything-off', 'price' => 40],
        ]);
        $qb = $em->createQueryBuilder()->select('p')->from(Product::class, 'p')->orderBy('p.id');

        (new SearchTermsQuery())->apply(
            $qb,
            'p.name',
            new ParsedSearchTerms(equals: [], notEquals: [], likes: ['a_b', '50%off'], notLikes: []),
            'p',
            new SearchTermsConfig(anywhere: false),
        );

        /** @var list<Product> $products */
        $products = $qb->getQuery()->getResult();

        self::assertSame(['a_b', '50%off'], array_map(static fn (Product $p): string => $p->name, $products));
    }

    #[Test]
    public function excludesRowsThroughAnOrmQueryBuilderForANegatedTerm(): void
    {
        $em = OrmFixture::createEntityManager();
        OrmFixture::seedProducts($em, [
            ['name' => 'red shirt', 'price' => 10],
            ['name' => 'blue shirt', 'price' => 20],
            ['name' => 'red hat', 'price' => 30],
        ]);
        $qb = $em->createQueryBuilder()->select('p')->from(Product::class, 'p')->orderBy('p.id');

        (new SearchTermsQuery())->applyString($qb, 'p.name', '*shirt* -*red*', 'p', new SearchTermsConfig());

        /** @var list<Product> $products */
        $products = $qb->getQuery()->getResult();

        self::assertSame(['blue shirt'], array_map(static fn (Product $p): string => $p->name, $products));
    }

    /**
     * Compared as strings: pdo_mysql hands integer columns back as strings, pdo_sqlite as ints.
     *
     * @return list<string>
     */
    private function filteredIds(Connection $connection, ParsedSearchTerms $parsed, SearchTermsConfig $config): array
    {
        $qb = DbalFixture::productQueryBuilder($connection)->orderBy('id');

        (new SearchTermsQuery())->apply($qb, 'name', $parsed, 'p', $config);

        return array_map(
            static function (mixed $id): string {
                self::assertIsScalar($id);

                return (string) $id;
            },
            array_column($qb->fetchAllAssociative(), 'id'),
        );
    }

    private function connectionFor(string $engine): Connection
    {
        if (self::SQLITE === $engine) {
            return DbalFixture::createConnection();
        }

        $dsn = DbalFixture::mysqlDsn();

        if (null === $dsn) {
            self::markTestSkipped(\sprintf('%s is unset — no MySQL-family server to run against.', DbalFixture::MYSQL_DSN_ENV));
        }

        return DbalFixture::createMysqlConnection($dsn);
    }

    /**
     * @return string[]
     */
    private function filteredNames(Connection $connection, ParsedSearchTerms $parsed, ?SearchTermsConfig $config): array
    {
        $qb = DbalFixture::productQueryBuilder($connection)->orderBy('id');

        (new SearchTermsQuery())->apply($qb, 'name', $parsed, 'p', $config ?? new SearchTermsConfig());

        return array_map(
            static function (mixed $name): string {
                self::assertIsString($name);

                return $name;
            },
            array_column($qb->fetchAllAssociative(), 'name'),
        );
    }

    /**
     * @return iterable<string, array{ParsedSearchTerms, ?SearchTermsConfig, string[]}>
     */
    private static function filterScenarios(): iterable
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
        // the user's own like marker (see the contains-style scenario below).
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
}
