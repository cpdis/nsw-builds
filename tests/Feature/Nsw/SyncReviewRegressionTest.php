<?php

namespace Tests\Feature\Nsw;

use App\Jobs\SyncNswFeedPage;
use App\Models\Application;
use App\Models\Builder;
use App\Models\SyncRun;
use App\Sources\Nsw\ApplicationUpserter;
use App\Sources\Nsw\BuilderNameClassifier;
use App\Sources\Nsw\EplanningClient;
use App\Sources\Nsw\Feed;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Regression tests for the pstack /interrogate review of PR #1. Each one
 * failed on 1f18d7a and pins the behaviour the sync is meant to have.
 */
class SyncReviewRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function row(string $pan, array $extra = []): array
    {
        return array_replace([
            'PlanningPortalApplicationNumber' => $pan,
            'ApplicationStatus' => 'Determined',
            'DateLastUpdated' => '2022-11-19T19:18:15.467',
            'DeterminationDate' => '2023-06-01',
            'Council' => ['CouncilName' => 'Sutherland Shire Council'],
            'Location' => [[
                'FullAddress' => '129 HALL DRIVE MENAI 2234',
                'Suburb' => 'MENAI', 'Postcode' => '2234',
                'X' => '151.004799033', 'Y' => '-34.020673514',
            ]],
        ], $extra);
    }

    private function envelope(array $rows, int $total, int $pageSize, int $page = 1): array
    {
        return [
            'PageSize' => $pageSize, 'PageNumber' => $page,
            'TotalPages' => (int) ceil($total / $pageSize), 'TotalCount' => $total,
            'Application' => $rows,
        ];
    }

    /** F1: `--now` only runs page 1 inline when QUEUE_CONNECTION=database (the .env.example default). */
    public function test_now_flag_processes_every_page_inline_on_the_default_database_queue(): void
    {
        config(['queue.default' => 'database', 'nsw.page_size' => 2, 'nsw.page_delay_ms' => 0]);

        Http::fake(['*/OnlineCC' => Http::sequence()
            ->push($this->envelope([$this->row('CFT-1'), $this->row('CFT-2')], 3, 2, 1))
            ->push($this->envelope([$this->row('CFT-3')], 3, 2, 2)),
        ]);

        $this->artisan('nsw:sync', ['feed' => ['cc'], '--since' => '2026-11-20', '--now' => true])->assertSuccessful();

        $this->assertSame(
            ['applications' => 3, 'queued_jobs' => 0, 'run_finished' => true],
            [
                'applications' => Application::count(),
                'queued_jobs' => DB::table('jobs')->count(),
                'run_finished' => SyncRun::first()->finished_at !== null,
            ],
        );
    }

    /** F2b: with the sync driver the 2 s pause between pages is silently dropped. */
    public function test_now_flag_keeps_the_pause_between_pages(): void
    {
        config(['queue.default' => 'sync', 'nsw.page_size' => 1, 'nsw.page_delay_ms' => 2000]);
        Sleep::fake();
        Http::fake(['*/OnlineCC' => Http::sequence()
            ->push($this->envelope([$this->row('CFT-1')], 3, 1, 1))
            ->push($this->envelope([$this->row('CFT-2')], 3, 1, 2))
            ->push($this->envelope([$this->row('CFT-3')], 3, 1, 3)),
        ]);

        $t = microtime(true);
        $this->artisan('nsw:sync', ['feed' => ['cc'], '--since' => '2026-11-20', '--now' => true])->assertSuccessful();
        $elapsed = microtime(true) - $t;

        $slept = 0;
        foreach ((new \ReflectionProperty(Sleep::class, 'sequence'))->getValue() as $d) {
            $slept += $d->totalSeconds;
        }

        $this->assertSame(3, Http::recorded()->count());
        $this->assertGreaterThanOrEqual(4.0, $elapsed + $slept, sprintf('3 pages fetched in %.2f s wall clock, %d s slept', $elapsed, $slept));
    }

    /** F3: a narrow backfill (or any filtered run) moves the incremental watermark for the whole feed. */
    public function test_filtered_backfill_does_not_advance_the_incremental_watermark(): void
    {
        Http::fake(['*/OnlineCC' => Http::response($this->envelope([], 0, 1000))]);

        // Last good full incremental run was 10 days ago.
        $lastGood = CarbonImmutable::now()->subDays(10);
        SyncRun::create(['feed' => 'cc', 'filters' => ['ApplicationLastUpdatedFrom' => '2019-01-01'],
            'incremental' => true, 'started_at' => $lastGood, 'finished_at' => $lastGood]);

        // Today someone backfills one council's 2023 lodgements.
        $this->artisan('nsw:sync', ['feed' => ['cc'], '--from' => '2023-01-01', '--to' => '2023-12-31',
            '--council' => ['CITY OF PARRAMATTA'], '--now' => true])->assertSuccessful();

        // Tonight's incremental run.
        $this->artisan('nsw:sync', ['feed' => ['cc'], '--now' => true])->assertSuccessful();

        $sent = json_decode(Http::recorded()->last()[0]->header('filters')[0], true)['filters'];

        $this->assertSame(
            ['ApplicationLastUpdatedFrom' => $lastGood->subDays(2)->timezone('Australia/Sydney')->toDateString()],
            $sent,
        );
    }

    /** F4: Location[].Lot[].Section (a separate field in the real feed) is dropped from lot keys. */
    public function test_lot_section_from_the_feed_is_kept_in_lot_keys(): void
    {
        $da = fn (string $pan, string $section) => $this->row($pan, ['Location' => [[
            'FullAddress' => '2 MARCUS PLACE FRENCHS FOREST 2086',
            'Lot' => [['Lot' => '44', 'Section' => $section, 'PlanLabel' => 'DP235700']],
        ]]]);

        (new ApplicationUpserter)->upsert(Feed::DA, [$da('PAN-1', '4'), $da('PAN-2', '7')]);

        $keys = collect(DB::select('SELECT pan, lot_keys::text AS lots FROM applications ORDER BY pan'))->pluck('lots', 'pan')->all();

        $this->assertSame(['PAN-1' => '{44/4/DP235700}', 'PAN-2' => '{44/7/DP235700}'], $keys);
    }

    /** F5: source_updated_at is written as Sydney wall-clock into a column every reader treats as UTC. */
    public function test_source_updated_at_round_trips_to_the_same_instant(): void
    {
        $sydneyNow = CarbonImmutable::now('Australia/Sydney')->startOfSecond();

        (new ApplicationUpserter)->upsert(Feed::CC, [
            $this->row('CFT-1', ['DateLastUpdated' => $sydneyNow->format('Y-m-d\TH:i:s')]),
        ]);

        $app = Application::first();

        $this->assertSame(
            $sydneyNow->utc()->toDateTimeString(),
            $app->source_updated_at->utc()->toDateTimeString(),
            'hours in the future: '.round(now()->diffInMinutes($app->source_updated_at) / 60, 1),
        );
    }

    /** F6: one out-of-range value rolls back the whole 1,000-row page and stops the feed's chain. */
    #[DataProvider('poisonRows')]
    public function test_one_bad_row_does_not_lose_the_rest_of_the_page(array $poison): void
    {
        config(['nsw.page_size' => 3, 'nsw.page_delay_ms' => 0]);
        Http::fake(['*/OnlineCC' => Http::sequence()
            ->push($this->envelope([$this->row('CFT-1'), $this->row('CFT-2', $poison), $this->row('CFT-3')], 4, 3, 1))
            ->push($this->envelope([$this->row('CFT-4')], 4, 3, 2)),
        ]);

        $error = null;
        try {
            $this->artisan('nsw:sync', ['feed' => ['cc'], '--since' => '2026-11-20', '--now' => true])->run();
        } catch (\Throwable $e) {
            $error = substr($e->getMessage(), 0, 90);
        }

        // The out-of-range field is nulled; every row is kept and paging carries on.
        $this->assertSame(
            ['error' => null, 'stored' => 4, 'pages_fetched' => 2],
            ['error' => $error, 'stored' => Application::count(), 'pages_fetched' => Http::recorded()->count()],
        );
    }

    public static function poisonRows(): array
    {
        return [
            'storeys over smallint' => [['StoreysProposed' => '40000']],
            'units over int' => [['UnitsProposed' => '3000000000']],
            'floor area over numeric(12,2)' => [['ProposedGrossFloorArea' => 12345678901.0]],
            'address over 255 chars' => [['Location' => [['FullAddress' => str_repeat('LOT 1 DP123456 ', 20).'1 HALL DRIVE MENAI 2234']]]],
        ];
    }

    /** A 4xx is not retried: asking again will not change the answer. */
    public function test_client_does_not_retry_a_400(): void
    {
        Sleep::fake();
        Http::fake(['*/OnlineCC' => Http::response(['message' => 'Required parameters not met'], 400)]);

        try {
            EplanningClient::fromConfig()->page(Feed::CC, 1, 1000, []);
            $this->fail('a 400 must throw');
        } catch (RequestException) {
        }

        $this->assertSame(1, Http::recorded()->count());
        Sleep::assertNeverSlept();
    }

    /** A 503 is still retried. */
    public function test_client_retries_a_503(): void
    {
        Sleep::fake();
        Http::fake(['*/OnlineCC' => Http::sequence()
            ->push('busy', 503)
            ->push($this->envelope([$this->row('CFT-1')], 1, 1000))]);

        $page = EplanningClient::fromConfig()->page(Feed::CC, 1, 1000, []);

        $this->assertCount(1, $page->records);
        $this->assertSame(2, Http::recorded()->count());
    }

    /** F7b: worst-case retry budget of one page fetch must fit inside the job's 85 s timeout. */
    public function test_http_retry_budget_fits_inside_the_job_timeout(): void
    {
        Sleep::fake();
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('cURL error 28: Operation timed out after 60001 milliseconds');
        });

        try {
            EplanningClient::fromConfig()->page(Feed::CC, 1, 1000, []);
        } catch (\Throwable) {
        }

        $slept = 0;
        Sleep::assertSlept(function ($duration) use (&$slept) {
            $slept += $duration->totalSeconds;

            return true;
        }, $attempts - 1);

        $worstCase = $attempts * config('nsw.timeout') + (int) $slept;
        $jobTimeout = (new SyncNswFeedPage(Feed::CC, [], 1, 1))->timeout;

        $this->assertLessThanOrEqual($jobTimeout, $worstCase, "attempts={$attempts}, slept={$slept}s, per-request timeout=".config('nsw.timeout').'s');
    }

    /** F8: a 200 JSON body that is not the envelope is read as "empty last page" and the run is marked good. */
    public function test_non_envelope_200_body_is_not_recorded_as_a_successful_empty_run(): void
    {
        Http::fake(['*/OnlineCC' => Http::response(['message' => 'Required parameters not met'], 200)]);

        $this->artisan('nsw:sync', ['feed' => ['cc'], '--now' => true])->assertFailed();

        $run = SyncRun::first();
        $this->assertNull($run->finished_at);
        $this->assertStringContainsString('Unexpected eplanning response', $run->error);
    }

    /** F9: names of natural persons that the classifier publishes as companies. */
    #[DataProvider('personNames')]
    public function test_person_names_are_suppressed(string $name): void
    {
        (new ApplicationUpserter)->upsert(Feed::CC, [$this->row('CFT-1', ['BuilderLegalName' => $name])]);

        $this->assertSame(
            ['is_company' => false, 'publishable' => 0],
            ['is_company' => BuilderNameClassifier::isCompany($name), 'publishable' => Builder::publishable()->count()],
        );
    }

    public static function personNames(): array
    {
        return [
            ['JOHN SMITH AS TRUSTEE FOR THE SMITH FAMILY TRUST'],
            ['John Smith ATF J & M Smith Family Trust'],
            ['JOHN SMITH T/AS SMITH BUILDING GROUP'],
            ['JOHN SMITH - TRUSTEE'],
            ['The Trustee for J & M Smith Family Trust'],
            ['D LYMBERY TRUSTEE'],
            ['SAMUEL GROSSER T/A GROSSER BUILDING GROUP'],
        ];
    }

    /** A company acting as trustee, or trading under another name, is still a company. */
    #[DataProvider('companyNames')]
    public function test_companies_acting_as_trustee_are_still_published(string $name): void
    {
        $this->assertTrue(BuilderNameClassifier::isCompany($name), $name);
    }

    public static function companyNames(): array
    {
        return [
            ['SMITH CONSTRUCTIONS PTY LTD ATF SMITH FAMILY TRUST'],
            ['Acme Building Pty Ltd as trustee for the Acme Unit Trust'],
            ['HUTCHINSON BUILDERS PTY LTD T/A HUTCHINSON'],
        ];
    }

    /** TotalPages ends the run; an exactly-full last page must not cost an extra request. */
    public function test_paging_stops_at_total_pages(): void
    {
        config(['nsw.page_size' => 2, 'nsw.page_delay_ms' => 0]);
        Http::fake(['*/OnlineCC' => Http::response($this->envelope([$this->row('CFT-1'), $this->row('CFT-2')], 2, 2, 1))]);

        $this->artisan('nsw:sync', ['feed' => ['cc'], '--since' => '2026-11-20', '--now' => true])->assertSuccessful();

        $this->assertSame(1, Http::recorded()->count());
        $this->assertNotNull(SyncRun::first()->finished_at);
    }

    /** Builders are created outside the page transaction, so parallel feeds can't deadlock on them. */
    public function test_builders_are_created_before_the_page_transaction(): void
    {
        [$builderLevels, $applicationLevels] = [[], []];
        Builder::creating(function () use (&$builderLevels) {
            $builderLevels[] = DB::transactionLevel();
        });
        Application::creating(function () use (&$applicationLevels) {
            $applicationLevels[] = DB::transactionLevel();
        });

        (new ApplicationUpserter)->upsert(Feed::CC, [
            $this->row('CFT-1', ['BuilderLegalName' => 'BETA BUILD PTY LTD']),
            $this->row('CFT-2', ['BuilderLegalName' => 'ALPHA BUILD PTY LTD']),
        ]);

        $this->assertLessThan(min($applicationLevels), max($builderLevels));
        $this->assertSame(['ALPHA BUILD PTY LTD', 'BETA BUILD PTY LTD'], Builder::orderBy('id')->pluck('name_key')->all());
    }
}
