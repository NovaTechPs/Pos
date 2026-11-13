<?php

use App\Services\SchemaIntegrity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A sales rep's daily customer list: each customer is checked off with what came of the visit
     * (a sale, a collection only, or an order to prepare) and an optional note.
     */
    public function up(): void
    {
        SchemaIntegrity::create('customer_visits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('party_id')->constrained('parties')->cascadeOnDelete();
            $table->date('visit_date');
            $table->unsignedInteger('position')->default(0);
            $table->string('outcome', 20)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('visited_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'party_id', 'visit_date']);
            $table->index(['tenant_id', 'visit_date', 'user_id']);
        });

        // Deployed stores do not re-run the permission seeder, so the new permission is added here.
        DB::table('permissions')->updateOrInsert(['name' => 'customer_visits.manage'], [
            'display_name' => 'إدارة قوائم زيارات المندوبين', 'group' => 'المبيعات', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_visits');
        DB::table('permissions')->where('name', 'customer_visits.manage')->delete();
    }
};
