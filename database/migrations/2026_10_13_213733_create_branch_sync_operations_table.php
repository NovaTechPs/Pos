<?php

use App\Services\SchemaIntegrity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        SchemaIntegrity::create('branch_sync_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('branch_sync_devices')->nullOnDelete();
            $table->uuid('operation_uuid');
            $table->string('operation_type', 80);
            $table->enum('status', ['pending', 'processing', 'synced', 'conflict', 'failed'])->default('pending');
            $table->json('payload');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('received_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'operation_uuid']);
            $table->index(['tenant_id', 'branch_id', 'status', 'id'], 'branch_sync_operations_pending_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branch_sync_operations');
    }
};
