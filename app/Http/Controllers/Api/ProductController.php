<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Models\Product;

class ProductController extends Controller
{
    public function index()
    {
        return response()->json(Product::query()
            ->where('is_deleted', 0)
            ->orderBy('id')
            ->get());
    }

    public function store(StoreProductRequest $request)
    {
        $data = $request->validated();

        $product = Product::query()->create([
            'name' => $data['name'],
            'buy_price' => $data['buy_price'] ?? $data['buy_price'] ?? 0,
            'sell_price' => $data['sell_price'] ?? $data['sell_price'] ?? 0,
            'quantity' => $data['quantity'] ?? 0,
        ]);

        return response()->json($product, 201);
    }

    public function show(Product $product)
    {
        return response()->json($product);
    }

    public function update(StoreProductRequest $request, Product $product)
    {
        $data = $request->validated();
        $product->update([
            'name' => $data['name'] ?? $product->name,
            'buy_price' => $data['buy_price'] ?? $product->buy_price,
            'sell_price' => $data['sell_price'] ?? $product->sell_price,
            'quantity' => $data['quantity'] ?? $product->quantity,
        ]);

        return response()->json($product);
    }

    public function destroy(Product $product)
    {
        $product->is_deleted = 1;
        $product->save();
        return response()->json(null, 204);
    }
}
