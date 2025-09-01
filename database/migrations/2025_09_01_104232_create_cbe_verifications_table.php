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
        Schema::create('cbe_verifications', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_id')->index();
            $table->string('account_number')->index();
            $table->string('sender_name')->nullable();
            $table->string('sender_bank_name')->default('Commercial Bank of Ethiopia');
            $table->string('receiver_name')->nullable();
            $table->string('receiver_bank_name')->nullable();
            $table->string('status')->default('UNKNOWN');
            $table->datetime('date')->nullable();
            $table->decimal('amount', 15, 2)->default(0.00);
            $table->text('message')->nullable();
            $table->text('debug_info')->nullable();
            $table->datetime('verified_at')->nullable();
            $table->timestamps();
            
            // Add indexes for better performance
            $table->index(['transaction_id', 'account_number']);
            $table->index('status');
            $table->index('verified_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cbe_verifications');
    }
};
