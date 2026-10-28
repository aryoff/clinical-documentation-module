# Capability contract: `clinicaldocumentation.destruction-authorization` v1

**Owner:** ClinicalDocumentation · **Interaction Mode:** `sync` · **Version:** 1.0.0

Whether ONE destruction step is allowed right now (root ADR 0033, #473, decision record #488). Asked by every custodian at the start of each
irreversible step. Binding: `Modules\ClinicalDocumentation\Contracts\DestructionAuthorizationContract`. Scalars in, scalars out.

## Legal reading

The mandatory duty is to keep electronic records **at least 25 years from the last visit** (Permenkes 24/2022 Pasal 39(1); PP 28/2024 Pasal 974(3)).
Destruction afterwards is optional, has no deadline, and is done under the retention schedule (Pasal 39(2)-(3); PP 28/2024 Pasal 974-976). The
processing history must be recorded (PP 28/2024 Pasal 977). Backups are the facility's own duty (Permenkes Pasal 20(4)-(5)). Engineering
analysis, not legal advice.

## Scope correction 2026-10-28

[Decision record #488](https://github.com/aryoff/boilerplate-laravel-react/issues/488) drops two-person proposal/approval, approval binding to a
reviewed inventory/schedule/history/hold state and approval invalidation. The authorization id identifies the destruction a person started;
it is not an approval token. Per-step authorization and current protection checks below remain required.

Backup-expiry states and confirmation, the backup-retention setting, an off-database destruction register, post-restore re-application and
the inpatient-alignment attestation are also dropped. A restore is disaster recovery; resurrected past-retention content is reconsidered
in the next authorized destruction cycle. Nothing destroys automatically. Processing history records who, why, when, which copies and
their receipts, never findings. The deployment switch remains off by default. The data owner's request route and its interaction with the
25-year minimum remain for the facility's legal adviser; this contract does not implement that route.

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
