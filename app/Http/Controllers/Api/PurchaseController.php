<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePurchaseRequest;
use App\Http\Services\FundsService;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Product;
use App\Models\Dealer;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PurchaseController extends Controller
{
    public function index(Request $request)
    {
        $query = Purchase::query()->with(['dealer', 'items.product']);

        // Search filter (dealer name, notes)
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function($q) use ($search) {
                $q->whereHas('dealer', function($dealerQuery) use ($search) {
                    $dealerQuery->where('name', 'like', "%{$search}%");
                })->orWhere('notes', 'like', "%{$search}%");
            });
        }

        // Dealer filter
        if ($request->has('dealer_id')) {
            $query->where('dealer_id', $request->input('dealer_id'));
        }

        // Total amount range filters
        if ($request->has('min_amount')) {
            $query->where('total_amount', '>=', $request->input('min_amount'));
        }
        if ($request->has('max_amount')) {
            $query->where('total_amount', '<=', $request->input('max_amount'));
        }

        // Date range filters
        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }
        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        // Filter by return status
        if ($request->has('has_returns')) {
            $query->whereHas('items', function($q) {
                $q->where('is_return', true);
            });
        }

        // Sorting
        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = $request->input('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $purchases = $query->get();

        // Format response according to interface
        $formattedPurchases = $purchases->map(function ($purchase) {
            return [
                'id' => $purchase->id,
                'dealer_id' => $purchase->dealer_id,
                'dealer_name' => $purchase->dealer ? $purchase->dealer->name : null,
                'items' => $purchase->items->map(function ($item) {
                    return [
                        'product_id' => $item->product_id,
                        'product_name' => $item->product ? $item->product->name : null,
                        'quantity' => $item->quantity,
                        'buy_price' => (float) $item->buy_price,
                        'sell_price' => $item->product ? (float) $item->product->sell_price : (float) $item->buy_price,
                        'total_cost' => (float) ($item->quantity * $item->buy_price),
                        'is_return' => (bool) $item->is_return,
                    ];
                }),
                'total_amount' => (float) $purchase->total_amount,
                'status' => 'completed', // You can add logic to determine status
                'notes' => $purchase->notes,
                'created_at' => $purchase->created_at,
                'updated_at' => $purchase->updated_at,
            ];
        });

        return response()->json($formattedPurchases);
    }

    public function store(StorePurchaseRequest $request)
    {
        $data = $request->validated();

        return DB::transaction(function () use ($data) {
            $dealerId = $data['dealer_id'] ?? null;

            // If no dealer_id but dealer_name exists, create new dealer
            if (!$dealerId && isset($data['dealer_name'])) {
                $dealer = Dealer::query()->create([
                    'name' => $data['dealer_name'],
                    'status' => true,
                ]);
                $dealerId = $dealer->id;
            }

            // Create purchase
            $purchase = Purchase::query()->create([
                'dealer_id' => $dealerId,
                'total_amount' => $data['total_amount'],
                'notes' => $data['notes'] ?? null,
            ]);

            $used = FundsService::allocateFunds($data['total_amount'], 'purchase', $purchase->id);

            Log::info('Закуп оплачен', $used);

            // Process each item
            foreach ($data['items'] as $itemData) {
                $productId = $itemData['product_id'] ?? null;
                $isReturn = $itemData['is_return'] ?? false;

                // If no product_id but product_name exists, create new product
                if (!$productId && isset($itemData['product_name'])) {
                    $product = Product::query()->create([
                        'name' => $itemData['product_name'],
                        'buy_price' => $itemData['buy_price'],
                        'sell_price' => $itemData['sell_price'] ?? $itemData['buy_price'],
                        'quantity' => $isReturn ? 0 : $itemData['quantity'],
                    ]);
                    $productId = $product->id;
                } else if ($productId) {
                    // Update product quantity
                    $product = Product::query()->find($productId);
                    if ($product) {
                        if($itemData['buy_price'] != $product->buy_price){
                            $product->buy_price = $itemData['buy_price'];
                        }
                        if($itemData['sell_price'] != $product->sell_price){
                            $product->sell_price = $itemData['sell_price'];
                        }
                        if ($isReturn) {
                            $product->quantity -= $itemData['quantity'];
                        } else {
                            $product->quantity += $itemData['quantity'];
                        }
                        $product->save();
                    }
                }

                // Create purchase item
                PurchaseItem::query()->create([
                    'purchase_id' => $purchase->id,
                    'product_id' => $productId,
                    'quantity' => $itemData['quantity'],
                    'buy_price' => $itemData['buy_price'],
                    'is_return' => $isReturn,
                ]);
            }

            return response()->json($purchase->load(['dealer', 'items.product']), 201);
        });
    }

    public function show(Purchase $purchase)
    {
        return response()->json($purchase->load(['dealer', 'items.product']));
    }
    public function update(StorePurchaseRequest $request, Purchase $purchase)
    {
        $data = $request->validated();

        return DB::transaction(function () use ($data, $purchase) {
            // Revert old items' quantity changes
            foreach ($purchase->items as $oldItem) {
                if ($oldItem->product_id) {
                    $product = Product::query()->find($oldItem->product_id);
                    if ($product) {
                        if ($oldItem->is_return) {
                            $product->quantity += $oldItem->quantity;
                        } else {
                            $product->quantity -= $oldItem->quantity;
                        }
                        $product->save();
                    }
                }
            }

            // Delete old items
            $purchase->items()->delete();

            // Update dealer if needed
            $dealerId = $data['dealer_id'] ?? null;
            if (!$dealerId && isset($data['dealer_name'])) {
                $dealer = Dealer::query()->create([
                    'name' => $data['dealer_name'],
                    'status' => true,
                ]);
                $dealerId = $dealer->id;
            }

            // Update purchase
            $purchase->update([
                'dealer_id' => $dealerId ?? $purchase->dealer_id,
                'total_amount' => $data['total_amount'],
                'notes' => $data['notes'] ?? $purchase->notes,
            ]);

            // Process new items
            foreach ($data['items'] as $itemData) {
                $productId = $itemData['product_id'] ?? null;
                $isReturn = $itemData['is_return'] ?? false;

                // If no product_id but product_name exists, create new product
                if (!$productId && isset($itemData['product_name'])) {
                    $product = Product::query()->create([
                        'name' => $itemData['product_name'],
                        'buy_price' => $itemData['buy_price'],
                        'sell_price' => $itemData['sell_price'] ?? $itemData['buy_price'],
                        'quantity' => $isReturn ? 0 : $itemData['quantity'],
                    ]);
                    $productId = $product->id;
                } else if ($productId) {
                    // Update product quantity
                    $product = Product::query()->find($productId);
                        if($itemData['buy_price']!=$product->buy_price){
                            $product->buy_price = $itemData['buy_price'];
                        }
                        if($itemData['sell_price'] != $product->sell_price){
                            $product->sell_price = $itemData['sell_price'];
                        }
                    if ($product) {
                        if ($isReturn) {
                            $product->quantity -= $itemData['quantity'];
                        } else {
                            $product->quantity += $itemData['quantity'];
                        }
                        $product->save();
                    }
                }

                // Create purchase item
                PurchaseItem::query()->create([
                    'purchase_id' => $purchase->id,
                    'product_id' => $productId,
                    'quantity' => $itemData['quantity'],
                    'buy_price' => $itemData['buy_price'],
                    'is_return' => $isReturn,
                ]);
            }

            return response()->json($purchase->load(['dealer', 'items.product']));
        });
    }
    public function destroy(Purchase $purchase)
    {
        return DB::transaction(function () use ($purchase) {
            // Revert quantity changes for all items
            foreach ($purchase->items as $item) {
                if ($item->product_id) {
                    $product = Product::query()->find($item->product_id);
                    if ($product) {
                        if ($item->is_return) {
                            $product->quantity += $item->quantity;
                        } else {
                            $product->quantity -= $item->quantity;
                        }
                        $product->save();
                    }
                }
            }

            $purchase->delete();
            return response()->json(null, 204);
        });
    }
}
