<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\DTOs;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Modules\ClinicalDocumentation\Exceptions\InvalidVitalsKeyException;

class VitalsData implements CastsAttributes
{
    public const VALID_KEYS = [
        'temperature',
        'systolic_bp',
        'diastolic_bp',
        'pulse_rate',
        'spo2',
        'respiratory_rate',
        'weight',
        'height',
    ];

    public ?float $temperature = null;
    public ?int $systolic_bp = null;
    public ?int $diastolic_bp = null;
    public ?int $pulse_rate = null;
    public ?float $spo2 = null;
    public ?int $respiratory_rate = null;
    public ?float $weight = null;
    public ?float $height = null;

    /**
     * Constructor to initialize with values.
     */
    public function __construct(array $attributes = [])
    {
        foreach ($attributes as $key => $value) {
            if (!in_array($key, self::VALID_KEYS, true)) {
                throw new InvalidVitalsKeyException($key);
            }
            $this->{$key} = $value !== null && $value !== '' ? $this->castValue($key, $value) : null;
        }
    }

    /**
     * Cast the value to float or int depending on key type.
     */
    private function castValue(string $key, mixed $value): float|int
    {
        if (in_array($key, ['temperature', 'spo2', 'weight', 'height'], true)) {
            return (float) $value;
        }
        return (int) $value;
    }

    /**
     * Cast the given value from database.
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?self
    {
        if ($value === null) {
            return null;
        }

        $decoded = json_decode((string) $value, true);
        if (!is_array($decoded)) {
            return null;
        }

        return new self($decoded);
    }

    /**
     * Prepare the given value for storage.
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof self) {
            return json_encode($value->toArray());
        }

        if (is_array($value)) {
            $dto = new self($value);
            return json_encode($dto->toArray());
        }

        throw new \InvalidArgumentException('Vitals data must be an array or an instance of VitalsData.');
    }

    /**
     * Convert the DTO to array structure.
     */
    public function toArray(): array
    {
        $arr = [];
        foreach (self::VALID_KEYS as $key) {
            $arr[$key] = $this->{$key};
        }
        return $arr;
    }
}
