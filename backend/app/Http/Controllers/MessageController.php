<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\MessageThread;
use App\Services\Messages\MessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MessageController extends Controller
{
    public function __construct(private readonly MessageService $messages) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->messages->listThreadsFor($request->user()),
        ]);
    }

    public function show(Request $request, MessageThread $thread): JsonResponse
    {
        return response()->json([
            'data' => $this->messages->listMessagesFor($request->user(), $thread),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'thread_id' => ['nullable', 'exists:message_threads,id'],
            'job_id' => ['nullable', 'exists:jobs,id'],
            'recipient_id' => [
                'required_without:thread_id',
                'exists:users,id',
                Rule::notIn([$user->id]),
            ],
            'subject' => ['required_without:thread_id', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:5000'],
            'attachments' => ['array'],
            'attachments.*' => ['file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
        ]);

        if (empty($validated['body']) && ! $request->hasFile('attachments')) {
            return response()->json([
                'message' => 'A message body or an attachment is required.',
            ], 422);
        }

        return response()->json(
            $this->messages->send($user, $validated, $request->file('attachments', [])),
            201
        );
    }

    public function markAsViewed(Request $request, Message $message): JsonResponse
    {
        return response()->json(
            $this->messages->markAsViewed($request->user(), $message)
        );
    }
}
