<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Only the scheduled, unfiltered run may move the incremental watermark. A
 * backfill or a council-scoped run finishing must not skip other councils.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_runs', function (Blueprint $table) {
            $table->boolean('incremental')->default(false)->after('filters');
            $table->index(['feed', 'incremental', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::table('sync_runs', function (Blueprint $table) {
            $table->dropIndex(['feed', 'incremental', 'started_at']);
            $table->dropColumn('incremental');
        });
    }
};
