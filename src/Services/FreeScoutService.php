<?php
declare(strict_types=1);

namespace HelpdeskForm\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

class FreeScoutService
{
    private Client $client;
    private string $apiUrl;
    private string $apiKey;
    private LoggerInterface $logger;
    private ?array $customFieldsCache = null;
    private array $mappings;
    private ?array $fieldDefinitions = null;
    private ?int $mailboxId = null;
    
    public function __construct(string $apiUrl, string $apiKey, LoggerInterface $logger, ?array $mappings = null, ?array $fieldDefinitions = null, ?int $mailboxId = null)
    {
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->apiKey = $apiKey;
        $this->logger = $logger;
        $this->mailboxId = $mailboxId;
        
        // Load mappings from config file or use provided mappings
        if ($mappings === null) {
            $configFile = __DIR__ . '/../../config/freescout_mappings.php';
            if (file_exists($configFile)) {
                $this->mappings = require $configFile;
            } else {
                // Fallback to minimal defaults if config file doesn't exist
                $this->mappings = [
                    'custom_fields' => [],
                    'request_types' => [],
                    'tags' => [],
                    'subject_templates' => ['_default' => 'IT Request'],
                    'exclude_from_body' => ['requester_name', 'requester_email', 'csrf_token']
                ];
            }
        } else {
            $this->mappings = $mappings;
        }
        
        // Store field definitions from YAML for field-level FreeScout mapping
        $this->fieldDefinitions = $fieldDefinitions;
        
        // Configure client options
        $clientOptions = [
            'base_uri' => $this->apiUrl . '/', // Ensure trailing slash for proper path appending
            'timeout' => 30,
            'headers' => [
                'X-FreeScout-API-Key' => $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ]
        ];
        
        // Disable SSL verification for local development if needed
        // WARNING: Only use this for trusted local environments
        if (strpos($apiUrl, '.local') !== false || strpos($apiUrl, 'localhost') !== false) {
            $clientOptions['verify'] = false;
        }
        
        $this->client = new Client($clientOptions);
    }
    
    /**
     * Filter conversation data to remove internal/administrative fields
     * that end users should not see
     */
    private function filterConversationForCustomer(array $conversation): array
    {
        // Fields to keep for customer view
        $allowedFields = [
            'id',
            'number',
            'subject',
            'status',
            'state',
            'createdAt',
            'updatedAt',
            'customer',
            'customFields',
            'assignee',
            '_embedded'
        ];
        
        // Filter top-level fields
        $filtered = array_intersect_key($conversation, array_flip($allowedFields));
        
        // Filter threads to remove internal notes and private data
        if (isset($filtered['_embedded']['threads'])) {
            $filtered['_embedded']['threads'] = array_values(array_filter(
                $filtered['_embedded']['threads'],
                function($thread) {
                    // Only show customer messages and staff replies (not internal notes)
                    return in_array($thread['type'] ?? '', ['customer', 'message']);
                }
            ));
            
            // Filter thread fields
            $filtered['_embedded']['threads'] = array_map(function($thread) {
                $allowedThreadFields = [
                    'id',
                    'type',
                    'body',
                    'createdAt',
                    'createdBy',
                    'attachments'
                ];
                
                $filteredThread = array_intersect_key($thread, array_flip($allowedThreadFields));
                
                // Filter createdBy to only show name
                if (isset($filteredThread['createdBy'])) {
                    $filteredThread['createdBy'] = [
                        'firstName' => $filteredThread['createdBy']['firstName'] ?? '',
                        'lastName' => $filteredThread['createdBy']['lastName'] ?? ''
                    ];
                }
                
                // Filter attachments to only show customer-relevant info
                if (isset($filteredThread['attachments'])) {
                    $filteredThread['attachments'] = array_map(function($attachment) {
                        return [
                            'fileName' => $attachment['fileName'] ?? '',
                            'mimeType' => $attachment['mimeType'] ?? '',
                            'size' => $attachment['size'] ?? 0
                        ];
                    }, $filteredThread['attachments']);
                }
                
                return $filteredThread;
            }, $filtered['_embedded']['threads']);
        }
        
        // Filter customer fields to only show relevant info
        if (isset($filtered['customer'])) {
            $filtered['customer'] = [
                'firstName' => $filtered['customer']['firstName'] ?? '',
                'lastName' => $filtered['customer']['lastName'] ?? '',
                'email' => $filtered['customer']['email'] ?? ''
            ];
        }
        
        // Filter custom fields to only show name and value
        if (isset($filtered['customFields'])) {
            $filtered['customFields'] = array_map(function($field) {
                return [
                    'name' => $field['name'] ?? '',
                    'value' => $field['value'] ?? ''
                ];
            }, $filtered['customFields']);
        }
        
        return $filtered;
    }
    
