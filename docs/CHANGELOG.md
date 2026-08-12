# Changelog

All notable changes to this project are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versioning follows [SemVer](https://semver.org/).

## [Unreleased]

## [1.0.0] - 2026-08-16

### Added
- `Adapter\DbalSimpleAdapter` and `Adapter\OrmSimpleAdapter` — implement `pimbay/search-query`'s `PageAdapter`, `SliceAdapter`, `CountableAdapter`, `HeadableAdapter`, `AllAdapter` over a Doctrine QueryBuilder, no field name required.
- `Adapter\DbalIdentityAdapter` and `Adapter\OrmIdentityAdapter` — the matching `SimpleAdapter` plus `IdentifiableAdapter::ids()`, for consumers that actually need a cheap ID list.
- `Adapter\OrmFetchJoinSafeAdapter` — `PageAdapter`/`CountableAdapter` over Doctrine's `Paginator`, for ORM queries with a `fetch`-joined collection where a plain `LIMIT`/`OFFSET` would return incomplete or duplicated rows.
- `SearchTerms\SearchTermsQuery` — turns a `SearchTerms\ParsedSearchTerms` into `andWhere()` conditions against one column.
