# pimbay/search-query-doctrine

[![Latest Version on Packagist](https://img.shields.io/packagist/v/pimbay/search-query-doctrine?style=flat-square&color=blue)](https://packagist.org/packages/pimbay/search-query-doctrine)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.3-8892bf?style=flat-square&logo=php)](https://php.net)
[![License](https://img.shields.io/packagist/l/pimbay/search-query-doctrine?style=flat-square&color=green)](LICENSE)
[![Code Coverage](https://img.shields.io/badge/coverage-100%25-brightgreen?style=flat-square)](https://codeberg.org/pimbay-php/search-query-doctrine)
[![Mutation Score](https://img.shields.io/badge/MSI-100%25-brightgreen?style=flat-square)](https://codeberg.org/pimbay-php/search-query-doctrine)

Doctrine DBAL and ORM adapters for [`pimbay/search-query`](https://packagist.org/packages/pimbay/search-query).
`Adapter\DbalSimpleAdapter` and `Adapter\OrmSimpleAdapter` implement `PageAdapter`/`SliceAdapter`/`CountableAdapter`/`HeadableAdapter`/`AllAdapter` over a Doctrine `QueryBuilder` you've already built — filtering and sorting stay entirely in your own repository code, this package only adds pagination-shape operations (`LIMIT`/`OFFSET`, `COUNT`) on top.
`DbalIdentityAdapter`/`OrmIdentityAdapter` (same two drivers) additionally implement `IdentifiableAdapter` for the cases that need a cheap `ids()` read — the only capability that needs a named field, so it's the only one that asks for one.
`SearchTerms\SearchTermsQuery` turns an already-parsed `SearchTerms\ParsedSearchTerms` (from `pimbay/search-query`'s `SearchTermsParser`) into `andWhere()` conditions — free-text search wired into the same `QueryBuilder`.

Supports Doctrine DBAL `^3.8 || ^4.0`. `doctrine/orm` is optional — only needed if you use `Adapter\OrmSimpleAdapter`; `Adapter\DbalSimpleAdapter` has no ORM dependency.

## Installation

```bash
composer require pimbay/search-query-doctrine
```

## Usage

### `Adapter\OrmSimpleAdapter`

Wraps a Doctrine ORM `QueryBuilder`. No field name needed — `count()` derives its `COUNT(<rootAlias>)` from the `QueryBuilder` itself.

```php
<?php

declare(strict_types=1);

use PimBay\SearchQuery\Doctrine\Adapter\OrmSimpleAdapter;
use PimBay\SearchQuery\Page\PageIndex;
use PimBay\SearchQuery\Page\PageAssembler;
use PimBay\SearchQuery\Size;

$qb = $entityManager->createQueryBuilder()
    ->select('r')
    ->from(Request::class, 'r')
    ->addOrderBy('r.id', 'DESC');

$adapter = new OrmSimpleAdapter($qb);

$result = (new PageAssembler())->paginate($adapter, new PageIndex(1), new Size(20));
```

### `Adapter\OrmIdentityAdapter`

Same as `OrmSimpleAdapter`, plus `IdentifiableAdapter::ids()` — for when you actually need a cheap ID list. `$idField` is a DQL path (e.g. `'r.id'`), not a bare column name, and doesn't have to be the entity's primary key.

`$idField` is interpolated directly into the `SELECT` (same on `DbalIdentityAdapter` below) — as with `SearchTermsQuery`'s `$column`, only pass a literal you control, never raw user input.

```php
<?php

declare(strict_types=1);

use PimBay\SearchQuery\Doctrine\Adapter\OrmIdentityAdapter;

$adapter = new OrmIdentityAdapter($qb, idField: 'r.id');

$adapter->ids(); // int[] — scalar SELECT, no entity hydration
```

### `Adapter\DbalSimpleAdapter` / `Adapter\DbalIdentityAdapter`

Same two classes, same shapes, over a Doctrine DBAL `QueryBuilder` instead — `idField` is a raw SQL column/alias here, not a DQL path.

```php
<?php

declare(strict_types=1);

use PimBay\SearchQuery\Doctrine\Adapter\DbalSimpleAdapter;
use PimBay\SearchQuery\Page\PageIndex;
use PimBay\SearchQuery\Page\PageAssembler;
use PimBay\SearchQuery\Size;

$qb = $connection->createQueryBuilder()
    ->select('*')
    ->from('request', 'r')
    ->addOrderBy('id', 'DESC');

$adapter = new DbalSimpleAdapter($qb);

$result = (new PageAssembler())->paginate($adapter, new PageIndex(1), new Size(20));
```

### `Adapter\OrmFetchJoinSafeAdapter`

For an ORM `QueryBuilder` that `fetch`-joins a to-many association — a plain `LIMIT`/`OFFSET` over such a query returns duplicated or incomplete rows, so this wraps Doctrine's `Paginator` (`fetchJoinCollection: true`) instead.
`PageAdapter`/`CountableAdapter` only — no `SliceAdapter`/`HeadableAdapter`/`AllAdapter`, since those aren't meaningful against a `Paginator`-backed count.

```php
<?php

declare(strict_types=1);

use PimBay\SearchQuery\Doctrine\Adapter\OrmFetchJoinSafeAdapter;
use PimBay\SearchQuery\Page\PageIndex;
use PimBay\SearchQuery\Page\PageAssembler;
use PimBay\SearchQuery\Size;

$qb = $entityManager->createQueryBuilder()
    ->select('r', 'items')
    ->from(Request::class, 'r')
    ->leftJoin('r.items', 'items')
    ->addSelect('items')
    ->addOrderBy('r.id', 'DESC');

$adapter = new OrmFetchJoinSafeAdapter($qb);

$result = (new PageAssembler())->paginate($adapter, new PageIndex(1), new Size(20));
```

`$useOutputWalkers` defaults to `null` — Doctrine's `Paginator` auto-detects a fetch-joined to-many association (exactly the case above) and turns output walkers on by itself, so leave it unset in the common case.
Set it explicitly only to override that detection: `false` if your DQL uses a construct the output walker can't rewrite (some custom scalar functions, certain mixed-result selects), accepting the less-precise identifier-based fallback; `true` to force it on if auto-detection doesn't fire for your query shape.

### `SearchTerms\SearchTermsQuery`

Turns a `SearchTerms\ParsedSearchTerms` into `andWhere()` conditions against one column — works against either QueryBuilder type.

```php
<?php

declare(strict_types=1);

use PimBay\SearchQuery\Doctrine\SearchTerms\SearchTermsQuery;
use PimBay\SearchQuery\SearchTerms\SearchTermsConfig;
use PimBay\SearchQuery\SearchTerms\SearchTermsParser;

$config = new SearchTermsConfig();
$parsed = (new SearchTermsParser())->parse(['dog', 'hors*', '-cow'], $config);

(new SearchTermsQuery())->apply($qb, 'title', $parsed, 'title', $config);
```

> **Security note:** `$column` (here and in `applyString()`) is interpolated directly into the generated SQL/DQL fragment — only the parsed *values* go through bound parameters (`:paramPrefixN`). Only ever pass a literal from your own code (or an allowlist you control); never pass a raw, unvalidated end-user string as `$column`, or it opens a SQL/DQL injection path through the column name itself.

## Testing

```bash
composer test:83-dbal3  # PHP 8.3 + DBAL 3.8 + ORM 3.0
composer test:83-dbal4  # PHP 8.3 + DBAL 4.0 + ORM 3.0
composer test:84-dbal3  # PHP 8.4 + DBAL 3.8 + ORM 3.0
composer test:84-dbal4  # PHP 8.4 + DBAL 4.0 + ORM 3.0
composer test:85-dbal3  # PHP 8.5 + DBAL 3.8 + ORM 3.0
composer test:85-dbal4  # PHP 8.5 + DBAL 4.0 + ORM 3.0
composer test:all       # all of the above
composer test:coverage  # php83-dbal4 combo, --coverage-text
composer test:mutation # infection — mutation testing, --min-msi=100 --min-covered-msi=100
```

Each combo runs in its own Docker image with dependencies baked in at build time — no `composer update` happens at test-run time, and combos never share or overwrite each other's installed dependency versions.
Requires Docker and Docker Compose locally.

| PHP | DBAL 3.8 | DBAL 4.0 |
|:----|:----|:----|
| **8.3** | ✅ | ✅ |
| **8.4** | ✅ | ✅ |
| **8.5** | ✅ | ✅ |

## Development Helpers

```bash
composer php:cs        # php-cs-fixer, --dry-run --diff (check only)
composer php:cs:fix    # same, applies the fix
composer php:stan      # phpstan analyse
```

## Packages in the stack

| Package | Description |
|---|---|
| `pimbay/search-query` | Framework-agnostic contracts this package adapts Doctrine to — no datasource code of its own. |
| `pimbay/search-query-doctrine` | This package — Doctrine DBAL/ORM adapters. |

## Architecture & Decisions

- **[docs/context.md](docs/context.md)** — current working state: what's in progress, what's next.
- **[docs/DECISIONS.md](docs/DECISIONS.md)** — why things are built the way they are, in the order the decisions were made.
- **[docs/CHANGELOG.md](docs/CHANGELOG.md)** — version history.

## License

Public domain — [Unlicense](LICENSE)

Created by [Jan Sarmir](https://pimbay.dev) · No conditions · No copyright

Bundled third-party dependencies and their licenses: **[docs/THIRD-PARTY-NOTICES.md](docs/THIRD-PARTY-NOTICES.md)**.
