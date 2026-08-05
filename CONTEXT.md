# ClinicalDocumentation

This context owns signed clinical documents, clinical facts, access control, addenda, and the authorization of archive release.

## Language

**Clinical Document**:
A clinically authored record whose signed form is immutable and whose correction is a linked addendum.

**Active Clinical Record**:
The authorized working view of signed clinical documents and facts for an active care journey; it is not a separate source of truth.

**Clinical Addendum**:
An auditable linked correction or clarification of a signed Clinical Document that preserves the original.

**Clinical Fact**:
An immutable contract-defined statement published after a care context finalizes its own record, allowing derived records and projections without transferring source ownership. This is the generic shape; a publishing context defines its own named variant, such as a Laboratory Result Clinical Projection or a Prescription Clinical Fact, and remains the authority for the record behind it.

**Diagnosis Assertion**:
A clinically authored, accountable statement of diagnosis linked to supporting clinical evidence.

**Allergy Assertion**:
A clinically authored structured statement of substance, reaction, severity, verification, and active status that optional safety consumers may read.

**Clinical Handoff**:
The explicit, auditable authorization that grants eligible staff in a care context permission to author clinical documents for a linked journey.

**Clinical Document Visibility**:
The policy that determines which authorized users may view a clinical document or class of document and records its access.

**Break-Glass Clinical Access**:
The emergency, reasoned, alert-producing access path that is separately audited and does not normalize unrestricted access.

**Clinical Payload**:
The sensitive authored content of a clinical record, encrypted and decrypted by ClinicalDocumentation rather than held as plaintext by MedicalRecords.

**Clinical Record Archive**:
The optional archive projection of finalized signed-document chains and structured facts after discharge, released through the MedicalRecords boundary.

**Clinical Record Release Capability**:
A short-lived authorization for a named user and purpose to release a bounded encrypted record or archive package.

**Clinical File Staging**:
A short-lived protected FileVault object used to validate and encrypt a clinical artifact before verified archive custody.

**Clinical Consumable Usage**:
The patient-specific record of a single-use treatment supply that requests a correlated Warehouse issue and may publish a chargeable fact.

**Clinical Consumable Order**:
The clinical instruction for a treatment consumable that has no stock or billing effect until actual authorized use is recorded.

**Clinical Document Template**:
The versioned definition of a clinical document type, its fields, eligible author professions, signing and co-signature policy, and applicable care settings. SOAP is an initial template, and a signed document stays bound to the version it was signed against.

**Clinical Rationale Reference**:
An optional public reference between a signed Clinical Document and an EPrescription-owned prescription. It preserves the clinical basis when both modules are installed; neither requires the other.

**Clinical Evidence Reference**:
An optional public reference from a Billable Service Fact, prescription, or ancillary request to its supporting signed Clinical Document. It proves rationale without making this context the source of that financial, prescription, order, or result fact.

**Local Clinical Retention**:
The sealed, access-controlled retention of finalized Clinical Documents and their addendum chains when MedicalRecords is not installed, providing required custody without an external archive provider.

## Relationships

**OperatingRoom, Obstetrics, Radiology, Laboratory, and PhysicalTherapy** — each owns its own execution, birth, imaging, specimen, or session evidence and publishes immutable projections here. Authoring in this context requires that source's explicit clinical handoff; a projection never transfers ownership of the source record.

**MedicalRecords** — the optional ciphertext vault. This context alone holds the keys, encrypts every Clinical Payload before it leaves the application, keeps the archive manifest, and authorizes each bounded one-patient release. Vault absence means sealed Local Clinical Retention, never a blocked discharge.

**EPrescription** — independently installable. A Clinical Rationale Reference may link a prescription to signed reasoning, but urgent prescribing works with no clinical document and this context works with no prescriber.

**Warehouse** — a guarded provider holding batch custody behind Clinical Consumable Usage. This context owns why a supply was used; Warehouse owns the stock truth, joined by one consumable audit correlation.
