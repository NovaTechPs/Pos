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
        SchemaIntegrity::create('employee_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('date');
            $table->time('check_in')->nullable();
            $table->time('check_out')->nullable();
            $table->integer('late_minutes')->default(0);
            $table->integer('overtime_minutes')->default(0);
            $table->integer('worked_minutes')->default(0);
            $table->integer('overtime_approved_minutes')->default(0);
            $table->decimal('production_units', 12, 3)->default(0);
            $table->enum('status', ['present', 'absent', 'late', 'leave'])->default('present');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'date']);
            $table->index(['date', 'status']);
        });

        SchemaIntegrity::create('employee_attendance_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_attendance_id')->constrained()->restrictOnDelete();
            $table->dateTime('checked_in_at');
            $table->dateTime('checked_out_at')->nullable();
            $table->enum('source', ['self', 'barcode', 'manager', 'sync'])->default('self');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('device_name')->nullable();
            $table->uuid('sync_uuid')->unique();
            $table->timestamps();
            $table->index(['employee_id', 'checked_out_at', 'checked_in_at'], 'employee_session_open_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_attendance_sessions');
        Schema::dropIfExists('employee_attendances');
    }
};
