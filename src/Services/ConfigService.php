<?php
declare(strict_types=1);

namespace HelpdeskForm\Services;

use Symfony\Component\Yaml\Yaml;

class ConfigService
{
    private array $config;
    
    public function __construct(string $configPath)
    {
        if (!file_exists($configPath)) {
            throw new \RuntimeException("Configuration file not found: {$configPath}");
        }
        
        $this->config = Yaml::parseFile($configPath);
    }
    
    public function getFormFields(string $requestType): array
    {
        $common = $this->config['form_fields']['common'] ?? [];
        $specific = $this->config['form_fields']['request_types'][$requestType]['fields'] ?? [];
        
        return array_merge($common, $specific);
    }
    
    public function getRequestTypes(): array
    {
        return array_keys($this->config['form_fields']['request_types'] ?? []);
    }
    
    public function getRequestTypeInfo(string $requestType): array
    {
        return $this->config['form_fields']['request_types'][$requestType] ?? [];
    }
    
    public function getValidationRules(): array
    {
        return $this->config['settings']['validation_rules'] ?? [];
    }
    
    public function getSettings(): array
    {
        return $this->config['settings'] ?? [];
    }
    
    public function getAllowedFileTypes(): array
    {
        return $this->config['settings']['allowed_file_types'] ?? [];
    }
    
    public function getMaxFileSize(): string
    {
        return $this->config['settings']['max_file_size'] ?? '10MB';
    }
    
    public function getAutosaveInterval(): int
    {
        return $this->config['settings']['autosave_interval'] ?? 30;
    }
    
    /**
     * Get all field definitions across all request types
     * Used for extracting FreeScout field mappings
     * 
     * @return array All field definitions with their configuration
     */
    public function getAllFieldDefinitions(): array
    {
        $allFields = [];
        
        // Get common fields
        $commonFields = $this->config['form_fields']['common'] ?? [];
        foreach ($commonFields as $field) {
            if (isset($field['name'])) {
                $allFields[] = $field;
            }
        }
        
        // Get request type specific fields
        $requestTypes = $this->config['form_fields']['request_types'] ?? [];
        foreach ($requestTypes as $requestType => $config) {
            $fields = $config['fields'] ?? [];
            foreach ($fields as $field) {
                if (isset($field['name'])) {
                    // Check if field already exists (from common)
                    $exists = false;
                    foreach ($allFields as $existingField) {
                        if ($existingField['name'] === $field['name']) {
                            $exists = true;
                            break;
                        }
                    }
                    
                    if (!$exists) {
                        $allFields[] = $field;
                    }
                }
            }
        }
        
        return $allFields;
    }
    
    public function getRequestTypeTags(string $requestType): array
    {
        $typeConfig = $this->config['form_fields']['request_types'][$requestType] ?? [];
        return $typeConfig['freescout_tags'] ?? [];
    }
}
