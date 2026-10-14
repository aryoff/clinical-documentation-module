<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Services;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\ClinicalDocumentation\Contracts\ConsumableStockPort;
use Modules\ClinicalDocumentation\Contracts\HospitalRegistrationPort;
use Modules\ClinicalDocumentation\Exceptions\ConsumableStockUnavailable;
use Modules\ClinicalDocumentation\Models\ClinicalConsumableEvent;
use Ramsey\Uuid\Uuid;

/**
 * Why a treatment supply was used. This module owns the order and the actual use and keeps no
 * balance: the stock effect is the custodian's, joined to the use by one correlation (use_id).
 * An order has no stock or billing effect; absence of the custodian makes every operation
 * unavailable rather than recording a use whose stock it cannot honour.
 */
final class ClinicalConsumableUsageService
{
    public const ORDER_PERMISSION = "clinicaldocumentation.consumables.order";
    public const RECORD_PERMISSION = "clinicaldocumentation.consumables.record";
    public const CORRECT_PERMISSION = "clinicaldocumentation.consumables.correct";
    public const SOURCE = "clinical-documentation";
    /** Proxy for the custodian's own gate: a route only the user cannot complete is not offered. */
    public const CUSTODIAN_PERMISSION = "pharmacy.consumables.consume";

    public function __construct(
        private readonly ConsumableStockPort $stock,
        private readonly HospitalRegistrationPort $registrations,
    ) {}

    public function order(array $input, User $actor): ClinicalConsumableEvent
    {
        abort_unless($actor->can(self::ORDER_PERMISSION), 403);
        $data = Validator::make($input, [
            "idempotency_key" => ["required", "uuid"],
            "registration_id" => ["required", "uuid"],
            "product_id" => ["required", "uuid"],
            "quantity" => $this->quantityRule(),
            "note" => ["nullable", "string", "max:1000"],
        ])->validate();
        $data = $this->normalised($data);

        return $this->append("ordered", $data, $actor, function (array $registration) use ($data) {
            $this->requireCustodian();
            return [null, array_intersect_key($data, array_flip(["product_id", "quantity", "note"]))];
        });
    }

    public function record(array $input, User $actor): ClinicalConsumableEvent
    {
        abort_unless($actor->can(self::RECORD_PERMISSION), 403);
        $data = Validator::make($input, [
            "idempotency_key" => ["required", "uuid"],
            "registration_id" => ["required", "uuid"],
            "order_id" => ["nullable", "uuid"],
            ...$this->batchRules(),
        ])->validate();
        $data = $this->normalised($data);

        return $this->append("recorded", $data, $actor, function (array $registration, string $use_id) use ($data, $actor) {
            $this->requireCustodian();
            if (!empty($data["order_id"])) {
                // An order is an instruction that several uses may fulfil, but only for the item it ordered
                // (its quantity is guidance and is not enforced).
                $order = ClinicalConsumableEvent::where("use_id", $data["order_id"])
                    ->where("type", "ordered")
                    ->where("registration_id", $data["registration_id"])
                    ->first();
                if ($order === null) {
                    $this->refuse("order_id", "No order for this registration carries that identity.");
                }
                if (($order->payload["product_id"] ?? null) !== $data["product_id"]) {
                    $this->refuse("order_id", "That order is for a different item.");
                }
            }
            $payload = array_intersect_key($data, array_flip([
                "order_id",
                "profile_id",
                "product_id",
                "batch_number",
                "expiry_date",
                "quantity",
            ]));
            $answer = $this->stock->consume([
                "idempotency_key" => $data["idempotency_key"],
                "use_id" => $use_id,
                "source" => self::SOURCE,
                "registration_id" => $data["registration_id"],
                "arrival_reference" => null,
                "actor_id" => (string) $actor->id,
                ...array_intersect_key($payload, array_flip([
                    "profile_id",
                    "product_id",
                    "batch_number",
                    "expiry_date",
                    "quantity",
                ])),
            ]);
            return [$answer["movement_id"] ?? null, [...$payload, "item" => $this->item($answer)]];
        });
    }

