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

**Presented External Evidence**:
A patient-provided file or scan staged against an active Hospital Registration before the treating clinician takes over. ClinicalDocumentation records only the file custody, stager identity, staging time, and the patient's freeform claim about the file; it records no clinical interpretation, trust decision, or asserted source authority. A clinician reviews it during draft authoring and makes any clinical incorporation an explicit, auditable authoring act. It uses FileVault's short-lived protected storage, needs no accepted Clinical Handoff to stage, and performs no OCR.

**Diagnosis Assertion**:
A clinically authored, accountable statement of diagnosis linked to supporting clinical evidence. The first assertion for an active care journey is the Initial Diagnosis Prerequisite for prescribing; later clinician-authored assertions preserve the earlier record while supplementing or superseding its current clinical interpretation.

**Diagnosis Assertion Lineage**:
The ordered history of immutable Diagnosis Assertions for an active care journey. Each supplemental or superseding assertion preserves the earlier nodes and identifies the current interpretation without rewriting clinical history.

**Diagnostic Result Evidence**:
An immutable laboratory or radiology result that may support a clinical interpretation. It is evidence for a Diagnosis Assertion, not a diagnosis itself, until an accountable clinician reviews and authors that assertion.

**Clinical Diagnosis Read**:
A purpose-scoped read of the complete Diagnosis Assertion Lineage and its linked diagnostic-result evidence, including prior interpretations and the current assertion heads. It permits an authorized clinician to make a decision but does not itself grant authoring authority.

**Allergy Assertion**:
A clinically authored structured statement of substance, reaction, severity, verification, and active status that optional safety consumers may read.

**Clinical Handoff**:
The explicit, auditable authorization that grants eligible staff in a care context permission to author clinical documents for a linked journey.

**Takeover Safety Read**:
A narrow, auditable read of safety facts and the Clinical Diagnosis Read for a clinician who holds shortfall Takeover Authority. It uses the originating clinician's accepted Clinical Handoff as the treatment-context anchor, grants no unrestricted document visibility or clinical-document authoring, and is not Break-Glass Clinical Access.

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
A public reference between signed clinical reasoning and an EPrescription-owned prescription. It preserves the diagnosis and evidence behind prescribing; ClinicalDocumentation is required for the prescribing path, while neither context owns the other's records.

**Clinical Evidence Reference**:
An optional public reference from a Billable Service Fact, prescription, or ancillary request to its supporting signed Clinical Document. It proves rationale without making this context the source of that financial, prescription, order, or result fact.

**Inpatient Discharge Summary**:
The Clinical Document authored against the `inpatient-discharge-summary` template when a ward sends a patient home. It is a Clinical Document like any other — signed once, corrected only by addendum — and what makes it a discharge summary is the template it is bound to and the required elements that template names.

**Required Discharge Element**:
One of the named parts a discharge summary must carry before it may be signed, published with the role that owns it. The list is this context's and is read from it rather than restated: a consumer that kept its own copy would eventually be the one telling a ward its paperwork was finished. A signature on a summary missing an element is refused, because signing an incomplete summary converts an omission into a certification.

**Discharge Explanation Receipt**:
The record that a signed discharge summary was explained to the patient or an authorised representative, in a stated language and with whatever support they needed. Insert-only: a correction is a new receipt. It is separate evidence from the summary itself, because a document that exists and a patient who understands it are different claims and only the second one makes a discharge safe.

**Discharge Documentation Completion Outcome**:
The provider-neutral answer to "is this episode's discharge paperwork actually finished" — complete or not, which elements are missing and who owns each, and whether a later signed document has overtaken the summary. It carries no clinical content. A consumer receives this and never the payload; one that reached for the document another way would have made itself a clinical-records system.

**Superseded Discharge Summary**:
A signed summary that a later signed document postdates. The completion outcome stops reporting complete, because something happened that the summary does not describe. A signed addendum restores it, which is the correction mechanism this context already has rather than a second one.

**Local Clinical Retention**:
The sealed, access-controlled retention of finalized Clinical Documents and their addendum chains when MedicalRecords is not installed, providing required custody without an external archive provider.

## Relationships

**OperatingRoom, Obstetrics, Radiology, Laboratory, and PhysicalTherapy** — each owns its own execution, birth, imaging, specimen, or session evidence and publishes immutable projections here. Authoring in this context requires that source's explicit clinical handoff; a projection never transfers ownership of the source record.

**InpatientCare** — the ward asks this context two questions and receives no clinical content for either: whether an accepted Clinical Handoff permits a clinician to author, and whether the episode's discharge documentation is complete. The second gates the ordinary discharge, so an incomplete summary keeps a patient in a bed. The ward owns the episode, the retention schedule for it and the audit of who read it; this context owns the documents and the release of them. Every supported composition with a ward carries this context, because a ward that documents nothing is not a hospital.

**MedicalRecords** — the optional ciphertext vault. This context alone holds the keys, encrypts every Clinical Payload before it leaves the application, keeps the archive manifest, and authorizes each bounded one-patient release. Vault absence means sealed Local Clinical Retention, never a blocked discharge.

**EPrescription** — depends on this context for prescribing. A Medication Prescription cannot be authored or issued without an Initial Diagnosis Prerequisite and a Clinical Rationale Reference to the accountable clinical record. A clinician with shortfall Takeover Authority may request a narrow, audited diagnosis and safety read anchored by the originating clinician's accepted Clinical Handoff; it receives only the facts needed to make a replacement decision, never unrestricted Clinical Document visibility or authoring rights, and no new Clinical Handoff is created. Laboratory and Radiology may publish results as supporting evidence; only an accountable clinician authors the resulting supplemental or superseding Diagnosis Assertion.

**Warehouse** — a guarded provider holding batch custody behind Clinical Consumable Usage. This context owns why a supply was used; Warehouse owns the stock truth, joined by one consumable audit correlation.
