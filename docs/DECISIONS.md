# Decisions

> Append-only log of decisions specific to _this_ project.
> Never edit or delete a past entry — if a decision changes, add a new entry that supersedes it and says so.
>
> **What belongs here** (test): would changing this silently break correctness, compatibility, or behavior if someone didn't know why it was done this way?
> If yes → here.
> If it's a cheap/local implementation detail → docs/context.md instead.
> If it's a pattern repeated across multiple repos → AGENTS.md instead, not here.

## `count()` strips `ORDER BY` before running, version-conditionally on DBAL

**Date:** 2026-08-10

**Decision:** Both `SimpleAdapter::count()` implementations clear `ORDER BY` on the cloned query before selecting `COUNT`. ORM uses `resetDQLPart('orderBy')`. DBAL uses `resetOrderBy()` (available since DBAL 3.8, the package's minimum supported DBAL version).

**Why:** `ORDER BY` on a `COUNT`-only `SELECT` is pointless work at best, an error at worst, on some engines.

## `doctrine/dbal` required, `doctrine/orm` optional

**Date:** 2026-08-10

**Decision:** `composer.json` requires `doctrine/dbal` directly; `doctrine/orm` is `suggest` + `require-dev` only.

**Why:** `Dbal*` classes need DBAL regardless of ORM, and ORM depends on DBAL transitively anyway. A DBAL-only consumer shouldn't be forced to install ORM.

## `OrmFetchJoinSafeAdapter` — `PageAdapter`/`CountableAdapter` only

**Date:** 2026-08-10

**Decision:** A `QueryBuilder` that fetch-joins a to-many association (not just `leftJoin` for filtering) breaks `OrmSimpleAdapter`'s naive `COUNT` + `LIMIT`/`OFFSET` — rows are counted/limited after the join, multiplied by child-row count, not per root entity. `OrmFetchJoinSafeAdapter` wraps `Doctrine\ORM\Tools\Pagination\Paginator` instead, which resolves this via a distinct-root-id subquery. It implements `PageAdapter`/`CountableAdapter` only — `Paginator`'s model doesn't give a cheap, correct way to add `Slice`/`Headable`/`All`/`Identifiable` without reimplementing its internals.

**Alternatives considered:** Copying Pagerfanta's full `Paginator`-based adapter wholesale. Rejected — unnecessary AST-rewriting overhead for the common case (`leftJoin`-only filtering), where `OrmSimpleAdapter`'s naive approach is already correct and cheaper.

## `symfony/var-exporter` added to `require-dev`, for test-only lazy proxy support on PHP <8.4

**Date:** 2026-08-12

**Decision:** `composer.json`'s `require-dev` now includes `symfony/var-exporter` (`^6.4 || ^7.0`). `tests/Fixture/OrmFixture.php` calls `$config->enableNativeLazyObjects(PHP_VERSION_ID >= 80400)` — `true` on PHP 8.4+, which has native lazy objects and needs no package; `false` below that, where Doctrine ORM 3.x's proxy factory falls back to Symfony's `LazyGhost` trait and requires this package to be installed.

**Why:** The test suite's `OrmFixture` builds real `EntityManager` instances against fetch-joined entities (`Product`/`Tag`), which triggers ORM 3.x's lazy-proxy machinery. Without either native lazy objects or `symfony/var-exporter` present, `EntityManager` construction throws `ORMInvalidArgumentException` on PHP 8.3. This is `require-dev` only — it does not affect what a consumer installs via `composer require pimbay/search-query-doctrine`.
