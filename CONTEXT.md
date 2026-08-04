# ClinicalDocumentation

This context owns signed clinical documents, clinical facts, access control, addenda, and the authorization of archive release.

## Language

**Clinical Document**:
A clinically authored record whose signed form is immutable and whose correction is a linked addendum.

**Active Clinical Record**:
The authorized working view of signed clinical documents and facts for an active care journey; it is not a separate source of truth.

**Clinical Addendum**:
An auditable linked correction or clarification of a signed Clinical Document that preserves the original.

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