    public function createConversation(array $data): array
    {
        try {
            $this->logger->info('Creating FreeScout conversation', ['data' => $data]);
            
            $response = $this->client->post('conversations', [
                'json' => $data
            ]);
            
            $result = json_decode($response->getBody()->getContents(), true);
            
            $this->logger->info('FreeScout conversation created', [
                'response' => $result,
                'status' => $response->getStatusCode()
            ]);
            
            return $result;
            
        } catch (GuzzleException $e) {
            $this->logger->error('Failed to create FreeScout conversation', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
            
            throw new \RuntimeException('Failed to create ticket: ' . $e->getMessage());
        }
    }
    
    public function getConversation(int $conversationId): array
    {
        try {
            $response = $this->client->get("conversations/{$conversationId}", [
                'query' => ['embed' => 'threads']
            ]);
            $conversation = json_decode($response->getBody()->getContents(), true);
            return $this->filterConversationForCustomer($conversation);
        } catch (GuzzleException $e) {
            $this->logger->error('Failed to get FreeScout conversation', [
                'conversation_id' => $conversationId,
                'error' => $e->getMessage()
            ]);
            
            throw new \RuntimeException('Failed to get ticket: ' . $e->getMessage());
        }
    }
    
    public function getCustomerConversations(string $customerEmail): array
    {
        try {
            $response = $this->client->get('conversations', [
                'query' => [
                    'customerEmail' => $customerEmail,
                    'sortField' => 'updatedAt',
                    'sortOrder' => 'desc',
                    'pageSize' => 100
                ]
            ]);
            
            $result = json_decode($response->getBody()->getContents(), true);
            $conversations = $result['_embedded']['conversations'] ?? [];
            
            // Filter out deleted tickets (state = 5) and spam (state = 4)
            $conversations = array_filter($conversations, function($conversation) {
                $state = $conversation['state'] ?? 0;
                // Exclude deleted (5) and spam (4) tickets
                return !in_array($state, [4, 5]);
            });
            
            // Filter each conversation to remove internal data
            return array_map([$this, 'filterConversationForCustomer'], $conversations);
            
        } catch (GuzzleException $e) {
            $this->logger->error('Failed to get customer conversations', [
                'email' => $customerEmail,
                'error' => $e->getMessage()
            ]);
            
            return [];
        }
    }
    
    public function addThread(int $conversationId, array $threadData): array
    {
        try {
            $this->logger->info('Adding thread to conversation', [
                'conversation_id' => $conversationId,
                'thread_type' => $threadData['type'] ?? 'unknown',
                'has_attachments' => isset($threadData['attachments'])
            ]);
            
            $response = $this->client->post("conversations/{$conversationId}/threads", [
                'json' => $threadData
            ]);
            
            $result = json_decode($response->getBody()->getContents(), true);
            
            $this->logger->info('Thread added successfully', [
                'conversation_id' => $conversationId,
                'thread_id' => $result['id'] ?? 'unknown'
            ]);
            
            return $result;
        } catch (GuzzleException $e) {
            $this->logger->error('Failed to add thread to conversation', [
                'conversation_id' => $conversationId,
                'error' => $e->getMessage()
            ]);
            
            throw new \RuntimeException('Failed to add message: ' . $e->getMessage());
        }
    }
    
    public function getCustomer(string $email): ?array
    {
        try {
            $response = $this->client->get('customers', [
                'query' => ['email' => $email]
            ]);
            
            $result = json_decode($response->getBody()->getContents(), true);
            
            if (isset($result['_embedded']['customers']) && !empty($result['_embedded']['customers'])) {
                return $result['_embedded']['customers'][0];
            }
            
            return null;
        } catch (GuzzleException $e) {
            $this->logger->error('Failed to get customer', [
                'email' => $email,
                'error' => $e->getMessage()
            ]);
            
            return null;
        }
    }
    
    public function createCustomer(array $customerData): array
    {
        try {
            $response = $this->client->post('customers', [
                'json' => $customerData
            ]);
            
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            $this->logger->error('Failed to create customer', [
                'data' => $customerData,
                'error' => $e->getMessage()
            ]);
            
            throw new \RuntimeException('Failed to create customer: ' . $e->getMessage());
        }
    }
    
    public function getMailboxes(): array
    {
        try {
            $response = $this->client->get('mailboxes');
            $result = json_decode($response->getBody()->getContents(), true);
            
            return $result['_embedded']['mailboxes'] ?? [];
        } catch (GuzzleException $e) {
            $this->logger->error('Failed to get mailboxes', [
                'error' => $e->getMessage()
            ]);
            
            return [];
        }
    }
    
    public function getMailboxCustomFields(int $mailboxId): array
    {
        try {
            $response = $this->client->get("mailboxes/{$mailboxId}/custom_fields");
            $result = json_decode($response->getBody()->getContents(), true);
            
            return $result['_embedded']['custom_fields'] ?? [];
        } catch (GuzzleException $e) {
            $this->logger->error('Failed to get mailbox custom fields', [
                'mailbox_id' => $mailboxId,
                'error' => $e->getMessage()
            ]);
            
            return [];
        }
    }
    
    public function uploadAttachment(string $filePath, string $originalName): ?array
    {
        try {
            $response = $this->client->post('attachments', [
                'multipart' => [
                    [
                        'name' => 'file',
                        'contents' => fopen($filePath, 'r'),
                        'filename' => $originalName
                    ]
                ]
            ]);
            
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            $this->logger->error('Failed to upload attachment', [
                'file' => $originalName,
                'error' => $e->getMessage()
            ]);
            
            return null;
        }
    }
    
    /**
     * Prepare attachment for inline inclusion in conversation thread
     * Uses base64 encoding as specified in FreeScout API docs
     */
    public function prepareInlineAttachment(string $filePath, string $originalName): ?array
    {
        try {
            if (!file_exists($filePath)) {
                $this->logger->error('Attachment file not found', ['file' => $filePath]);
                return null;
            }
            
            $fileContents = file_get_contents($filePath);
            if ($fileContents === false) {
                $this->logger->error('Failed to read attachment file', ['file' => $filePath]);
                return null;
            }
            
            $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';
            
            return [
                'fileName' => $originalName,
                'mimeType' => $mimeType,
                'data' => base64_encode($fileContents)
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to prepare inline attachment', [
                'file' => $originalName,
                'error' => $e->getMessage()
            ]);
            
            return null;
        }
    }
    
