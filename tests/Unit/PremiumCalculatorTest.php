<?php

namespace Tests\Unit;

use App\Enums\Destination;
use App\Enums\PlanCode;
use App\Services\PremiumCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PremiumCalculatorTest extends TestCase
{
    private PremiumCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new PremiumCalculator();
    }

    /**
     * Specification canonical verification example:
     * PLUS, born 1998-04-10, INTERNATIONAL, 2026-11-10 to 2026-11-14, 300000
     * Days: 5, Age: 28 (multiplier 1.00), Dest: 1.80, Coverage: 1.25
     * 20 * 5 * 1.00 * 1.80 * 1.25 = 225.00
     */
    public function test_canonical_specification_example_produces_225_premium(): void
    {
        $result = $this->calculator->calculate(
            planCode: PlanCode::PLUS,
            dateOfBirth: '1998-04-10',
            tripStart: '2026-11-10',
            tripEnd: '2026-11-14',
            destination: Destination::INTERNATIONAL,
            sumInsured: 300000
        );

        $this->assertSame(5, $result->travel_days);
        $this->assertSame('225.00', $result->premium);
        $this->assertSame('1.00', $result->breakdown['age_multiplier']);
        $this->assertSame('1.80', $result->breakdown['destination_multiplier']);
        $this->assertSame('1.25', $result->breakdown['coverage_multiplier']);
    }

    public function test_one_day_trip_calculates_minimum_one_day(): void
    {
        $result = $this->calculator->calculate(
            planCode: PlanCode::BASIC,
            dateOfBirth: '2000-01-01',
            tripStart: '2026-12-01',
            tripEnd: '2026-12-01',
            destination: Destination::DOMESTIC,
            sumInsured: 100000
        );

        $this->assertSame(1, $result->travel_days);
        // 12 * 1 * 1.00 * 1.00 * 1.00 = 12.00
        $this->assertSame('12.00', $result->premium);
    }

    public function test_age_17_and_18_boundary(): void
    {
        $tripStart = '2026-11-10';

        // Age 17 on trip start (born 2008-11-11 -> turns 18 day after trip start)
        $child = $this->calculator->calculate(
            planCode: PlanCode::BASIC,
            dateOfBirth: '2008-11-11',
            tripStart: $tripStart,
            tripEnd: $tripStart,
            destination: Destination::DOMESTIC,
            sumInsured: 100000
        );
        $this->assertSame(17, $child->breakdown['age_on_trip_start']);
        $this->assertSame('1.10', $child->breakdown['age_multiplier']);
        // 12 * 1 * 1.10 * 1.00 * 1.00 = 13.20
        $this->assertSame('13.20', $child->premium);

        // Age 18 on trip start (born 2008-11-10 -> exactly 18 on trip start)
        $adult = $this->calculator->calculate(
            planCode: PlanCode::BASIC,
            dateOfBirth: '2008-11-10',
            tripStart: $tripStart,
            tripEnd: $tripStart,
            destination: Destination::DOMESTIC,
            sumInsured: 100000
        );
        $this->assertSame(18, $adult->breakdown['age_on_trip_start']);
        $this->assertSame('1.00', $adult->breakdown['age_multiplier']);
        $this->assertSame('12.00', $adult->premium);
    }

    public function test_age_70_and_71_boundary(): void
    {
        $tripStart = '2026-11-10';

        // Age 70 on trip start (multiplier 1.50)
        $senior70 = $this->calculator->calculate(
            planCode: PlanCode::BASIC,
            dateOfBirth: '1956-11-10',
            tripStart: $tripStart,
            tripEnd: $tripStart,
            destination: Destination::DOMESTIC,
            sumInsured: 100000
        );
        $this->assertSame(70, $senior70->breakdown['age_on_trip_start']);
        $this->assertSame('1.50', $senior70->breakdown['age_multiplier']);
        // 12 * 1 * 1.50 = 18.00
        $this->assertSame('18.00', $senior70->premium);

        // Age 71 on trip start (multiplier 2.00)
        $senior71 = $this->calculator->calculate(
            planCode: PlanCode::BASIC,
            dateOfBirth: '1955-11-10',
            tripStart: $tripStart,
            tripEnd: $tripStart,
            destination: Destination::DOMESTIC,
            sumInsured: 100000
        );
        $this->assertSame(71, $senior71->breakdown['age_on_trip_start']);
        $this->assertSame('2.00', $senior71->breakdown['age_multiplier']);
        // 12 * 1 * 2.00 = 24.00
        $this->assertSame('24.00', $senior71->premium);
    }

    public function test_basic_domestic_100000(): void
    {
        // 10 days, age 30, basic, domestic, 100000
        // 12 * 10 * 1.00 * 1.00 * 1.00 = 120.00
        $result = $this->calculator->calculate(
            planCode: PlanCode::BASIC,
            dateOfBirth: '1996-01-01',
            tripStart: '2026-05-01',
            tripEnd: '2026-05-10',
            destination: Destination::DOMESTIC,
            sumInsured: 100000
        );

        $this->assertSame(10, $result->travel_days);
        $this->assertSame('120.00', $result->premium);
    }

    public function test_premium_international_500000_elderly(): void
    {
        // PREMIUM (30), days 10, age 75 (2.00), international (1.80), 500000 (1.50)
        // 30 * 10 * 2.00 * 1.80 * 1.50 = 1620.00
        $result = $this->calculator->calculate(
            planCode: PlanCode::PREMIUM,
            dateOfBirth: '1951-01-01',
            tripStart: '2026-06-01',
            tripEnd: '2026-06-10',
            destination: Destination::INTERNATIONAL,
            sumInsured: 500000
        );

        $this->assertSame(10, $result->travel_days);
        $this->assertSame('1620.00', $result->premium);
    }

    public function test_invalid_travel_dates_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Trip end date cannot be earlier than trip start date.');

        $this->calculator->calculate(
            planCode: PlanCode::BASIC,
            dateOfBirth: '1995-01-01',
            tripStart: '2026-05-10',
            tripEnd: '2026-05-01',
            destination: Destination::DOMESTIC,
            sumInsured: 100000
        );
    }

    public function test_unsupported_sum_insured_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported sum insured: 250000');

        $this->calculator->calculate(
            planCode: PlanCode::BASIC,
            dateOfBirth: '1995-01-01',
            tripStart: '2026-05-01',
            tripEnd: '2026-05-10',
            destination: Destination::DOMESTIC,
            sumInsured: 250000
        );
    }
}
