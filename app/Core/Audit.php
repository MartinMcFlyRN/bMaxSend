<?php

declare(strict_types=1);

namespace App\Core;

final class Audit
{
    public static function log(string $action, array|string|null $details = null): void
    {
        try {
            DB::insert('audit_log', [
                'user_id' => Auth::id(),
                'actor_id' => Auth::realId(),
                'action' => $action,
                'details' => is_array($details) ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $details,
                'ip' => PHP_SAPI === 'cli' ? 'cli' : client_ip(),
                'created_at' => now_utc(),
            ]);
        } catch (\Throwable $e) {
            App::log('audit', $e->getMessage());
        }
    }
}
