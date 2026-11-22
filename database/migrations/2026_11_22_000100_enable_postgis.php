<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Laravel Cloud (Neon) support for PostGIS is undocumented. If this
        // fails there, point DB_URL at Neon or Supabase directly.
        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
    }

    public function down(): void
    {
        // Leave extensions in place; other objects may depend on them.
    }
};
