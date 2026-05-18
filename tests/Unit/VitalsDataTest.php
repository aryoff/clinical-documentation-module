<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Tests\Unit;

use Tests\TestCase;
use Modules\ClinicalDocumentation\DTOs\VitalsData;
use Modules\ClinicalDocumentation\Exceptions\InvalidVitalsKeyException;

class VitalsDataTest extends TestCase
{
    public function test_vitals_can_be_instantiated_with_valid_keys(): void
    {
        $vitals = new VitalsData([
            'temperature' => 36.6,
            'systolic_bp' => 120,
            'diastolic_bp' => 80,
            'pulse_rate' => 72,
            'spo2' => 98.5,
            'respiratory_rate' => 16,
            'weight' => 70.2,
            'height' => 175.5,
        ]);

        $this->assertEquals(36.6, $vitals->temperature);
        $this->assertEquals(120, $vitals->systolic_bp);
        $this->assertEquals(80, $vitals->diastolic_bp);
        $this->assertEquals(72, $vitals->pulse_rate);
        $this->assertEquals(98.5, $vitals->spo2);
        $this->assertEquals(16, $vitals->respiratory_rate);
        $this->assertEquals(70.2, $vitals->weight);
        $this->assertEquals(175.5, $vitals->height);
    }

    public function test_vitals_throws_exception_on_invalid_key(): void
    {
        $this->expectException(InvalidVitalsKeyException::class);

        new VitalsData([
            'temperature' => 36.6,
            'blood_sugar' => 110, // Invalid key
        ]);
    }

    public function test_vitals_casts_values_correctly(): void
    {
        $vitals = new VitalsData([
            'temperature' => '36.6', // string to float
            'systolic_bp' => '120',  // string to int
        ]);

        $this->assertIsFloat($vitals->temperature);
        $this->assertEquals(36.6, $vitals->temperature);

        $this->assertIsInt($vitals->systolic_bp);
        $this->assertEquals(120, $vitals->systolic_bp);
    }

    public function test_vitals_to_array_returns_correct_keys(): void
    {
        $vitals = new VitalsData([
            'temperature' => 36.6,
            'systolic_bp' => 120,
        ]);

        $arr = $vitals->toArray();

        $this->assertArrayHasKey('temperature', $arr);
        $this->assertEquals(36.6, $arr['temperature']);
        $this->assertNull($arr['pulse_rate']);
    }
}