    private function mapCustomFields(array $formData, int $mailboxId): array
    {
        // Get custom fields definition from FreeScout
        if ($this->customFieldsCache === null) {
            $this->customFieldsCache = $this->getMailboxCustomFields($mailboxId);
        }
        
        $customFields = [];
        
        // Build a combined mapping from both sources:
        // 1. Field definitions from YAML (field-level freescout_field attribute)
        // 2. Legacy PHP config mappings (for backward compatibility)
        $customFieldMapping = $this->mappings['custom_fields'] ?? [];
        
        // Add YAML-based mappings (these take priority)
        if ($this->fieldDefinitions !== null) {
            foreach ($this->fieldDefinitions as $fieldDef) {
                if (isset($fieldDef['freescout_field']) && !empty($fieldDef['freescout_field'])) {
                    $customFieldMapping[$fieldDef['name']] = $fieldDef['freescout_field'];
                }
            }
        }
        
        // Loop through form data and map to custom fields
        foreach ($customFieldMapping as $formFieldName => $customFieldName) {
            // Skip if form doesn't have this field
            if (!isset($formData[$formFieldName]) || empty($formData[$formFieldName])) {
                continue;
            }
            
            // Find the custom field definition
            $customField = null;
            foreach ($this->customFieldsCache as $field) {
                if ($field['name'] === $customFieldName) {
                    $customField = $field;
                    break;
                }
            }
            
            if (!$customField) {
                $this->logger->warning('Custom field not found in FreeScout', [
                    'field_name' => $customFieldName,
                    'form_field' => $formFieldName
                ]);
                continue;
            }
            
            $value = $formData[$formFieldName];
            
            // Transform request_type values to FreeScout Conversation Type values
            $requestTypeMapping = $this->mappings['request_types'] ?? [];
            if ($formFieldName === 'request_type' && isset($requestTypeMapping[$value])) {
                $value = $requestTypeMapping[$value];
            }
            
            // For dropdown fields, we need to map the text value to the option ID
            if ($customField['type'] === 'dropdown' && isset($customField['options'])) {
                // Search for the matching option value (case-insensitive)
                $optionId = null;
                foreach ($customField['options'] as $id => $label) {
                    if (strcasecmp(trim($label), trim($value)) === 0) {
                        $optionId = $id;
                        break;
                    }
                }
                
                if ($optionId !== null) {
                    $value = (int)$optionId;
                } else {
                    $this->logger->warning('Dropdown option not found', [
                        'field' => $customFieldName,
                        'value' => $value,
                        'available_options' => $customField['options']
                    ]);
                    continue;
                }
            }
            
            $customFields[] = [
                'id' => $customField['id'],
                'value' => $value
            ];
        }
        
        return $customFields;
    }
    