    public function correct(array $input, User $actor): ClinicalConsumableEvent
    {
        abort_unless($actor->can(self::CORRECT_PERMISSION), 403);
        $data = Validator::make($input, [
            "idempotency_key" => ["required", "uuid"],
            "use_id" => ["required", "uuid"],
            "approver_id" => ["required", "uuid"],
            "reason" => ["required", "string", "max:1000"],
        ])->validate();
        $data = $this->normalised($data);
        // UUIDs compare case-insensitively; the custodian decides the same way on the stored user.
        if (strtolower($data["approver_id"]) === strtolower((string) $actor->id)) {
            $this->refuse("approver_id", "A correction needs an approver other than the person who recorded it.");
        }
        $used = $this->recordedUse($data["use_id"]);
        $data["registration_id"] = $used->registration_id;

        return $this->append("corrected", $data, $actor, function (array $registration, string $use_id) use ($data, $actor, $used) {
            if (ClinicalConsumableEvent::where("use_id", $used->use_id)->where("type", "corrected")->exists()) {
                $this->refuse("use_id", "This use has already been corrected.");
            }
            $this->requireCustodian();
            $answer = $this->stock->correct([
                "idempotency_key" => $data["idempotency_key"],
                "use_id" => $used->use_id,
                "actor_id" => (string) $actor->id,
                "approver_id" => $data["approver_id"],
                "reason" => $data["reason"],
            ]);
            return [$answer["movement_id"] ?? null, [
                "approver_id" => $data["approver_id"],
                // Named beside the id, so the audit still reads if the approver's module or account is gone.
                "approver_name" => User::find($data["approver_id"])?->name,
                "reason" => $data["reason"],
                "item" => $this->item($answer),
            ]];
        }, $used->use_id);
    }

    public function waste(array $input, User $actor): ClinicalConsumableEvent
    {
        abort_unless($actor->can(self::RECORD_PERMISSION), 403);
        $data = Validator::make($input, [
            "idempotency_key" => ["required", "uuid"],
            "use_id" => ["required", "uuid"],
            "quantity" => $this->quantityRule(),
            "reason" => ["required", "in:opened,contaminated,damaged,lost"],
        ])->validate();
        $data = $this->normalised($data);
        $used = $this->recordedUse($data["use_id"]);
        $data["registration_id"] = $used->registration_id;

        return $this->append("wasted", $data, $actor, function (array $registration, string $use_id) use ($data, $actor, $used) {
            $this->requireCustodian();
            $batch = array_intersect_key($used->payload, array_flip([
                "profile_id",
                "product_id",
                "batch_number",
                "expiry_date",
            ]));
            $answer = $this->stock->dispose([
                "idempotency_key" => $data["idempotency_key"],
                "use_id" => $used->use_id,
                "actor_id" => (string) $actor->id,
                ...$batch,
                "quantity" => $data["quantity"],
                "reason" => $data["reason"],
            ]);
            return [$answer["movement_id"] ?? null, [...$batch, "quantity" => $data["quantity"], "reason" => $data["reason"], "item" => $this->item($answer)]];
        }, $used->use_id);
    }

    /**
     * Appends one event, once per idempotency key. The custodian is called inside the transaction,
     * so a refusal leaves no use fact and a failure after it leaves no stock movement.
     *
     * @param callable(array, string): array{0: ?string, 1: array} $write
     */
    private function append(
        string $type,
        array $data,
        User $actor,
        callable $write,
        ?string $use_id = null,
    ): ClinicalConsumableEvent {
        // The actor is part of the command: a second person repeating a key is not a retry.
        $hash = $this->hash($type, [...$data, "actor_id" => strtolower((string) $actor->id)]);
        $existing = ClinicalConsumableEvent::where("idempotency_key", $data["idempotency_key"])->first();
        if ($existing !== null) {
            if (!hash_equals($existing->request_hash, $hash)) {
                $this->refuse("idempotency_key", "This key identifies a different command.");
            }
            return $existing;
        }

        try {
            return $this->appendOnce($type, $data, $actor, $write, $hash, $use_id);
        } catch (QueryException $exception) {
            // A concurrent attempt with the same key won the unique index; its result is the answer.
            $winner = ClinicalConsumableEvent::where("idempotency_key", $data["idempotency_key"])->first();
            if ($winner === null) {
                throw $exception;
            }
            if (!hash_equals($winner->request_hash, $hash)) {
                $this->refuse("idempotency_key", "This key identifies a different command.");
            }
            return $winner;
        }
    }

