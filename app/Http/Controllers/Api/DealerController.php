<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDealerRequest;
use App\Models\Dealer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DealerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Dealer::query();

        // Search filter (name)
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('name', 'like', "%{$search}%");
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

        // Sorting
        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = $request->input('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        return $this->sendJsonResponse(
            Response::HTTP_OK,
            [],
            $query->get()
        );
    }

    public function store(StoreDealerRequest $request): JsonResponse
    {
        $data = $request->validated();

        $dealer = Dealer::query()->create([
            'name' => $data['name'],
            'status' => $data['status'] ?? true,
        ]);

        return $this->sendJsonResponse(
            Response::HTTP_CREATED,
            [],
            $dealer
        );
    }

    public function show(Dealer $dealer): JsonResponse
    {
        return $this->sendJsonResponse(
            Response::HTTP_OK,
            [],
            $dealer
        );
    }

    public function update(StoreDealerRequest $request, Dealer $dealer): JsonResponse
    {
        $data = $request->validated();

        $dealer->update([
            'name' => $data['name'] ?? $dealer->name,
            'status' => $data['status'] ?? $dealer->status,
        ]);

        return $this->sendJsonResponse(
            Response::HTTP_OK,
            [],
            $dealer
        );
    }

    public function destroy(Dealer $dealer): JsonResponse
    {
        $dealer->delete();
        return $this->sendJsonResponse(Response::HTTP_NO_CONTENT);
    }
}
