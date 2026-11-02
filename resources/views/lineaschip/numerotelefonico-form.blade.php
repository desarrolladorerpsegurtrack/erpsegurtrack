@extends('layouts.crud-form')

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const planSelect = document.getElementById('select-plantelef_idplantelef');
            const priceInput = document.querySelector('[name="precio"]');
            const currencySelect = document.querySelector('[name="moneda"]');
            const prices = @json($planPriceMap ?? []);

            if (!planSelect || !priceInput || !currencySelect) {
                return;
            }

            const updatePlanDefaults = function () {
                const plan = prices[planSelect.value];
                if (!plan) {
                    return;
                }

                priceInput.value = plan.price;
                currencySelect.value = plan.currency;
                currencySelect.dispatchEvent(new Event('change', { bubbles: true }));
            };

            planSelect.addEventListener('change', updatePlanDefaults);
        });
    </script>
@endpush
