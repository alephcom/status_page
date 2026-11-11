<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_chats', function (Blueprint $table) {
            $table->id();
            // Numeric ids exceed 32 bits, and channels may be given as "@name".
            $table->string('chat_id')->unique();
            $table->string('type')->nullable();
            $table->string('title')->nullable();
            $table->boolean('added_by_admin')->default(false);
            $table->string('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('component_telegram_chat', function (Blueprint $table) {
            $table->foreignId('component_id')->constrained()->cascadeOnDelete();
            $table->foreignId('telegram_chat_id')->constrained()->cascadeOnDelete();
            $table->primary(['component_id', 'telegram_chat_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('component_telegram_chat');
        Schema::dropIfExists('telegram_chats');
    }
};
