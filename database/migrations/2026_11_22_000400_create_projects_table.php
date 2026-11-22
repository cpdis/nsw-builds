<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One project per site. Stage is derived from its events, never set by hand,
     * so a late-arriving OC fixes itself on the next sync. Projects are never deleted.
     */
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            // lodged | exhibition | approved | construction | complete
            $table->string('stage', 16)->index();
            $table->timestamp('stage_changed_at')->nullable();

            $table->text('address')->nullable();
            $table->string('suburb')->nullable()->index();
            $table->string('postcode', 4)->nullable();
            $table->string('council_name')->nullable()->index();

            // Denormalised headline figures for map popups and tiles.
            $table->decimal('cost', 15, 2)->nullable()->index();
            $table->unsignedSmallInteger('storeys')->nullable();
            $table->unsignedInteger('units')->nullable();
            $table->string('headline')->nullable(); // templated, e.g. "6-storey, 48-unit residential flat building, $22M"

            $table->date('first_event_at')->nullable();
            $table->date('last_event_at')->nullable()->index();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE projects ADD COLUMN lot_keys text[] NOT NULL DEFAULT '{}'");
        DB::statement('ALTER TABLE projects ADD COLUMN geom geometry(Point, 4326)');
        DB::statement('CREATE INDEX projects_lot_keys_gin ON projects USING gin (lot_keys)');
        DB::statement('CREATE INDEX projects_geom_gist ON projects USING gist (geom)');
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
