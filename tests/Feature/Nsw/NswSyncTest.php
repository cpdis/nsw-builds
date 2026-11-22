<?php

namespace Tests\Feature\Nsw;

use App\Jobs\SyncNswFeedPage;
use App\Models\Application;
use App\Models\Builder;
use App\Models\SyncRun;
use App\Sources\Nsw\EplanningClient;
use App\Sources\Nsw\Feed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use Tests\TestCase;

class NswSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        config(['nsw.page_delay_ms' => 0]);
    }

    private function cc(string $pan, string $builder = 'Acme Constructions Pty Ltd'): array
    {
        return [
            'PlanningPortalApplicationNumber' => $pan,
            'ApplicationStatus' => 'Determined',
            'BuilderLegalName' => $builder,
            'StoreysProposed' => '6',
            'UnitsProposed' => 48,
            'DeterminationDate' => '2023-06-14',
            'DateLastUpdated' => '2022-11-19T19:18:15.467',
            'Council' => ['CouncilName' => 'CITY OF PARRAMATTA'],
            'Location' => [[
                'FullAddress' => '57 Station Road, SEVEN HILLS NSW 2147',
                'Suburb' => 'SEVEN HILLS', 'Postcode' => '2147',
                'X' => '150.9363', 'Y' => '-33.7745',
                'Lot' => [['Lot' => '12', 'PlanLabel' => 'DP123456']],
            ]],
        ];
    }

    /** The real envelope, as seen in the spike. */
    private function envelope(array $rows, int $page, int $totalPages, ?int $total = null): array
    {
        return ['PageSize' => 2, 'PageNumber' => $page, 'TotalPages' => $totalPages, 'TotalCount' => $total ?? count($rows), 'Application' => $rows];
    }

    public function test_sync_sends_header_driven_request_and_stores_records(): void
    {
        config(['nsw.page_size' => 2]);

        Http::fake([
            '*/OnlineCC' => Http::sequence()
                ->push($this->envelope([$this->cc('PAN-1'), $this->cc('PAN-2', 'John Smith')], 1, 2, 3))
                ->push($this->envelope([$this->cc('PAN-3', 'ACME CONSTRUCTIONS PTY. LIMITED')], 2, 2, 3)),
        ]);

        $this->artisan('nsw:sync', ['feed' => ['cc'], '--since' => '2026-11-20', '--now' => true])->assertSuccessful();

        Http::assertSent(fn (Request $r) => $r->hasHeader('PageSize', '2')
            && $r->hasHeader('PageNumber', '1')
            && json_decode($r->header('filters')[0], true) === ['filters' => ['ApplicationLastUpdatedFrom' => '2026-11-20']]);
        Http::assertSentCount(2);

        $this->assertSame(3, Application::where('feed', 'cc')->count());
        $app = Application::where('pan', 'PAN-1')->first();
        $this->assertSame(6, $app->storeys);
        $this->assertSame('2023-06-14', $app->determined_at->toDateString());

        $row = DB::selectOne("SELECT lot_keys::text AS lots, ST_X(geom) AS lng, source_updated_at::text AS updated FROM applications WHERE pan = 'PAN-1'");
        $this->assertSame('{12/DP123456}', $row->lots);
        $this->assertEqualsWithDelta(150.9363, $row->lng, 1e-6);
        $this->assertStringStartsWith('2022-11-19 08:18:15', $row->updated); // UTC, not Sydney wall-clock

        // Suffix variants merge into one builder; the individual is suppressed.
        $this->assertSame(1, Builder::publishable()->count());
        $this->assertTrue(Builder::where('legal_name', 'John Smith')->first()->suppressed);

        $run = SyncRun::first();
        $this->assertSame([2, 3, 3, SyncRun::SCOPED], [$run->pages, $run->records, $run->total_count, $run->kind]);
        $this->assertNotNull($run->finished_at);
    }

    public function test_now_runs_every_page_inline_even_with_a_real_queue(): void
    {
        config(['nsw.page_size' => 1, 'queue.default' => 'database']);

        Http::fake([
            '*/OnlineCC' => Http::sequence()
                ->push($this->envelope([$this->cc('PAN-1')], 1, 3))
                ->push($this->envelope([$this->cc('PAN-2')], 2, 3))
                ->push($this->envelope([$this->cc('PAN-3')], 3, 3)),
        ]);

        $this->artisan('nsw:sync', ['feed' => ['cc'], '--now' => true])->assertSuccessful();

        Http::assertSentCount(3);
        $this->assertSame(3, Application::count());
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertNotNull(SyncRun::first()->finished_at);
        Sleep::assertSleptTimes(2);
    }

    public function test_queued_sync_dispatches_one_job_per_page(): void
    {
        Queue::fake();

        $this->artisan('nsw:sync', ['feed' => ['cc', 'oc']])->assertSuccessful();

        Queue::assertPushed(SyncNswFeedPage::class, 2);
        Http::assertNothingSent();
    }

    public function test_stops_on_total_pages_even_when_last_page_is_full(): void
    {
        config(['nsw.page_size' => 2]);
        Http::fake(['*/OnlineCC' => Http::response($this->envelope([$this->cc('PAN-1'), $this->cc('PAN-2')], 1, 1))]);

        $this->artisan('nsw:sync', ['feed' => ['cc'], '--now' => true])->assertSuccessful();

        Http::assertSentCount(1);
    }

    public function test_unrecognised_body_fails_the_run_instead_of_finishing_it(): void
    {
        Http::fake(['*/OnlineCC' => Http::response(['Message' => 'Required parameters not met'])]);

        $this->artisan('nsw:sync', ['feed' => ['cc'], '--now' => true])->assertFailed();

        $run = SyncRun::first();
        $this->assertNull($run->finished_at);
        $this->assertStringContainsString('Unexpected eplanning response', $run->error);
    }

    public function test_scoped_runs_do_not_move_the_nightly_watermark(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        SyncRun::create(['feed' => 'cc', 'kind' => SyncRun::INCREMENTAL, 'filters' => [], 'started_at' => now()->subDays(10), 'finished_at' => now()->subDays(10)]);
        SyncRun::create(['feed' => 'cc', 'kind' => SyncRun::SCOPED, 'filters' => [], 'started_at' => now()->subHour(), 'finished_at' => now()]);

        Http::fake(['*/OnlineCC' => Http::response($this->envelope([], 1, 0))]);

        $this->artisan('nsw:sync', ['feed' => ['cc'], '--now' => true])->assertSuccessful();

        // Last incremental run (12 Nov) minus 2 days, in Sydney time.
        Http::assertSent(fn (Request $r) => json_decode($r->header('filters')[0], true)['filters']['ApplicationLastUpdatedFrom'] === '2026-11-10');
        $this->assertSame(SyncRun::INCREMENTAL, SyncRun::latest('id')->first()->kind);
    }

    public function test_one_bad_record_is_skipped_not_fatal(): void
    {
        $bad = $this->cc('PAN-BAD');
        $bad['Junk'] = "a\u{0}b"; // NUL: Postgres jsonb rejects \u0000, so this row's insert fails

        Http::fake(['*/OnlineCC' => Http::response($this->envelope([$this->cc('PAN-1'), $bad, $this->cc('PAN-3')], 1, 1))]);

        $this->artisan('nsw:sync', ['feed' => ['cc'], '--now' => true])->assertSuccessful();

        $this->assertEqualsCanonicalizing(['PAN-1', 'PAN-3'], Application::pluck('pan')->all());
        $run = SyncRun::first();
        $this->assertSame([2, 1], [$run->records, $run->skipped]);
        $this->assertNotNull($run->finished_at);
    }

    public function test_client_does_not_retry_client_errors(): void
    {
        Http::fake(['*/OnlineCC' => Http::response('bad request', 400)]);

        try {
            app(EplanningClient::class)->page(Feed::CC, 1, 10);
            $this->fail('Expected a RequestException');
        } catch (RequestException $e) {
            $this->assertSame(400, $e->response->status());
        }

        Http::assertSentCount(1);
    }

    public function test_client_retries_server_errors_within_budget(): void
    {
        Http::fake(['*/OnlineCC' => Http::sequence()->push('down', 503)->push($this->envelope([], 1, 0))]);

        $page = app(EplanningClient::class)->page(Feed::CC, 1, 10);

        $this->assertTrue($page->isLast());
        Http::assertSentCount(2);
        $this->assertLessThan(85, config('nsw.timeout') * config('nsw.attempts') + 3);
    }

    public function test_client_rejects_filters_the_api_would_silently_ignore(): void
    {
        Http::fake();

        $this->expectException(InvalidArgumentException::class);
        app(EplanningClient::class)->page(Feed::CC, 1, 10, ['LodgmentDateFrom' => '2023-01-01']);
    }

    public function test_resync_upserts_on_pan(): void
    {
        Http::fake(['*/OnlineCC' => Http::response($this->envelope([$this->cc('PAN-1')], 1, 1))]);

        $this->artisan('nsw:sync', ['feed' => ['cc'], '--now' => true])->assertSuccessful();
        $this->artisan('nsw:sync', ['feed' => ['cc'], '--now' => true])->assertSuccessful();

        $this->assertSame(1, Application::count());
        $this->assertSame(1, Builder::count());
    }
}
