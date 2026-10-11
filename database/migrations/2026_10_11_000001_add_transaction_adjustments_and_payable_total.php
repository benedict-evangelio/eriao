<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->float('adjusted_total_amount')->nullable()->after('total_amount');
        });

        Schema::create('transaction_adjustments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id')->index();
            $table->unsignedBigInteger('user_id');
            $table->float('previous_amount');
            $table->float('adjusted_amount');
            $table->float('refund_amount')->default(0);
            $table->json('previous_items');
            $table->json('adjusted_items');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_adjustments');

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('adjusted_total_amount');
        });
    }
};
