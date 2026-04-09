<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cashboxes', function (Blueprint $table) {
            $table->string('type', 64)->nullable()->after('name');
            $table->boolean('is_primary')->default(false)->after('is_active');

            $table->index(['tenant_id', 'branch_id', 'is_primary']);
        });

        Schema::table('cashboxes', function (Blueprint $table) {
            // Previously enforced a single cashbox per branch.
            $table->dropUnique(['branch_id']);
        });
    }

    public function down(): void
    {
        Schema::table('cashboxes', function (Blueprint $table) {
            $table->unique('branch_id');
        });

        Schema::table('cashboxes', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'branch_id', 'is_primary']);
            $table->dropColumn(['type', 'is_primary']);
        });
    }
};

