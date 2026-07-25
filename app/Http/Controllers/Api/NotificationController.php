<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        return Notification::where('user_id', $request->user()->id)
            ->when($request->has('unread'), fn ($q) => $q->where('is_read', false))
            ->latest()->paginate($request->integer('per_page', 25));
    }

    public function markRead(Request $request, Notification $notification)
    {
        abort_unless($request->attributes->get('auth_type') === 'passenger'
            && $notification->user_id === $request->user()->id, 403);

        $notification->update(['is_read' => true]);

        return $notification;
    }
}
