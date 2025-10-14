<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSaleRequest;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SaleController extends Controller
{
    public function index()
    {
        $sales = Sale::query()
            ->where('is_deleted', 0)
            ->with([ 'returns'])
            ->with(['items' => function ($query) {
                $query->where('quantity', '>', 0);
            }])
            ->orderBy('created_at', 'desc')
            ->get();
        return response()->json($sales);
    }

    public function store(StoreSaleRequest $request)
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

                $profit = ($sellPrice - $product->buy_price) * $quantity;
                $lineTotal = $sellPrice * $quantity;
                $total += $lineTotal;

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

                // Создать запись о возврате
                $refundAmount = $saleItem->sell_price * $returnQuantity;
                $totalRefund += $refundAmount;

                SaleReturn::create([
                    'sale_id' => $sale->id,
                    'product_id' => $productId,
                    'product_name' => $saleItem->name,
                    'quantity' => $returnQuantity,
                    'sell_price' => $saleItem->sell_price,
                    'buy_price' => $saleItem->buy_price,
                    'refund_amount' => $refundAmount,
                    'comment' => $comment,
                ]);
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
}
