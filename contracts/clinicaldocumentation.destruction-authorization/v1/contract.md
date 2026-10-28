# Capability contract: `clinicaldocumentation.destruction-authorization` v1

**Owner:** ClinicalDocumentation · **Interaction Mode:** `sync` · **Version:** 1.0.0

Whether ONE destruction step is allowed right now (root ADR 0033, #473, decision record #488). Asked by every custodian at the start of each
irreversible step. Binding: `Modules\ClinicalDocumentation\Contracts\DestructionAuthorizationContract`. Scalars in, scalars out.

## Legal reading

The mandatory duty is to keep electronic records **at least 25 years from the last visit** (Permenkes 24/2022 Pasal 39(1); PP 28/2024 Pasal 974(3)).
Destruction afterwards is optional, has no deadline, and is done under the retention schedule (Pasal 39(2)-(3); PP 28/2024 Pasal 974-976). The
processing history must be recorded (PP 28/2024 Pasal 977). Backups are the facility's own duty (Permenkes Pasal 20(4)-(5)). Engineering
analysis, not legal advice.

## `permits(authorization_id, patient_id, custodian, ref_type, ref_id)`

```
authorization_id  string   the destruction id a person started
patient_id        string
custodian         string   e.g. 'laboratory'
ref_type, ref_id  string   the one copy the custodian is about to destroy
returns           { permitted: bool, reason?: string }
```

`permitted` is true only when **all** hold:

- the destruction exists, belongs to that patient and covers exactly that `(custodian, ref_type, ref_id)` (a copy it does not cover is refused);
- destruction is switched on in this deployment;
- the retention review still has no blocker (schedule, last visit, attendance coverage, holds, extensions);
- no retained assertion cites the content, no vaulted copy of unproven custody exists, and no copy appeared that the person did not see.

A consumer treats any other answer, and the absence of a provider, as **not permitted**.
