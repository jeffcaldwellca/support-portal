<?php
declare(strict_types=1);

/**
 * FreeScout Field Mappings Configuration
 * 
 * This file defines how form fields are mapped to FreeScout custom fields,
 * request types, and tags. Customize these mappings to match your FreeScout setup.
 */

return [
    /**
     * Custom Field Mapping
     * 
     * Maps form field names to FreeScout custom field names.
     * Format: 'form_field_name' => 'FreeScout Custom Field Name'
     * 
     * The form field name is the 'name' attribute in your form_fields.yaml
     * The FreeScout field name must match exactly as defined in FreeScout.
     */
    'custom_fields' => [
        'priority' => 'Priority',
        // Add more mappings as needed:
        // 'department' => 'Department',
        // 'location' => 'Site Location',
        // 'urgency' => 'Urgency Level',
    ],
    
    /**
     * Request Type Mapping
     * 
     * Maps form request types to FreeScout Conversation Type values.
     * This is used when the 'request_type' field is mapped to a custom field.
     * Format: 'form_request_type' => 'FreeScout Conversation Type Value'
     */
    'request_types' => [
        'problem' => 'Problem',
        // Add more mappings as needed:
        // 'hardware_request' => 'Request',
        // 'password_reset' => 'Incident',
    ],
    
    /**
     * Request Type Tag Mapping
     * 
     * Maps request types to FreeScout tags for categorization and filtering.
     * Format: 'request_type' => ['tag1', 'tag2', ...]
     * 
     * Tags help organize and filter tickets in FreeScout.
     * Use lowercase, no spaces (use hyphens instead).
     */
    'tags' => [
        'jdedwards' => ['jde', 'erp'],
        'problem' => ['technical-issue'],
        'software_request' => ['software'],
        'access_request' => ['access', 'permissions'],
        'onboarding' => ['onboarding', 'new-hire'],
        'change' => ['change-request'],
        'other' => [],
        // Add more tag mappings as needed:
        // 'hardware_request' => ['hardware', 'equipment'],
        // 'password_reset' => ['password', 'account'],
        // 'network_issue' => ['network', 'connectivity'],
    ],
    
    /**
     * Subject Templates
     * 
     * Define subject line templates for different request types.
     * Use {field_name} placeholders to insert form field values.
     * Format: 'request_type' => 'Subject Template'
     */
    'subject_templates' => [
        'onboarding' => 'New Employee Onboarding - {employee_name}',
        'problem' => 'Technical Problem - {device_type}',
        'change' => 'Change Request - {subject}',
        'software_request' => 'Software Request - {application_name}',
        'access_request' => 'Access Request - {system_name}',
        'jdedwards' => 'JD Edwards Issue - {subject}',
        'other' => 'IT Request - {subject}',
        // Fallback if no match
        '_default' => 'IT Request',
    ],
    
    /**
     * Fields to Exclude from Ticket Body
     * 
     * These fields will not be included in the ticket body as they're
     * already captured in FreeScout metadata, custom fields, or subject.
     */
    'exclude_from_body' => [
        'requester_name',
        'requester_email',
        'department',
        'priority',
        'request_type',
        'subject',
        'csrf_token',
        'auth_method',
        'autosave_session_id',
    ],
];
