<?php

declare(strict_types=1);

namespace PimBay\SearchQuery\Doctrine\Tests\Fixture;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Builds a real DBAL Connection against an in-memory SQLite database. Good enough to exercise real SQL
 * generation/execution, which is the point: DbalSimpleAdapter/DbalIdentityAdapter must never be tested
 * against a hand-mocked QueryBuilder — the fluent chain is too deep to mock faithfully.
 */
final class DbalFixture
{
    public static function createConnection(): Connection
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }

    /**
     * @param array<int, array{id: int, name: string, price: int}> $rows
     */
    public static function seedProducts(Connection $connection, array $rows): void
    {
        $connection->executeStatement(
            'CREATE TABLE product (id INTEGER PRIMARY KEY, name TEXT NOT NULL, price INTEGER NOT NULL)',
        );

        foreach ($rows as $row) {
            $connection->insert('product', $row);
        }
    }

    public static function productQueryBuilder(Connection $connection): QueryBuilder
    {
        return $connection->createQueryBuilder()
            ->select('id', 'name', 'price')
            ->from('product');
    }
}
