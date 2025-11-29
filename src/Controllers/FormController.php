<?php
declare(strict_types=1);

namespace HelpdeskForm\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use Psr\Log\LoggerInterface;
use HelpdeskForm\Services\ConfigService;
use HelpdeskForm\Services\DatabaseService;
use HelpdeskForm\Services\FreeScoutService;
use HelpdeskForm\Services\FileUploadService;

class FormController
{
    private Twig $twig;
    private ConfigService $configService;
    private DatabaseService $databaseService;
    private FreeScoutService $freeScoutService;
    private FileUploadService $fileUploadService;
    private LoggerInterface $logger;
    
    public function __construct(
        Twig $twig,
        ConfigService $configService,
        DatabaseService $databaseService,
        FreeScoutService $freeScoutService,
        FileUploadService $fileUploadService,
        LoggerInterface $logger
    ) {
        $this->twig = $twig;
        $this->configService = $configService;
        $this->databaseService = $databaseService;
        $this->freeScoutService = $freeScoutService;
        $this->fileUploadService = $fileUploadService;
        $this->logger = $logger;
    }
    
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $request->getAttribute('user');
        $requestTypes = $this->configService->getRequestTypes();
        
        $typesInfo = [];
        foreach ($requestTypes as $type) {
            $typesInfo[$type] = $this->configService->getRequestTypeInfo($type);
        }
        
        // Get user's recent tickets
        $userTickets = $this->freeScoutService->getCustomerConversations($user['email']);
        
