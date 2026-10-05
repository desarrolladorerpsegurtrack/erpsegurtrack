<?php

namespace Tests\Unit;

use App\Services\CxcService;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CxcPaymentBreakdownTest extends TestCase
{
    #[DataProvider('deductionCases')]
    public function test_deduction_uses_gross_threshold_and_client_type(
        float $gross,
        bool $isRetention,
        float $expectedDeduction,
        float $expectedCashDue
    ): void {
        $result = CxcService::calculatePaymentBreakdown($gross, $isRetention, 0, false, 0);

        $this->assertSame($expectedDeduction, $result['deduction']);
        $this->assertSame($expectedCashDue, $result['cash_due']);
    }

    public static function deductionCases(): array
    {
        return [
            'exact threshold has no deduction' => [700.00, false, 0.00, 700.00],
            'standard detraccion' => [1284.00, false, 154.08, 1129.92],
            'retention client' => [1284.00, true, 38.52, 1245.48],
        ];
    }

    public function test_credit_is_optional_and_applied_after_deduction(): void
    {
        $withoutCredit = CxcService::calculatePaymentBreakdown(1000, false, 200, false, 880);
        $withCredit = CxcService::calculatePaymentBreakdown(1000, false, 200, true, 680);

        $this->assertSame(0.0, $withoutCredit['credit_applied']);
        $this->assertSame(880.0, $withoutCredit['cash_applied']);
        $this->assertSame(200.0, $withCredit['credit_applied']);
        $this->assertSame(680.0, $withCredit['cash_applied']);
        $this->assertSame(0.0, $withCredit['remaining_debt']);
    }

    public function test_overpayment_becomes_new_credit_after_deduction(): void
    {
        $result = CxcService::calculatePaymentBreakdown(1000, false, 0, false, 930);

        $this->assertSame(880.0, $result['cash_applied']);
        $this->assertSame(50.0, $result['new_credit']);
        $this->assertSame(0.0, $result['remaining_debt']);
    }

    public function test_5760_detraccion_uses_the_net_amount_of_5068_80(): void
    {
        $result = CxcService::calculatePaymentBreakdown(5760, false, 0, false, 0);
        $paidGross = CxcService::calculatePaymentBreakdown(5760, false, 0, false, 5760);

        $this->assertSame(691.20, $result['deduction']);
        $this->assertSame(5068.80, $result['cash_due']);
        $this->assertSame(5068.80, $paidGross['cash_applied']);
        $this->assertSame(691.20, $paidGross['new_credit']);
    }

    public function test_withholding_can_remain_unapplied_until_proof_is_confirmed(): void
    {
        $pendingDetraction = CxcService::calculatePaymentBreakdown(750, false, 0, false, 660, false);
        $pendingRetention = CxcService::calculatePaymentBreakdown(750, true, 0, false, 727.50, false);

        $this->assertSame(0.0, $pendingDetraction['deduction']);
        $this->assertSame(90.0, $pendingDetraction['remaining_debt']);
        $this->assertSame(0.0, $pendingRetention['deduction']);
        $this->assertSame(22.5, $pendingRetention['remaining_debt']);
    }

    public function test_partial_payment_before_period_end_uses_partial_state(): void
    {
        $state = CxcService::resolvePaymentState(
            25.00,
            0.00,
            'Contado',
            false,
            '2026-09-30',
            Carbon::parse('2026-09-29')
        );

        $this->assertSame('Pendiente Pago parcial', $state);
    }

    public function test_credit_payment_before_period_end_uses_credit_state(): void
    {
        $state = CxcService::resolvePaymentState(
            25.00,
            0.00,
            'Crédito 30 días',
            false,
            '2026-09-30',
            Carbon::parse('2026-09-29')
        );

        $this->assertSame('Pendiente a credito', $state);
    }

    public function test_open_balance_becomes_overdue_only_after_period_end(): void
    {
        $stateOnEndDate = CxcService::resolvePaymentState(
            25.00,
            0.00,
            'Contado',
            false,
            '2026-09-27',
            Carbon::parse('2026-09-27')
        );
        $stateAfterEndDate = CxcService::resolvePaymentState(
            25.00,
            0.00,
            'Contado',
            false,
            '2026-09-27',
            Carbon::parse('2026-09-28')
        );

        $this->assertSame('Pendiente Pago parcial', $stateOnEndDate);
        $this->assertSame('4', $stateAfterEndDate);
    }

    public function test_full_deduction_payment_uses_detraccion_state_but_retention_does_not(): void
    {
        $detraccionState = CxcService::resolvePaymentState(0.00, 12.00, 'Contado', false, '2026-09-30');
        $retentionState = CxcService::resolvePaymentState(0.00, 3.00, 'Contado', true, '2026-09-30');

        $this->assertSame('Cancelado Detracción', $detraccionState);
        $this->assertSame('3', $retentionState);
    }

    public function test_credit_payment_states_include_installment_number_and_advance(): void
    {
        $firstState = CxcService::resolvePaymentState(
            25.00,
            0.00,
            'Crédito 30 días',
            false,
            '2026-09-30',
            Carbon::parse('2026-09-29'),
            1,
            2
        );
        $secondState = CxcService::resolvePaymentState(
            25.00,
            0.00,
            'Crédito 30 días',
            false,
            '2026-10-30',
            Carbon::parse('2026-09-29'),
            2,
            2
        );

        $this->assertSame('Pendiente a credito: 1/2', $firstState);
        $this->assertSame('Pendiente a credito: 2/2', $secondState);
    }

    public function test_next_period_generation_date_is_first_day_of_period_end_month(): void
    {
        $generationDate = CxcService::automaticGenerationDate('2026-10-20');

        $this->assertNotNull($generationDate);
        $this->assertSame('2026-10-01', $generationDate->toDateString());
    }

    public function test_advance_period_end_counts_current_period_in_total(): void
    {
        $this->assertSame('2027-01-15', CxcService::advancePeriodEnd('2026-11-15', 3)->toDateString());
        $this->assertSame('2026-11-15', CxcService::advancePeriodEnd('2026-11-15', 1)->toDateString());
        $this->assertSame('2026-11-25', CxcService::advancePeriodEnd('2026-10-25', 2)->toDateString());
    }

    public function test_advance_months_are_saved_as_a_bounded_description_suffix(): void
    {
        $description = CxcService::descriptionWithAdvanceMonths('Comentario libre del usuario', 3);

        $this->assertSame(3, CxcService::advanceMonthsFromDescription($description));
        $this->assertStringEndsWith(' - 3', $description);
        $this->assertLessThanOrEqual(50, mb_strlen($description, 'UTF-8'));
        $this->assertSame(0, CxcService::advanceMonthsFromDescription('Comentario libre del usuario'));
        $this->assertSame(3, CxcService::advanceMonthsFromDescription(
            CxcService::descriptionWithAdvanceMonths('', 3)
        ));
    }

    public function test_previous_debt_picker_excludes_current_month_and_future_periods(): void
    {
        $octoberFirst = Carbon::parse('2026-10-01');
        $this->assertFalse(CxcService::isPreviousBillingPeriod(Carbon::parse('2026-10-15'), $octoberFirst));
        $this->assertFalse(CxcService::isPreviousBillingPeriod(Carbon::parse('2026-11-15'), $octoberFirst));
        $this->assertTrue(CxcService::isPreviousBillingPeriod(Carbon::parse('2026-10-15'), Carbon::parse('2026-11-01')));
    }

    public function test_full_payment_renews_immediately_when_period_end_exists(): void
    {
        $this->assertTrue(CxcService::shouldRenewAfterFullPayment('2026-10-20'));
        $this->assertFalse(CxcService::shouldRenewAfterFullPayment(null));
    }

    #[DataProvider('automaticRenewalCases')]
    public function test_scheduled_renewal_only_includes_open_cxc_states(
        string $state,
        string $periodEnd,
        string $today,
        bool $expected
    ): void {
        $this->assertSame(
            $expected,
            CxcService::shouldGenerateNextPeriod($state, $periodEnd, Carbon::parse($today))
        );
    }

    public static function automaticRenewalCases(): array
    {
        return [
            'pending on generation day' => ['1', '2026-10-20', '2026-10-01', true],
            'invoiced on generation day' => ['2', '2026-10-20', '2026-10-01', true],
            'overdue on generation day' => ['4', '2026-10-20', '2026-10-01', true],
            'partial payment remains pending' => ['Pendiente Pago parcial', '2026-10-20', '2026-10-01', true],
            'credit remains pending' => ['Pendiente a credito', '2026-10-20', '2026-10-01', true],
            'does not renew before first day' => ['1', '2026-10-20', '2026-09-30', false],
            'paid cxc is excluded from schedule' => ['3', '2026-10-20', '2026-10-01', false],
            'cancelled alias is excluded from schedule' => ['CANCELADO', '2026-10-20', '2026-10-01', false],
            'deduction cancellation is excluded from schedule' => ['Cancelado Detracción', '2026-10-20', '2026-10-01', false],
        ];
    }
}