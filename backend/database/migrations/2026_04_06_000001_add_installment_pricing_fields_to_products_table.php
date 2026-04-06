<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('monthly_percent_of_cash', 8, 2)->nullable()->after('installment_price');
            $table->decimal('fixed_monthly_amount', 12, 2)->nullable()->after('monthly_percent_of_cash');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['monthly_percent_of_cash', 'fixed_monthly_amount']);
        });
    }
};
