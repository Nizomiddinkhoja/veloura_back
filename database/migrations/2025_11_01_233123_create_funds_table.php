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
        Schema::create('funds', function (Blueprint $table) {
            $table->id();
            $table->enum('fund_type', ['investment', 'cogs', 'profit'])->unique();
            $table->decimal('balance', 14, 2)->default(0);
            $table->timestamps();
        });



        // Инициализация стартовых счетов
        \Illuminate\Support\Facades\DB::table('funds')->insert([
            ['fund_type' => 'investment', 'balance' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['fund_type' => 'cogs', 'balance' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['fund_type' => 'profit', 'balance' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('funds');
    }
};
