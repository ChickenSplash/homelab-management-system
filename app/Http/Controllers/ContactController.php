<?php

namespace App\Http\Controllers;

use App\Mail\ContactMessageReceived;
use App\Models\Message;
use App\Support\ClientIp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class ContactController
{
    public function store(Request $request): JsonResponse
    {
        // Match the existing API: silently accept honeypot submissions without storing them.
        if (filled($request->input('website'))) {
            return response()->json(['ok' => true], 201);
        }

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'subject' => ['required', 'string', 'max:150'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $message = Message::create([
            'email' => $validated['email'],
            'subject' => $validated['subject'],
            'body' => $validated['message'],
            'ip' => ClientIp::of($request),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
        ]);

        // Save first: a mail failure must not lose a visitor's message.
        if ($admin = config('app.admin_email')) {
            try {
                Mail::to($admin)->send(new ContactMessageReceived($message));
            } catch (Throwable $e) {
                report($e);
            }
        }

        return response()->json(['ok' => true], 201);
    }

    public function submit(Request $request): JsonResponse|RedirectResponse
    {
        $response = $this->store($request);

        return $request->expectsJson()
            ? $response
            : redirect('/#contact')->with('contact_sent', true);
    }
}
