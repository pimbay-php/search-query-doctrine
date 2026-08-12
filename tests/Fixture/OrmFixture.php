<?php

declare(strict_types=1);

namespace PimBay\SearchQuery\Doctrine\Tests\Fixture;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Tools\SchemaTool;
use PimBay\SearchQuery\Doctrine\Tests\Fixture\Entity\Product;
use PimBay\SearchQuery\Doctrine\Tests\Fixture\Entity\Tag;

/**
 * Builds a real EntityManager against an in-memory SQLite database. Manual Configuration instead
 * of Doctrine\ORM\ORMSetup — ORMSetup's cache helpers require symfony/cache, which this project
 * doesn't depend on; ArrayCacheItemPool (this dir) fills the same PSR-6 slot for tests.
 */
final class OrmFixture
{
    public static function createEntityManager(): EntityManager
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([__DIR__.'/Entity']));
        $config->setMetadataCache(new ArrayCacheItemPool());
        $config->setQueryCache(new ArrayCacheItemPool());
        $config->setProxyDir(sys_get_temp_dir().'/search-query-doctrine-proxies');
        $config->setProxyNamespace('PimBay\SearchQuery\Doctrine\Tests\Fixture\Proxies');
        $config->setAutoGenerateProxyClasses(true);
        // PHP 8.4+ has native lazy objects; below that, ORM 3.x falls back to Symfony's LazyGhost trait,
        // which needs symfony/var-exporter (require-dev, test-only).
        $config->enableNativeLazyObjects(\PHP_VERSION_ID >= 80400);

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $em = new EntityManager($connection, $config);

        $tool = new SchemaTool($em);
        $tool->createSchema([
            $em->getClassMetadata(Product::class),
            $em->getClassMetadata(Tag::class),
        ]);

        return $em;
    }

    /**
     * @param array<int, array{name: string, price: int, tags?: string[]}> $rows
     */
    public static function seedProducts(EntityManager $em, array $rows): void
    {
        $tagsByName = [];

        foreach ($rows as $row) {
            $product = new Product();
            $product->name = $row['name'];
            $product->price = $row['price'];

            foreach ($row['tags'] ?? [] as $tagName) {
                if (!isset($tagsByName[$tagName])) {
                    $tag = new Tag();
                    $tag->name = $tagName;
                    $em->persist($tag);
                    $tagsByName[$tagName] = $tag;
                }

                $product->tags->add($tagsByName[$tagName]);
            }

            $em->persist($product);
        }

        $em->flush();
        $em->clear();
    }
}
