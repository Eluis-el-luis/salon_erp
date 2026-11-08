<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * CONTROLADOR DE NOTIFICACIONES (API para campanita).
 *
 * Endpoints usados por el componente Alpine notificationBell:
 * - GET /notificaciones/api/count     → contador de no leídas
 * - GET /notificaciones/api/list      → lista de notificaciones (dropdown)
 * - POST /notificaciones/api/{id}/read → marcar una como leída
 * - POST /notificaciones/api/read-all  → marcar todas como leídas
 */
class NotificationController extends Controller
{
    public function __construct(private NotificationService $notifications) {}

    /**
     * Contador de notificaciones no leídas del usuario autenticado.
     */
    public function count(): JsonResponse
    {
        $count = $this->notifications->countUnreadForAuth();
        return response()->json(['count' => $count]);
    }

    /**
     * Lista de notificaciones para el dropdown (últimas 20).
     */
    public function list(): JsonResponse
    {
        $notifications = $this->notifications->getAllForAuth(20);

        return response()->json([
            'notifications' => $notifications->map(function ($n) {
                return [
                    'id' => $n->id,
                    'type' => $n->type,
                    'title' => $n->title,
                    'message' => $n->message,
                    'data' => $n->data,
                    'priority' => $n->priority,
                    'read' => $n->read,
                    'created_at' => $n->created_at->toISOString(),
                ];
            }),
        ]);
    }

    /**
     * Marca una notificación como leída.
     */
    public function markRead(int $id): JsonResponse
    {
        $updated = $this->notifications->markAsRead([$id]);
        return response()->json(['success' => $updated > 0]);
    }

    /**
     * Marca todas las notificaciones como leídas.
     */
    public function markAllRead(): JsonResponse
    {
        $updated = $this->notifications->markAllAsRead();
        return response()->json(['success' => true, 'updated' => $updated]);
    }

    /**
     * Vista completa de notificaciones (página "Ver todas").
     */
    public function index()
    {
        $notifications = Notification::where('user_id', auth()->id())
            ->orderByDesc('read')
            ->orderByDesc('created_at')
            ->paginate(30);

        return view('notifications.index', compact('notifications'));
    }
}