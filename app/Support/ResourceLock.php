<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class ResourceLock
{
    public const TTL_SECONDS = 120;
    public const CACHE_PREFIX = 'lock.';

    public static function enabled(): bool
    {
        return config('locks.enabled', true);
    }

    public static function ttl(): int
    {
        return (int) config('locks.ttl', self::TTL_SECONDS);
    }

    public static function cachePrefix(): string
    {
        return (string) config('locks.cache_prefix', self::CACHE_PREFIX);
    }

    public static function cacheKey(string $resource, string $id): string
    {
        $resourcePrefix = trim((string) config('locks.resource_prefix', ''));

        if ($resourcePrefix !== '') {
            $resource = $resourcePrefix . '.' . trim($resource);
        }

        return self::cachePrefix() . trim($resource) . '.' . trim($id);
    }

    public static function acquire(string $resource, string $id, string $usuario): array
    {
        if (! self::enabled()) {
            return [
                'success' => true,
                'lock' => null,
            ];
        }

        $key = self::cacheKey($resource, $id);
        $now = Carbon::now();
        $lock = [
            'resource' => $resource,
            'id' => $id,
            'usuario' => $usuario,
            'created_at' => $now->toDateTimeString(),
            'expires_at' => $now->copy()->addSeconds(self::ttl())->toDateTimeString(),
        ];

        // Atomic path: if key does not exist, lock is acquired immediately.
        if (Cache::add($key, $lock, self::ttl())) {
            return [
                'success' => true,
                'lock' => $lock,
            ];
        }

        $existing = Cache::get($key);

        if (!$existing) {
            if (Cache::add($key, $lock, self::ttl())) {
                return [
                    'success' => true,
                    'lock' => $lock,
                ];
            }

            $existing = Cache::get($key);
        }

        if ($existing && isset($existing['expires_at']) && Carbon::parse($existing['expires_at'])->isPast()) {
            Cache::forget($key);

            if (Cache::add($key, $lock, self::ttl())) {
                return [
                    'success' => true,
                    'lock' => $lock,
                ];
            }

            $existing = Cache::get($key);
        }

        if ($existing && ($existing['usuario'] ?? null) === $usuario) {
            $renewed = [
                'resource' => $resource,
                'id' => $id,
                'usuario' => $usuario,
                'created_at' => $existing['created_at'] ?? $lock['created_at'],
                'expires_at' => $lock['expires_at'],
            ];

            Cache::put($key, $renewed, self::ttl());

            return [
                'success' => true,
                'lock' => $renewed,
            ];
        }

        return [
            'success' => false,
            'lock' => $existing,
        ];
    }

    public static function release(string $resource, string $id, string $usuario): array
    {
        if (! self::enabled()) {
            return [
                'success' => true,
                'lock' => null,
            ];
        }

        $key = self::cacheKey($resource, $id);
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

    public static function status(string $resource, string $id): ?array
    {
        if (! self::enabled()) {
            return null;
        }

        $key = self::cacheKey($resource, $id);
        $lock = Cache::get($key);

        if (!$lock || (isset($lock['expires_at']) && Carbon::parse($lock['expires_at'])->isPast())) {
            if ($lock) {
                Cache::forget($key);
            }

            return null;
        }

        return $lock;
    }
}
