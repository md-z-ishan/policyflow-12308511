<?php

namespace App\Services;

use App\Enums\Destination;
use App\Enums\PlanCode;
use Carbon\Carbon;
use InvalidArgumentException;

/**
 * PremiumCalculator
 *
 * Deterministic business logic for calculating travel insurance premium.
 * Pure function: takes input parameters and returns a breakdown and final rounded premium.
 * Uses bcmath with scale 6 for intermediate calculations and rounds half-up to 2 decimals.
 * Never uses floating-point arithmetic for monetary calculations.
 */
class PremiumCalculator
{
    private const SCALE = 6;

    public function calculate(
        string|PlanCode $planCode,
        string $dateOfBirth,
        string $tripStart,
        string $tripEnd,
        string|Destination $destination,
        int|string $sumInsured
    ): PremiumCalculationResult {
        $plan = $planCode instanceof PlanCode ? $planCode : PlanCode::from($planCode);
        $dest = $destination instanceof Destination ? $destination : Destination::from($destination);

        $startDate = Carbon::parse($tripStart)->startOfDay();
        $endDate = Carbon::parse($tripEnd)->startOfDay();
        $dob = Carbon::parse($dateOfBirth)->startOfDay();

        if ($endDate->lt($startDate)) {
            throw new InvalidArgumentException("Trip end date cannot be earlier than trip start date.");
        }

        // Days: inclusive (trip_end - trip_start + 1), minimum 1
        $travelDays = (int) max(1, $startDate->diffInDays($endDate) + 1);

        // Age calculated on trip_start
        $ageOnTripStart = (int) $dob->diffInYears($startDate);
        $ageMultiplier = $this->resolveAgeMultiplier($ageOnTripStart);

        $baseDailyRate = $plan->baseDailyRate();
        $destinationMultiplier = $dest->multiplier();
        $coverageMultiplier = $this->resolveCoverageMultiplier((int) $sumInsured);

        // formula = base_daily_rate * travel_days * age_multiplier * destination_multiplier * coverage_multiplier
        $step1 = bcmul($baseDailyRate, (string) $travelDays, self::SCALE);
        $step2 = bcmul($step1, $ageMultiplier, self::SCALE);
        $step3 = bcmul($step2, $destinationMultiplier, self::SCALE);
        $rawPremium = bcmul($step3, $coverageMultiplier, self::SCALE);

        $finalPremium = $this->roundHalfUp($rawPremium, 2);

        $breakdown = [
            'plan_code' => $plan->value,
            'base_daily_rate' => $baseDailyRate,
            'travel_days' => $travelDays,
            'age_on_trip_start' => $ageOnTripStart,
            'age_multiplier' => $ageMultiplier,
            'destination' => $dest->value,
            'destination_multiplier' => $destinationMultiplier,
            'sum_insured' => (int) $sumInsured,
            'coverage_multiplier' => $coverageMultiplier,
            'raw_premium' => $rawPremium,
            'final_premium' => $finalPremium,
        ];

        return new PremiumCalculationResult($travelDays, $finalPremium, $breakdown);
    }

    private function resolveAgeMultiplier(int $age): string
    {
        return match (true) {
            $age <= 17 => '1.10',
            $age <= 60 => '1.00',
            $age <= 70 => '1.50',
            default => '2.00',
        };
    }

    private function resolveCoverageMultiplier(int $sumInsured): string
    {
        return match ($sumInsured) {
            100000 => '1.00',
            300000 => '1.25',
            500000 => '1.50',
            default => throw new InvalidArgumentException("Unsupported sum insured: {$sumInsured}"),
        };
    }

    /**
     * Round half-up to specified scale using string manipulation and bcmath.
     * Guaranteed safe from IEEE 754 floating point inaccuracies.
     */
    private function roundHalfUp(string $number, int $precision = 2): string
    {
        $parts = explode('.', $number);
        $intPart = $parts[0];
        $decPart = $parts[1] ?? '0';

        // Pad to ensure we have enough digits to inspect
        $decPart = str_pad($decPart, $precision + 1, '0');
        $inspectDigit = (int) $decPart[$precision];
        $truncatedDec = substr($decPart, 0, $precision);
        $base = $intPart . ($precision > 0 ? '.' . $truncatedDec : '');

        if ($inspectDigit >= 5) {
            $increment = '0.' . str_repeat('0', max(0, $precision - 1)) . '1';
            return bcadd($base, $increment, $precision);
        }

        return bcadd($base, '0', $precision);
    }
}
