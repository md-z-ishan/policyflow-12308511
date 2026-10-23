<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issuance_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_request_id')->constrained('policy_requests')->cascadeOnDelete();
            $table->unsignedInteger('attempt_no');
            $table->string('idempotency_key', 150)->index();
            $table->string('outcome', 32);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->json('response_body')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['policy_request_id', 'attempt_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issuance_attempts');
    }
};
