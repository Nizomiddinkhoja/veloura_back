<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSaleRequest;
use App\Http\Services\FundsService;
use App\Http\Services\InventoryAnalyticsService;
use App\Models\Fund;
use App\Models\Product;
use App\Models\PurchaseItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class SaleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Sale::query()
            ->where('is_deleted', 0)
            ->with(['returns.product'])
            ->with(['items.product']);

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

        return $this->sendJsonResponse(
            Response::HTTP_OK,
            [],
            $query->get()
        );
    }

    public function store(StoreSaleRequest $request): JsonResponse
    {
        $items = $request->input('items');

        DB::beginTransaction();
        try {
            $total = 0;

            $sale = Sale::query()->create([
                'customer_name' => $request->input('customer_name'),
                'comment' => $request->input('comment'),
                'paid' => $request->input('paid'),
                'total' => 0
            ]);

            foreach ($items as $it) {
                $product = Product::query()->lockForUpdate()->find((int)$it['product_id']);
                if (!$product) {
                    return $this->sendJsonResponse(
                        Response::HTTP_NOT_FOUND,
                        ['message' => 'Продукт ' . $it['product_id'] . ' не найден ']
                    );
                }

                $quantity = (int)$it['quantity'];
                $sellPrice = (float)$it['sell_price'];

                // ✅ Проверяем наличие по FIFO
                $available = PurchaseItem::query()
                    ->where('product_id', $product->id)
                    ->where('is_return', 0)
                    ->sum('remaining_quantity');

                if ($quantity > $available) {
                    return $this->sendJsonResponse(
                        Response::HTTP_UNPROCESSABLE_ENTITY,
                        ['message' => 'Продукт ' . $product['name'] . ' нет в наличии!']
                    );
                }

                // 💸 Списываем через FIFO
                $batches = PurchaseItem::query()
                    ->where('product_id', $product->id)
                    ->where('is_return', 0)
                    ->where('remaining_quantity', '>', 0)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $remaining = $quantity;
                $totalCOGS = 0;

                foreach ($batches as $batch) {
                    if ($remaining <= 0) break;

                    $take = min($batch->remaining_quantity, $remaining);
                    $batch->decrement('remaining_quantity', $take);

                    $totalCOGS += $batch->buy_price * $take;
                    $remaining -= $take;

                    // 📦 Добавляем запись движения товара
                    StockMovement::query()->create([
                        'product_id' => $product->id,
                        'purchase_item_id' => $batch->id,
                        'type' => StockMovement::TYPE_OUT,
                        'quantity' => $take,
                        'reference_type' => StockMovement::REFERENCE_TYPE_SALE,
                        'reference_id' => $sale->id,
                    ]);
                }

                $lineTotal = $sellPrice * $quantity;
                $profit = $lineTotal - $totalCOGS;
                $total += $lineTotal;

                // 💰 Обновляем фонды
                FundsService::updateBalance(Fund::TYPE_COGS, $totalCOGS, StockMovement::REFERENCE_TYPE_SALE, $sale->id, 'Начисление себестоимости');
                FundsService::updateBalance(Fund::TYPE_PROFIT, $profit, StockMovement::REFERENCE_TYPE_SALE, $sale->id, 'Начисление прибыли');

                // 💾 Создаём элемент продажи
                SaleItem::query()->create([
                    'sale_id' => $sale->id,
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'sell_price' => $sellPrice,
                    'buy_price' => $totalCOGS / $quantity, // средняя цена по партиям
                ]);

                // Обновим общий остаток товара (опционально)
                $product->quantity = PurchaseItem::query()
                    ->where('product_id', $product->id)
                    ->where('is_return', 0)
                    ->sum('remaining_quantity');
                $product->save();
            }

            $sale->total = $total;
            $sale->save();

            DB::commit();

            return $this->sendJsonResponse(
                Response::HTTP_CREATED,
                [],
                $sale->load('items')
            );

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->sendJsonResponse(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['message' => $e->getMessage()]
            );
        }
    }

    public function show(Sale $sale): JsonResponse
    {
        $data = $sale->load('items');
        return $this->sendJsonResponse(
            Response::HTTP_OK,
            [],
            $data
        );
    }

    public function destroy(Sale $sale): JsonResponse
    {
        $sale->is_deleted = 1;
        $sale->save();
        return $this->sendJsonResponse(Response::HTTP_NO_CONTENT);
    }

    public function paid($id): JsonResponse
    {
        $sale = Sale::query()
            ->where('is_deleted', 0)
            ->find($id);
        $sale->paid = 1;
        $sale->save();
        return $this->sendJsonResponse(Response::HTTP_NO_CONTENT);
    }

    public function updateComment(Request $request, $id): JsonResponse
    {
        $request->validate([
            'comment' => 'nullable|string',
        ]);

        $sale = Sale::query()
            ->where('is_deleted', 0)
            ->findOrFail($id);

        $sale->comment = $request->input('comment');
        $sale->save();

        return $this->sendJsonResponse(
            Response::HTTP_OK,
            [],
            $sale->load(['items', 'returns'])
        );
    }

    public function returnItems(Request $request, $id): JsonResponse
    {
        $request->validate([
            'items' => 'required|array',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.comment' => 'nullable|string',
        ]);

        $sale = Sale::query()->where('is_deleted', 0)->findOrFail($id);

        DB::beginTransaction();
        try {
            $returnItems = $request->input('items');
            $totalRefund = 0;

            foreach ($returnItems as $it) {
                $productId = $it['product_id'];
                $returnQuantity = (int)$it['quantity'];
                $comment = $it['comment'] ?? null;

                $saleItem = SaleItem::query()
                    ->where('sale_id', $sale->id)
                    ->where('product_id', $productId)
                    ->with('product')
                    ->firstOrFail();

                if ($returnQuantity > $saleItem->quantity) {
                    return $this->sendJsonResponse(
                        Response::HTTP_UNPROCESSABLE_ENTITY,
                        ['message' => "Нельзя вернуть {$returnQuantity} шт. ({$saleItem->product->name}), доступно {$saleItem->quantity}"]
                    );
                }

                // 💸 Считаем суммы
                $refundAmount = $saleItem->sell_price * $returnQuantity;
                $refundCOGS = $saleItem->buy_price * $returnQuantity;
                $refundProfit = ($saleItem->sell_price - $saleItem->buy_price) * $returnQuantity;
                $totalRefund += $refundAmount;

                $purchaseItem = PurchaseItem::query()
                    ->where([
                        'product_id' => $productId,
                        'buy_price' => $saleItem->buy_price,
                        'is_return' => 0
                    ])
                    ->orderBy('id', 'desc')
                    ->first();

                // 📦 Добавляем возврат в FIFO-запасы (как новый приход)
                StockMovement::query()->create([
                    'product_id' => $productId,
                    'purchase_item_id' => $purchaseItem->id,
                    'type' => StockMovement::TYPE_IN,
                    'quantity' => $returnQuantity,
                    'reference_type' => StockMovement::REFERENCE_TYPE_SALE_RETURNS,
                    'reference_id' => $sale->id,
                ]);

                // Добавляем количество на склад
                $purchaseItem->quantity = $purchaseItem->quantity + $returnQuantity;
                $purchaseItem->remaining_quantity = $purchaseItem->remaining_quantity + $returnQuantity;
                $purchaseItem->save();

                // 💰 Обновляем фонды
                FundsService::updateBalance(Fund::TYPE_COGS, -$refundCOGS, StockMovement::REFERENCE_TYPE_SALE_RETURNS, $sale->id, 'Возврат себестоимости');
                FundsService::updateBalance(Fund::TYPE_PROFIT, -$refundProfit, StockMovement::REFERENCE_TYPE_SALE_RETURNS, $sale->id, 'Возврат прибыли');

                // Создаём запись возврата
                SaleReturn::query()->create([
                    'sale_id' => $sale->id,
                    'product_id' => $productId,
                    'quantity' => $returnQuantity,
                    'sell_price' => $saleItem->sell_price,
                    'buy_price' => $saleItem->buy_price,
                    'refund_amount' => $refundAmount,
                    'comment' => $comment,
                ]);

                $saleItem->delete();

                // Обновляем остатки товара
                $product = Product::query()->lockForUpdate()->find($productId);
                $product->quantity = PurchaseItem::query()
                    ->where('product_id', $productId)
                    ->where('is_return', 0)
                    ->sum('remaining_quantity');
                $product->save();
            }

            $sale->total -= $totalRefund;
            $sale->save();

            DB::commit();

            return $this->sendJsonResponse(
                Response::HTTP_OK,
                [],
                [
                    'sale' => $sale->load(['items', 'returns']),
                    'total_refund' => $totalRefund,
                ]
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->sendJsonResponse(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['message' => $e->getMessage()]
            );
        }
    }

    public function analytics(Request $request): JsonResponse
    {
        try {
            $result = InventoryAnalyticsService::getSummary($request->input('date_from'), $request->input('date_to'));
            return $this->sendJsonResponse(
                Response::HTTP_OK,
                [],
                $result
            );
        } catch (\Exception $e) {
            return $this->sendJsonResponse(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['message' => $e->getMessage()]
            );
        }
    }
}
