# Changelog

All notable changes to this project are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versioning follows [SemVer](https://semver.org/).

## [Unreleased]

## [2.0.0] - 2026-09-29

### Added
- Functional tests run against MariaDB 11 as well as SQLite when `SEARCH_QUERY_MYSQL_DSN` is set.
- `SearchTermsQuery` is now covered against a Doctrine ORM `QueryBuilder` too, not only a DBAL one — including that the `ESCAPE` clause survives DQL's re-quoting on the way into the generated SQL.
- Scenarios for a multi-character like marker and for a marker that prefixes another, which is what `SearchTermsConfig`'s longest-first normalisation exists for and what no test covered before.

### Changed
- `Adapter\OrmSimpleAdapter::count()` selects `COUNT(1)` instead of `COUNT(<rootAlias>)`. Same number on every query that worked before, but it no longer reads `getRootAliases()[0]`, which raised `Undefined array key 0` from inside the adapter for a `QueryBuilder` with no `from()`.
- **BC break:** `doctrine/orm` must be `^3.5` where the `Orm*` adapters are used, declared as `conflict: doctrine/orm <3.5`. 1.0.1 constrained it nowhere, so an ORM 3.0–3.4 install that resolved before now refuses. The floor is where it is because 3.5.0 is the first release the suite can run against — `Configuration::enableNativeLazyObjects(false)` throws on PHP 8.3 before it — and an accepted version CI never exercises is a claim, not support.
- `require-dev`'s `psr/cache` floor is `^2 || ^3`, was `^1 || ^2 || ^3`. PSR-6 1.x declares `getItem($key)` untyped, which the test suite's PSR-6 pool cannot implement. Test-only; nothing in `src/` touches PSR-6.
- The `doctrine/orm` floor is enforced by the resolver rather than described in `suggest` prose. The dependency itself stays optional.
- **BC break:** the `LIKE` escape character is `~`, not `\`. `ESCAPE '\'` is a syntax error on MySQL/MariaDB, and the `ESCAPE '\\'` that fixes them is rejected by PostgreSQL and SQLite.
- **BC break:** `SqlHelper::escapeLike()` and `SqlHelper::likeEscapeClause()` lost their `$escapeChar` argument; `SqlHelper::DEFAULT_LIKE_ESCAPE_CHAR` is gone.
- **BC break:** requires `pimbay/search-query: ^2.1`.
- **BC break:** negated terms now also match records whose column is `NULL`. `new SearchTermsConfig(ignoredTermsMatchNull: false)` restores the stricter reading.

### Fixed
- `SearchTermsQuery` emitted `ESCAPE '\'` for any term with a wildcard marker, which MySQL and MariaDB reject with `SQLSTATE[42000] 1064`. DBAL only — the ORM re-quotes the literal through the platform.
- A wildcard marker that `SqlHelper::escapeLike()` also escapes (`%`, `_`, `~`) became an escaped literal instead of a wildcard. Markers are substituted before escaping now.
- `count()` returned `0` (DBAL) or threw `NoResultException` (ORM) when the `QueryBuilder` already carried `setFirstResult()`; `PageAdapter::pageView()` reported that `0` as `totalCount`.
- `all()` and `ids()` honoured a `setFirstResult()`/`setMaxResults()` left on the `QueryBuilder`, truncating reads whose contract is the whole set.

## [1.0.1] - 2026-08-16

### Added
- `Adapter\DbalSimpleAdapter` and `Adapter\OrmSimpleAdapter` — implement `pimbay/search-query`'s `PageAdapter`, `SliceAdapter`, `CountableAdapter`, `HeadableAdapter`, `AllAdapter` over a Doctrine QueryBuilder, no field name required.
- `Adapter\DbalIdentityAdapter` and `Adapter\OrmIdentityAdapter` — the matching `SimpleAdapter` plus `IdentifiableAdapter::ids()`, for consumers that actually need a cheap ID list.
- `Adapter\OrmFetchJoinSafeAdapter` — `PageAdapter`/`CountableAdapter` over Doctrine's `Paginator`, for ORM queries with a `fetch`-joined collection where a plain `LIMIT`/`OFFSET` would return incomplete or duplicated rows.
- `SearchTerms\SearchTermsQuery` — turns a `SearchTerms\ParsedSearchTerms` into `andWhere()` conditions against one column.
