<?php
declare(strict_types=1);

namespace HelpdeskForm\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use HelpdeskForm\Services\ConfigService;
use HelpdeskForm\Services\DatabaseService;
use HelpdeskForm\Services\FreeScoutService;

class ApiController
{
    private ConfigService $configService;
    private DatabaseService $databaseService;
    private FreeScoutService $freeScoutService;
    private LoggerInterface $logger;
    
    public function __construct(
        ConfigService $configService,
        DatabaseService $databaseService,
        FreeScoutService $freeScoutService,
        LoggerInterface $logger
    ) {
        $this->configService = $configService;
        $this->databaseService = $databaseService;
        $this->freeScoutService = $freeScoutService;
        $this->logger = $logger;
    }
    
    public function getFormFields(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $type = $args['type'];
        
        try {
            // Validate request type
            if (!in_array($type, $this->configService->getRequestTypes())) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'error' => 'Invalid request type'
                ], 400);
            }
            
            $formFields = $this->configService->getFormFields($type);
            $typeInfo = $this->configService->getRequestTypeInfo($type);
            $settings = $this->configService->getSettings();
            
            return $this->jsonResponse($response, [
                'success' => true,
                'data' => [
                    'fields' => $formFields,
                    'type_info' => $typeInfo,
                    'settings' => $settings
                ]
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to get form fields', [
                'type' => $type,
                'error' => $e->getMessage()
            ]);
            
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => 'Failed to load form configuration'
            ], 500);
        }
    }
    
    public function validateForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = $request->getParsedBody();
        $type = $data['request_type'] ?? '';
        $formData = $data['form_data'] ?? [];
        
        try {
            if (!in_array($type, $this->configService->getRequestTypes())) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'error' => 'Invalid request type'
                ], 400);
            }
            
            $errors = $this->validateFormData($type, $formData);
            
            return $this->jsonResponse($response, [
                'success' => empty($errors),
                'errors' => $errors
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Form validation failed', [
                'type' => $type,
                'error' => $e->getMessage()
            ]);
            
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => 'Validation failed'
            ], 500);
        }
    }
    
    private function validateFormData(string $type, array $formData): array
    {
        $errors = [];
        $formFields = $this->configService->getFormFields($type);
        
        foreach ($formFields as $field) {
            $fieldName = $field['name'];
            $isRequired = $field['required'] ?? false;
            $value = $formData[$fieldName] ?? null;
            
            // Check required fields
            if ($isRequired && (empty($value) && $value !== '0')) {
                $errors[$fieldName] = "'{$field['label']}' is required";
                continue;
            }
            
            // Validate field value if not empty
            if (!empty($value)) {
                $fieldError = $this->validateFieldValue($field, $value);
                if ($fieldError) {
                    $errors[$fieldName] = $fieldError;
                }
            }
        }
        
        return $errors;
    }
    
    private function validateFieldValue(array $field, $value): ?string
    {
        $type = $field['type'];
        $label = $field['label'];
        
        switch ($type) {
            case 'email':
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    return "Invalid email format for '{$label}'";
                }
                break;
                
            case 'date':
                if (!strtotime($value)) {
                    return "Invalid date format for '{$label}'";
                }
                break;
                
            case 'text':
            case 'textarea':
                if (isset($field['validation'])) {
                    $rules = explode('|', $field['validation']);
                    foreach ($rules as $rule) {
                        if (strpos($rule, 'max:') === 0) {
                            $max = (int)substr($rule, 4);
                            if (strlen($value) > $max) {
                                return "'{$label}' exceeds maximum length of {$max} characters";
                            }
                        }
                        
                        if (strpos($rule, 'min:') === 0) {
                            $min = (int)substr($rule, 4);
                            if (strlen($value) < $min) {
                                return "'{$label}' must be at least {$min} characters";
                            }
                        }
                    }
                }
                break;
                
            case 'select':
                if (isset($field['options'])) {
                    $validOptions = array_map(function($option) {
                        return is_array($option) ? $option['value'] : $option;
                    }, $field['options']);
                    
                    if (!in_array($value, $validOptions)) {
                        return "Invalid option selected for '{$label}'";
                    }
                }
                break;
                
            case 'checkbox_group':
                if (is_array($value) && isset($field['options'])) {
                    $validOptions = $field['options'];
                    foreach ($value as $selectedValue) {
                        if (!in_array($selectedValue, $validOptions)) {
                            return "Invalid option selected for '{$label}'";
                        }
                    }
                }
                break;
        }
        
        return null;
    }
    
    private function jsonResponse(ResponseInterface $response, array $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_PRETTY_PRINT));
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }
}
