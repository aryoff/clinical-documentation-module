# Capability contract: `clinicaldocumentation.disclosure-authorization` v1

**Owner:** ClinicalDocumentation · **Interaction Mode:** `sync` · **Version:** 1.0.0

Whether a recorded Clinical Disclosure Request entitles its recipient to ONE exact result version right now (root ADR 0034, #474).
Binding: `Modules\ClinicalDocumentation\Contracts\DisclosureAuthorizationContract`. Scalars in, scalars out.

## What entitles a recipient

Only the **basis the Disclosure Officer verified**: `patient_self_access` (the verified person is the patient; no consent), `patient_authorization`
(a Clinical Disclosure Consent naming this recipient, purpose and a result scope, in force and not withdrawn; the recipient's identity, not the patient's, was verified), `representative_authority` (established
authority evidence, never the relationship) or `statutory` (a supported statutory basis with its own request evidence). A family relationship, a
staff read permission, a payment status or a chosen channel is none of these. General registration or treatment consent cannot be recorded as
disclosure consent. Unpaid charges never refuse a disclosure.

## `authorize(request_id, patient_id, ref_type, ref_id, lineage_id, is_current_version)`

```
request_id, patient_id   string  the Clinical Disclosure Request and the patient it is about
ref_type, ref_id         string  the exact result version, e.g. 'laboratory_result' / result id
lineage_id               string  the stable result line the version belongs to (consent and request scope are lineage ids)
is_current_version       bool    false asks for a superseded (historical) copy
returns                  { authorized, reason, authorization_id, basis, recipient_name, historical_copy, request_id, ref_type, ref_id, lineage_id }
```

Every call about a known request records one insert-only decision, granted or refused, naming the verification and consent it relied on. A call about an unknown request or another patient's request is refused as `unknown_request` and records nothing (there is no request to attach it to); the consumer's own audit records that attempt. `authorization_id` is set only when `authorized`. `is_current_version` is the consumer's claim: the consumer owns version state and must pass the truth. A superseded version is
authorized only when the request explicitly named that exact version (`ref_id`) as a historical copy, and the answer says `historical_copy: true`. An amendment is a new
version and needs a fresh `authorize` call; a consent that still covers its lineage need not be recreated.

## `recheck(authorization_id)`

Re-evaluates a granted decision, against the verification it relied on, with what is true now: a consent withdrawn or expired since refuses it. A granted answer echoes the exact `ref_type`/`ref_id`/`lineage_id` the decision covers, and **a consumer must match them against the version it is about to hand over**: a decision for one version never covers another, and an amendment needs a fresh `authorize`. It is a point-in-time read; the consumer records its handover straight after it. Consumers recheck at handover. It is read-only and records no decision; the consumer records its own refused attempt.

## Never returned

Consent-document contents (`evidence_reference`) and verification evidence are never part of an answer.

A consumer treats any other answer, an unavailable or incompatible provider and the absence of a provider as **not authorized**.
