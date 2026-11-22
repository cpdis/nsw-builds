<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per Planning Portal application number (PAN) per feed.
     * The raw record is kept so mappings can be re-derived without re-fetching.
     */
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->string('feed', 8);                 // da | cdc | cc | oc
            $table->string('pan', 64);                 // PlanningPortalApplicationNumber
            $table->string('application_type')->nullable();
            $table->string('status')->nullable();
            $table->string('council_name')->nullable()->index();
            $table->jsonb('development_types')->nullable();
            $table->jsonb('building_classes')->nullable();

            $table->date('lodged_at')->nullable();
            $table->date('determined_at')->nullable();
            $table->date('exhibition_starts_at')->nullable();
            $table->date('exhibition_ends_at')->nullable();
            $table->timestamp('source_updated_at')->nullable(); // DateLastUpdated

            $table->decimal('cost', 15, 2)->nullable();
            $table->unsignedSmallInteger('storeys')->nullable();
            $table->unsignedInteger('units')->nullable();
            $table->decimal('gross_floor_area', 12, 2)->nullable();

            $table->foreignId('builder_id')->nullable()->constrained()->nullOnDelete();

            // Explicit parent reference (DA / consent / CC number), if the feed carries one.
            $table->string('parent_reference')->nullable()->index();

            $table->text('address')->nullable();
            $table->string('address_key')->nullable()->index(); // normalised, for linker rule 3
            $table->string('suburb')->nullable();
            $table->string('postcode', 4)->nullable();

            $table->jsonb('raw');
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['feed', 'pan']);
            $table->index(['feed', 'determined_at']);
        });

        // Normalised "LOT/PLAN" keys, e.g. {"12/DP123456"}: linker rule 2.
        DB::statement("ALTER TABLE applications ADD COLUMN lot_keys text[] NOT NULL DEFAULT '{}'");
        DB::statement('ALTER TABLE applications ADD COLUMN geom geometry(Point, 4326)');
        DB::statement('CREATE INDEX applications_lot_keys_gin ON applications USING gin (lot_keys)');
        DB::statement('CREATE INDEX applications_geom_gist ON applications USING gist (geom)');
    }

    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};
