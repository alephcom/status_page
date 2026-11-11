<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('component_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('order')->default(0);
            $table->boolean('collapsed')->default(false);
            $table->timestamps();
        });

        Schema::create('components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('component_group_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('link')->nullable();
            $table->unsignedInteger('order')->default(0);
            $table->boolean('enabled')->default(true);
            // Effective status shown to the public.
            $table->string('status')->default('operational');
            // Status last derived from the monitoring sources.
            $table->string('auto_status')->default('operational');
            // Optional manual override, expiring at manual_status_until (null = until cleared).
            $table->string('manual_status')->nullable();
            $table->timestamp('manual_status_until')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('component_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('component_id')->constrained()->cascadeOnDelete();
            $table->string('driver');
            $table->json('config');
            $table->timestamps();
        });

        Schema::create('component_status_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('component_id')->constrained()->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('status');
            $table->timestamp('changed_at');
            $table->index(['component_id', 'changed_at']);
        });

        Schema::create('component_status_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('component_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('operational_seconds')->default(0);
            $table->unsignedInteger('degraded_seconds')->default(0);
            $table->unsignedInteger('maintenance_seconds')->default(0);
            $table->unsignedInteger('partial_outage_seconds')->default(0);
            $table->unsignedInteger('major_outage_seconds')->default(0);
            $table->unique(['component_id', 'date']);
        });

        Schema::create('zabbix_problems', function (Blueprint $table) {
            $table->id();
            $table->foreignId('component_id')->constrained()->cascadeOnDelete();
            $table->string('eventid');
            $table->string('triggerid')->nullable();
            $table->string('name');
            $table->unsignedTinyInteger('severity');
            $table->timestamp('started_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['component_id', 'eventid']);
            $table->index(['component_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zabbix_problems');
        Schema::dropIfExists('component_status_daily');
        Schema::dropIfExists('component_status_changes');
        Schema::dropIfExists('component_sources');
        Schema::dropIfExists('components');
        Schema::dropIfExists('component_groups');
    }
};
