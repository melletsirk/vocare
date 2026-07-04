<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class AuditService
{
    /**
     * Registra un evento de auditoría.
     *
     * @param string $event       Nombre del evento (login, logout, created, updated, deleted...)
     * @param mixed  $auditable   El modelo afectado (opcional)
     * @param array  $oldValues   Valores anteriores (opcional)
     * @param array  $newValues   Valores nuevos (opcional)
     */
    public static function log(
        string $event,
        mixed $auditable = null,
        array $oldValues = [],
        array $newValues = []
    ): void {
        AuditLog::create([
            'user_id'        => Auth::id(),
            'event'          => $event,
            'auditable_type' => $auditable ? get_class($auditable) : null,
            'auditable_id'   => $auditable?->getKey(),
            'old_values'     => $oldValues ?: null,
            'new_values'     => $newValues ?: null,
            'ip_address'     => Request::ip(),
            'user_agent'     => Request::userAgent(),
        ]);
    }
}
