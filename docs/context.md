# Context

> Working memory, not a historical record.
> Continuously edited, not append-only — unlike DECISIONS.md.
> When something here resolves: delete it if it was only ever local/temporary, or promote it to DECISIONS.md if it turned out to matter beyond this moment.
> Don't let resolved items pile up here.

## Current focus

`src/` built: `SqlHelper.php`, `SearchTerms/SearchTermsQuery.php`, flat `Adapter/` namespace with driver name in the class name — `Adapter/DbalSimpleAdapter.php` + `Adapter/DbalIdentityAdapter.php`, `Adapter/OrmSimpleAdapter.php` + `Adapter/OrmIdentityAdapter.php` + `Adapter/OrmFetchJoinSafeAdapter.php`. PHP config files (composer.json, phpstan.neon.dist, phpunit.xml.dist, infection.json5, .php-cs-fixer.*, docker-compose.yml, docker/Dockerfile, .github/workflows/ci.yml) are in place. `tests/Unit` and `tests/Functional` suites are written; preparing for first release.

## Open questions

## Known limitations / non-goals (for now)

- No `CursorAdapter` implementation.
- No aggregation-composition helper (e.g. pagination + a `GROUP BY` breakdown in one response) — deliberately out of scope for this package.
- `count()` doesn't account for an existing `GROUP BY` on the consumer's QueryBuilder.

## Implementation notes

- `idField` (on `DbalIdentityAdapter`, both drivers) deliberately isn't named `primaryField` — it doesn't have to be the table's/entity's primary key, only a field that identifies a row well enough for the caller's `ids()` use case.
- `idField` on the ORM adapter must be a DQL path (e.g. `'r.id'`), not a bare column name — differs from the DBAL adapter, where it's a raw SQL column/alias. This asymmetry is inherent to DBAL vs. DQL, not something to "fix."
- `OrmSimpleAdapter::count()` derives the count alias from `$queryBuilder->getRootAliases()[0]` rather than requiring the consumer to pass one — DQL doesn't support `COUNT(*)` the way SQL does (it needs `COUNT(alias)` or `COUNT(alias.field)`), but the root alias is already knowable from the QueryBuilder itself, so nothing new needs asking of the consumer for this.
- `DbalIdentityAdapter`/`OrmIdentityAdapter` `extends` their driver's `SimpleAdapter` and add only `ids()` — inheritance, not composition. This is a deliberate exception to the package's "final by default" convention: `DbalSimpleAdapter`/`OrmSimpleAdapter` are intentionally left non-`final` so the `IdentityAdapter` subclasses can extend them and reuse `cloneQuery()`/`count()`/etc. without duplicating query logic or wrapping every method in a delegate.
- No `BaseAdapter` abstract class in `Adapter/` — with `DbalSimpleAdapter`/`OrmSimpleAdapter` as the shared parent per driver, `cloneQuery()`'s logic lives once in each driver's `SimpleAdapter` and is reused via inheritance, not via a separate shared base class.

## Ideas / future plans

- A `CursorAdapter` implementation once its constructor shape (explicit keyset column(s)) is resolved.
- Functional test suite against the Docker Compose matrix, using `FunctionalTestCase`-style shared setup.
