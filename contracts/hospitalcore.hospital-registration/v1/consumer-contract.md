# Hospital Registration consumer contract

ClinicalDocumentation optionally consumes `hospitalcore.hospital-registration`
in synchronous mode. When the provider is enabled, `describe(registration_id)`
returns the registration identity and journey status. Evidence staging accepts
only a registration whose `journey_status` is `active`. During the transaction
that creates a staged evidence record, the consumer calls `assertActive` so the
provider locks the registration row until that transaction commits; this keeps
release and staging from crossing one another.

When HospitalCore is absent, the port returns no registration and the staging
workflow is unavailable. ClinicalDocumentation's authoring and record features
remain independently loadable.
