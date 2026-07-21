<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class CorrelativoService
{
    /**
     * Resolve a requested correlativo for a tipo_documento.
     * Returns array with keys: accepted(bool), final(int), reason(string).
     */
    public static function resolveCorrelativo(int $tipoId, ?int $requested, string $table = 'compras', string $idColumn = 'idcompras'): array
    {
        return DB::transaction(function () use ($tipoId, $requested, $table, $idColumn) {
            $td = DB::table('tipodocumento')->where('idtipoDocumento', $tipoId)->lockForUpdate()->first();
            $valorMaximo = (int) ($td->correlativo ?? 0);

            // If no request provided, keep current
            if ($requested === null) {
                return ['accepted' => true, 'final' => $valorMaximo, 'reason' => 'no_change_requested'];
            }

            $candidate = (int) $requested;

            if ($candidate === $valorMaximo) {
                return ['accepted' => true, 'final' => $valorMaximo, 'reason' => 'equal_to_current'];
            }

            $serie = trim((string) ($td->serie ?? ''));

            // Helper: format an id using serie + zero-padded number
            $formatId = function (int $num) use ($serie) {
                if ($serie === '') {
                    return sprintf('%05d', $num);
                }
                return $serie . sprintf('%05d', $num);
            };

            // Check existence function (table and column are configurable)
            $existsInDocs = function (int $num) use ($tipoId, $formatId, $table, $idColumn) {
                $full = $formatId($num);
                return DB::table($table)->where($idColumn, $full)->exists();
            };

            // New rule: reject candidate if candidate+1 already exists
            $candidatePlusOne = $candidate + 1;

            // Helper to check candidate+1 existence
            $existsPlusOne = $existsInDocs($candidatePlusOne);

            if ($candidate > $valorMaximo) {
                // For greater candidate: reject only when candidate+1 already exists.
                // If candidate itself exists but candidate+1 does NOT exist, accept the candidate
                if ($existsPlusOne) {
                    return ['accepted' => false, 'final' => $valorMaximo, 'reason' => 'next_already_used'];
                }

                if ($existsInDocs($candidate)) {
                    return ['accepted' => true, 'final' => $candidate, 'reason' => 'accepted_greater_but_candidate_exists_next_free'];
                }

                // candidate and candidate+1 do not exist -> accept greater
                return ['accepted' => true, 'final' => $candidate, 'reason' => 'accepted_greater'];
            }

            // candidate < valorMaximo
            // If candidate+1 exists, reject
            if ($existsPlusOne) {
                return ['accepted' => false, 'final' => $valorMaximo, 'reason' => 'next_already_used'];
            }

            // If candidate itself exists but candidate+1 does not, it's allowed
            if ($existsInDocs($candidate)) {
                // allowed to accept candidate when candidate+1 does not exist;
                // persist the candidate as final (do not jump to max used)
                return ['accepted' => true, 'final' => $candidate, 'reason' => 'accepted_candidate_exists_but_next_free'];
            }

            // candidate does not exist and candidate+1 does not exist -> check free spaces logic
            $diferencia = $valorMaximo - $candidate;
            $elementosDeRango = DB::table($table)
                ->where('tipoDocumento_idtipoDocumento', $tipoId)
                ->whereRaw('CAST(RIGHT(' . $idColumn . ', 5) AS UNSIGNED) > ? AND CAST(RIGHT(' . $idColumn . ', 5) AS UNSIGNED) <= ?', [$candidate, $valorMaximo])
                ->count();
            $espaciosLibres = $diferencia - $elementosDeRango;
            if ($espaciosLibres > 0) {
                return ['accepted' => true, 'final' => $candidate, 'reason' => 'accepted_with_free_spaces', 'espacios_libres' => $espaciosLibres];
            }

            // No free spaces
            return ['accepted' => false, 'final' => $valorMaximo, 'reason' => 'no_free_spaces', 'espacios_libres' => $espaciosLibres];
        });
    }

    /**
     * Allocate next correlativo for creating a new document.
     * Returns array with keys: next(int), formatted(string), serie(string), max_used(int)
     */
    public static function allocateNext(int $tipoId, string $table = 'compras', string $idColumn = 'idcompras'): array
    {
        // lock the tipo_documento row
        $td = DB::table('tipodocumento')->where('idtipoDocumento', $tipoId)->lockForUpdate()->first();
        $valorMaximo = (int) ($td->correlativo ?? 0);
        $serie = trim((string) ($td->serie ?? ''));

        // find maximum used numeric suffix in the target table for this tipo
        $maxUsed = DB::table($table)
            ->where('tipoDocumento_idtipoDocumento', $tipoId)
            ->selectRaw('MAX(CAST(RIGHT(' . $idColumn . ', 5) AS UNSIGNED)) as m')
            ->value('m');
        $maxUsed = (int) ($maxUsed ?? 0);

        // Try to find the first free number >= valorMaximo+1 up to maxUsed
        $start = $valorMaximo + 1;
        $next = null;
        if ($start <= $maxUsed) {
            for ($n = $start; $n <= $maxUsed; $n++) {
                $full = ($serie === '') ? sprintf('%05d', $n) : $serie . sprintf('%05d', $n);
                $exists = DB::table($table)->where($idColumn, $full)->exists();
                if (!$exists) {
                    $next = $n;
                    break;
                }
            }
        }

        if ($next === null) {
            // no free in range, pick max(maxUsed, valorMaximo) + 1
            $next = max($maxUsed, $valorMaximo) + 1;
        }

        // Persist only the allocated next correlativo
        DB::table('tipodocumento')->where('idtipoDocumento', $tipoId)->update(['correlativo' => $next]);

        $formatted = ($serie === '') ? sprintf('%05d', $next) : $serie . sprintf('%05d', $next);

        return ['next' => $next, 'formatted' => $formatted, 'serie' => $serie, 'max_used' => $maxUsed, 'persisted' => $next];
    }
}
