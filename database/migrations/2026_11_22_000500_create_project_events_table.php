<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Links an application to a project. Every link keeps how it was made and
     * how sure we are, so re-linking just moves the event.
     */
    public function up(): void
    {
        Schema::create('project_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('application_id')->unique()->constrained()->cascadeOnDelete();
            // da_lodged | da_modification | cdc_issued | cc_issued | oc_issued
            $table->string('event_type', 32);
            $table->date('occurred_at')->nullable();
            // seed | explicit | lot_plan | address | proximity
            $table->string('link_method', 16);
            $table->decimal('confidence', 3, 2); // 1.0, 0.9, 0.7, 0.5
            $table->timestamps();

            $table->index(['project_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_events');
    }
};
