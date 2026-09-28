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

## `LIKE` escaping: the escape character is `~`, and markers are substituted before escaping

**Date:** 2026-09-27

**Decision:** `SqlHelper::LIKE_ESCAPE_CHAR` is `~` and `private`; neither `escapeLike()` nor `likeEscapeClause()` takes an `$escapeChar` argument. `SearchTermsQuery::toLikePattern()` splits the value on `SearchTermsConfig::$likeMarkers` first, escapes each chunk, then joins with the SQL wildcard. Supersedes the public `DEFAULT_LIKE_ESCAPE_CHAR = '\\'` and the two-argument signatures.

**Why `~`:** a backslash has no spelling valid on every engine, and `likeEscapeClause()` emitted the one MySQL/MariaDB reject outright.

| | MySQL 8.0 | MariaDB 11 | PostgreSQL 16 | SQLite |
|:--|:--|:--|:--|:--|
| `ESCAPE '\'` | syntax error 1064 | syntax error 1064 | ok | ok |
| `ESCAPE '\\'` | ok | ok | rejected | rejected |
| `ESCAPE '~'` | ok | ok | ok | ok |

`NO_BACKSLASH_ESCAPES` additionally swaps which backslash spelling MySQL accepts, at session level, where the library cannot see it. The parameter is gone rather than re-defaulted because the caller had to keep both methods in sync by hand and no other value was ever safe.

**Why split before escaping:** escaping first turned any marker that `escapeLike()` also escapes — `%`, `_`, `~` — into a literal, so `likeMarkers: ['%']` produced a query that ran but could never match. Splitting first makes every marker work instead of forbidding the dangerous ones by validation.

**Consequence:** `escapeLike()` output changes from `\_`/`\%` to `~_`/`~%`, and a caller who uses it *without* `likeEscapeClause()` silently stops escaping — `~` has no implicit meaning the way MySQL's `\` had.

**Alternatives considered:** detecting the platform from the `QueryBuilder`. Rejected — DBAL 3's builder exposes no public connection getter, and it would not solve `NO_BACKSLASH_ESCAPES` anyway.

## MariaDB 11 is the second test engine, on the PHP 8.3 combos only

**Date:** 2026-09-27

**Decision:** `docker-compose.yml` runs a `mariadb:11` service and the functional `SearchTermsQueryTest` runs every scenario on SQLite and on MariaDB, reached through `SEARCH_QUERY_MYSQL_DSN`. The MariaDB half skips when the variable is unset. Only `php83-dbal3`/`php83-dbal4` are wired to it; `test:coverage`/`test:mutation` pass an empty DSN.

**Why:** SQLite accepts SQL MySQL and MariaDB reject, which is how the malformed `ESCAPE` clause shipped. PostgreSQL accepts it too, so it would add an engine but not a second opinion. DBAL major is the axis that changes the generated SQL text, PHP version is not, so the other four combos would only add healthcheck waits. Infection is excluded because its threads share one database, where a concurrency error reads as a killed mutant.

**Connection:** `DsnParser`, not `DriverManager`'s `url` — DBAL 4 removed `url`, `DsnParser` exists on 3.8 too.

## `OrmSimpleAdapter::count()` selects `COUNT(1)`, not `COUNT(<rootAlias>)`

**Date:** 2026-09-27

**Decision:** `count()` selects the literal `COUNT(1)`. Supersedes deriving a count alias from `$queryBuilder->getRootAliases()[0]`.

**Why:** DQL's `AggregateExpression` takes a `SimpleArithmeticExpression`, so a literal is valid — the grammar is byte-identical in ORM 3.0.0 and 3.7.2, so it does not move within any 3.x the package accepts. `COUNT(1)` and `COUNT(<rootAlias>)` return the same number on both a plain and a joined query, because a root alias is never NULL. Reading `getRootAliases()[0]` raised `Undefined array key 0` from inside the adapter for a `QueryBuilder` with no `from()`, followed by an unrelated-looking DQL syntax error; the literal has no such arm, so no guard and no new exception are needed for it.

**Careful:** `COUNT(*)` is the one spelling DQL rejects outright (`[Syntax Error] Expected Literal, got '*'`) — do not "normalise" the literal to it. `phpstan.neon.dist` now sets `reportPossiblyNonexistentGeneralArrayOffset: true`, which is what would have flagged the old offset access at `level: max`.

## `splitOnMarkers()` scans with a bounded `foreach`, not a hand-advanced cursor

**Date:** 2026-09-27

**Decision:** The scan iterates `str_split($value)` and skips consumed marker bytes with a `$skip` counter, compared as `0 !== $skip`. Supersedes the `while ($offset < $length)` loop with `++$offset` / `$offset += \strlen($marker)`.

