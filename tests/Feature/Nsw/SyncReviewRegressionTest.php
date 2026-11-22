<?php

namespace Tests\Feature\Nsw;

use App\Sources\Nsw\ApplicationUpserter;
use App\Sources\Nsw\BuilderNameClassifier;
use App\Sources\Nsw\Feed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Cases from the pstack /interrogate review of PR #1 that NswSyncTest and
 * NormaliserTest do not already pin.
 */
class SyncReviewRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function row(string $pan, array $extra = []): array
    {
        return array_replace([
            'PlanningPortalApplicationNumber' => $pan,
            'ApplicationStatus' => 'Determined',
            'Council' => ['CouncilName' => 'Sutherland Shire Council'],
            'Location' => [['FullAddress' => '129 HALL DRIVE MENAI 2234', 'X' => '151.0048', 'Y' => '-34.0207']],
        ], $extra);
    }

    private function envelope(array $rows, int $page, int $totalPages): array
    {
        return ['PageSize' => 1, 'PageNumber' => $page, 'TotalPages' => $totalPages, 'TotalCount' => $totalPages, 'Application' => $rows];
    }

    /** `--now` keeps the pause between pages; the sync queue driver used to drop it. */
    public function test_now_keeps_the_pause_between_pages(): void
    {
        config(['nsw.page_size' => 1, 'nsw.page_delay_ms' => 2000]);
        Sleep::fake();
        Http::fake(['*/OnlineCC' => Http::sequence()
            ->push($this->envelope([$this->row('CFT-1')], 1, 3))
            ->push($this->envelope([$this->row('CFT-2')], 2, 3))
            ->push($this->envelope([$this->row('CFT-3')], 3, 3)),
        ]);

        $this->artisan('nsw:sync', ['feed' => ['cc'], '--since' => '2026-11-20', '--now' => true])->assertSuccessful();

        Http::assertSentCount(3);
        Sleep::assertSleptTimes(2);
        Sleep::assertSequence([Sleep::for(2000)->milliseconds(), Sleep::for(2000)->milliseconds()]);
    }

    /**
     * Parallel feeds share builders. Builder inserts must not run inside the
     * page transaction, or two workers deadlock (SQLSTATE 40P01, reproduced
     * with two processes in the review).
     */
    public function test_builders_are_written_outside_the_page_transaction(): void
    {
        $levels = ['builders' => [], 'applications' => []];
        DB::listen(function ($q) use (&$levels) {
            foreach (array_keys($levels) as $table) {
                if (preg_match("/^insert into \"{$table}\"/i", $q->sql)) {
                    $levels[$table][] = DB::transactionLevel();
                }
            }
        });

        (new ApplicationUpserter)->upsert(Feed::CC, [
            $this->row('CFT-1', ['BuilderLegalName' => 'BETA BUILD PTY LTD']),
            $this->row('CFT-2', ['BuilderLegalName' => 'ALPHA BUILD PTY LTD']),
        ]);

        $this->assertNotEmpty($levels['builders']);
        $this->assertNotEmpty($levels['applications']);
        $this->assertLessThan(min($levels['applications']), max($levels['builders']));
    }

    /** Extra names from the review beyond NormaliserTest::builderNames(). */
    #[DataProvider('names')]
    public function test_review_builder_names(string $name, bool $company): void
    {
        $this->assertSame($company, BuilderNameClassifier::isCompany($name), $name);
    }

    public static function names(): array
    {
        return [
            ['The Trustee for J & M Smith Family Trust', false],
            ['D LYMBERY TRUSTEE', false],
            ['JOHN SMITH - TRUSTEE', false],
            ['John Smith ATF J & M Smith Family Trust', false],
            ['JOHN SMITH T/AS SMITH BUILDING GROUP', false],
            ['SMITH CONSTRUCTIONS PTY LTD ATF SMITH FAMILY TRUST', true],
            ['Acme Building Pty Ltd as trustee for the Acme Unit Trust', true],
            ['HUTCHINSON BUILDERS PTY LTD T/A HUTCHINSON', true],
        ];
    }
}
