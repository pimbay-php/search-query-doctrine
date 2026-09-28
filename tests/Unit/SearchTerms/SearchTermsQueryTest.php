<?php

declare(strict_types=1);

namespace PimBay\SearchQuery\Doctrine\Tests\Unit\SearchTerms;

use Doctrine\DBAL\Query\QueryBuilder as DbalQueryBuilder;
use Doctrine\ORM\QueryBuilder as OrmQueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PimBay\SearchQuery\Doctrine\SearchTerms\SearchTermsQuery;
use PimBay\SearchQuery\Doctrine\Tests\Fixture\DbalFixture;
use PimBay\SearchQuery\Doctrine\Tests\Fixture\Entity\Product;
use PimBay\SearchQuery\Doctrine\Tests\Fixture\OrmFixture;
use PimBay\SearchQuery\SearchTerms\ParsedSearchTerms;
use PimBay\SearchQuery\SearchTerms\SearchTermsConfig;

final class SearchTermsQueryTest extends TestCase
{
    private SearchTermsQuery $query;

    /**
     * @return iterable<string, array{ParsedSearchTerms, string, SearchTermsConfig, string, array<string, string>}>
     */
    public static function applyScenarioProvider(): iterable
    {
        yield 'equals terms are OR-grouped' => [
            new ParsedSearchTerms(equals: ['foo', 'bar'], notEquals: [], likes: [], notLikes: []),
            'p',
            new SearchTermsConfig(),
            'WHERE (name = :p1 OR name = :p2)',
            ['p1' => 'foo', 'p2' => 'bar'],
        ];

        yield 'notEquals terms are AND-grouped' => [
            new ParsedSearchTerms(equals: [], notEquals: ['foo', 'bar'], likes: [], notLikes: []),
            'p',
            new SearchTermsConfig(),
            'WHERE ((name != :p1 OR name IS NULL) AND (name != :p2 OR name IS NULL))',
            ['p1' => 'foo', 'p2' => 'bar'],
        ];

        yield 'equals and likes share one OR group' => [
            new ParsedSearchTerms(equals: ['foo'], notEquals: [], likes: ['bar'], notLikes: []),
            'p',
            new SearchTermsConfig(),
            "WHERE (name = :p1 OR name LIKE :p2 ESCAPE '~')",
            ['p1' => 'foo', 'p2' => '%bar'],
        ];

        yield 'notEquals and notLikes share one AND group' => [
            new ParsedSearchTerms(equals: [], notEquals: ['foo'], likes: [], notLikes: ['bar']),
            'p',
            new SearchTermsConfig(),
            "WHERE ((name != :p1 OR name IS NULL) AND (name NOT LIKE :p2 ESCAPE '~' OR name IS NULL))",
            ['p1' => 'foo', 'p2' => '%bar'],
        ];

        yield 'positive and negative groups are both applied and ANDed together' => [
            new ParsedSearchTerms(equals: ['foo'], notEquals: ['baz'], likes: [], notLikes: []),
            'p',
            new SearchTermsConfig(),
            'WHERE ((name = :p1)) AND (((name != :p2 OR name IS NULL)))',
            ['p1' => 'foo', 'p2' => 'baz'],
        ];

        yield 'parameters are numbered continuously across all four buckets' => [
            new ParsedSearchTerms(equals: ['e'], notEquals: ['ne'], likes: ['l'], notLikes: ['nl']),
            'p',
            new SearchTermsConfig(),
            'name = :p1',
            ['p1' => 'e', 'p2' => '%l', 'p3' => 'ne', 'p4' => '%nl'],
        ];

        yield 'like pattern prepends wildcard when anywhere is true' => [
            new ParsedSearchTerms(equals: [], notEquals: [], likes: ['foo'], notLikes: []),
            'p',
            new SearchTermsConfig(anywhere: true),
            'name LIKE :p1',
            ['p1' => '%foo'],
        ];

        yield 'like pattern has no leading wildcard when anywhere is false' => [
            new ParsedSearchTerms(equals: [], notEquals: [], likes: ['foo'], notLikes: []),
            'p',
            new SearchTermsConfig(anywhere: false),
            'name LIKE :p1',
            ['p1' => 'foo'],
        ];

        yield 'a like marker is converted to an SQL wildcard' => [
            new ParsedSearchTerms(equals: [], notEquals: [], likes: ['fo*o'], notLikes: []),
            'p',
            new SearchTermsConfig(anywhere: false, likeMarkers: ['*']),
            'name LIKE :p1',
            ['p1' => 'fo%o'],
        ];

        yield 'like value SQL wildcards are escaped, the marker is not' => [
            new ParsedSearchTerms(equals: [], notEquals: [], likes: ['100%_off*done'], notLikes: []),
            'p',
            new SearchTermsConfig(anywhere: false, likeMarkers: ['*']),
            'name LIKE :p1',
            ['p1' => '100~%~_off%done'],
        ];

        // The marker is `%`, which SqlHelper also escapes — escaping before substituting would
        // turn the caller's wildcard into a literal that can never match.
        yield 'a marker that SqlHelper escapes still becomes a wildcard' => [
            new ParsedSearchTerms(equals: [], notEquals: [], likes: ['100%done'], notLikes: []),
            'p',
            new SearchTermsConfig(anywhere: false, likeMarkers: ['%']),
            'name LIKE :p1',
            ['p1' => '100%done'],
        ];

        yield 'several markers all map to the same wildcard' => [
            new ParsedSearchTerms(equals: [], notEquals: [], likes: ['a*b?c'], notLikes: []),
            'p',
            new SearchTermsConfig(anywhere: false, likeMarkers: ['*', '?']),
            'name LIKE :p1',
            ['p1' => 'a%b%c'],
        ];

        yield 'a multi-character marker maps to one wildcard and the rest of the value survives' => [
            new ParsedSearchTerms(equals: [], notEquals: [], likes: ['a**b'], notLikes: []),
            'p',
            new SearchTermsConfig(anywhere: false, likeMarkers: ['**']),
            'name LIKE :p1',
            ['p1' => 'a%b'],
        ];

        // SearchTermsConfig normalises markers longest-first, which is the only reason taking the first hit
        // at each offset is correct: matching `*` first would split the `**` into two wildcards.
        yield 'a marker that prefixes another never shadows the longer one' => [
            new ParsedSearchTerms(equals: [], notEquals: [], likes: ['a**b*c'], notLikes: []),
            'p',
            new SearchTermsConfig(anywhere: false, likeMarkers: ['*', '**']),
            'name LIKE :p1',
            ['p1' => 'a%b%c'],
        ];

        yield 'no markers configured leaves the value fully escaped' => [
            new ParsedSearchTerms(equals: [], notEquals: [], likes: ['a*b'], notLikes: []),
            'p',
            new SearchTermsConfig(anywhere: false, likeMarkers: []),
            'name LIKE :p1',
            ['p1' => 'a*b'],
        ];

        // `value != 'red'` is NULL, not TRUE, for a row with no value, and WHERE keeps only TRUE.
        yield 'negations spell out the NULL case by default' => [
            new ParsedSearchTerms(equals: [], notEquals: ['foo'], likes: [], notLikes: ['bar']),
            'p',
            new SearchTermsConfig(),
            "WHERE ((name != :p1 OR name IS NULL) AND (name NOT LIKE :p2 ESCAPE '~' OR name IS NULL))",
            ['p1' => 'foo', 'p2' => '%bar'],
        ];

        yield 'ignoredTermsMatchNull false restores the bare stricter predicate' => [
            new ParsedSearchTerms(equals: [], notEquals: ['foo'], likes: [], notLikes: ['bar']),
            'p',
            new SearchTermsConfig(ignoredTermsMatchNull: false),
            "WHERE (name != :p1 AND name NOT LIKE :p2 ESCAPE '~')",
            ['p1' => 'foo', 'p2' => '%bar'],
        ];

        yield 'positive terms are untouched by ignoredTermsMatchNull' => [
            new ParsedSearchTerms(equals: ['foo'], notEquals: [], likes: ['bar'], notLikes: []),
            'p',
            new SearchTermsConfig(),
            "WHERE (name = :p1 OR name LIKE :p2 ESCAPE '~')",
            ['p1' => 'foo', 'p2' => '%bar'],
        ];

        yield 'custom param prefix is used for placeholders' => [
            new ParsedSearchTerms(equals: ['foo'], notEquals: [], likes: [], notLikes: []),
            'search_',
            new SearchTermsConfig(),
            'name = :search_1',
            ['search_1' => 'foo'],
        ];
    }

