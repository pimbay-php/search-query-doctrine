<?php

declare(strict_types=1);

namespace PimBay\SearchQuery\Doctrine\Tests\Unit\SearchTerms;

use Doctrine\DBAL\Query\QueryBuilder as DbalQueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PimBay\SearchQuery\Doctrine\SearchTerms\SearchTermsQuery;
use PimBay\SearchQuery\Doctrine\Tests\Fixture\DbalFixture;
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
            'WHERE (name != :p1 AND name != :p2)',
            ['p1' => 'foo', 'p2' => 'bar'],
        ];

        yield 'equals and likes share one OR group' => [
            new ParsedSearchTerms(equals: ['foo'], notEquals: [], likes: ['bar'], notLikes: []),
            'p',
            new SearchTermsConfig(),
            "WHERE (name = :p1 OR name LIKE :p2 ESCAPE '\\')",
            ['p1' => 'foo', 'p2' => '%bar'],
        ];

        yield 'notEquals and notLikes share one AND group' => [
            new ParsedSearchTerms(equals: [], notEquals: ['foo'], likes: [], notLikes: ['bar']),
            'p',
            new SearchTermsConfig(),
            "WHERE (name != :p1 AND name NOT LIKE :p2 ESCAPE '\\')",
            ['p1' => 'foo', 'p2' => '%bar'],
        ];

        yield 'positive and negative groups are both applied and ANDed together' => [
            new ParsedSearchTerms(equals: ['foo'], notEquals: ['baz'], likes: [], notLikes: []),
            'p',
            new SearchTermsConfig(),
            'WHERE ((name = :p1)) AND ((name != :p2))',
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

        yield 'likeChar marker is converted to an SQL wildcard' => [
            new ParsedSearchTerms(equals: [], notEquals: [], likes: ['fo*o'], notLikes: []),
            'p',
            new SearchTermsConfig(anywhere: false, likeChar: '*'),
            'name LIKE :p1',
            ['p1' => 'fo%o'],
        ];

        yield 'like value SQL wildcards are escaped before likeChar substitution' => [
            new ParsedSearchTerms(equals: [], notEquals: [], likes: ['100%_off*done'], notLikes: []),
            'p',
            new SearchTermsConfig(anywhere: false, likeChar: '*'),
            'name LIKE :p1',
            ['p1' => '100\\%\\_off%done'],
        ];

        yield 'custom param prefix is used for placeholders' => [
            new ParsedSearchTerms(equals: ['foo'], notEquals: [], likes: [], notLikes: []),
            'search_',
            new SearchTermsConfig(),
            'name = :search_1',
            ['search_1' => 'foo'],
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

        self::assertStringContainsString('WHERE ((name = :p1)) AND ((name != :p2))', $qb->getSQL());
        self::assertSame(['p1' => 'foo', 'p2' => 'bar'], $qb->getParameters());
    }

    private function builder(): DbalQueryBuilder
    {
        return DbalFixture::productQueryBuilder(DbalFixture::createConnection());
    }
}
