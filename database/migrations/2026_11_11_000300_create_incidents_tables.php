<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('status')->default('investigating');
            $table->string('impact')->default('minor');
            $table->boolean('visible')->default(true);
            $table->boolean('auto_created')->default(false);
            $table->string('zabbix_eventid')->nullable();
            // Status applied to linked components while the incident is open (null = no effect).
            $table->string('component_status')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['visible', 'resolved_at']);
        });

        Schema::create('incident_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status');
            $table->text('message');
            $table->timestamps();
        });

        Schema::create('component_incident', function (Blueprint $table) {
            $table->foreignId('component_id')->constrained()->cascadeOnDelete();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->primary(['component_id', 'incident_id']);
        });

        Schema::create('maintenances', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('message')->nullable();
            $table->timestamp('scheduled_start');
            $table->timestamp('scheduled_end');
            $table->boolean('visible')->default(true);
            $table->boolean('notified')->default(false);
            $table->timestamps();
            $table->index(['visible', 'scheduled_end']);
        });

        Schema::create('component_maintenance', function (Blueprint $table) {
            $table->foreignId('component_id')->constrained()->cascadeOnDelete();
            $table->foreignId('maintenance_id')->constrained()->cascadeOnDelete();
            $table->primary(['component_id', 'maintenance_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('component_maintenance');
        Schema::dropIfExists('maintenances');
        Schema::dropIfExists('component_incident');
        Schema::dropIfExists('incident_updates');
        Schema::dropIfExists('incidents');
    }
};
