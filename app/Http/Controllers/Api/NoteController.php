<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreNoteRequest;
use App\Models\Note;
use Illuminate\Http\Request;

class NoteController extends Controller
{
    public function index(Request $request)
    {
        $query = Note::query()->where('is_deleted', 0);

        // Search filter (text)
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('text', 'like', "%{$search}%");
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
        $sortOrder = $request->input('sort_order', 'asc');
        $query->orderBy($sortBy, $sortOrder);

        return response()->json($query->get());
    }

    public function store(StoreNoteRequest $request)
    {
        $data = $request->validated();

        $note = Note::query()->create([
            'text' => $data['text'],
        ]);

        return response()->json($data['text'], 200);
    }

    public function show(Note $note)
    {
        return response()->json($note);
    }

    public function update(StoreNoteRequest $request, Note $note)
    {
        $data = $request->validated();
        $note->update([
            'text' => $data['text'] ?? $note->text
        ]);

        return response()->json($note);
    }

    public function destroy(Note $note)
    {
        $note->is_deleted = 1;
        $note->save();
        return response()->json(null, 204);
    }
}
