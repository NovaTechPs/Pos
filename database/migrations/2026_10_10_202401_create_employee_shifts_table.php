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
        SchemaIntegrity::create('employee_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('effective_from')->default('1970-01-01');
            $table->date('effective_until')->nullable();
            $table->string('shift_name')->default('Morning');
            $table->time('start_time');
            $table->time('end_time');
            $table->json('work_days'); // e.g., ["sat", "sun", "mon", "tue", "wed", "thu"]
            $table->integer('grace_period_minutes')->default(15); // فترة السماح للتأخير
            $table->timestamps();

            $table->unique(['employee_id', 'effective_from']);
            $table->index(['employee_id', 'effective_from', 'effective_until'], 'employee_shift_effective_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_shifts');
    }
};
