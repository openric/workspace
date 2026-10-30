<?php

// OpenRiC Workspace - application settings.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

return [

    // The OpenRiC specification release this build implements (ADR 0003, rule 1;
    // conformance/README.md). Raised only together with the conformance matrix.
    'openric_spec_version' => '0.56.0',

    // Ed25519 key that signs every audit row (ADR 0004). Kept outside git and the
    // database, readable only by the application; back it up with the database.
    'audit_signing_key' => env('WORKSPACE_AUDIT_SIGNING_KEY', storage_path('keys/audit-signing.sk')),

    // The Workspace's own vocabularies, bundled with the code (resources/vocabularies).
    'bundled_vocabularies' => ['account-role.ttl', 'permission.ttl', 'audit-action.ttl', 'description-element.ttl', 'language-value-state.ttl', 'description-status.ttl', 'description-detail.ttl', 'level-of-description.ttl', 'record-kind.ttl', 'date-property.ttl', 'date-qualifier.ttl', 'name-type.ttl', 'identifier-type.ttl', 'relation-type.ttl', 'relation-state.ttl', 'relation-certainty.ttl', 'assertion-origin.ttl', 'assertion-status.ttl', 'concept-scheme-kind.ttl', 'concept-match-type.ttl', 'agent-type.ttl', 'carrier-type.ttl', 'file-use.ttl', 'file-availability.ttl', 'container-type.ttl', 'storage-place-type.ttl', 'container-status.ttl', 'capacity-unit.ttl', 'storage-security-level.ttl', 'api-scope.ttl', 'proposal-kind.ttl', 'rights-basis.ttl', 'rights-statement.ttl', 'licence.ttl', 'copyright-status.ttl', 'rights-act.ttl', 'rights-restriction.ttl', 'tk-label.ttl', 'label-applier.ttl', 'job-type.ttl', 'job-status.ttl', 'watermark-type.ttl', 'watermark-position.ttl', 'email-bounce-type.ttl', 'email-status.ttl', 'access-request-status.ttl', 'access-request-urgency.ttl', 'usage-event.ttl', 'web-crawler.ttl', 'report-status.ttl', 'report-section-type.ttl', 'report-frequency.ttl', 'repository-type.ttl', 'access-request-type.ttl', 'access-type.ttl', 'access-request-scope.ttl', 'icip-sensitivity.ttl', 'community-access-condition.ttl', 'display-standard.ttl', 'display-element.ttl', 'rad-material-designation.ttl', 'dcmi-type.ttl', 'mods-resource-type.ttl', 'acquisition-type.ttl', 'accession-resource-type.ttl', 'processing-status.ttl', 'processing-priority.ttl', 'accession-event-type.ttl', 'deaccession-scope.ttl', 'caais-extent-type.ttl', 'caais-extent-unit.ttl', 'caais-content-type.ttl', 'caais-carrier-type.ttl', 'caais-preservation-type.ttl', 'caais-source-confidentiality.ttl', 'agreement-type.ttl', 'agreement-status.ttl', 'agreement-right.ttl', 'agreement-permission.ttl', 'agreement-restriction-type.ttl', 'popia-category.ttl', 'agreement-document-type.ttl', 'reminder-type.ttl', 'reminder-recurrence.ttl', 'reminder-priority.ttl', 'reminder-status.ttl', 'intake-status.ttl', 'intake-priority.ttl', 'timeline-event.ttl', 'attachment-category.ttl', 'appraisal-type.ttl', 'appraisal-significance.ttl', 'appraisal-recommendation.ttl', 'valuation-type.ttl', 'valuation-method.ttl', 'appraisal-sector.ttl', 'container-condition.ttl', 'rights-access.ttl', 'provenance-current-status.ttl', 'provenance-custody-type.ttl', 'provenance-acquisition-type.ttl', 'provenance-certainty.ttl', 'provenance-research-status.ttl', 'cultural-property-status.ttl', 'provenance-owner-type.ttl', 'provenance-evidence-type.ttl', 'provenance-document-type.ttl', 'provenance-transfer-type.ttl', 'workflow-scope.ttl', 'workflow-trigger.ttl', 'workflow-step-type.ttl', 'workflow-action.ttl', 'workflow-task-status.ttl', 'workflow-priority.ttl', 'workflow-decision.ttl', 'workflow-history-action.ttl', 'workflow-escalation-action.ttl', 'publish-gate-rule-type.ttl', 'publish-gate-severity.ttl'],

    // API keys (ADR 0007, Heratio's ApiKeyService): what an account must hold, anywhere, for a key it
    // owns to carry each scope (api-scope vocabulary), as [action, kind] pairs of which any one will do.
    // read needs nothing: an API read sees what the Public role sees. A key loses a scope at once when
    // its account no longer qualifies.
    'api_scope_requires' => [
        'read' => [],
        'write' => [['create', 'description'], ['update', 'description'], ['create', 'authority'], ['update', 'authority']],
    ],

    // Requests a key may make per hour unless it says otherwise (Heratio's default rate limit).
    'api_key_hourly_limit' => (int) env('WORKSPACE_API_KEY_HOURLY_LIMIT', 1000),

    // The shape of the permission matrix: for each kind of record (record-kind
    // vocabulary), the actions that apply to it (permission vocabulary), as
    // Heratio has one action list per class (ADR 0005).
    'permission_matrix' => [
        'institution' => ['manage-institution', 'manage-accounts', 'manage-roles', 'view-audit-log', 'manage-workflows'],
        'repository' => ['read', 'create', 'update', 'delete', 'publish'],
        'description' => ['read', 'create', 'update', 'delete', 'view-draft', 'publish', 'read-master', 'read-reference', 'read-thumbnail', 'translate', 'review-proposals', 'review-access-requests'],
        'vocabulary' => ['create', 'update', 'delete', 'publish'],
        'authority' => ['read', 'create', 'update', 'delete', 'publish'],
        'storage' => ['read', 'create', 'update', 'delete'],
        'accession' => ['read', 'create', 'update', 'delete'],
        'agreement' => ['read', 'create', 'update', 'delete'],
    ],

    // Which date properties (date-property vocabulary) each kind of entity (App\Relations\EntityKinds,
    // and names and relations) offers on screen, in this order (ADR 0006). The store accepts any of them.
    'date_properties' => [
        'record' => ['creation', 'accumulation', 'associated'],
        'agent' => ['existence', 'associated'],
        'agent:person' => ['existence', 'birth', 'death', 'associated'],
        'repository' => ['existence', 'associated'],
        'name' => ['used-from', 'used-to'],
        'relation' => ['beginning', 'end', 'relation'],
        'place' => ['existence', 'associated'],
        'activity' => ['associated', 'beginning', 'end'],
        'instantiation' => ['creation', 'associated'],
        'container' => ['existence', 'associated'],
        // An accession: the date it was accessioned, and the dates of the material received (CAAIS 3.1).
        'accession' => ['acquisition', 'creation', 'accumulation'],
    ],

    // The groups a relation type's pairs (relation_type_pairs) may name for a kind of entity: "agent" stands for
    // every kind of agent, person a person, group a family or corporate body (rico:Group). The relation types
    // themselves, what they connect and how they read, are rows (migration 2026_10_29_000100).
    'relation_kind_groups' => [
        'agent' => ['repository', 'agent'],
        'person' => ['agent:person'],
        'group' => ['repository', 'agent:family|corporate-body'],
        'position' => ['agent:position'],
    ],

    // The RiC-O 1.1 class the concepts of each kind of local vocabulary (concept-scheme-kind
    // vocabulary) are exported as, beside skos:Concept.
    'scheme_kinds' => [
        'subject' => 'https://www.ica.org/standards/RiC/ontology#Concept',
        'occupation-type' => 'https://www.ica.org/standards/RiC/ontology#OccupationType',
        'place-type' => 'https://www.ica.org/standards/RiC/ontology#PlaceType',
        'documentary-form-type' => 'https://www.ica.org/standards/RiC/ontology#DocumentaryFormType',
        'legal-status' => 'https://www.ica.org/standards/RiC/ontology#LegalStatus',
        'activity-type' => 'https://www.ica.org/standards/RiC/ontology#ActivityType',
        'container-type' => 'https://openric.org/ns/ext/v1#ContainerType',
        'storage-place-type' => 'https://www.ica.org/standards/RiC/ontology#PlaceType',
    ],

    // Description elements whose value is a list of ISO codes, as AtoM's language and script pick lists: language
    // (ISO 639) or script (ISO 15924). The codes are kept in one value, separated by spaces, in the language
    // zxx (no linguistic content), and named in the interface language (IsoCodes).
    'code_elements' => [
        'languages-of-material-codes' => 'language', 'scripts-of-material-codes' => 'script',
        'languages-of-description' => 'language', 'scripts-of-description' => 'script',
        'repository-languages' => 'language', 'repository-scripts' => 'script',
        'agent-languages' => 'language', 'agent-scripts' => 'script',
        'activity-languages' => 'language', 'activity-scripts' => 'script',
    ],

    // Description elements that are names (ADR 0006): what the description form
    // writes there is kept as rico:Name entities of this name type (name-type
    // vocabulary). An authorised name is single, one in each language; the others
    // take one name per line.
    'name_elements' => [
        'title' => 'authorised',
        'authorised-name' => 'authorised',
        'parallel-name' => 'parallel',
        'other-name' => 'other',
        'place-name' => 'authorised',
        'place-parallel-name' => 'parallel',
        'place-other-name' => 'other',
        'agent-authorised-name' => 'authorised',
        'agent-parallel-name' => 'parallel',
        'agent-standardised-name' => 'standardised',
        'agent-other-name' => 'other',
        'activity-name' => 'authorised',
        'activity-parallel-name' => 'parallel',
        'activity-other-name' => 'other',
        'alternate-title' => 'other',
        'instantiation-title' => 'authorised',
        'container-name' => 'authorised',
        'container-other-name' => 'other',
        'accession-title' => 'authorised',
        'agreement-title' => 'authorised',
    ],

    // The roles a new institution starts with, by account-role template, with
    // their parent and their permissions on everything (* = every action of
    // every kind), after Heratio's and AtoM's standard groups. Read only when
    // an institution is installed; afterwards roles and permissions are data.
    'default_roles' => [
        'public' => ['parent' => null, 'grants' => ['repository' => ['read'], 'description' => ['read', 'read-reference', 'read-thumbnail'], 'authority' => ['read']]],
        'signed-in' => ['parent' => 'public', 'grants' => []],
        'administrator' => ['parent' => 'signed-in', 'grants' => '*'],
        'editor' => ['parent' => 'signed-in', 'grants' => [
            'repository' => ['read', 'create', 'update', 'publish'],
            'description' => ['read', 'create', 'update', 'delete', 'view-draft', 'publish', 'read-master', 'read-reference', 'read-thumbnail', 'review-proposals', 'review-access-requests'],
            'vocabulary' => ['create', 'update', 'publish'],
            'authority' => ['read', 'create', 'update', 'publish'],
            'storage' => ['read', 'create', 'update'],
            'accession' => ['read', 'create', 'update', 'delete'],
            'agreement' => ['read', 'create', 'update', 'delete'],
        ]],
        'contributor' => ['parent' => 'signed-in', 'grants' => [
            'repository' => ['read'],
            'description' => ['read', 'create', 'update', 'view-draft', 'read-reference', 'read-thumbnail'],
            'authority' => ['read', 'create', 'update'],
            'storage' => ['read'],
        ]],
        'translator' => ['parent' => 'signed-in', 'grants' => ['description' => ['read', 'view-draft', 'translate'], 'authority' => ['read']]],
    ],

    // Vocabularies every installation loads (`php artisan workspace:vocabulary:import --configured`).
    // Published OpenRiC vocabularies are used as they are, never copied into local ones
    // (logical model, section 2).
    'vocabularies' => [
        'https://openric.org/vocab/role-type.ttl',
        'https://openric.org/vocab/date-type.ttl',
        'https://openric.org/vocab/activity-type.ttl',
        'https://openric.org/vocab/rule-type.ttl',
    ],

    // Digital files (WS-007). Uploads larger than this are refused; nginx and PHP must allow
    // at least as much. The scanner is a ClamAV client run on every upload (clamdscan, which
    // uses the running daemon); empty, files are kept as not scanned.
    'max_upload_megabytes' => (int) env('WORKSPACE_MAX_UPLOAD_MB', 60),
    'object_store' => env('WORKSPACE_OBJECT_STORE'),
    'virus_scanner' => env('WORKSPACE_VIRUS_SCANNER', 'clamdscan'),

    // A copy of AtoM's uploads folder (the one holding r/), from which an import of
    // archival descriptions takes the file each digitalObjectURI names; empty, none is.
    'import_files' => env('WORKSPACE_IMPORT_FILES'),

    // The OAI-PMH adminEmail when no repository gives a contact email (Export-Only).
    'oai_admin_email' => env('WORKSPACE_OAI_ADMIN_EMAIL'),

    // Email delivery (roadmap phase 7). The mail provider's bounce and complaint webhook,
    // POST /webhooks/email/bounce, is signed with this secret (an HMAC-SHA256 of the body in
    // X-AHG-Signature); empty, every call is refused. The provider is found from the payload,
    // or forced here: postmark, ses, sparkpost, mailgun or generic.
    'email_bounce_secret' => env('WORKSPACE_EMAIL_BOUNCE_SECRET'),
    'email_bounce_provider' => env('WORKSPACE_EMAIL_BOUNCE_PROVIDER'),

    // Usage statistics (roadmap phase 8): days the raw usage events are kept, as PSIS's retention_days; the daily
    // counts made from them are kept for good. The events hold no address, so this limits size, not exposure.
    'usage_retention_days' => (int) env('WORKSPACE_USAGE_RETENTION_DAYS', 90),

    // The time zone the times of scheduled reports are given in (roadmap phase 8).
    'timezone' => env('WORKSPACE_TIMEZONE', 'Africa/Johannesburg'),
];
