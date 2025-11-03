<?php

namespace App\Http\Services;

use App\Models\Fund;
use App\Models\FundsHistory;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Sale;
use App\Models\StockMovement;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Exception;
use Illuminate\Support\Facades\Log;

class InventoryService
{
    /**
     * Списывает товар по FIFO
     *
     * @param int $productId
     * @param int $quantity
     * @param string $referenceType (например 'sale')
     * @param int|null $referenceId
     * @return void
     * @throws Exception
     */
    public static function reduceStockFIFO(int $productId, int $quantity, string $referenceType, ?int $referenceId = null): void
    {
        DB::beginTransaction();
        try {
            $remaining = $quantity;

            // Партии с остатками по возрастанию (FIFO)
            $batches = PurchaseItem::query()
                ->where('product_id', $productId)
                ->where('remaining_quantity', '>', 0)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($batches as $batch) {
                if ($remaining <= 0) break;

                $take = min($batch->remaining_quantity, $remaining);

                // Обновляем остаток партии
                $batch->decrement('remaining_quantity', $take);

                // Создаём запись движения
                StockMovement::query()
                    ->create([
                        'product_id' => $productId,
                        'purchase_item_id' => $batch->id,
                        'type' => 'out',
                        'quantity' => $take,
                        'reference_type' => $referenceType,
                        'reference_id' => $referenceId,
                    ]);

                $remaining -= $take;
            }

            if ($remaining > 0) {
                throw new Exception("Недостаточно товара (не хватает $remaining шт)");
            }

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Добавление товара в запас (при закупке, возврате и т.д.)
     */
    public static function addStock(int $productId, int $quantity, float $buyPrice, string $referenceType, ?int $referenceId = null): void
    {
        DB::transaction(function () use ($productId, $quantity, $buyPrice, $referenceType, $referenceId) {
            $purchaseItem = PurchaseItem::query()->create([
                'product_id' => $productId,
                'buy_price' => $buyPrice,
                'quantity' => $quantity,
                'remaining_quantity' => $quantity,
            ]);

            StockMovement::query()->create([
                'product_id' => $productId,
                'purchase_item_id' => $purchaseItem->id,
                'type' => StockMovement::TYPE_IN,
                'quantity' => $quantity,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
            ]);
        });
    }

    public static function getAnalytics(?string $startDate = null, ?string $endDate = null): object
    {
//        $startDate = Carbon::parse($startDate ?? '1970-01-01')->timezone('Asia/Dushanbe')->format('Y-m-d');
//        $endDate = Carbon::parse($endDate ?? now())->timezone('Asia/Dushanbe')->format('Y-m-d h:i:s');


        $tz = 'Asia/Dushanbe';

        $startDate = Carbon::parse($startDate ?? '1970-01-01', $tz)
            ->startOfDay()
            ->setTimezone('UTC')
            ->toDateTimeString();

        $endDate = Carbon::parse($endDate ?? now($tz), $tz)
            ->endOfDay()
            ->setTimezone('UTC')
            ->toDateTimeString();

        // 📦 Закупки и 🔄 Возвраты дилеру
        $purchaseItems = Purchase::with('items')
//            ->where('created_at', '>=', $endDate)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->get()
            ->flatMap->items;

        Log::info('$purchaseItems', [$startDate, $endDate]);

        $purchases_total = $purchaseItems->where('is_return', 0)->sum(fn($i) => $i->buy_price * $i->quantity);
        $returned_to_dealer = $purchaseItems->where('is_return', 1)->sum(fn($i) => $i->buy_price * $i->quantity);

        // 💵 Продажи и возвраты
        $sales = Sale::with('items', 'returns')
            ->where('is_deleted', 0)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->get();

        $total_revenue = $sales->sum('total');
        $refund_paid_total = $sales->where('paid', 1)->flatMap->returns->sum('refund_amount');
        $refund_not_paid_total = $sales->where('paid', 0)->flatMap->returns->sum('refund_amount');
        $returned_cost = $sales->flatMap->returns->sum(fn($r) => $r->buy_price * $r->quantity);
        $sold_cost = $sales->flatMap->items->sum(fn($i) => $i->buy_price * $i->quantity) - $returned_cost;

        // 🧮 Количество товаров и продаж
        $products_count = Product::query()->where('is_deleted', 0)->count();
        $sales_count = $sales->count();

        // 💵 Оплаченные / Неоплаченные продажи
        $paid_sales_total = $sales->where('paid', 1)->sum('total') + $refund_paid_total;
        $not_paid_sales_total = $sales->where('paid', 0)->sum('total') + $refund_not_paid_total;
        $refund_total = $refund_paid_total + $refund_not_paid_total;

        // 💰 Балансы фондов из истории
        $funds = FundsHistory::query()
            ->select(
                'fund_type',
                DB::raw("
                    SUM(
                        CASE
                            WHEN direction = 'in' THEN amount
                            WHEN direction = 'out' THEN -amount
                            ELSE 0
                        END
                    ) as balance
                ")
            )
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('fund_type')
            ->pluck('balance', 'fund_type');

        $investment_balance = (float)($funds['investment'] ?? 0);
        $cogs_balance = (float)($funds['cogs'] ?? 0);
        $profit_balance = (float)($funds['profit'] ?? 0);

        // 🧮 Итоги
        $real_purchases = $purchases_total - $returned_to_dealer;
        $current_stock_cost = $real_purchases - $sold_cost + $refund_total;

        return (object)[
            'purchases_total' => (float)$purchases_total,
            'returned_to_dealer' => (float)$returned_to_dealer,
            'total_revenue' => (float)$total_revenue,
            'sold_cost' => (float)$sold_cost,
            'refund_total' => (float)$refund_total,
            'returned_cost' => (float)$returned_cost,
            'products_count' => (int)$products_count,
            'sales_count' => (int)$sales_count,
            'paid_sales_total' => (float)$paid_sales_total,
            'not_paid_sales_total' => (float)$not_paid_sales_total,
            'investment_balance' => $investment_balance,
            'cogs_balance' => $cogs_balance,
            'profit_balance' => $profit_balance,
            'real_purchases' => (float)$real_purchases,
            'current_stock_cost' => (float)$current_stock_cost,
        ];
    }
}
