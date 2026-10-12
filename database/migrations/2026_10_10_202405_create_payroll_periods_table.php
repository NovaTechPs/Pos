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
        Schema::create('payroll_periods', function (Blueprint $table) {
            $table->id();
            $table->string('title'); // مثال: "راتب شهر 10 - 2026"
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('status', ['draft', 'processing', 'approved', 'paid'])->default('draft');
            $table->timestamps();

            $table->index(['start_date', 'end_date', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payroll_periods');
    }
};
