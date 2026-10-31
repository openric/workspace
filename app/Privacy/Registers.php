<?php

// OpenRiC Workspace - the privacy registers of POPIA governance, each defined once (roadmap phase 18a, ADR 0016), after
// Heratio's ahg-privacy registers: Information Officers, the processing register, impact assessments, consent, data
// subject requests, PAIA requests, complaints and security compromises. A register's fields, list and reference are
// defined here, so its list, form and record are made from one definition, as Heratio's privacy pages repeat for each.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// A field is [type, label] with, for a vocabulary, its name. Types: text, textarea, date, datetime, number, money,
// bool, vocab, jurisdiction, user, officer, processing. A reference prefix numbers each entry PREFIX-YYYY-NNNN.

namespace App\Privacy;

use App\Models\ConsentRecord;
use App\Models\ImpactAssessment;
use App\Models\PaiaRequest;
use App\Models\PrivacyComplaint;
use App\Models\PrivacyOfficer;
use App\Models\PrivacyRequest;
use App\Models\ProcessingActivity;
use App\Models\SecurityCompromise;

final class Registers
{
    public const ALL = [
        'officers' => [
            'model' => PrivacyOfficer::class, 'title' => 'Information Officers', 'one' => 'Information Officer', 'label' => 'name',
            'fields' => [
                'name' => ['text', 'Name', 'required'], 'title' => ['text', 'Office'], 'is_deputy' => ['bool', 'Deputy Information Officer'],
                'email' => ['text', 'Email'], 'phone' => ['text', 'Telephone'], 'user_id' => ['user', 'Account'],
                'jurisdiction_id' => ['jurisdiction', 'Jurisdiction', 'required'], 'registration_number' => ['text', 'Registration with the Regulator (POPIA s.55)'],
                'appointed_on' => ['date', 'Appointed on'], 'is_active' => ['bool', 'In office'],
            ],
            'list' => ['name', 'title', 'is_deputy', 'registration_number', 'is_active'],
        ],
        'processing' => [
            'model' => ProcessingActivity::class, 'title' => 'Processing register', 'one' => 'Processing activity', 'label' => 'name', 'status' => 'processing-register-status', 'start' => 'draft',
            'fields' => [
                'name' => ['text', 'Name', 'required'], 'description' => ['textarea', 'Description'], 'jurisdiction_id' => ['jurisdiction', 'Jurisdiction', 'required'],
                'purpose' => ['textarea', 'Purpose (POPIA s.13)', 'required'], 'lawful_basis_id' => ['vocab', 'Lawful basis (POPIA s.11)', 'required', 'lawful-basis'],
                'data_subjects' => ['textarea', 'Data subjects'], 'data_categories' => ['textarea', 'Categories of personal information'],
                'special_information' => ['bool', 'Special personal information (POPIA s.26)'], 'children' => ['bool', 'Personal information of children (POPIA s.34)'],
                'recipients' => ['textarea', 'Recipients'], 'transfers' => ['textarea', 'Transfers across borders (POPIA s.72)'],
                'retention_period' => ['text', 'Retention'], 'security_measures' => ['textarea', 'Security measures (POPIA s.19)'],
                'impact_assessment_required' => ['bool', 'Impact assessment required'], 'owner' => ['text', 'Owner'], 'department' => ['text', 'Department'],
                'officer_id' => ['officer', 'Information Officer'], 'next_review_on' => ['date', 'Next review'],
            ],
            'list' => ['name', 'lawful_basis_id', 'status_id', 'next_review_on'],
        ],
        'assessments' => [
            'model' => ImpactAssessment::class, 'title' => 'Impact assessments', 'one' => 'Impact assessment', 'label' => 'name', 'status' => 'dpia-status', 'start' => 'draft',
            'fields' => [
                'name' => ['text', 'Name', 'required'], 'processing_activity_id' => ['processing', 'Processing activity'], 'description' => ['textarea', 'What is assessed'],
                'necessity' => ['textarea', 'Necessity and proportionality'], 'risks' => ['textarea', 'Risks to data subjects'], 'measures' => ['textarea', 'Measures to reduce them'],
                'residual_risks' => ['textarea', 'Risks that remain'], 'officer_opinion' => ['textarea', 'Information Officer\'s opinion'], 'officer_consulted_on' => ['date', 'Information Officer consulted on'],
            ],
            'list' => ['name', 'processing_activity_id', 'status_id', 'signed_off_at'],
        ],
        'consent' => [
            'model' => ConsentRecord::class, 'title' => 'Consent', 'one' => 'Consent', 'label' => 'data_subject',
            'fields' => [
                'data_subject' => ['text', 'Data subject', 'required'], 'contact' => ['text', 'Contact'], 'purpose' => ['textarea', 'Purpose consented to', 'required'],
                'processing_activity_id' => ['processing', 'Processing activity'], 'given_at' => ['datetime', 'Given on', 'required'], 'source' => ['text', 'How it was given'],
                'note' => ['textarea', 'Note'],
            ],
            'list' => ['data_subject', 'purpose', 'given_at', 'withdrawn_at'],
        ],
        'requests' => [
            'model' => PrivacyRequest::class, 'title' => 'Data subject requests', 'one' => 'Data subject request', 'label' => 'reference', 'prefix' => 'DSR', 'status' => 'privacy-request-status', 'start' => 'received',
            'fields' => [
                'jurisdiction_id' => ['jurisdiction', 'Jurisdiction', 'required'], 'type_id' => ['vocab', 'What is asked', 'required', 'privacy-request-type'],
                'requester_name' => ['text', 'Requester', 'required'], 'requester_email' => ['text', 'Email'], 'requester_phone' => ['text', 'Telephone'],
                'requester_id_type' => ['text', 'Proof of identity'], 'requester_id_number' => ['text', 'Identity number'], 'requester_address' => ['textarea', 'Address'],
                'details' => ['textarea', 'What the request says'], 'received_on' => ['date', 'Received on', 'required'], 'assigned_to_id' => ['user', 'Handled by'],
                'outcome' => ['textarea', 'Outcome'], 'refusal_reason' => ['textarea', 'Reason for refusal'], 'fee' => ['money', 'Fee'], 'fee_paid' => ['bool', 'Fee paid'],
            ],
            'list' => ['reference', 'type_id', 'requester_name', 'status_id', 'due_on'],
        ],
        'paia' => [
            'model' => PaiaRequest::class, 'title' => 'PAIA requests', 'one' => 'PAIA request', 'label' => 'reference', 'prefix' => 'PAIA', 'status' => 'privacy-request-status', 'start' => 'received',
            'fields' => [
                'kind_id' => ['vocab', 'Kind of request', 'required', 'paia-section'], 'requester_name' => ['text', 'Requester', 'required'], 'requester_email' => ['text', 'Email'],
                'requester_phone' => ['text', 'Telephone'], 'requester_id_number' => ['text', 'Identity number'], 'requester_address' => ['textarea', 'Address'],
                'record_description' => ['textarea', 'Records asked for', 'required'], 'access_form_id' => ['vocab', 'Form of access (PAIA s.29)', null, 'paia-access-form'],
                'received_on' => ['date', 'Received on', 'required'], 'assigned_to_id' => ['user', 'Handled by'], 'refusal_ground_id' => ['vocab', 'Ground of refusal', null, 'paia-refusal-ground'],
                'outcome' => ['textarea', 'Outcome'], 'fee_request' => ['money', 'Request fee'], 'fee_deposit' => ['money', 'Deposit'], 'fee_access' => ['money', 'Access fee'],
                'fee_paid' => ['bool', 'Fees paid'],
            ],
            'list' => ['reference', 'kind_id', 'requester_name', 'status_id', 'due_on'],
        ],
        'complaints' => [
            'model' => PrivacyComplaint::class, 'title' => 'Complaints', 'one' => 'Complaint', 'label' => 'reference', 'prefix' => 'CMP', 'status' => 'privacy-request-status', 'start' => 'received',
            'fields' => [
                'jurisdiction_id' => ['jurisdiction', 'Jurisdiction', 'required'], 'complainant_name' => ['text', 'Complainant', 'required'], 'complainant_email' => ['text', 'Email'],
                'complainant_phone' => ['text', 'Telephone'], 'type_id' => ['vocab', 'About', 'required', 'complaint-type'], 'description' => ['textarea', 'Complaint', 'required'],
                'incident_on' => ['date', 'Happened on'], 'received_on' => ['date', 'Received on', 'required'], 'assigned_to_id' => ['user', 'Handled by'],
                'resolution' => ['textarea', 'Resolution'], 'resolved_on' => ['date', 'Resolved on'],
            ],
            'list' => ['reference', 'complainant_name', 'type_id', 'status_id', 'received_on'],
        ],
        'compromises' => [
            'model' => SecurityCompromise::class, 'title' => 'Security compromises', 'one' => 'Security compromise', 'label' => 'reference', 'prefix' => 'SC', 'status' => 'breach-status', 'start' => 'detected',
            'fields' => [
                'jurisdiction_id' => ['jurisdiction', 'Jurisdiction', 'required'], 'type_id' => ['vocab', 'What was affected', 'required', 'breach-type'],
                'severity_id' => ['vocab', 'Severity', 'required', 'breach-severity'], 'description' => ['textarea', 'What happened', 'required'],
                'detected_at' => ['datetime', 'Detected', 'required'], 'occurred_at' => ['datetime', 'Happened'], 'contained_at' => ['datetime', 'Contained'], 'resolved_at' => ['datetime', 'Resolved'],
                'subjects_affected' => ['number', 'Data subjects affected'], 'categories_affected' => ['textarea', 'Personal information affected'], 'risk' => ['textarea', 'Risk to data subjects'],
                'notification_required' => ['bool', 'The Regulator and data subjects must be told (POPIA s.22)'], 'assigned_to_id' => ['user', 'Handled by'],
            ],
            'list' => ['reference', 'type_id', 'severity_id', 'status_id', 'detected_at'],
        ],
    ];

    public static function get(string $register): array
    {
        return self::ALL[$register] ?? abort(404);
    }
}