    public function buildTicketData(array $formData, string $requestType): array
    {
        // Get or create customer
        $customer = $this->getCustomer($formData['requester_email']);
        if (!$customer) {
            $customer = $this->createCustomer([
                'firstName' => explode(' ', $formData['requester_name'])[0] ?? $formData['requester_name'],
                'lastName' => implode(' ', array_slice(explode(' ', $formData['requester_name']), 1)) ?: '',
                'email' => $formData['requester_email']
            ]);
        }
        
        // Get mailbox ID from configuration or fall back to API lookup
        $mailboxId = $this->getConfiguredMailboxId();
        
        // Build subject
        $subject = $this->buildSubject($requestType, $formData);
        
        // Build body
        $body = $this->buildBody($requestType, $formData);
        
        // Parse name into first and last
        $nameParts = explode(' ', $formData['requester_name'], 2);
        $firstName = $nameParts[0] ?? $formData['requester_name'];
        $lastName = $nameParts[1] ?? '';
        
        // Map form fields to FreeScout custom fields
        $customFields = $this->mapCustomFields($formData, $mailboxId);
        
        $ticketData = [
            'subject' => $subject,
            'mailboxId' => $mailboxId,
            'customer' => [
                'email' => $formData['requester_email'],
                'first_name' => $firstName,
                'last_name' => $lastName
            ],
            'type' => 'email',
            'status' => 'active',
            'threads' => [
                [
                    'type' => 'customer',
                    'customer' => [
                        'email' => $formData['requester_email'],
                        'first_name' => $firstName,
                        'last_name' => $lastName
                    ],
                    'text' => $body,
                    'attachments' => []
                ]
            ],
            'tags' => $this->buildTags($requestType)
        ];
        
        // Add custom fields if any were mapped
        if (!empty($customFields)) {
            $ticketData['customFields'] = $customFields;
        }
        
        return $ticketData;
    }
    
    /**
     * Get the configured mailbox ID
     * Uses the environment variable if set, otherwise falls back to API lookup
     * 
     * @return int The mailbox ID to use for ticket creation
     * @throws \RuntimeException If no mailbox ID is configured and none found via API
     */
    public function getConfiguredMailboxId(): int
    {
        // Use configured mailbox ID if available
        if ($this->mailboxId !== null) {
            $this->logger->debug('Using configured mailbox ID', ['mailbox_id' => $this->mailboxId]);
            return $this->mailboxId;
        }
        
        // Fall back to fetching first mailbox from API
        $this->logger->warning('No mailbox ID configured, falling back to API lookup');
        $mailboxes = $this->getMailboxes();
        
        if (empty($mailboxes)) {
            throw new \RuntimeException('No mailbox ID configured and no mailboxes found in FreeScout. Please set FREESCOUT_MAILBOX_ID environment variable.');
        }
        
        $mailboxId = $mailboxes[0]['id'] ?? null;
        if ($mailboxId === null) {
            throw new \RuntimeException('Could not determine mailbox ID from FreeScout API response.');
        }
        
        return (int) $mailboxId;
    }
    
