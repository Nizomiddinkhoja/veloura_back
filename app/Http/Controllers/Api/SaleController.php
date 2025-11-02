<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSaleRequest;
use App\Http\Services\FundsService;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Http\Services\InventoryAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SaleController extends Controller
{
    public function index(Request $request)
    {
        $query = Sale::query()
            ->where('is_deleted', 0)
            ->with(['returns'])
            ->with(['items' => function ($q) {
                $q->where('quantity', '>', 0);
            }]);

        // Search filter (customer name, comment)
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('customer_name', 'like', "%{$search}%")
                    ->orWhere('comment', 'like', "%{$search}%");
            });
        }

        // Customer name filter
        if ($request->has('customer_name')) {
            $query->where('customer_name', 'like', "%{$request->input('customer_name')}%");
        }

        // Total amount range filters
        if ($request->has('min_total')) {
            $query->where('total', '>=', $request->input('min_total'));
        }
        if ($request->has('max_total')) {
            $query->where('total', '<=', $request->input('max_total'));
        }

        // Paid status filter
        if ($request->has('paid')) {
            $query->where('paid', $request->input('paid'));
        }

        // Status filter
        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        // Date range filters
        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }
        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        // Filter by sales with returns
        if ($request->has('has_returns')) {
            $query->whereHas('returns');
        }

        // Sorting
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        return response()->json($query->get());
    }

    public function store(StoreSaleRequest $request): \Illuminate\Http\JsonResponse
    {
        $items = $request->input('items');

        DB::beginTransaction();
        try {
            $total = 0;
            $sale = Sale::query()
                ->create([
                    'customer_name' => $request->input('customer_name'),
                    'comment' => $request->input('comment'),
                    'paid' => $request->input('paid'),
                    'total' => 0
                ]);

            foreach ($items as $it) {
                $product = Product::lockForUpdate()->find($it['product_id']);
                if (!$product) throw new \Exception('Product not found: ' . $it['product_id']);

                $quantity = (int)$it['quantity'];
                $sellPrice = (float)$it['sell_price'];

                if ($sellPrice < $product->buy_price) {
                    throw new \Exception("Цена продажи для {$product->name} меньше цены покупки");
                }

                if ($quantity > $product->quantity) {
                    throw new \Exception("Недостаточно на складе: {$product->name}");
                }

                // Уменьшаем остаток
                $product->quantity -= $quantity;
                $product->save();

                $lineTotal = $sellPrice * $quantity;
                $total += $lineTotal;

                // Себестоимость и прибыль
                $COGS = $product->buy_price * $quantity;
                $profit = $lineTotal - $COGS;

                // Обновляем балансы
                FundsService::updateBalance('cogs', $COGS, 'sales', $sale->id, 'Начисление себестоимости');
                FundsService::updateBalance('profit', $profit, 'sales', $sale->id, 'Начисление прибыли');

                SaleItem::query()
                    ->create([
                        'sale_id' => $sale->id,
                        'product_id' => $product->id,
                        'name' => $product->name,
                        'quantity' => $quantity,
                        'sell_price' => $sellPrice,
                        'buy_price' => $product->buy_price,
                        'profit' => $profit,
                    ]);
            }

            $sale->total = $total;
            $sale->save();

            DB::commit();
            return response()->json($sale->load('items'), 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function show(Sale $sale)
    {
        return response()->json($sale->load('items'));
    }

    public function destroy(Sale $sale)
    {
        $sale->is_deleted = 1;
        $sale->save();
        return response()->json('ok', '204');
    }

    public function paid($id)
    {
        $sale = Sale::query()
            ->where('is_deleted', 0)
            ->find($id);
        $sale->paid = 1;
        $sale->save();
        return response()->json('ok', '204');
    }

    public function updateComment(Request $request, $id)
    {
        $request->validate([
            'comment' => 'nullable|string',
        ]);

        $sale = Sale::query()
            ->where('is_deleted', 0)
            ->findOrFail($id);

        $sale->comment = $request->input('comment');
        $sale->save();

        return response()->json($sale->load(['items', 'returns']));
    }

    public function returnItems(Request $request, $id)
    {
        $request->validate([
            'items' => 'required|array',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.comment' => 'nullable',
        ]);

        $sale = Sale::query()
            ->where('is_deleted', 0)
            ->findOrFail($id);

        DB::beginTransaction();
        try {
            $returnItems = $request->input('items');
            $totalRefund = 0;

            foreach ($returnItems as $returnItem) {
                $productId = $returnItem['product_id'];
                $comment = $returnItem['comment'];
                $returnQuantity = (int)$returnItem['quantity'];

                // Найти товар в заказе
                $saleItem = SaleItem::query()
                    ->where('sale_id', $sale->id)
                    ->where('product_id', $productId)
                    ->first();

                if (!$saleItem) {
                    throw new \Exception("Товар с ID {$productId} не найден в заказе");
                }

                // Проверить, сколько уже возвращено
                $alreadyReturned = SaleReturn::query()
                    ->where('sale_id', $sale->id)
                    ->where('product_id', $productId)
                    ->sum('quantity');

                $availableToReturn = $saleItem->quantity - $alreadyReturned;

                if ($returnQuantity > $availableToReturn) {
                    throw new \Exception("Нельзя вернуть {$returnQuantity} шт. товара '{$saleItem->name}'. Доступно для возврата: {$availableToReturn} шт.");
                }

                // Вернуть товар на склад
                $product = Product::lockForUpdate()->find($productId);
                if (!$product) {
                    throw new \Exception("Товар с ID {$productId} не найден");
                }


                $product->quantity += $returnQuantity;
                $product->save();

                // При возврате товаров нужно уменьшать COGS и прибыль
                $refundCOGS = $saleItem->buy_price * $returnQuantity;
                $refundProfit = ($saleItem->sell_price - $saleItem->buy_price) * $returnQuantity;
                // Создать запись о возврате
                $refundAmount = $saleItem->sell_price * $returnQuantity;
                $totalRefund += $refundAmount;

                $saleReturn = SaleReturn::create([
                    'sale_id' => $sale->id,
                    'product_id' => $productId,
                    'product_name' => $saleItem->name,
                    'quantity' => $returnQuantity,
                    'sell_price' => $saleItem->sell_price,
                    'buy_price' => $saleItem->buy_price,
                    'refund_amount' => $refundAmount,
                    'comment' => $comment,
                ]);

                // Вычитаем из балансов
                FundsService::updateBalance('cogs', -$refundCOGS, 'sale_returns', $saleReturn->id, 'Вычитание из себестоимости');
                FundsService::updateBalance('profit', -$refundProfit, 'sale_returns', $saleReturn->id, 'Вычитание из прибыли');

            }

            // Обновить общую сумму заказа
            $sale->total -= $totalRefund;
            $sale->save();

            $saleItem->quantity = $saleItem->quantity - $returnQuantity;
            $saleItem->save();

            DB::commit();

            return response()->json([
                'message' => 'Возврат успешно оформлен',
                'sale' => $sale->load(['items', 'returns']),
                'total_refund' => $totalRefund,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function analytics(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $result = InventoryAnalyticsService::getSummary($request->input('date_from'), $request->input('date_to'));
            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json($e->getMessage(), 422);
        }
    }
}
