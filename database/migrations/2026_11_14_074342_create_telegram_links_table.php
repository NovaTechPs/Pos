<?php

use App\Services\SchemaIntegrity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Telegram chats linked to the shared WaslaPOS bot: a staff member's (for store notifications) or
     * a customer party's (for their invoices and receipts). A row starts as a one-time link token and
     * gets its chat id when the person presses Start in the bot.
     */
    public function up(): void
    {
        SchemaIntegrity::create('telegram_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('party_id')->nullable()->constrained('parties')->cascadeOnDelete();
            $table->string('chat_id', 40)->nullable();
            $table->string('chat_name')->nullable();
            $table->string('link_token', 40)->nullable()->unique();
            $table->timestamp('link_token_expires_at')->nullable();
            $table->json('notifications')->nullable();
            $table->timestamp('linked_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id']);
            $table->unique(['tenant_id', 'party_id']);
            $table->index('chat_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_links');
    }
};
