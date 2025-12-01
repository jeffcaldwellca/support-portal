<?php
declare(strict_types=1);

namespace HelpdeskForm\Tests\Services;

use PHPUnit\Framework\TestCase;
use HelpdeskForm\Services\FreeScoutService;
use Psr\Log\LoggerInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Middleware;

class FreeScoutServiceTest extends TestCase
{
    private LoggerInterface $logger;
    
    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
    }
    
    /**
     * Test that mailbox ID is correctly fetched from configuration
     */
    public function testGetConfiguredMailboxIdWithConfiguredValue(): void
    {
        $mailboxId = 5;
        $service = new FreeScoutService(
            'https://example.com/api',
            'test-api-key',
            $this->logger,
            null,
            null,
            $mailboxId
        );
        
        $result = $service->getConfiguredMailboxId();
        
        $this->assertEquals($mailboxId, $result);
    }
    
    /**
     * Test that mailbox ID validation returns true when mailbox exists
     */
    public function testValidateMailboxIdWithValidMailbox(): void
    {
        // Create a mock handler for API responses
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                '_embedded' => [
                    'mailboxes' => [
                        ['id' => 1, 'name' => 'Support'],
                        ['id' => 5, 'name' => 'Sales'],
                        ['id' => 10, 'name' => 'HR']
                    ]
                ]
            ]))
        ]);
        
        $handlerStack = HandlerStack::create($mock);
        
        $mailboxId = 5;
        $service = $this->createServiceWithMockClient($handlerStack, $mailboxId);
        
        $result = $service->validateMailboxId();
        
        $this->assertTrue($result);
    }
    
    /**
     * Test that mailbox ID validation returns false when mailbox does not exist
     */
    public function testValidateMailboxIdWithInvalidMailbox(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                '_embedded' => [
                    'mailboxes' => [
                        ['id' => 1, 'name' => 'Support'],
                        ['id' => 10, 'name' => 'HR']
                    ]
                ]
            ]))
        ]);
        
        $handlerStack = HandlerStack::create($mock);
        
        $mailboxId = 999; // Non-existent mailbox
        $service = $this->createServiceWithMockClient($handlerStack, $mailboxId);
        
        $result = $service->validateMailboxId();
        
        $this->assertFalse($result);
    }
    
    /**
     * Test that mailbox ID validation returns false when no mailbox ID is configured
     */
    public function testValidateMailboxIdWithNoConfiguration(): void
    {
        $service = new FreeScoutService(
            'https://example.com/api',
            'test-api-key',
            $this->logger,
            null,
            null,
            null // No mailbox ID configured
        );
        
        $result = $service->validateMailboxId();
        
        $this->assertFalse($result);
    }
    
    /**
     * Test that buildTicketData includes the correct mailbox ID from configuration
     */
    public function testBuildTicketDataUsesConfiguredMailboxId(): void
    {
        $configuredMailboxId = 7;
        
        // Mock API responses for customer lookup and custom fields
        $mock = new MockHandler([
            // getCustomer response - customer not found
            new Response(200, [], json_encode([
                '_embedded' => ['customers' => []]
            ])),
            // createCustomer response
            new Response(201, [], json_encode([
                'id' => 1,
                'firstName' => 'Test',
                'lastName' => 'User',
                'email' => 'test@example.com'
            ])),
            // getMailboxCustomFields response
            new Response(200, [], json_encode([
                '_embedded' => ['custom_fields' => []]
            ]))
        ]);
        
        $handlerStack = HandlerStack::create($mock);
        $service = $this->createServiceWithMockClient($handlerStack, $configuredMailboxId);
        
        $formData = [
            'requester_name' => 'Test User',
            'requester_email' => 'test@example.com',
            'subject' => 'Test Subject',
            'description' => 'Test Description'
        ];
        
        $ticketData = $service->buildTicketData($formData, 'problem');
        
        $this->assertEquals($configuredMailboxId, $ticketData['mailboxId']);
    }
    
    /**
     * Test that buildTicketData falls back to API when mailbox ID is not configured
     */
    public function testBuildTicketDataFallsBackToApiWhenNoMailboxIdConfigured(): void
    {
        $apiMailboxId = 3;
        
        // Mock API responses
        $mock = new MockHandler([
            // getCustomer response - customer not found
            new Response(200, [], json_encode([
                '_embedded' => ['customers' => []]
            ])),
            // createCustomer response
            new Response(201, [], json_encode([
                'id' => 1,
                'firstName' => 'Test',
                'lastName' => 'User',
                'email' => 'test@example.com'
            ])),
            // getMailboxes response for fallback
            new Response(200, [], json_encode([
                '_embedded' => [
                    'mailboxes' => [
                        ['id' => $apiMailboxId, 'name' => 'Support']
                    ]
                ]
            ])),
            // getMailboxCustomFields response
            new Response(200, [], json_encode([
                '_embedded' => ['custom_fields' => []]
            ]))
        ]);
        
        $handlerStack = HandlerStack::create($mock);
        $service = $this->createServiceWithMockClient($handlerStack, null); // No mailbox ID configured
        
        $formData = [
            'requester_name' => 'Test User',
            'requester_email' => 'test@example.com',
            'subject' => 'Test Subject',
            'description' => 'Test Description'
        ];
        
        $ticketData = $service->buildTicketData($formData, 'problem');
        
        $this->assertEquals($apiMailboxId, $ticketData['mailboxId']);
    }
    
    /**
     * Test that exception is thrown when no mailbox ID configured and API returns empty
     */
    public function testGetConfiguredMailboxIdThrowsExceptionWhenNoMailboxAvailable(): void
    {
        // Mock empty mailboxes response
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                '_embedded' => ['mailboxes' => []]
            ]))
        ]);
        
        $handlerStack = HandlerStack::create($mock);
        $service = $this->createServiceWithMockClient($handlerStack, null);
        
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No mailbox ID configured and no mailboxes found in FreeScout. Please set FREESCOUT_MAILBOX_ID environment variable.');
        
        $service->getConfiguredMailboxId();
    }
    
    /**
     * Helper method to create a FreeScoutService with a mocked HTTP client
     */
    private function createServiceWithMockClient(HandlerStack $handlerStack, ?int $mailboxId): FreeScoutService
    {
        $service = new FreeScoutService(
            'https://example.com/api',
            'test-api-key',
            $this->logger,
            [
                'custom_fields' => [],
                'request_types' => [],
                'tags' => [],
                'subject_templates' => ['_default' => 'IT Request'],
                'exclude_from_body' => ['requester_name', 'requester_email', 'csrf_token']
            ],
            null,
            $mailboxId
        );
        
        // Use reflection to replace the HTTP client with our mock
        $reflection = new \ReflectionClass($service);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($service, new Client([
            'handler' => $handlerStack,
            'base_uri' => 'https://example.com/api/',
            'headers' => [
                'X-FreeScout-API-Key' => 'test-api-key',
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ]
        ]));
        
        return $service;
    }
}
