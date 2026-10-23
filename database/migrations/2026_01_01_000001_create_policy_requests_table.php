<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('client_request_id', 100);
            $table->string('agent_id', 100);
            $table->string('customer_name', 150);
            $table->date('date_of_birth');
            $table->string('destination', 32);
            $table->date('trip_start');
            $table->date('trip_end');
            $table->string('plan_code', 32);
            $table->unsignedBigInteger('sum_insured');
            $table->unsignedInteger('travel_days');
            $table->decimal('premium', 12, 2);
            $table->json('premium_breakdown');
            $table->string('state', 32)->index();
            $table->string('provider_reference')->nullable()->unique();
            $table->string('policy_number')->nullable()->unique();
            $table->json('last_provider_response')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->string('mock_scenario')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            // Strict unique compound constraint to prevent duplicate requests per agent
            $table->unique(['agent_id', 'client_request_id'], 'uq_agent_client_request');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_requests');
    }
};
