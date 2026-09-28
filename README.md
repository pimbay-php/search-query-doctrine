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

Supports Doctrine DBAL `^3.8 || ^4.0`. `doctrine/orm` is optional and must be `^3.5` where used — only needed if you use `Adapter\OrmSimpleAdapter`; `Adapter\DbalSimpleAdapter` has no ORM dependency.

## Installation

```bash
composer require pimbay/search-query-doctrine
```

## Usage

### `Adapter\OrmSimpleAdapter`

Wraps a Doctrine ORM `QueryBuilder`. No field name needed — `count()` selects `COUNT(1)`, which DQL accepts without a root alias.

`count()`, `all()` and `ids()` ignore any `LIMIT`/`OFFSET` already set on the `QueryBuilder` you hand over: a count is of the whole set, and `AllAdapter`/`IdentifiableAdapter` are the unbounded reads by contract.

Mind the shape behind the `iterable` those contracts declare: `head()` hands back a lazy, one-pass generator on the ORM adapter but an already-materialised array on the DBAL one, and `all()` is a generator on both.
Iterate once, or wrap the result in `iterator_to_array()` yourself if you need to walk it twice.

```php
<?php

declare(strict_types=1);

use PimBay\SearchQuery\Doctrine\Adapter\OrmSimpleAdapter;
use PimBay\SearchQuery\Page\PageAssembler;

$qb = $entityManager->createQueryBuilder()
    ->select('r')
    ->from(Request::class, 'r')
    ->addOrderBy('r.id', 'DESC');

$adapter = new OrmSimpleAdapter($qb);

$result = (new PageAssembler())->paginate($adapter, 1, 20);
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
use PimBay\SearchQuery\Page\PageAssembler;

$qb = $connection->createQueryBuilder()
    ->select('*')
    ->from('request', 'r')
    ->addOrderBy('id', 'DESC');

$adapter = new DbalSimpleAdapter($qb);

$result = (new PageAssembler())->paginate($adapter, 1, 20);
```

### `Adapter\OrmFetchJoinSafeAdapter`

For an ORM `QueryBuilder` that `fetch`-joins a to-many association — a plain `LIMIT`/`OFFSET` over such a query returns duplicated or incomplete rows, so this wraps Doctrine's `Paginator` (`fetchJoinCollection: true`) instead.
`PageAdapter`/`CountableAdapter` only — no `SliceAdapter`/`HeadableAdapter`/`AllAdapter`, since those aren't meaningful against a `Paginator`-backed count.

```php
<?php

declare(strict_types=1);

use PimBay\SearchQuery\Doctrine\Adapter\OrmFetchJoinSafeAdapter;
use PimBay\SearchQuery\Page\PageAssembler;

$qb = $entityManager->createQueryBuilder()
    ->select('r', 'items')
    ->from(Request::class, 'r')
    ->leftJoin('r.items', 'items')
    ->addSelect('items')
    ->addOrderBy('r.id', 'DESC');

$adapter = new OrmFetchJoinSafeAdapter($qb);

$result = (new PageAssembler())->paginate($adapter, 1, 20);
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

`apply()` numbers its bound parameters `:{$paramPrefix}1`, `:{$paramPrefix}2`, … from `1` on every call, so give each call a `$paramPrefix` of its own — two calls sharing a prefix bind to the same placeholders.

`SearchTermsConfig`'s `anywhere` (default `true`) adds a **leading** `%` only; a trailing wildcard comes from the term's own like marker.
So with the default markers `hors*` searches for `%hors%`, `*hors` for `%hors`, and a term with no marker at all is an `=` comparison that `anywhere` does not touch.

Negated terms (`-cow` above) also match records whose column is `NULL`. Pass `new SearchTermsConfig(ignoredTermsMatchNull: false)` for the stricter reading that excludes them.

> **Security note:** `$column` and `$paramPrefix` (here and in `applyString()`) are interpolated directly into the generated SQL/DQL fragment — only the parsed *values* go through bound parameters (`:paramPrefixN`).

## Testing

```bash
composer test:all       # every combination in the table below
composer test:coverage  # php83-dbal4 combo, --coverage-text
composer test:mutation  # infection — mutation testing, --min-msi=100 --min-covered-msi=100
```

Each combo runs in its own Docker image with dependencies baked in at build time — no `composer update` happens at test-run time, and combos never share or overwrite each other's installed dependency versions.
Requires Docker and Docker Compose locally.

Functional tests run against in-memory SQLite in every combination, and additionally against MariaDB 11 in the two PHP 8.3 ones.

Each combo resolves the *highest* release matching its constraint, so the `^3.8` rows run whatever 3.x is current, not 3.8.0 itself.

| Command | PHP | DBAL | ORM | SQLite | MariaDB 11 |
|:---|:---|:---|:---|:---:|:---:|
| `composer test:83-dbal3` | 8.3 | `^3.8` | `^3.5` | ✅ | ✅ |
| `composer test:83-dbal4` | 8.3 | `^4.0` | `^3.5` | ✅ | ✅ |
| `composer test:84-dbal3` | 8.4 | `^3.8` | `^3.5` | ✅ | — |
| `composer test:84-dbal4` | 8.4 | `^4.0` | `^3.5` | ✅ | — |
| `composer test:85-dbal3` | 8.5 | `^3.8` | `^3.5` | ✅ | — |
| `composer test:85-dbal4` | 8.5 | `^4.0` | `^3.5` | ✅ | — |

`test:coverage` and `test:mutation` reuse the `php83-dbal4` image with `SEARCH_QUERY_MYSQL_DSN` emptied, so they are SQLite-only.
The MariaDB rows `depends_on` a `mariadb` service that `docker compose` starts and health-checks for you; stop it again with `docker compose down`.
Outside Docker, `vendor/bin/phpunit` skips the MariaDB half unless you point `SEARCH_QUERY_MYSQL_DSN` at a server yourself (`mysql://user:password@host:3306/dbname`).

## Development Helpers

```bash
composer php:cs        # php-cs-fixer, --dry-run --diff (check only)
composer php:cs:fix    # same, applies the fix
composer php:stan      # phpstan analyse
```

## Architecture & Decisions

- **[docs/context.md](docs/context.md)** — current working state: what's in progress, what's next.
- **[docs/DECISIONS.md](docs/DECISIONS.md)** — why things are built the way they are, in the order the decisions were made.
- **[docs/CHANGELOG.md](docs/CHANGELOG.md)** — version history.

## Packages in the stack

| Package | Description |
|---|---|
| `pimbay/search-query` | Framework-agnostic contracts this package adapts Doctrine to — no datasource code of its own. |
| `pimbay/search-query-doctrine` | This package — adapters over a Doctrine DBAL or ORM QueryBuilder. |
| `pimbay/search-query-pimcore` | Adapters over a Pimcore listing — the sibling package for Pimcore projects. |

## License

Public domain — [Unlicense](LICENSE)

Created by [Jan Sarmir](https://pimbay.dev) · No conditions · No copyright

Bundled third-party dependencies and their licenses: **[docs/THIRD-PARTY-NOTICES.md](docs/THIRD-PARTY-NOTICES.md)**.
