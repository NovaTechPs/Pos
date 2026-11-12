<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ForeignKeyDefinition;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Recurring tasks create a new employee task each day, week or month, and every task can hold
     * a checklist of steps the employee ticks off.
     */
    public function up(): void
    {
        Schema::create('employee_recurring_tasks', function (Blueprint $table) {
            $table->id();
            $this->reference($table, 'tenant_id', 'tenants')->cascadeOnDelete();
            $this->reference($table, 'employee_id', 'employees')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('priority', ['low', 'normal', 'high'])->default('normal');
            $table->enum('frequency', ['daily', 'weekly', 'monthly']);
            $table->json('weekdays')->nullable();
            $table->unsignedTinyInteger('day_of_month')->nullable();
            $table->json('steps')->nullable();
            $table->date('starts_on');
            $table->boolean('is_active')->default(true);
            $table->date('last_generated_on')->nullable();
            $this->reference($table, 'created_by', 'users', nullable: true)->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });

        Schema::table('employee_tasks', function (Blueprint $table) {
            $this->reference($table, 'recurring_task_id', 'employee_recurring_tasks', nullable: true)->nullOnDelete();
            $table->unique(['recurring_task_id', 'due_date']);
        });

        Schema::create('employee_task_steps', function (Blueprint $table) {
            $table->id();
            $this->reference($table, 'employee_task_id', 'employee_tasks')->cascadeOnDelete();
            $table->string('title');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamp('completed_at')->nullable();
            $this->reference($table, 'completed_by', 'users', nullable: true)->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_task_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_task_steps');
        Schema::table('employee_tasks', function (Blueprint $table) {
            $table->dropUnique(['recurring_task_id', 'due_date']);
            $table->dropConstrainedForeignId('recurring_task_id');
        });
        Schema::dropIfExists('employee_recurring_tasks');
    }

    /**
     * Some MySQL databases were restored from PostgreSQL and keep signed `bigint` ids,
     * so the foreign key column must copy the referenced id's exact type.
     */
    private function reference(Blueprint $table, string $column, string $referencedTable, bool $nullable = false): ForeignKeyDefinition
    {
        $idType = Schema::hasTable($referencedTable)
            ? (collect(Schema::getColumns($referencedTable))->firstWhere('name', 'id')['type'] ?? '')
            : 'unsigned';
        $definition = str_contains(strtolower($idType), 'unsigned') || Schema::getConnection()->getDriverName() !== 'mysql'
            ? $table->unsignedBigInteger($column)
            : $table->bigInteger($column);

        if ($nullable) {
            $definition->nullable();
        }

        return $table->foreign($column)->references('id')->on($referencedTable);
    }
};
