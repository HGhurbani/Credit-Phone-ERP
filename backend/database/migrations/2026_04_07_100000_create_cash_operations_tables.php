<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashboxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('opening_balance', 14, 2)->default(0);
            $table->decimal('current_balance', 14, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('branch_id');
        });

        Schema::create('cash_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cashbox_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('transaction_type', 64);
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->decimal('amount', 14, 2);
            $table->enum('direction', ['in', 'out']);
            $table->date('transaction_date');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('voucher_number')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'branch_id', 'transaction_date']);
            $table->index(['reference_type', 'reference_id']);
            $table->unique(['tenant_id', 'voucher_number']);
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cashbox_id')->nullable()->constrained()->nullOnDelete();
            $table->string('expense_number');
            $table->string('category');
            $table->decimal('amount', 14, 2);
            $table->date('expense_date');
            $table->string('vendor_name')->nullable();
            $table->text('notes')->nullable();
            $table->enum('status', ['active', 'cancelled'])->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'expense_number']);
            $table->index(['tenant_id', 'branch_id', 'expense_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('cash_transactions');
        Schema::dropIfExists('cashboxes');
    }
};