    /**
     * Validate that the configured mailbox ID exists in FreeScout
     * 
     * @return bool True if mailbox exists, false otherwise
     */
    public function validateMailboxId(): bool
    {
        if ($this->mailboxId === null) {
            $this->logger->warning('No mailbox ID configured for validation');
            return false;
        }
        
        try {
            $mailboxes = $this->getMailboxes();
            foreach ($mailboxes as $mailbox) {
                if (isset($mailbox['id']) && (int) $mailbox['id'] === $this->mailboxId) {
                    $this->logger->info('Mailbox ID validated successfully', ['mailbox_id' => $this->mailboxId]);
                    return true;
                }
            }
            
            $this->logger->error('Configured mailbox ID not found in FreeScout', [
                'configured_mailbox_id' => $this->mailboxId,
                'available_mailboxes' => array_column($mailboxes, 'id')
            ]);
            return false;
        } catch (\Exception $e) {
            $this->logger->error('Failed to validate mailbox ID', [
                'mailbox_id' => $this->mailboxId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    private function buildSubject(string $requestType, array $formData): string
    {
        // Prioritize user-provided subject if available
        if (!empty($formData['subject'])) {
            return trim($formData['subject']);
        }
        
        // Get subject templates from mappings
        $subjectTemplates = $this->mappings['subject_templates'] ?? [];
        
        // Get template for this request type or use default
        $template = $subjectTemplates[$requestType] ?? $subjectTemplates['_default'] ?? 'IT Request';
        
        // Replace placeholders with actual form data
        // Format: {field_name} gets replaced with $formData['field_name']
        $subject = preg_replace_callback('/\{([a-z_]+)\}/', function($matches) use ($formData) {
            $fieldName = $matches[1];
            return $formData[$fieldName] ?? $matches[0]; // Keep placeholder if field doesn't exist
        }, $template);
        
        return $subject;
    }
    
    private function buildBody(string $requestType, array $formData): string
    {
        $body = "";
        
        // Get list of fields to exclude from body (from PHP config)
        $excludeFields = $this->mappings['exclude_from_body'] ?? [];
        
        // Build a map of field names to their include_in_body setting from YAML
        $fieldInclusionMap = [];
        if ($this->fieldDefinitions !== null) {
            foreach ($this->fieldDefinitions as $fieldDef) {
                $fieldName = $fieldDef['name'];
                // Default to true if not specified
                $includeInBody = $fieldDef['include_in_body'] ?? true;
                $fieldInclusionMap[$fieldName] = $includeInBody;
            }
        }
        
        foreach ($formData as $key => $value) {
            // Check if field is excluded by PHP config
            if (in_array($key, $excludeFields)) {
                continue;
            }
            
            // Check if field has include_in_body set to false in YAML
            if (isset($fieldInclusionMap[$key]) && $fieldInclusionMap[$key] === false) {
                continue;
            }
            
            if (is_array($value)) {
                $value = implode(', ', $value);
            }
            
            if (!empty($value)) {
                $label = ucfirst(str_replace('_', ' ', $key));
                $body .= "<p><strong>{$label}:</strong> " . htmlspecialchars($value) . "</p>\n";
            }
        }
        
        return $body;
    }
    
    private function buildTags(string $requestType): array
    {
        $tags = [];
        
        // Get tags from PHP mappings (backward compatibility)
        $tagMapping = $this->mappings['tags'] ?? [];
        $phpTags = $tagMapping[$requestType] ?? [];
        
        // Get tags from YAML config (takes priority)
        $yamlTags = [];
        if (isset($this->fieldDefinitions)) {
            // Extract tags from request type configuration
            $configService = new ConfigService(__DIR__ . '/../../config/form_fields.yaml');
            $yamlTags = $configService->getRequestTypeTags($requestType);
        }
        
        // Merge tags: YAML tags first, then PHP tags (remove duplicates)
        $tags = array_unique(array_merge($yamlTags, $phpTags));
        
        return $tags;
    }
    
    public function testConnection(): bool
    {
        try {
            $this->logger->info('Testing FreeScout connection', [
                'url' => $this->apiUrl . '/mailboxes',
                'api_key_length' => strlen($this->apiKey)
            ]);
            
            $response = $this->client->get('mailboxes');
            $statusCode = $response->getStatusCode();
            
            $this->logger->info('FreeScout connection test successful', [
                'status_code' => $statusCode
            ]);
            
            return $statusCode === 200;
        } catch (GuzzleException $e) {
            $this->logger->error('FreeScout connection test failed', [
                'error' => $e->getMessage(),
                'url' => $this->apiUrl . '/mailboxes',
                'class' => get_class($e)
            ]);
            return false;
        }
    }
}
