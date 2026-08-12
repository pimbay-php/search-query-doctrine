<?php

declare(strict_types=1);

namespace PimBay\SearchQuery\Doctrine\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PimBay\SearchQuery\Doctrine\Adapter\DbalIdentityAdapter;
use PimBay\SearchQuery\Doctrine\Tests\Fixture\DbalFixture;

final class DbalIdentityAdapterTest extends TestCase
{
    private DbalIdentityAdapter $adapter;

    protected function setUp(): void
    {
        $connection = DbalFixture::createConnection();
        DbalFixture::seedProducts($connection, [
            ['id' => 1, 'name' => 'a', 'price' => 10],
            ['id' => 2, 'name' => 'b', 'price' => 20],
            ['id' => 3, 'name' => 'c', 'price' => 30],
        ]);
        $qb = DbalFixture::productQueryBuilder($connection)->orderBy('id');

        $this->adapter = new DbalIdentityAdapter($qb, 'id');
    }

    #[Test]
    public function idsReturnsEveryValueOfTheConfiguredIdField(): void
    {
        self::assertSame([1, 2, 3], $this->adapter->ids());
    }

    #[Test]
    public function idsDoesNotMutateTheOriginalQueryBuilderSelectList(): void
    {
        $this->adapter->ids();

        $chunk = $this->adapter->pageView(0, 10);
        $results = iterator_to_array($chunk->results);

        self::assertSame(['id', 'name', 'price'], array_keys($results[0]));
    }

    #[Test]
    public function idFieldNeedNotBeThePrimaryKey(): void
    {
        $connection = DbalFixture::createConnection();
        DbalFixture::seedProducts($connection, [
            ['id' => 1, 'name' => 'sku-a', 'price' => 10],
            ['id' => 2, 'name' => 'sku-b', 'price' => 20],
        ]);
        $qb = DbalFixture::productQueryBuilder($connection)->orderBy('id');

        $adapter = new DbalIdentityAdapter($qb, 'name');

        self::assertSame(['sku-a', 'sku-b'], $adapter->ids());
    }

    #[Test]
    public function inheritsCountableBehaviorFromDbalSimpleAdapter(): void
    {
        self::assertSame(3, $this->adapter->count());
    }
}
