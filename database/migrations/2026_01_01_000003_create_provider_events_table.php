<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_request_id')->nullable()->constrained('policy_requests')->nullOnDelete();
            $table->string('provider_event_id', 150)->unique();
            $table->string('provider_reference', 150)->index();
            $table->string('event_type', 50);
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->string('outcome', 50);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_events');
    }
};
