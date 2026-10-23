<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mock_policies', function (Blueprint $table) {
            $table->id();
            $table->string('client_reference', 150)->unique();
            $table->string('provider_reference', 150)->unique();
            $table->string('policy_number', 150)->unique();
            $table->string('scenario', 50);
            $table->string('status', 32);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mock_policies');
    }
};
