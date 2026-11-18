<?php

namespace Tests\Unit;

use App\Http\Controllers\TicketsController;
use App\Support\ResourceLock;
use App\Support\TicketFlowProgress;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class TicketFlowProgressTest extends TestCase
{
    public function test_only_views_three_six_and_seven_wait_for_all_previous_views(): void
    {
        $rules = new Collection([
            (object) ['idflujoregla' => 1, 'vista_idvista' => 1],
            (object) ['idflujoregla' => 2, 'vista_idvista' => 2],
            (object) ['idflujoregla' => 3, 'vista_idvista' => 3],
            (object) ['idflujoregla' => 4, 'vista_idvista' => 4],
            (object) ['idflujoregla' => 5, 'vista_idvista' => 5],
            (object) ['idflujoregla' => 6, 'vista_idvista' => 6],
            (object) ['idflujoregla' => 7, 'vista_idvista' => 7],
        ]);
        $progress = [1 => 'atendiendo'];

        $this->assertTrue(TicketFlowProgress::isEligible($rules[3], $rules, $progress));
        $this->assertFalse(TicketFlowProgress::isEligible($rules[2], $rules, $progress));

        $progress[1] = 'completado';
        $this->assertFalse(TicketFlowProgress::isEligible($rules[2], $rules, $progress));

        $progress[2] = 'finalizado';
        $this->assertTrue(TicketFlowProgress::isEligible($rules[2], $rules, $progress));
        $this->assertFalse(TicketFlowProgress::isEligible($rules[5], $rules, $progress));
        $this->assertFalse(TicketFlowProgress::isEligible($rules[6], $rules, $progress));

        $progress[3] = 'completado';
        $progress[4] = 'completado';
        $progress[5] = 'completado';
        $this->assertTrue(TicketFlowProgress::isEligible($rules[5], $rules, $progress));
        $this->assertFalse(TicketFlowProgress::isEligible($rules[6], $rules, $progress));
        $progress[6] = 'completado';
        $this->assertTrue(TicketFlowProgress::isEligible($rules[6], $rules, $progress));
    }

    public function test_locking_one_view_does_not_block_another_view_of_the_same_ticket(): void
    {
        config(['locks.enabled' => true, 'cache.default' => 'array']);
        Cache::flush();

        $firstView = ResourceLock::acquire('ticket.vista', '42:1', 'user.one');
        $otherView = ResourceLock::acquire('ticket.vista', '42:4', 'user.two');
        $sameView = ResourceLock::acquire('ticket.vista', '42:1', 'user.three');

        $this->assertTrue($firstView['success']);
        $this->assertTrue($otherView['success']);
        $this->assertFalse($sameView['success']);
    }

    public function test_continue_resolves_the_next_view_only_when_authorized_and_unlocked(): void
    {
        config(['locks.enabled' => true, 'cache.default' => 'array']);
        Cache::flush();

        $rules = new Collection([
            (object) ['idflujoregla' => 1, 'vista_idvista' => 1],
            (object) ['idflujoregla' => 2, 'vista_idvista' => 2],
            (object) ['idflujoregla' => 3, 'vista_idvista' => 3],
        ]);
        $controller = app(TicketsController::class);
        $method = new \ReflectionMethod(TicketsController::class, 'resolveNextAvailableRule');

        $nextRule = $method->invoke($controller, $rules[0], $rules, [1 => 'completado'], [1, 2, 3], false, 42, 'user.one');
        $this->assertSame(2, (int) $nextRule->vista_idvista);

        $unauthorizedNextRule = $method->invoke($controller, $rules[0], $rules, [1 => 'completado'], [1], false, 42, 'user.one');
        $this->assertNull($unauthorizedNextRule);

        $nextSequentialRule = $method->invoke(
            $controller,
            $rules[1],
            $rules,
            [1 => 'completado', 2 => 'atendiendo'],
            [1, 2, 3],
            false,
            42,
            'user.one'
        );
        $this->assertSame(3, (int) $nextSequentialRule->vista_idvista);

        $blockedSequentialRule = $method->invoke(
            $controller,
            $rules[1],
            $rules,
            [],
            [1, 2, 3],
            false,
            43,
            'user.one'
        );
        $this->assertNull($blockedSequentialRule);

        ResourceLock::acquire('ticket.vista', '42:2', 'user.two');
        $lockedNextRule = $method->invoke($controller, $rules[0], $rules, [1 => 'completado'], [1, 2, 3], false, 42, 'user.one');
        $this->assertNull($lockedNextRule);
    }
}
