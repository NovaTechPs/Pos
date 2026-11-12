<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Keeps every stored balance corrected by parties:reconcile-balances, with its value before
     * and after, so a correction can always be reviewed or undone.
     */
    public function up(): void
    {
        Schema::create('party_balance_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('party_id')->constrained('parties')->cascadeOnDelete();
            $table->decimal('previous_balance', 15, 2);
            $table->decimal('corrected_balance', 15, 2);
            $table->string('reason');
            $table->timestamp('created_at');
            $table->index(['tenant_id', 'party_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('party_balance_corrections');
    }
};
