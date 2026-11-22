# Hosting on Laravel Cloud

This guide sets up NSW Builds on Laravel Cloud from the dashboard. Do the PostGIS test in step 4 before you rely on anything else. Laravel Cloud docs were checked on 22 Nov 2026.

## What you need

- Admin access to the `cpdis/nsw-builds` GitHub repo.
- The Flux Pro username (your Flux account email) and the licence key named "NSW Builds".
- A Postgres client on the Mac, such as TablePlus or `psql`.

## 1. Create the application

1. In Laravel Cloud, click **New application**.
2. Link GitHub if Cloud asks, then select `cpdis/nsw-builds`.
3. Name the application `nsw-builds`.
4. Select the region **Asia Pacific (Sydney)**, `ap-southeast-2`. Cloud now lists Sydney. If it is missing from the list in your account, select **Asia Pacific (Singapore)**, `ap-southeast-1`.
5. Open the default environment's **Settings** tab and set the branch to `main`.

The database must be in the same region as the app cluster, so pick the region once.

## 2. Add Postgres

1. In the environment canvas, click **Add database**.
2. Create a cluster of type **Laravel Serverless Postgres** in the same region. The smallest preset is enough to start.
3. Create a database in that cluster and attach it to the environment.

Cloud injects `DB_HOST`, `DB_USERNAME`, `DB_PASSWORD`, `DB_DATABASE`, and the other connection variables. Leave `DB_CONNECTION=pgsql`.

## 3. Set environment variables

Open the environment's **Settings** tab, then the environment variables section. Add these variables:

| Variable | Value |
| --- | --- |
| `FLUX_USERNAME` | Your Flux account email |
| `FLUX_LICENSE_KEY` | The "NSW Builds" licence key |
| `QUEUE_CONNECTION` | `database` |
| `CACHE_STORE` | `database` |
| `SESSION_DRIVER` | `database` |
| `APP_TIMEZONE` | `UTC` |

Never commit the Flux credentials. Store them only in Cloud, in GitHub repo secrets for CI, and in the gitignored `auth.json` on the Mac. Cloud makes environment variables available to build commands. Changes to variables take effect on the next deploy.

## 4. Test PostGIS before the first deploy

The first migration, `2026_11_22_000100_enable_postgis.php`, runs `CREATE EXTENSION postgis` and `pg_trgm`. The Cloud docs mention only pgvector by name, so test PostGIS first.

1. On the database cluster, click **View credentials**, then **Open in database client**. You can also paste the credentials into `psql`.
2. Run this SQL:

	```sql
	CREATE EXTENSION IF NOT EXISTS postgis;
	CREATE EXTENSION IF NOT EXISTS pg_trgm;
	SELECT postgis_full_version();
	```

3. If the last query returns a version string, go to step 5.

### If PostGIS fails

Use an external Postgres in Sydney instead of the Cloud database.

1. Create a database with PostGIS in Sydney (`ap-southeast-2`) on one of these providers:
	- **Neon**. PostGIS is supported. The free tier is enough for the backfill measurement.
	- **Supabase**. Enable PostGIS in the dashboard extensions list.
2. Detach the Cloud database from the environment.
3. Add `DB_URL` to the environment variables, with `sslmode=require`. Laravel reads `DB_URL` in `config/database.php`.
4. Run the SQL from step 4 against that database.

## 5. Set build and deploy commands

Open the environment's **Settings** tab and replace the build commands with these commands:

```sh
composer config http-basic.composer.fluxui.dev "${FLUX_USERNAME}" "${FLUX_LICENSE_KEY}"
composer install --no-dev --prefer-dist --optimize-autoloader
npm ci --audit false
npm run build
php artisan optimize
```

`composer install` must run before `npm run build`, because the Vite build imports `flux.css` from `vendor/`.

Set the deploy commands to this command:

```sh
php artisan migrate --force
```

The Cloud docs say not to add `queue:restart`, `optimize:clear`, or `storage:link` here. Build and deploy commands each time out after 15 minutes.

## 6. Add the queue worker

`nsw:sync` queues one `SyncNswFeedPage` job per page. Each job queues the next page.

1. In the canvas, click the **App** cluster.
2. Under **Background processes**, click **New background process**.
3. Select the Laravel queue worker, with these options:
	- Connection `database`, queue `default`.
	- Processes `1`. One worker is enough for the nightly delta. Use up to `4` processes, one for each feed, if a run takes too long.
	- Timeout `85`, tries `3`. These values match the job's `$timeout` and `$tries`. The timeout must stay under `retry_after` (90 s in `config/queue.php`).
4. Turn **Scale to Zero** off on the App cluster. A sleeping environment stops `queue:work`, and queued jobs don't wake it.

### The 90 s job cap

Laravel Cloud **managed queues** on Flex compute stop a job after 90 seconds. The self-managed worker above has no fixed cap. The code still keeps each job under 90 s, so you can move to a managed queue later without code changes:

- One page per job (`app/Jobs/SyncNswFeedPage.php`, `$timeout = 85`).
- Worst-case HTTP time per page is 3 attempts of 20 s plus 3 s of backoff (`config/nsw.php`).

A managed queue sets `QUEUE_CONNECTION=cloud` and needs `aws/aws-sdk-php`. Don't switch now.

## 7. Turn on the scheduler

1. Click the **App** cluster and turn on **Scheduler**.
2. Save, then deploy.

Cloud runs `schedule:run` every minute. `routes/console.php` runs `nsw:sync` daily at 03:00 in `Australia/Sydney`, with `withoutOverlapping()` and `onOneServer()`. Both use the database cache store. Daylight saving is handled by the timezone on the schedule, so `APP_TIMEZONE` stays `UTC`.

## 8. Deploy and check

1. Click **Deploy**.
2. Open the **Commands** tab and run `php artisan migrate:status`. All migrations show **Ran**.
3. Run `php artisan schedule:list`. The `nsw:sync` entry shows the next run at 03:00 Sydney time.

## 9. Test whether Cloud can reach the NSW API

`api.apps1.nsw.gov.au` works from the Mac. No one has tested it from Laravel Cloud. Cloud has no static outbound IPs, so an IP allowlist on the NSW side can't fix a block.

1. In the **Commands** tab, run this command:

	```sh
	curl -sS -m 20 -o /dev/null -w '%{http_code}\n' -H 'PageSize: 1' -H 'PageNumber: 1' https://api.apps1.nsw.gov.au/eplanning/data/v0/OnlineCC
	```

	A `200` means Cloud can reach the API. A `403`, a timeout, or a connection error means it can't.

2. If you see `200`, run a small scoped sync inline. Scoped runs don't move the nightly watermark.

	```sh
	php artisan nsw:sync cc --since=2026-11-20 --now
	```

	Then check `sync_runs` and `applications` in the database client.

### If Cloud can't reach the API

1. Turn off **Scheduler** on the App cluster, so the 03:00 run doesn't fail every night.
2. Run the sync from the Mac against the production database. Use the credentials from **View credentials**, or `DB_URL`:

	```sh
	DB_URL='postgresql://...?sslmode=require' php artisan nsw:sync --now
	```

3. Schedule that command on the Mac with `launchd` or `cron` at 03:00 Sydney time.

## Backfills

Run backfills from the Mac with `--now`, not on Cloud. A full feed is about 1.34M rows at 3.5 s per 1,000-row page. The Commands tab stops commands after 30 minutes.

```sh
DB_URL='postgresql://...?sslmode=require' php artisan nsw:sync da --from=2023-01-01 --to=2023-12-31 --now
```
