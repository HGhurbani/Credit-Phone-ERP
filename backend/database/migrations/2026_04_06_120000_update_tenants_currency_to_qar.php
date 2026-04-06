<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('tenants')->where(function ($q) {
            $q->where('currency', 'SAR')->orWhereNull('currency');
        })->update(['currency' => 'QAR']);
    }

    public function down(): void
    {
        DB::table('tenants')->where('currency', 'QAR')->update(['currency' => 'SAR']);
    }
};
