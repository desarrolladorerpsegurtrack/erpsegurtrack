<?php

namespace App\Support;

use Illuminate\Support\Collection;

final class TicketFlowProgress
{
    private const SEQUENTIAL_VISTA_IDS = [3, 6, 7];

    public static function isComplete(object $rule, array $progress): bool
    {
        return \in_array(
            $progress[(int) $rule->idflujoregla] ?? null,
            ['completado', 'finalizado'],
            true
        );
    }

    public static function isEligible(object $rule, Collection $rules, array $progress): bool
    {
        if (!\in_array((int) $rule->vista_idvista, self::SEQUENTIAL_VISTA_IDS, true)) {
            return true;
        }

        return self::arePreviousRulesComplete($rule, $rules, $progress);
    }

    public static function arePreviousRulesComplete(object $rule, Collection $rules, array $progress): bool
    {
        $index = $rules->search(
            fn ($candidate) => (int) $candidate->idflujoregla === (int) $rule->idflujoregla
        );
        if ($index === false) {
            return false;
        }

        foreach ($rules->take($index) as $previousRule) {
            if (!self::isComplete($previousRule, $progress)) {
                return false;
            }
        }

        return true;
    }
}
