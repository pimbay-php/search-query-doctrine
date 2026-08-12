<?php

declare(strict_types=1);

namespace PimBay\SearchQuery\Doctrine\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PimBay\SearchQuery\Doctrine\Adapter\OrmIdentityAdapter;
use PimBay\SearchQuery\Doctrine\Tests\Fixture\Entity\Product;
use PimBay\SearchQuery\Doctrine\Tests\Fixture\OrmFixture;

final class OrmIdentityAdapterTest extends TestCase
{
    /**
     * @var OrmIdentityAdapter<Product>
     */
    private OrmIdentityAdapter $adapter;

    protected function setUp(): void
    {
        $em = OrmFixture::createEntityManager();
        OrmFixture::seedProducts($em, [
            ['name' => 'a', 'price' => 10],
            ['name' => 'b', 'price' => 20],
            ['name' => 'c', 'price' => 30],
        ]);
        $qb = $em->createQueryBuilder()->select('p')->from(Product::class, 'p')->orderBy('p.id');

        $this->adapter = new OrmIdentityAdapter($qb, 'p.id');
    }

    #[Test]
    public function idsReturnsEveryValueOfTheConfiguredDqlPath(): void
    {
        self::assertSame([1, 2, 3], $this->adapter->ids());
    }

    #[Test]
    public function idFieldCanBeANonPrimaryKeyDqlPath(): void
    {
        $em = OrmFixture::createEntityManager();
        OrmFixture::seedProducts($em, [
            ['name' => 'sku-a', 'price' => 10],
            ['name' => 'sku-b', 'price' => 20],
        ]);
        $qb = $em->createQueryBuilder()->select('p')->from(Product::class, 'p')->orderBy('p.id');

        $adapter = new OrmIdentityAdapter($qb, 'p.name');

        self::assertSame(['sku-a', 'sku-b'], $adapter->ids());
    }

    #[Test]
    public function idsDoesNotMutateTheOriginalQueryBuilderSelect(): void
    {
        $this->adapter->ids();

        $chunk = $this->adapter->pageView(0, 10);

        self::assertCount(3, $chunk->results);
    }

    #[Test]
    public function inheritsCountableBehaviorFromOrmSimpleAdapter(): void
    {
        self::assertSame(3, $this->adapter->count());
    }
}
