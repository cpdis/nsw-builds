# NSW Builds

A map of NSW development activity (DA, CDC, CC, OC) built on Laravel 12 + Inertia React, Postgres 16 + PostGIS.

## Workflow: pstack

This repo uses [pstack](https://github.com/cursor/plugins/tree/main/pstack). `.claude/settings.json` adds the `cursor-plugins` marketplace and enables `pstack@cursor-plugins`; Claude Code offers to install it when you trust the folder.

- Start any non-trivial task with `/pstack:poteto-mode`. It picks a playbook (feature, bug fix, investigation, opening a PR, ...) and runs the other skills.
- Review a diff with `/pstack:interrogate`. Findings count only if a command reproduces them.
- Model choices per role live in `.claude/rules/pstack-models.md`.
- Steps that need Cursor-only tools (Bugbot, Automations, `cursor-team-kit`'s `/deslop`, `control-ui`) are skipped with a one-line note, per pstack's Claude Code adapter.
- If the plugin is not installed in a session, read the skill directly from `https://github.com/cursor/plugins/tree/main/harness/pstack/claude-code/skills/<name>/SKILL.md`.

## Project facts

- `api.apps1.nsw.gov.au` (eplanning feeds) is reachable only from Colin's Mac. Cloud sandboxes get a proxy 403. Run spike scripts and `nsw:sync` against the live API there.
- Local checkout on the Mac: `/Users/colin/Documents/Personal Projects/nsw-builds`.
- Tests need Postgres with PostGIS (`php artisan test`). CI uses `postgis/postgis:16-3.4`.
- Data spike findings: `docs/data-spike.md`. Hosting notes: `docs/hosting.md`.