**Why:** with a hand-advanced cursor, three separate mutators broke termination rather than the result — `Increment` (`++$offset` → `--$offset`), `PlusEqual` (`+=` → `-=`) and `Assignment` (`+=` → `=`). Each produced an endless loop that grew `$chunk` until PHP hit its 128 MB limit, so Infection recorded a fatal error instead of a killed mutant: detected, but at the cost of three runaway processes per run. `foreach` fixes the iteration count at the value's length, so a wrong `$skip` can only produce a wrong split — which an assertion kills. Mutation run: 125/125 killed, no errors, against 120 killed + 3 errors before.

**Why not `preg_split()` either:** an alternation built from `preg_quote()` can never fail to compile, so the `false` arm `preg_split()` declares is unreachable — no test can pin it down and its fallback array is an unkillable mutant. This is why the scan is written out at all.

**Why `0 !== $skip` and not `$skip > 0`:** the counter is never negative, so the two are equivalent in every reachable state — but under `> 0` the initial `$skip = 0` and a mutated `$skip = -1` behave identically, which is an unkillable mutant. Comparing against zero makes the starting value observable.

**Careful:** do not "simplify" this back to a cursor, and do not widen the comparison to `>=`/`>`. Both reintroduce mutants the tests cannot kill by assertion. The multi-character-marker scenarios in `SearchTermsQueryTest` are what keeps the `$skip` branch reachable at all — with single-character markers `$skip` is always `0` and every mutation of it escapes.

## `idField` is named for what it does, and its spelling differs per driver

**Date:** 2026-09-28

*Transcribed from docs/context.md, where it had been sitting as an implementation note since the adapters were written. The decisions themselves predate this entry.*

**Decision:** The constructor parameter on `DbalIdentityAdapter`/`OrmIdentityAdapter` is `$idField`, not `$primaryField`. On the ORM adapter it takes a DQL path (`'r.id'`); on the DBAL adapter a raw SQL column or alias.

**Why the name:** it does not have to be the table's or entity's primary key — only a field that identifies a row well enough for the caller's `ids()` use case. `primaryField` would promise more than the adapter needs or checks.

**Why the asymmetry stands:** DBAL builds SQL and ORM builds DQL, and the two address a column differently. Normalising one into the other would mean parsing the consumer's query, so the asymmetry is inherent rather than an inconsistency to fix.

## `IdentityAdapter` extends its driver's `SimpleAdapter`

**Date:** 2026-09-28

*Transcribed from docs/context.md, as above.*

**Decision:** `DbalIdentityAdapter`/`OrmIdentityAdapter` `extends` their driver's `SimpleAdapter` and add only `ids()`. `DbalSimpleAdapter`/`OrmSimpleAdapter` are therefore deliberately left non-`final` — the one stated exception to the package's "final by default" convention.

**Why:** inheritance reuses `cloneQuery()`, `count()` and the page/slice methods as they are. Composition would mean wrapping every method in a delegate, and a shared abstract base would put `cloneQuery()` in a third class for no gain over the parent that already holds it — which is why there is no `BaseAdapter`.

**Careful:** making either `SimpleAdapter` `final` breaks the matching `IdentityAdapter`.

## The test suite runs at the dependency floor, which cost two dev-only corrections

**Date:** 2026-09-28

**Decision:** CI has a `lowest-deps` job (`composer update --prefer-lowest --prefer-stable`). Making it pass required raising two `require-dev` floors that were never installable: `psr/cache` from `^1 || ^2 || ^3` to `^2 || ^3`, and `doctrine/orm` from `^3.0` to `^3.5`. Extends the `symfony/var-exporter` entry above.

**Why `psr/cache: ^2`:** PSR-6 1.x declares `getItem($key)` with no parameter type. `ArrayCacheItemPool::getItem(string $key)` cannot narrow that — adding a parameter type to an implementation is a contravariance violation, so PHP fatals at class-declaration time. 2.0 types the parameter, and adding the return type the interface omits is allowed. Nothing in `src/` touches PSR-6; this is test-only.

**Why `require-dev`'s `doctrine/orm` floor is `^3.5`, not `^3.0`:** `Doctrine\ORM\Configuration::enableNativeLazyObjects()` exists from ORM 3.4.0, but 3.4 throws `LogicException` on PHP < 8.4 whatever the argument; 3.5.0 is the first release that accepts `false` there, which is what `OrmFixture` passes on PHP 8.3. Guarding the call with `method_exists()` would have let the suite run at ORM 3.0.0 — but against any installed ORM that condition is statically always true, which `php:stan` reports at `level: max`, and suppressing the authoritative gate to widen a test matrix is the wrong trade. `require-dev` describes what the suite needs.

**And `conflict` moved to `<3.5` to match:** `src/`'s own ORM API usage was verified present as far back as 3.0.0, so 3.0–3.4 would run — but nothing would ever put the suite against them, and an accepted version CI never exercises is a claim rather than support. Accepting only what is tested costs installs that would have resolved; leaving the gap would have cost the constraint its meaning.

**Also:** `lowest-deps` deliberately runs no `composer audit`. Pinning dev tools to their floors surfaces advisories in `phpunit/phpunit` and `symfony/process` that say nothing about this package.
