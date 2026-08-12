<?php

declare(strict_types=1);

namespace PimBay\SearchQuery\Doctrine\Tests\Fixture;

use Doctrine\ORM\QueryBuilder;

final class SpyOrmQueryBuilder extends QueryBuilder
{
    public ?CallLog $resetDqlPartLog = null;

    public function resetDQLPart(string $part): static
    {
        $this->resetDqlPartLog?->record($part);

        return parent::resetDQLPart($part);
    }
}
