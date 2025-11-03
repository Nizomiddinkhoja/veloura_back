<?php

namespace App\Http\Services;

use App\Models\Fund;
use App\Models\FundsHistory;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Exception;

class FundsService
{
    /** Получить баланс конкретного фонда */
    public static function getBalance(string $fundType): float
    {
        return DB::table('funds')->where('fund_type', $fundType)->value('balance') ?? 0;
    }

    /** Изменить баланс фонда на указанную сумму (может быть отрицательной) */
    public static function updateBalance(string $fundType, float $amount, ?string $reference_type = null, ?int $referenceId = null, ?string $description = null): void
    {
        DB::table('funds')->where('fund_type', $fundType)->increment('balance', $amount);

        // Добавляем историю
        self::logHistory(
            fundType: $fundType,
            amount: abs($amount),
            direction: $amount >= 0 ? StockMovement::TYPE_IN: StockMovement::TYPE_OUT,
            reference_type: $reference_type,
            referenceId: $referenceId,
            description: $description
        );
    }

    /**
     * Распределение суммы закупки по приоритету:
     * 1. investment → 2. cogs → 3. profit
     * Возвращает массив сколько с какого фонда было списано
     */
    public static function allocateFunds(float $amount, ?string $reference_type = 'purchase', ?int $referenceId = null): array
    {
        $investment = self::getBalance(Fund::TYPE_INVESTMENT);
        $cogs = self::getBalance(Fund::TYPE_COGS);
        $profit = self::getBalance(Fund::TYPE_PROFIT);

        $remaining = $amount;

        $fromInvestment = min($remaining, $investment);
        $remaining -= $fromInvestment;

        $fromCogs = min($remaining, $cogs);
        $remaining -= $fromCogs;

        $fromProfit = min($remaining, $profit);
        $remaining -= $fromProfit;

        if ($remaining > 0) {
            throw new Exception("Недостаточно средств для закупки: не хватает $remaining");
        }

        // Обновляем балансы
        DB::transaction(function () use ($fromInvestment, $fromCogs, $fromProfit, $reference_type, $referenceId) {
            if ($fromInvestment > 0) {
                self::updateBalance(Fund::TYPE_INVESTMENT, -$fromInvestment, $reference_type, $referenceId, 'Закупка из investment');
            }
            if ($fromCogs > 0) {
                self::updateBalance(Fund::TYPE_COGS, -$fromCogs, $reference_type, $referenceId, 'Закупка из cogs');
            }
            if ($fromProfit > 0) {
                self::updateBalance(Fund::TYPE_PROFIT, -$fromProfit, $reference_type, $referenceId, 'Закупка из profit');
            }
        });

        return [
            'investment' => $fromInvestment,
            'cogs' => $fromCogs,
            'profit' => $fromProfit,
        ];
    }

    /** Получить все фонды сразу */
    public static function getAll(): object
    {
        $rows = DB::table('funds')->pluck('balance', 'fund_type');
        return (object)[
            'investment' => (float)($rows['investment'] ?? 0),
            'cogs' => (float)($rows['cogs'] ?? 0),
            'profit' => (float)($rows['profit'] ?? 0),
        ];
    }

    /**
     * Универсальный метод для перевода средств между фондами
     */
    public static function transfer(string $from, string $to, float $amount): void
    {
        if ($amount <= 0 || $from === $to) return;

        $fromBalance = self::getBalance($from);
        if ($fromBalance < $amount) {
            throw new Exception("Недостаточно средств на {$from} для перевода {$amount}");
        }

        DB::transaction(function () use ($from, $to, $amount) {
            self::updateBalance($from, -$amount, $from);
            self::updateBalance($to, $amount, $to);
        });
    }

    protected static function logHistory(string $fundType, float $amount, string $direction, ?string $reference_type = null, ?int $referenceId = null, ?string $description = null): void
    {
        FundsHistory::query()->insert([
            'fund_type' => $fundType,
            'amount' => $amount,
            'direction' => $direction,
            'reference_type' => $reference_type,
            'reference_id' => $referenceId,
            'description' => $description,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
