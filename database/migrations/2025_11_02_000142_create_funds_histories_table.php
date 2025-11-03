<?php

use App\Models\Fund;
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
        Schema::create('funds_histories', function (Blueprint $table) {
            $table->id();
            $table->enum('fund_type', ['investment', 'cogs', 'profit']);
            $table->decimal('amount', 14, 2);
            $table->enum('direction', ['in', 'out']); // 'in' = поступление, 'out' = списание
            $table->string('reference_type')->nullable(); // источник (purchase, sale, return, manual и т.д.)
            $table->unsignedBigInteger('reference_id')->nullable(); // ID сущности (purchase_id, sale_id и т.д.)
            $table->text('description')->nullable();
            $table->timestamps();
        });

        \App\Http\Services\FundsService::updateBalance(Fund::TYPE_INVESTMENT, 1000, null, null, 'Начальная инвестиция');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('funds_histories');
    }
};