    /**
     * The ORM half of `apply()`'s `DbalQueryBuilder|OrmQueryBuilder` union. `apply()` branches on neither,
     * so this covers the parts DQL renders differently rather than repeating every DBAL scenario.
     *
     * @return iterable<string, array{ParsedSearchTerms, SearchTermsConfig, string, array<string, string>}>
     */
    public static function ormScenarioProvider(): iterable
    {
        yield 'equals and likes share one OR group' => [
            new ParsedSearchTerms(equals: ['foo'], notEquals: [], likes: ['bar'], notLikes: []),
            new SearchTermsConfig(),
            "WHERE (p.name = :p1 OR p.name LIKE :p2 ESCAPE '~')",
            ['p1' => 'foo', 'p2' => '%bar'],
        ];

        yield 'negations spell out the NULL case in DQL as well' => [
            new ParsedSearchTerms(equals: [], notEquals: ['foo'], likes: [], notLikes: ['bar']),
            new SearchTermsConfig(),
            "WHERE ((p.name != :p1 OR p.name IS NULL) AND (p.name NOT LIKE :p2 ESCAPE '~' OR p.name IS NULL))",
            ['p1' => 'foo', 'p2' => '%bar'],
        ];

        yield 'ignoredTermsMatchNull false restores the bare stricter predicate' => [
            new ParsedSearchTerms(equals: [], notEquals: ['foo'], likes: [], notLikes: []),
            new SearchTermsConfig(ignoredTermsMatchNull: false),
            'WHERE (p.name != :p1)',
            ['p1' => 'foo'],
        ];

        yield 'parameters are numbered continuously across all four buckets' => [
            new ParsedSearchTerms(equals: ['e'], notEquals: ['ne'], likes: ['l'], notLikes: ['nl']),
            new SearchTermsConfig(),
            'p.name = :p1',
            ['p1' => 'e', 'p2' => '%l', 'p3' => 'ne', 'p4' => '%nl'],
        ];
    }

