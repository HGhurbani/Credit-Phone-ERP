<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained('installment_contracts')->nullOnDelete();
            $table->enum('outcome', [
                'contacted', 'no_answer', 'promise_to_pay', 'wrong_number', 'reschedule_requested', 'visited',
            ]);
            $table->date('next_follow_up_date')->nullable();
            $table->enum('priority', ['low', 'normal', 'high'])->default('normal');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'customer_id', 'created_at']);
            $table->index(['tenant_id', 'branch_id']);
        });

        Schema::create('promise_to_pays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained('installment_contracts')->nullOnDelete();
            $table->decimal('promised_amount', 14, 2);
            $table->date('promised_date');
            $table->text('note')->nullable();
            $table->enum('status', ['active', 'fulfilled', 'cancelled'])->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'customer_id', 'status']);
        });

        Schema::create('reschedule_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained('installment_contracts')->cascadeOnDelete();
            $table->text('note')->nullable();
            $table->enum('status', ['pending', 'processed', 'cancelled'])->default('pending');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reschedule_requests');
        Schema::dropIfExists('promise_to_pays');
        Schema::dropIfExists('collection_follow_ups');
    }
};
