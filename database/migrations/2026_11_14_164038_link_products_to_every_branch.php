<?php

use App\Services\BranchProductLinker;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Products added before new products were linked to every branch exist in one branch only.
     */
    public function up(): void
    {
        app(BranchProductLinker::class)->linkMissing();
    }

    public function down(): void
    {
        //
    }
};
