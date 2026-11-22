<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('builders', function (Blueprint $table) {
            $table->id();
            $table->string('legal_name');
            $table->string('trading_name')->nullable();
            // Upper-cased, punctuation-stripped legal name, used for dedupe.
            $table->string('name_key')->unique();
            $table->string('slug')->unique();
            // Only company builders (Pty Ltd, Ltd, ...) are ever shown.
            // Names that look like individuals are suppressed everywhere.
            $table->boolean('is_company')->default(false)->index();
            $table->boolean('suppressed')->default(true)->index();
            $table->string('suppressed_reason')->nullable(); // individual | takedown
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('builders');
    }
};
