# Contributing

Contributions are welcome — new adapter methods, additional Doctrine version compatibility, bug fixes, documentation.

## Public Domain Dedication

By submitting a pull request, you dedicate your contribution to the public domain under the same [Unlicense](LICENSE) terms as this project.
You assert that you have the right to make this dedication.

## Guidelines

- PHP 8.3+, `declare(strict_types=1)` on every file
- PHPStan level max, no errors, no baseline ignores
- 100% code coverage required
- 100% mutation score required (`composer test:mutation`, Infection — min MSI 100%, min covered MSI 100%); an escaped mutant means the test needs a stronger assertion, not a suppressed mutator
- Any change to `Adapter/DbalSimpleAdapter.php` or `Adapter/OrmSimpleAdapter.php` must keep both classes behaviorally symmetric — the same method set, the same edge-case behavior (empty result, `hasMore` at the exact boundary) — even though the underlying Doctrine APIs differ; same for `Adapter/DbalIdentityAdapter.php` and `Adapter/OrmIdentityAdapter.php`
- A new adapter capability that needs a field name (like `DbalIdentityAdapter`'s `idField`) gets its own class extending `DbalSimpleAdapter`, not a new constructor parameter on `DbalSimpleAdapter` itself
- New Doctrine DBAL/ORM version support means adding a combo to `docker-compose.yml`, `composer.json`'s `test:*` scripts, and `.github/workflows/ci.yml`'s matrix — not just widening the `composer.json` version constraint
