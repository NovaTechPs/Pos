<?php

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
        Schema::create('employee_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('shift_name')->default('Morning');
            $table->time('start_time');
            $table->time('end_time');
            $table->json('work_days'); // e.g., ["sat", "sun", "mon", "tue", "wed", "thu"]
            $table->integer('grace_period_minutes')->default(15); // فترة السماح للتأخير
            $table->timestamps();

            $table->index('employee_id');
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
