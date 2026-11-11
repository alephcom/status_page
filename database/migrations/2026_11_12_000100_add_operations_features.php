<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            // Lifecycle notifications, each sent at most once.
            $table->timestamp('reminder_sent_at')->nullable();
            $table->timestamp('started_notified_at')->nullable();
            $table->timestamp('completed_notified_at')->nullable();
            // Recurring windows: when one completes the next occurrence is created.
            $table->string('recurrence')->nullable();
            $table->timestamp('recurrence_until')->nullable();
        });

        Schema::table('incidents', function (Blueprint $table) {
            $table->text('postmortem')->nullable();
            $table->timestamp('postmortem_published_at')->nullable();
            // Set when monitoring recovered while the incident was still open.
            $table->timestamp('recovered_at')->nullable();
        });

        Schema::create('scheduled_incident_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status');
            $table->text('message');
            $table->timestamp('publish_at')->index();
            $table->timestamps();
        });

        Schema::create('incident_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('title');
            $table->text('message');
            $table->string('impact')->default('minor');
            $table->string('component_status')->nullable();
            $table->json('component_ids')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('via', 20);
            $table->string('action', 20);
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label')->nullable();
            $table->json('changes')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->index();
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::table('subscribers', function (Blueprint $table) {
            $table->string('min_impact')->nullable();
        });

        Schema::table('telegram_chats', function (Blueprint $table) {
            $table->string('min_impact')->nullable();
        });

        Schema::table('webhooks', function (Blueprint $table) {
            $table->string('format')->default('json');
        });

        Schema::table('components', function (Blueprint $table) {
            $table->decimal('sla_target', 6, 3)->nullable();
            $table->string('visibility')->default('public');
        });

        Schema::create('component_dependencies', function (Blueprint $table) {
            $table->foreignId('component_id')->constrained()->cascadeOnDelete();
            $table->foreignId('depends_on_id')->constrained('components')->cascadeOnDelete();
            $table->primary(['component_id', 'depends_on_id']);
        });

        Schema::create('component_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('component_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('host');
            $table->string('item_key');
            $table->string('unit', 20)->nullable();
            $table->unsignedTinyInteger('decimals')->default(0);
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('order')->default(0);
            $table->string('itemid')->nullable();
            $table->unsignedTinyInteger('value_type')->nullable();
            $table->timestamp('last_polled_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('metric_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('component_metric_id')->constrained()->cascadeOnDelete();
            $table->timestamp('recorded_at');
            $table->double('value');
            $table->unique(['component_metric_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metric_points');
        Schema::dropIfExists('component_metrics');
        Schema::dropIfExists('component_dependencies');

        Schema::table('components', fn (Blueprint $t) => $t->dropColumn(['sla_target', 'visibility']));
        Schema::table('webhooks', fn (Blueprint $t) => $t->dropColumn('format'));
        Schema::table('telegram_chats', fn (Blueprint $t) => $t->dropColumn('min_impact'));
        Schema::table('subscribers', fn (Blueprint $t) => $t->dropColumn('min_impact'));

        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('incident_templates');
        Schema::dropIfExists('scheduled_incident_updates');

        Schema::table('incidents', fn (Blueprint $t) => $t->dropColumn(['postmortem', 'postmortem_published_at', 'recovered_at']));
        Schema::table('maintenances', fn (Blueprint $t) => $t->dropColumn([
            'reminder_sent_at', 'started_notified_at', 'completed_notified_at', 'recurrence', 'recurrence_until',
        ]));
    }
};
