<?php

namespace Tests\Feature\Nsw;

use App\Models\Application;
use App\Models\Builder;
use App\Models\SyncRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NswSyncTest extends TestCase
{
    use RefreshDatabase;

    private function cc(string $pan, string $builder): array
    {
        return [
            'PlanningPortalApplicationNumber' => $pan,
            'ApplicationStatus' => 'Determined',
            'BuilderLegalName' => $builder,
            'StoreysProposed' => '6',
            'UnitsProposed' => 48,
            'DeterminationDate' => '14/06/2023',
            'Council' => ['CouncilName' => 'CITY OF PARRAMATTA'],
            'Location' => [[
                'FullAddress' => '57 Station Road, SEVEN HILLS NSW 2147',
                'Suburb' => 'SEVEN HILLS', 'Postcode' => '2147',
                'X' => '150.9363', 'Y' => '-33.7745',
                'Lot' => [['Lot' => '12', 'PlanLabel' => 'DP123456']],
            ]],
        ];
    }

    public function test_sync_sends_header_driven_request_and_stores_records(): void
    {
        config(['nsw.page_size' => 2, 'nsw.page_delay_ms' => 0]);

        Http::fake([
            '*/OnlineCC' => Http::sequence()
                ->push(['TotalPages' => 2, 'Application' => [$this->cc('PAN-1', 'Acme Constructions Pty Ltd'), $this->cc('PAN-2', 'John Smith')]])
                ->push(['TotalPages' => 2, 'Application' => [$this->cc('PAN-3', 'ACME CONSTRUCTIONS PTY. LIMITED')]]),
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

        $row = DB::selectOne("SELECT lot_keys::text AS lots, ST_X(geom) AS lng FROM applications WHERE pan = 'PAN-1'");
        $this->assertSame('{12/DP123456}', $row->lots);
        $this->assertEqualsWithDelta(150.9363, $row->lng, 1e-6);

        // Suffix variants merge into one builder; the individual is suppressed.
        $this->assertSame(1, Builder::publishable()->count());
        $this->assertTrue(Builder::where('legal_name', 'John Smith')->first()->suppressed);

        $run = SyncRun::first();
        $this->assertSame(2, $run->pages);
        $this->assertSame(3, $run->records);
        $this->assertNotNull($run->finished_at);
    }

    public function test_resync_upserts_on_pan(): void
    {
        config(['nsw.page_size' => 10]);
        Http::fake(['*/OnlineCC' => Http::response(['TotalPages' => 1, 'Application' => [$this->cc('PAN-1', 'Acme Pty Ltd')]])]);

        $this->artisan('nsw:sync', ['feed' => ['cc'], '--now' => true])->assertSuccessful();
        $this->artisan('nsw:sync', ['feed' => ['cc'], '--now' => true])->assertSuccessful();

        $this->assertSame(1, Application::count());
    }
}