    protected function setUp(): void
    {
        $this->query = new SearchTermsQuery();
    }

    #[Test]
    public function emptyParsedTermsAddsNoCondition(): void
    {
        $qb = $this->builder();
        $sqlBefore = $qb->getSQL();

        $this->query->apply($qb, 'name', new ParsedSearchTerms([], [], [], []), 'p', new SearchTermsConfig());

        self::assertSame($sqlBefore, $qb->getSQL());
        self::assertSame([], $qb->getParameters());
    }

    /**
     * @param array<string, string> $expectedParameters
     */
    #[Test]
    #[DataProvider('applyScenarioProvider')]
    public function appliesConditionsAccordingToTheParsedSearchTerms(
        ParsedSearchTerms $parsed,
        string $paramPrefix,
        SearchTermsConfig $config,
        string $expectedSqlFragment,
        array $expectedParameters,
    ): void {
        $qb = $this->builder();

        $this->query->apply($qb, 'name', $parsed, $paramPrefix, $config);

        self::assertStringContainsString($expectedSqlFragment, $qb->getSQL());
        self::assertSame($expectedParameters, $qb->getParameters());
    }

    #[Test]
    public function applyStringParsesAndAppliesInOneStep(): void
    {
        $qb = $this->builder();

        $this->query->applyString($qb, 'name', 'foo -bar', 'p', new SearchTermsConfig());

        self::assertStringContainsString('WHERE ((name = :p1)) AND (((name != :p2 OR name IS NULL)))', $qb->getSQL());
        self::assertSame(['p1' => 'foo', 'p2' => 'bar'], $qb->getParameters());
    }

    /**
     * @param array<string, string> $expectedParameters
     */
    #[Test]
    #[DataProvider('ormScenarioProvider')]
    public function appliesTheSameConditionsToAnOrmQueryBuilder(
        ParsedSearchTerms $parsed,
        SearchTermsConfig $config,
        string $expectedDqlFragment,
        array $expectedParameters,
    ): void {
        $qb = $this->ormBuilder();

        $this->query->apply($qb, 'p.name', $parsed, 'p', $config);

        self::assertStringContainsString($expectedDqlFragment, $qb->getDQL());
        self::assertSame($expectedParameters, self::ormParameters($qb));
    }

    /**
     * The one place the two drivers genuinely diverge: DQL string literals are re-rendered through the
     * platform on the way to SQL, which is what hid the malformed `ESCAPE '\'` of 1.0.1 on the ORM side.
     */
    #[Test]
    public function theEscapeClauseSurvivesTranslationFromDqlIntoTheGeneratedSql(): void
    {
        $qb = $this->ormBuilder();

        $this->query->apply(
            $qb,
            'p.name',
            new ParsedSearchTerms(equals: [], notEquals: [], likes: ['bar'], notLikes: ['baz']),
            'p',
            new SearchTermsConfig(),
        );
        $sql = $qb->getQuery()->getSQL();

        // Query::getSQL() is declared string|list<string> — only a split query returns the list.
        self::assertIsString($sql);
        self::assertStringContainsString("LIKE ? ESCAPE '~'", $sql);
        self::assertStringContainsString("NOT LIKE ? ESCAPE '~'", $sql);
    }

    #[Test]
    public function applyStringParsesAndAppliesInOneStepAgainstAnOrmQueryBuilder(): void
    {
        $qb = $this->ormBuilder();

        $this->query->applyString($qb, 'p.name', 'foo -bar', 'p', new SearchTermsConfig());

        // Asserted in pieces: ORM's own andWhere() parenthesises the groups differently from DBAL's,
        // so pinning the exact bracket count would test Doctrine rather than this class.
        self::assertStringContainsString('WHERE (p.name = :p1) AND (', $qb->getDQL());
        self::assertStringContainsString('p.name != :p2 OR p.name IS NULL', $qb->getDQL());
        self::assertSame(['p1' => 'foo', 'p2' => 'bar'], self::ormParameters($qb));
    }

    /**
     * ORM hands parameters back as a Parameter collection, not the name-keyed map DBAL returns.
     *
     * @return array<string, string>
     */
    private static function ormParameters(OrmQueryBuilder $qb): array
    {
        $parameters = [];

        foreach ($qb->getParameters() as $parameter) {
            $name = $parameter->getName();
            $value = $parameter->getValue();
            self::assertIsString($value);
            $parameters[(string) $name] = $value;
        }

        return $parameters;
    }

    private function ormBuilder(): OrmQueryBuilder
    {
        return OrmFixture::createEntityManager()
            ->createQueryBuilder()
            ->select('p')
            ->from(Product::class, 'p');
    }

    private function builder(): DbalQueryBuilder
    {
        return DbalFixture::productQueryBuilder(DbalFixture::createConnection());
    }
}
