<?php

declare(strict_types=1);

namespace PimBay\SearchQuery\Doctrine\Tests\Fixture;

final class CallLog
{
    /** @var string[] */
    public array $calls = [];

    public function record(string $call): void
    {
        $this->calls[] = $call;
    }
}
