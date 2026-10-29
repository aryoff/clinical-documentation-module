<?php

return [
    'name' => 'ClinicalDocumentation',
    'presented_external_evidence_retention_days' => env('CLINICALDOCUMENTATION_PRESENTED_EXTERNAL_EVIDENCE_RETENTION_DAYS', 30),
    // Retention review judges attendance coverage up to this long before now: no provider can vouch for the last moment.
    'retention' => [
        'coverage_tolerance_minutes' => (int) env('CLINICALDOCUMENTATION_RETENTION_COVERAGE_TOLERANCE_MINUTES', 60),
        // Destruction executes only when this is switched on; off by default.
        'destruction_enabled' => (bool) env('CLINICALDOCUMENTATION_DESTRUCTION_ENABLED', false),
    ],
    'disclosure' => [
        // Facility-configurable, not a legal enumeration: the non-consensual routes a Disclosure Officer may record as the
        // basis, each carrying its own request evidence.
        // What can establish a representative's authority; a relationship such as "parent" does not.
        'representative_evidence_kinds' => ['guardianship_document', 'court_appointment', 'power_of_attorney'],
        'statutory_bases' => ['court_order', 'statutory_authority_request', 'public_health_obligation'],
    ],
];
