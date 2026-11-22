# Hosting: PostGIS on Laravel Cloud

**Status: unverified. Test on day one, before anything else is deployed.**

The migrations run `CREATE EXTENSION postgis` and `pg_trgm` (`2026_11_22_000100_enable_postgis.php`). Locally and in CI this works on Postgres 16 + PostGIS 3.

Laravel Cloud's Postgres ("Serverless Postgres") is Neon-based. Its docs (checked 22 Nov 2026) say only that it "supports a wide range of PostgreSQL extensions, including pgvector", with no extension list, no version list and no region list. PostGIS and the Sydney region are both unconfirmed. Neon itself supports PostGIS, so it is likely but not confirmed.

## Day-one test

1. Create a Laravel Cloud app in the Sydney region with a Postgres database.
2. In the database's SQL console (or `php artisan tinker` on Cloud): `CREATE EXTENSION IF NOT EXISTS postgis; SELECT postgis_full_version();`
3. If that works, deploy and run `php artisan migrate`.

## If it fails

Point `DB_URL` at an external Postgres that has PostGIS, in the same region (Sydney, `ap-southeast-2`):

- **Neon** directly: PostGIS is supported; free tier is enough for the backfill measurement.
- **Supabase**: PostGIS is a one-click extension.

Keep the backfill off Cloud either way: run `php artisan nsw:sync ... --now` from the Mac or GitHub Actions against the production database URL.
