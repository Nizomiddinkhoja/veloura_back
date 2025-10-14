<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreNoteRequest;
use App\Models\Note;

class NoteController extends Controller
{
    public function index()
    {
        return response()->json(Note::query()
            ->where('is_deleted', 0)
            ->orderBy('id')
            ->get());
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
