# Third-Party Notices

This project itself is released under [The Unlicense](../LICENSE).
It bundles or invokes the following third-party software, each under its own license.

## Composer dependencies

Full list with versions and licenses: run `composer licenses` — don't hand-maintain a duplicate of `composer.json`/`composer.lock` here.
Every dependency currently resolved is verified directly against each package's own `composer.json` `license` field, not assumed; none of it is copyleft.

Direct runtime dependencies — listed even though none carry an attribution requirement, so a reader doesn't have to run the tool just to see there's nothing unusual here:

| Package                   | License   | Note                                            |
|---------------------------|-----------|-------------------------------------------------|
| `doctrine/dbal`           | MIT       | Runtime dependency — required directly.         |
| `doctrine/orm`            | MIT       | Optional — only needed for the `Orm*` adapters. |
| `pimbay/search-query` | Unlicense | -                                               |

## Notes

This is not legal advice.
