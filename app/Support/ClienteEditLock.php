<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class ClienteEditLock
{
    public const TTL_SECONDS = 120;
    public const CACHE_PREFIX = 'clientes.edit.lock.';

    public static function cacheKey(string $clienteId): string
    {
        return self::CACHE_PREFIX . trim($clienteId);
    }

    public static function acquire(string $clienteId, string $usuario): array
    {
        $key = self::cacheKey($clienteId);
        $existing = Cache::get($key);

        if ($existing && isset($existing['expires_at']) && Carbon::parse($existing['expires_at'])->isFuture() && $existing['usuario'] !== $usuario) {
            return [
                'success' => false,
                'lock' => $existing,
            ];
        }

        $now = Carbon::now();
        $lock = [
            'usuario' => $usuario,
            'created_at' => $now->toDateTimeString(),
            'expires_at' => $now->copy()->addSeconds(self::TTL_SECONDS)->toDateTimeString(),
        ];

        Cache::put($key, $lock, self::TTL_SECONDS);

        return [
            'success' => true,
            'lock' => $lock,
        ];
    }

    public static function release(string $clienteId, string $usuario): array
    {
        $key = self::cacheKey($clienteId);
        $existing = Cache::get($key);

        if (!$existing || $existing['usuario'] !== $usuario) {
            return [
                'success' => false,
                'lock' => $existing,
            ];
        }

        Cache::forget($key);

        return [
            'success' => true,
            'lock' => $existing,
        ];
    }

    public static function status(string $clienteId): ?array
    {
        $lock = Cache::get(self::cacheKey($clienteId));

        if (!$lock || (isset($lock['expires_at']) && Carbon::parse($lock['expires_at'])->isPast())) {
            return null;
        }

        return $lock;
    }
}