    private function appendOnce(
        string $type,
        array $data,
        User $actor,
        callable $write,
        string $hash,
        ?string $use_id,
    ): ClinicalConsumableEvent {
        return DB::transaction(function () use ($type, $data, $actor, $write, $hash, $use_id) {
            $registration = $this->registrations->assertActive($data["registration_id"]);
            if ($registration === null) {
                $this->refuse("registration_id", "The registration is not active.");
            }
            // A use is identified by the attempt that created it, so a retry names the same correlation.
            $use_id ??= Uuid::uuid5(
                Uuid::NAMESPACE_URL,
                "clinicaldocumentation:consumable-{$type}:{$data["idempotency_key"]}",
            )->toString();
            [$movement_id, $payload] = $write($registration, $use_id);

            return ClinicalConsumableEvent::create([
                "use_id" => $use_id,
                "type" => $type,
                "idempotency_key" => $data["idempotency_key"],
                "request_hash" => $hash,
                "registration_id" => (string) $registration["registration_id"],
                "patient_id" => $registration["patient_id"] ?? null,
                "movement_id" => $movement_id,
                "payload" => $payload,
                "recorded_by" => (string) $actor->id,
                "recorded_by_name" => $actor->name,
            ]);
        });
    }

    /** UUIDs are one identity in any case, so a retry, a comparison and a hash all see one spelling. */
    private function normalised(array $data): array
    {
        foreach (
            [
                "idempotency_key",
                "registration_id",
                "order_id",
                "profile_id",
                "product_id",
                "use_id",
                "approver_id",
            ]
            as $key
        ) {
            if (isset($data[$key]) && is_string($data[$key])) {
                $data[$key] = strtolower($data[$key]);
            }
        }
        return $data;
    }

    /** What the custodian says the item is, kept beside its id for an audit that outlives the custodian. */
    private function item(array $answer): array
    {
        return array_filter(
            [
                "code" => $answer["item_code"] ?? null,
                "name" => $answer["item_name"] ?? null,
                "unit" => $answer["unit"] ?? null,
            ],
            fn($value) => $value !== null,
        );
    }

    private function recordedUse(string $use_id): ClinicalConsumableEvent
    {
        $used = ClinicalConsumableEvent::where("use_id", $use_id)->where("type", "recorded")->first();
        if ($used === null) {
            $this->refuse("use_id", "No recorded use carries this correlation.");
        }
        return $used;
    }

    private function requireCustodian(): void
    {
        if (!$this->stock->isAvailable()) {
            throw new ConsumableStockUnavailable();
        }
    }

    private function batchRules(): array
    {
        return [
            "profile_id" => ["required", "uuid"],
            "product_id" => ["required", "uuid"],
            "batch_number" => ["required", "string", "max:100"],
            "expiry_date" => ["required", "date_format:Y-m-d"],
            "quantity" => $this->quantityRule(),
        ];
    }

    private function quantityRule(): array
    {
        return ["required", "numeric", "min:0.001", "max:9999999.999", "decimal:0,3"];
    }

    private function hash(string $type, array $data): string
    {
        unset($data["idempotency_key"]);
        ksort($data);
        return hash("sha256", json_encode([$type, $data], JSON_THROW_ON_ERROR));
    }

    private function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
