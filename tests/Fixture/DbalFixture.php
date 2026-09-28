<?php

declare(strict_types=1);

namespace PimBay\SearchQuery\Doctrine\Tests\Fixture;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Tools\DsnParser;

/**
 * Real DBAL Connections — in-memory SQLite always, MariaDB when SEARCH_QUERY_MYSQL_DSN is set. The
 * adapters must never be tested against a hand-mocked QueryBuilder; the fluent chain is too deep.
 */
final class DbalFixture
{
    /**
     * Set by docker-compose and CI to the MariaDB service. Unset locally, where the MySQL-family cases skip.
     */
    public const string MYSQL_DSN_ENV = 'SEARCH_QUERY_MYSQL_DSN';

    public static function createConnection(): Connection
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }

    public static function mysqlDsn(): ?string
    {
        $dsn = getenv(self::MYSQL_DSN_ENV);

        return \is_string($dsn) && '' !== $dsn ? $dsn : null;
    }

    /**
     * DsnParser rather than the `url` parameter — DBAL 4 removed `url`, and DsnParser exists on 3.8 too,
     * so one spelling covers every combo in the matrix.
     */
    public static function createMysqlConnection(string $dsn): Connection
    {
        return DriverManager::getConnection((new DsnParser(['mysql' => 'pdo_mysql']))->parse($dsn));
    }

    /**
     * @param array<int, array{id: int, name: string, price: int}> $rows
     */
    public static function seedProducts(Connection $connection, array $rows): void
    {
        // Dropped first because a real server keeps the table between tests, unlike in-memory SQLite,
        // which hands every connection an empty database.
        $connection->executeStatement('DROP TABLE IF EXISTS product');
        $connection->executeStatement(
            'CREATE TABLE product (id INTEGER PRIMARY KEY, name TEXT NOT NULL, price INTEGER NOT NULL)',
        );

        foreach ($rows as $row) {
            $connection->insert('product', $row);
        }
    }

    /**
     * Same table, `name` nullable — the shape the NOT NULL fixture cannot express, and the only one in
     * which a negated term's three-valued-logic behaviour is observable at all.
     *
     * @param array<int, array{id: int, name: string|null, price: int}> $rows
     */
    public static function seedProductsWithNullableName(Connection $connection, array $rows): void
    {
        $connection->executeStatement('DROP TABLE IF EXISTS product');
        $connection->executeStatement(
            'CREATE TABLE product (id INTEGER PRIMARY KEY, name TEXT NULL, price INTEGER NOT NULL)',
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
