<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreNoteRequest;
use App\Models\Note;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NoteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Note::query()->where('is_deleted', 0);

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('text', 'like', "%{$search}%");
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

    public function store(StoreNoteRequest $request): JsonResponse
    {
        $data = $request->validated();

        $note = Note::query()->create([
            'text' => $data['text'],
        ]);

        return $this->sendJsonResponse(
            Response::HTTP_CREATED,
            [],
            $note
        );
    }

    public function show(Note $note): JsonResponse
    {
        return $this->sendJsonResponse(
            Response::HTTP_OK,
            [],
            $note
        );
    }

    public function update(StoreNoteRequest $request, Note $note): JsonResponse
    {
        $data = $request->validated();
        $note->update([
            'text' => $data['text'] ?? $note->text
        ]);

        return $this->sendJsonResponse(
            Response::HTTP_OK,
            [],
            $note
        );
    }

    public function destroy(Note $note): JsonResponse
    {
        $note->is_deleted = 1;
        $note->save();
        return $this->sendJsonResponse(Response::HTTP_CREATED);
    }
}
