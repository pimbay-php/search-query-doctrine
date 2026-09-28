# Context

> Working memory, not a historical record.
> Continuously edited, not append-only — unlike DECISIONS.md.
> When something here resolves: delete it if it was only ever local/temporary, or promote it to DECISIONS.md if it turned out to matter beyond this moment.
> Don't let resolved items pile up here.

## Current focus

Nothing in progress right now.

## Open questions

Nothing open.

## Known limitations / non-goals (for now)

- No `CursorAdapter` implementation.
- No aggregation-composition helper (e.g. pagination + a `GROUP BY` breakdown in one response) — deliberately out of scope for this package.
- `count()` assumes the consumer's QueryBuilder is in an unambiguously pageable shape. A `GROUP BY`, `HAVING` or `DISTINCT` is not: the count replaces the select list and is applied after those clauses, so the number no longer describes the rows a page returns. Getting the query into a pageable shape is the caller's job, not something this package detects.

## Implementation notes

- The ORM path masks a malformed `ESCAPE` literal: Doctrine's `SqlWalker` re-quotes DQL string literals through the platform, so only the DBAL `QueryBuilder` sends the clause verbatim. Reproduce escaping bugs against DBAL, not ORM.

## Ideas / future plans

- A `CursorAdapter` implementation once its constructor shape (explicit keyset column(s)) is resolved.
