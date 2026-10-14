# Diagnosis Fact Republication v1.0.0

Capability: `clinicaldocumentation.diagnosis-fact-republication`, synchronous command with targeted fact-event delivery.

The existing `clinicaldocumentation.diagnosis-assertion-fact` v1.0.0 capability is unchanged. This is a separately declared, optional consumption.

Trusted consumer bootstrap calls `registerConsumer(consumer, receive, authorize)` once with its existing diagnosis-fact listener and an authorization callback evaluated for the **current operator on every request**. Consumer code declares this capability in its manifest. This is an in-process module boundary, not a public registration endpoint: HTTP input cannot register, replace a listener, supply a callback or choose a requester. First registration wins. Callbacks must resolve request-scoped dependencies at invocation rather than capture an authenticated user during bootstrap.

`request(assertionId, consumer): void` carries one missing assertion id. The consumer name is trusted routing context fixed by the consumer's adapter, not a second record selector. No patient, registration, lineage, history filter or operator-supplied recipient is accepted. There is no automatic request or rate limit.

The owner rebuilds the fact from its durable Diagnosis Assertion. It dispatches `clinicaldocumentation.diagnosis-assertion-fact-published` to that registered consumer's existing listener only, on a private dispatcher. Global listeners and other registered consumers receive nothing. The scalar payload is the existing fact shape: assertion identity, lineage, revision, predecessor reference, clinical fields, author snapshot and original assertion timestamp are preserved. Delivery is immediate within the request; no acknowledgement of existence or delivery is returned.

**Identity exception:** `patient_id` is the current canonical identity on the durable assertion after reconciliation. This may differ from the original publication. Consumers deduplicate by `assertion_id`, not by payload equality. No other historical fields are recomputed.

An unregistered requester, a failed authorization check, an unknown/malformed id or an unavailable/retracted durable assertion produces the same void response and no event. A superseded assertion remains historical evidence and is requestable; supersession is not retraction. This version adds no retraction workflow; a record unavailable to the owner's normal assertion query cannot be reconstructed.

No ClinicalDocumentation outbox, retry ledger, source-wide publication or consumer read of source storage is involved. The consumer separately enforces its operator ability and only requests a predecessor it never received; republication does not repair facts its inbox previously rejected.
