# AGENTS.md — search-query-doctrine

## Project Overview

`pimbay/search-query-doctrine` implements `pimbay/search-query`'s adapter contracts over a Doctrine `QueryBuilder` — `Dbal*`/`Orm*` adapter pairs.
`doctrine/dbal` required, `doctrine/orm` optional (suggest + require-dev).
License: Unlicense. Minimum PHP: 8.3.

## Commands

```bash
composer install
composer php:cs         # php-cs-fixer, --dry-run --diff (check only, never mutates)
composer php:cs:fix     # same, applies the fix
composer php:stan       # phpstan analyse, level: max
composer test:83-dbal3  # docker compose run — PHP 8.3 + DBAL 3.8 + ORM 3.0
composer test:83-dbal4  # docker compose run — PHP 8.3 + DBAL 4.0 + ORM 3.0
composer test:84-dbal3  # docker compose run — PHP 8.4 + DBAL 3.8 + ORM 3.0
composer test:84-dbal4  # docker compose run — PHP 8.4 + DBAL 4.0 + ORM 3.0
composer test:85-dbal3  # docker compose run — PHP 8.5 + DBAL 3.8 + ORM 3.0
composer test:85-dbal4  # docker compose run — PHP 8.5 + DBAL 4.0 + ORM 3.0
composer test:all       # all test:*-dbal* combos
composer test:coverage  # docker compose run, php83-dbal4 combo — phpunit --coverage-text
composer test:mutation # infection — mutation testing, --min-msi=100 --min-covered-msi=100
composer ci             # php:cs + php:stan + test:all + test:mutation
```

`php:stan` is the authoritative type-safety gate — always run alongside `php:cs`/tests.
A bare command never mutates — only the `:fix` variant writes to disk.

## Code Style

- **PHP 8.3+**, `declare(strict_types=1)` everywhere.
- **`@PER-CS2.0` + `@PER-CS2.0:risky` + `@PHP83Migration` + `@Symfony` + `@Symfony:risky`** via php-cs-fixer — run `composer php:cs:fix`, don't hand-format.
- **`final` by default**; remove only with a stated, repo-specific reason.
- **`readonly` properties** by default — promoted constructor properties over separate declaration + assignment.
- **PSR-4**, one class per file, namespace mirrors directory 1:1.
- **Comments** only where non-obvious, always English. PHPDoc only for shapes PHPStan can't infer.
- **Markdown**: semantic linebreaks — break at sentence end, never inside a list item.
- **Docs discipline**: no "Project Layout" in READMEs — the tree speaks for itself.

## Architecture

```
src/
  Adapter/        — pimbay/search-query adapter contracts implemented over a Doctrine QueryBuilder.
  SearchTerms/    — turns a pimbay/search-query ParsedSearchTerms into andWhere() conditions on a QueryBuilder.
```

Namespace mirrors directory 1:1: `PimBay\SearchQuery\Doctrine\...` → `src/...`.

## Public library mode

Always applies — every repo here is published on Packagist. Every exported-symbol change is a public API decision.

- **Always ask before**: new `composer.json` dep, changing a public signature, new architectural pattern, touching >1 package at once.
- **Never without instruction**: delete a public class/file, rename an exported symbol, break wire/schema compatibility, add a build-affecting dev dependency.
- Two valid approaches → present both, no silent pick.
- Multi-file change → list files, confirm scope, then proceed.

## Testing

- **PHPUnit 11**, `tests/Unit/` + `tests/Functional/` (real SQLite in-memory connection) — mirrors `src/` 1:1.
- **`tests/Unit/`** — every collaborator faked (a fake/mock `QueryBuilder`), or the module has no external collaborator (`SqlHelper`).
- **`tests/Functional/`** — runs against a real SQLite in-memory `Connection`/`EntityManager` — don't mock what it can spin up for real.
- **Coverage: 100%** — hard gate; a dropped coverage change comes with new tests, not an exclusion.
- **Mutation testing: Infection, min MSI 100%** (`composer test:mutation`) — an escaped mutant needs a stronger assertion, not a suppressed mutator.
- **`#[Test]` attribute**, not `test`-prefix. `#[DataProvider('methodName')]` for parameterized cases.

## Guardrails

- No new `composer.json` deps without proposing them explicitly.
- Targeted diffs — don't rewrite a file for a small fix.
- No unrequested docs/test scaffolding.
- Don't introduce a DI container, config loader, or logging framework — flag the need, don't silently add.
- Domain-vocabulary vs local-shape placement unclear → ask, don't guess.
- New failure case → check for an existing exception (named constructor) before adding one.
