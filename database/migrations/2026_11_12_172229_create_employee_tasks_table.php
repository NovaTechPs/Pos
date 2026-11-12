<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ForeignKeyDefinition;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tasks a manager assigns to an employee. The employee sees and completes only their own tasks.
     */
    public function up(): void
    {
        Schema::create('employee_tasks', function (Blueprint $table) {
            $table->id();
            $this->reference($table, 'tenant_id', 'tenants')->cascadeOnDelete();
            $this->reference($table, 'employee_id', 'employees')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('priority', ['low', 'normal', 'high'])->default('normal');
            $table->enum('status', ['pending', 'in_progress', 'completed', 'cancelled'])->default('pending');
            $table->date('due_date')->nullable();
            $this->reference($table, 'created_by', 'users', nullable: true)->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $this->reference($table, 'completed_by', 'users', nullable: true)->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'due_date']);
            $table->index(['employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_tasks');
    }

    /**
     * Some MySQL databases were restored from PostgreSQL and keep signed `bigint` ids,
     * so the foreign key column must copy the referenced id's exact type.
     */
    private function reference(Blueprint $table, string $column, string $referencedTable, bool $nullable = false): ForeignKeyDefinition
    {
        $idType = collect(Schema::getColumns($referencedTable))->firstWhere('name', 'id')['type'] ?? '';
        $definition = str_contains(strtolower($idType), 'unsigned') || Schema::getConnection()->getDriverName() !== 'mysql'
            ? $table->unsignedBigInteger($column)
            : $table->bigInteger($column);

        if ($nullable) {
            $definition->nullable();
        }

        return $table->foreign($column)->references('id')->on($referencedTable);
    }
};
