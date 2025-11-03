<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Product::query()->where('is_deleted', 0);

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('name', 'like', "%{$search}%");
        }

        if ($request->has('min_buy_price')) {
            $query->where('buy_price', '>=', $request->input('min_buy_price'));
        }
        if ($request->has('max_buy_price')) {
            $query->where('buy_price', '<=', $request->input('max_buy_price'));
        }
        if ($request->has('min_sell_price')) {
            $query->where('sell_price', '>=', $request->input('min_sell_price'));
        }
        if ($request->has('max_sell_price')) {
            $query->where('sell_price', '<=', $request->input('max_sell_price'));
        }

        if ($request->has('min_quantity')) {
            $query->where('quantity', '>=', $request->input('min_quantity'));
        }
        if ($request->has('max_quantity')) {
            $query->where('quantity', '<=', $request->input('max_quantity'));
        }

        if ($request->has('low_stock') && $request->input('low_stock')) {
            $threshold = $request->input('low_stock_threshold', 10);
            $query->where('quantity', '<=', $threshold);
        }

        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }
        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = $request->input('sort_order', 'asc');
        $query->orderBy($sortBy, $sortOrder);

        return $this->sendJsonResponse(
            Response::HTTP_OK,
            [],
            $query->get()
        );
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $data = $request->validated();

        $product = Product::query()->create([
            'name' => $data['name'],
            'buy_price' => $data['buy_price'] ?? $data['buy_price'] ?? 0,
            'sell_price' => $data['sell_price'] ?? $data['sell_price'] ?? 0,
            'quantity' => $data['quantity'] ?? 0,
        ]);

        return $this->sendJsonResponse(
            Response::HTTP_CREATED,
            [],
            $product
        );
    }

    public function show(Product $product): JsonResponse
    {
        return $this->sendJsonResponse(
            Response::HTTP_OK,
            [],
            $product
        );
    }

    public function update(StoreProductRequest $request, Product $product): JsonResponse
    {
        $data = $request->validated();
        $product->update([
            'name' => $data['name'] ?? $product->name,
            'buy_price' => $data['buy_price'] ?? $product->buy_price,
            'sell_price' => $data['sell_price'] ?? $product->sell_price,
            'quantity' => $data['quantity'] ?? $product->quantity,
        ]);

        return $this->sendJsonResponse(
            Response::HTTP_OK,
            [],
            $product
        );
    }

    public function destroy(Product $product): JsonResponse
    {
        $product->is_deleted = 1;
        $product->save();
        return $this->sendJsonResponse(Response::HTTP_NO_CONTENT);
    }
}