        return $this->twig->render($response, 'form/index.html', [
            'user' => $user,
            'request_types' => $typesInfo,
            'user_tickets' => $userTickets
        ]);
    }
    
    public function showForm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $type = $args['type'];
        $user = $request->getAttribute('user');
        $sessionId = $request->getAttribute('session_id');
        
        // Validate request type
        if (!in_array($type, $this->configService->getRequestTypes())) {
            return $response
                ->withStatus(404)
                ->withHeader('Content-Type', 'text/plain')
                ->write('Request type not found');
        }
        
        // Get form configuration
        $formFields = $this->configService->getFormFields($type);
        $typeInfo = $this->configService->getRequestTypeInfo($type);
        $settings = $this->configService->getSettings();
        
        // Load autosaved data if available
        $autosavedData = $this->databaseService->getAutosaveData($sessionId, $type);
        
        return $this->twig->render($response, 'form/form.html', [
            'user' => $user,
            'request_type' => $type,
            'type_info' => $typeInfo,
            'form_fields' => $formFields,
            'settings' => $settings,
            'autosaved_data' => $autosavedData,
            'csrf_token' => $this->generateCsrfToken($request),
            'auth_disabled' => $_ENV['DISABLE_AUTH'] === 'true'
        ]);
    }
    
    public function submitForm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $type = $args['type'];
        $user = $request->getAttribute('user');
        $sessionId = $request->getAttribute('session_id');
        $formData = $request->getParsedBody();
        
        try {
            // Check for duplicate submission (rate limiting)
            if (isset($_SESSION['last_submission_time'])) {
                $timeSinceLastSubmission = time() - $_SESSION['last_submission_time'];
                if ($timeSinceLastSubmission < 5) { // 5 second cooldown
                    throw new \RuntimeException('Please wait a moment before submitting another request.');
                }
            }
            
            // Validate form data
            $this->validateFormData($type, $formData);
            
            // Prepare submission data
            $submissionData = [
                'request_type' => $type,
                'requester_email' => $user['email'],
                'requester_name' => $user['name'],
                'form_data' => $formData
            ];
            
            // Log submission to database
            $submissionUuid = $this->databaseService->logSubmission($submissionData);
            
            // Handle file uploads
            $uploadedFiles = [];
            $files = $request->getUploadedFiles();
            if (!empty($files)) {
                foreach ($files as $fieldName => $fileArray) {
                    if (is_array($fileArray)) {
                        $uploadedFiles = array_merge(
                            $uploadedFiles,
                            $this->fileUploadService->uploadMultipleFiles($fileArray, $submissionUuid)
                        );
                    } else {
                        if ($fileArray->getError() === UPLOAD_ERR_OK) {
                            $uploadedFiles[] = $this->fileUploadService->uploadFile($fileArray, $submissionUuid);
                        }
                    }
                }
                
                // Log file uploads
                foreach ($uploadedFiles as $fileInfo) {
                    $this->databaseService->logFileUpload($submissionUuid, $fileInfo);
                }
            }
            
            // Create ticket in FreeScout
            $ticketData = $this->freeScoutService->buildTicketData($formData, $type);
            
            // Prepare inline attachments for FreeScout if any
            if (!empty($uploadedFiles)) {
                $attachments = [];
                foreach ($uploadedFiles as $fileInfo) {
                    $attachment = $this->freeScoutService->prepareInlineAttachment(
                        $fileInfo['file_path'],
                        $fileInfo['original_filename']
                    );
                    if ($attachment) {
                        $attachments[] = $attachment;
                    }
                }
                
                if (!empty($attachments)) {
                    $ticketData['threads'][0]['attachments'] = $attachments;
                }
            }
            
            $ticketResponse = $this->freeScoutService->createConversation($ticketData);
            
            // FreeScout API can return different response structures
            // Check for ID in various possible locations
            $ticketId = $ticketResponse['id'] ?? $ticketResponse['_embedded']['conversation']['id'] ?? null;
            
            if ($ticketId) {
                // Update submission with ticket ID
                $this->databaseService->updateSubmissionStatus(
                    $submissionUuid,
                    'completed',
                    $ticketId
                );
                
                // Clear autosaved data
                $this->databaseService->saveAutosaveData($sessionId, $type, [], 0);
                
                $this->logger->info('Form submitted successfully', [
                    'submission_uuid' => $submissionUuid,
                    'ticket_id' => $ticketId,
                    'user_email' => $user['email']
                ]);
                
                // Store submission UUID and timestamp in session to prevent duplicates
                $_SESSION['last_submission'] = $submissionUuid;
                $_SESSION['last_submission_time'] = time();
                
                // Return JSON response for AJAX submission
                $payload = json_encode([
                    'success' => true,
                    'ticket_id' => $ticketId,
                    'submission_uuid' => $submissionUuid,
                    'redirect_url' => '/form/success/' . $submissionUuid
                ]);
                
                $response->getBody()->write($payload);
                return $response
                    ->withHeader('Content-Type', 'application/json')
                    ->withStatus(200);
            } else {
                // Log the full response for debugging
                $this->logger->error('Ticket created but no ID found in response', [
                    'response' => $ticketResponse
                ]);
                throw new \RuntimeException('Ticket not found');
            }
            
        } catch (\Exception $e) {
            $this->logger->error('Form submission failed', [
                'error' => $e->getMessage(),
                'user_email' => $user['email'],
                'request_type' => $type
            ]);
            
            // Update submission status
            if (isset($submissionUuid)) {
                $this->databaseService->updateSubmissionStatus($submissionUuid, 'failed');
            }
            
            // Return JSON error for AJAX submission
            $payload = json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
            
            $response->getBody()->write($payload);
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(500);
        }
    }
    
    public function showSuccess(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $submissionUuid = $args['uuid'];
        $user = $request->getAttribute('user');
        
        try {
            // Get submission from database
            $submission = $this->databaseService->getSubmission($submissionUuid);
            
            if (!$submission) {
                throw new \RuntimeException('Submission not found');
            }
            
            // Verify user owns this submission
            if (strcasecmp($submission['requester_email'], $user['email']) !== 0) {
                return $response
                    ->withStatus(403)
                    ->withHeader('Content-Type', 'text/plain')
                    ->write('Access denied');
            }
            
            // Get ticket from FreeScout
            $ticket = null;
            if ($submission['ticket_id']) {
                $ticket = $this->freeScoutService->getConversation($submission['ticket_id']);
            }
            
            return $this->twig->render($response, 'form/success.html', [
                'ticket' => $ticket,
                'submission' => $submission,
                'user' => $user
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to show success page', [
                'error' => $e->getMessage(),
                'submission_uuid' => $submissionUuid
            ]);
            
            return $this->twig->render($response->withStatus(500), 'form/error.html', [
                'error' => 'Unable to load submission details',
                'user' => $user
            ]);
        }
    }
    
    public function showTicket(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $ticketId = (int)$args['id'];
        $user = $request->getAttribute('user');
        
        try {
            // Get ticket from FreeScout with threads
            $ticket = $this->freeScoutService->getConversation($ticketId);
            
            // Verify user owns this ticket
            $ticketCustomerEmail = $ticket['customer']['email'] ?? '';
            if (strcasecmp($ticketCustomerEmail, $user['email']) !== 0) {
                $this->logger->warning('User attempted to access ticket they do not own', [
                    'user_email' => $user['email'],
                    'ticket_id' => $ticketId,
                    'ticket_customer' => $ticketCustomerEmail
                ]);
                
                return $this->twig->render($response->withStatus(403), 'form/error.html', [
                    'error' => 'You do not have permission to view this ticket',
                    'user' => $user
                ]);
            }
            
            // Get submission data if available (from our database)
            $submission = $this->databaseService->getSubmissionByTicketId($ticketId);
            
            return $this->twig->render($response, 'form/ticket.html', [
                'user' => $user,
                'ticket' => $ticket,
                'submission' => $submission,
                'csrf_token' => $this->generateCsrfToken($request)
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to load ticket', [
                'ticket_id' => $ticketId,
                'error' => $e->getMessage()
            ]);
            
            return $this->twig->render($response->withStatus(404), 'form/error.html', [
                'error' => 'Ticket not found',
                'user' => $user
            ]);
        }
    }
    
    public function addReply(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $ticketId = (int)$args['id'];
        $user = $request->getAttribute('user');
        
        try {
            // Get ticket to verify ownership and status
            $ticket = $this->freeScoutService->getConversation($ticketId);
            
            // Verify user owns this ticket
            $ticketCustomerEmail = $ticket['customer']['email'] ?? '';
            if (strcasecmp($ticketCustomerEmail, $user['email']) !== 0) {
                $this->logger->warning('User attempted to reply to ticket they do not own', [
                    'user_email' => $user['email'],
                    'ticket_id' => $ticketId
                ]);
                
                return $this->twig->render($response->withStatus(403), 'form/error.html', [
                    'error' => 'You do not have permission to reply to this ticket',
                    'user' => $user
                ]);
            }
            
            // Check if ticket is active
            if ($ticket['status'] !== 'active') {
                return $this->twig->render($response->withStatus(400), 'form/error.html', [
                    'error' => 'Cannot reply to a closed ticket',
                    'user' => $user
                ]);
            }
            
            $parsedBody = $request->getParsedBody();
            $message = trim($parsedBody['message'] ?? '');
            
            if (empty($message)) {
                return $this->twig->render($response->withStatus(400), 'form/error.html', [
                    'error' => 'Message cannot be empty',
                    'user' => $user
                ]);
            }
            
            // Handle file uploads
            $uploadedFiles = [];
            $files = $request->getUploadedFiles()['attachments'] ?? [];
            
            if (!empty($files)) {
                foreach ($files as $uploadedFile) {
                    if ($uploadedFile->getError() === UPLOAD_ERR_OK) {
                        $filename = $uploadedFile->getClientFilename();
                        $fileSize = $uploadedFile->getSize();
                        
                        // Validate file size (10MB max)
                        if ($fileSize > 10 * 1024 * 1024) {
                            $this->logger->warning('File too large in reply', [
                                'filename' => $filename,
                                'size' => $fileSize
                            ]);
                            continue;
                        }
                        
                        // Generate unique filename
                        $extension = pathinfo($filename, PATHINFO_EXTENSION);
                        $safeFilename = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
                        $uploadPath = __DIR__ . '/../../uploads/' . $safeFilename;
                        
                        $uploadedFile->moveTo($uploadPath);
                        
                        $uploadedFiles[] = [
                            'file_path' => $uploadPath,
                            'original_filename' => $filename,
                            'size' => $fileSize
                        ];
                    }
                }
            }
            
            // Build thread data for FreeScout
            $threadData = [
                'type' => 'customer',
                'text' => nl2br(htmlspecialchars($message)),
                'customer' => [
                    'email' => $user['email']
                ]
            ];
            
            // Add inline attachments if any
            if (!empty($uploadedFiles)) {
                $attachments = [];
                foreach ($uploadedFiles as $fileInfo) {
                    $attachment = $this->freeScoutService->prepareInlineAttachment(
                        $fileInfo['file_path'],
                        $fileInfo['original_filename']
                    );
                    if ($attachment) {
                        $attachments[] = $attachment;
                    }
                }
                
                if (!empty($attachments)) {
                    $threadData['attachments'] = $attachments;
                }
            }
            
            // Add thread to conversation
            $this->freeScoutService->addThread($ticketId, $threadData);
            
            $this->logger->info('Customer reply added to ticket', [
                'ticket_id' => $ticketId,
                'user_email' => $user['email'],
                'has_attachments' => !empty($uploadedFiles)
            ]);
            
            // Redirect back to ticket page
            return $response
                ->withStatus(302)
                ->withHeader('Location', "/ticket/{$ticketId}?reply=success");
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to add reply to ticket', [
                'ticket_id' => $ticketId,
                'error' => $e->getMessage()
            ]);
            
            return $this->twig->render($response->withStatus(500), 'form/error.html', [
                'error' => 'Failed to send reply: ' . $e->getMessage(),
                'user' => $user
            ]);
        }
    }
    
    public function uploadFile(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $request->getAttribute('user');
        $files = $request->getUploadedFiles();
        
        try {
            $uploadedFiles = [];
            
            foreach ($files as $file) {
                if ($file->getError() === UPLOAD_ERR_OK) {
                    // Use temporary UUID for uploads before form submission
                    $tempUuid = 'temp_' . uniqid();
                    $fileInfo = $this->fileUploadService->uploadFile($file, $tempUuid);
                    $uploadedFiles[] = $fileInfo;
                }
            }
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'files' => $uploadedFiles
            ]));
            
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $this->logger->error('File upload failed', [
                'error' => $e->getMessage(),
                'user_email' => $user['email']
            ]);
            
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]));
            
            return $response
                ->withStatus(400)
                ->withHeader('Content-Type', 'application/json');
        }
    }
    
    public function autosave(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $sessionId = $request->getAttribute('session_id');
        $data = $request->getParsedBody();
        $requestType = $data['request_type'] ?? '';
        $formData = $data['form_data'] ?? [];
        
        try {
            $this->databaseService->saveAutosaveData($sessionId, $requestType, $formData);
            
            $response->getBody()->write(json_encode(['success' => true]));
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]));
            
            return $response
                ->withStatus(500)
                ->withHeader('Content-Type', 'application/json');
        }
    }
    
    public function getAutosave(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $sessionId = $request->getAttribute('session_id');
        $requestType = $args['type'];
        
        $data = $this->databaseService->getAutosaveData($sessionId, $requestType);
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'data' => $data
        ]));
        
        return $response->withHeader('Content-Type', 'application/json');
    }
    
    private function validateFormData(string $type, array $formData): void
    {
        $formFields = $this->configService->getFormFields($type);
        
        foreach ($formFields as $field) {
            $fieldName = $field['name'];
            $isRequired = $field['required'] ?? false;
            $value = $formData[$fieldName] ?? null;
            
            // Check required fields
            if ($isRequired && (empty($value) && $value !== '0')) {
                throw new \RuntimeException("Field '{$field['label']}' is required");
            }
            
            // Validate based on field type
            if (!empty($value)) {
                $this->validateFieldValue($field, $value);
            }
        }
    }
    
    private function validateFieldValue(array $field, $value): void
    {
        $type = $field['type'];
        
        switch ($type) {
            case 'email':
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    throw new \RuntimeException("Invalid email format for '{$field['label']}'");
                }
                break;
                
            case 'date':
                if (!strtotime($value)) {
                    throw new \RuntimeException("Invalid date format for '{$field['label']}'");
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
                                throw new \RuntimeException("'{$field['label']}' exceeds maximum length of {$max} characters");
                            }
                        }
                    }
                }
                break;
        }
    }
    
    private function generateCsrfToken(ServerRequestInterface $request): string
    {
        // Try to get session ID from request attribute (set by AuthMiddleware)
        $sessionId = $request->getAttribute('session_id');
        
        // Fallback to cookie if attribute not set
        if (!$sessionId) {
            $cookies = $request->getCookieParams();
            $sessionId = $cookies['helpdesk_session'] ?? null;
        }
        
        // Fallback for development mode
        if (!$sessionId && $_ENV['DISABLE_AUTH'] === 'true') {
            $sessionId = 'dev-session-' . date('Y-m-d');
        }
        
        // Final fallback
        if (!$sessionId) {
            $sessionId = 'default';
        }
        
        return hash_hmac('sha256', $sessionId, $_ENV['CSRF_SECRET'] ?? 'default_secret');
    }
}
