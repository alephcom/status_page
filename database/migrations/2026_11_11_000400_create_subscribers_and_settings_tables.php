<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscribers', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('token', 64)->unique();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('component_subscriber', function (Blueprint $table) {
            $table->foreignId('component_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscriber_id')->constrained()->cascadeOnDelete();
            $table->primary(['component_id', 'subscriber_id']);
        });

        Schema::create('webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('url');
            $table->string('secret')->nullable();
            $table->json('events');
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('last_status')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('webhooks');
        Schema::dropIfExists('component_subscriber');
        Schema::dropIfExists('subscribers');
    }
};
