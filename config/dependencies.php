<?php
declare(strict_types=1);

use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
use HelpdeskForm\Services\ConfigService;
use HelpdeskForm\Services\DatabaseService;
use HelpdeskForm\Services\LdapService;
use HelpdeskForm\Services\LocalAuthService;
use HelpdeskForm\Services\FreeScoutService;
use HelpdeskForm\Services\FileUploadService;
use HelpdeskForm\Controllers\FormController;
use HelpdeskForm\Controllers\AuthController;
use HelpdeskForm\Controllers\ApiController;

return function (ContainerBuilder $containerBuilder) {
    $containerBuilder->addDefinitions([
        
        // Logger
        Logger::class => function (ContainerInterface $c) {
            $logger = new Logger('helpdesk-form');
            
            // Add file handler
            $fileHandler = new RotatingFileHandler(
                __DIR__ . '/../logs/app.log',
                0,
                $_ENV['APP_LOG_LEVEL'] ?? 'info'
            );
            $logger->pushHandler($fileHandler);
            
            // Add console handler in debug mode
            if ($_ENV['APP_DEBUG'] === 'true') {
                $streamHandler = new StreamHandler('php://stdout', Logger::DEBUG);
                $logger->pushHandler($streamHandler);
            }
            
            return $logger;
        },
        
        // Twig Template Engine
        Twig::class => function (ContainerInterface $c) {
            $twig = Twig::create(__DIR__ . '/../templates', [
                'cache' => $_ENV['APP_DEBUG'] === 'true' ? false : __DIR__ . '/../tmp/cache',
                'debug' => $_ENV['APP_DEBUG'] === 'true'
            ]);
            
            // Add global branding variables
            $environment = $twig->getEnvironment();
            $environment->addGlobal('branding', [
                'company_name' => $_ENV['COMPANY_NAME'] ?? 'Your Company Inc.',
                'company_short_name' => $_ENV['COMPANY_SHORT_NAME'] ?? 'Your Company',
                'brand_icon' => $_ENV['BRAND_ICON'] ?? 'bi-headset',
                'use_logo' => filter_var($_ENV['USE_LOGO'] ?? 'true', FILTER_VALIDATE_BOOLEAN),
                'support_email' => $_ENV['SUPPORT_EMAIL'] ?? 'support@yourcompany.com',
                'support_phone' => $_ENV['SUPPORT_PHONE'] ?? '+1 (555) 123-4567',
                'it_contact_email' => $_ENV['IT_CONTACT_EMAIL'] ?? 'it@yourcompany.com',
                'self_service_url' => $_ENV['SELF_SERVICE_URL'] ?? '',
                'portal_title' => $_ENV['PORTAL_TITLE'] ?? 'Support Portal',
                'portal_name' => $_ENV['PORTAL_NAME'] ?? 'Support Portal',
                'app_version' => $_ENV['APP_VERSION'] ?? '1.0.0'
            ]);
            
            return $twig;
        },
        
        // Configuration Service
        ConfigService::class => function (ContainerInterface $c) {
            return new ConfigService(__DIR__ . '/../config/form_fields.yaml');
        },
        
        // Database Service
        DatabaseService::class => function (ContainerInterface $c) {
            return new DatabaseService($_ENV['DB_PATH'] ?? './data/helpdesk.db');
        },
        
        // LDAP Service
        LdapService::class => function (ContainerInterface $c) {
            return new LdapService([
                'host' => $_ENV['LDAP_HOST'],
                'port' => (int)($_ENV['LDAP_PORT'] ?? 389),
                'base_dn' => $_ENV['LDAP_BASE_DN'],
                // Service account credentials for LDAP binding (NOT user credentials)
                'bind_dn' => $_ENV['LDAP_BIND_DN'] ?? null,
                'bind_password' => $_ENV['LDAP_BIND_PASSWORD'] ?? null,
                'user_filter' => $_ENV['LDAP_USER_FILTER'] ?? '(uid=%s)',
                'user_attributes' => explode(',', $_ENV['LDAP_USER_ATTRIBUTES'] ?? 'uid,cn,mail,displayName')
            ]);
        },
        
        // Local Authentication Service
        LocalAuthService::class => function (ContainerInterface $c) {
            return new LocalAuthService($c->get(DatabaseService::class));
        },
        
        // FreeScout API Service
        FreeScoutService::class => function (ContainerInterface $c) {
            // Load FreeScout field mappings configuration
            $mappingsFile = __DIR__ . '/freescout_mappings.php';
            $mappings = file_exists($mappingsFile) ? require $mappingsFile : null;
            
            // Get field definitions from ConfigService to extract FreeScout mappings
            $configService = $c->get(ConfigService::class);
            $fieldDefinitions = $configService->getAllFieldDefinitions();
            
            // Get mailbox ID from environment variable (optional, will fall back to API lookup)
            $mailboxId = null;
            if (!empty($_ENV['FREESCOUT_MAILBOX_ID'])) {
                $mailboxId = (int) $_ENV['FREESCOUT_MAILBOX_ID'];
                if ($mailboxId <= 0) {
                    throw new \InvalidArgumentException('FREESCOUT_MAILBOX_ID must be a positive integer');
                }
            }
            
            return new FreeScoutService(
                $_ENV['FREESCOUT_API_URL'],
                $_ENV['FREESCOUT_API_KEY'],
                $c->get(Logger::class),
                $mappings,
                $fieldDefinitions,
                $mailboxId
            );
        },
        
        // File Upload Service
        FileUploadService::class => function (ContainerInterface $c) {
            return new FileUploadService(
                __DIR__ . '/../uploads',
                (int)($_ENV['UPLOAD_MAX_SIZE'] ?? 10485760),
                explode(',', $_ENV['UPLOAD_ALLOWED_TYPES'] ?? 'pdf,doc,docx,txt,png,jpg,jpeg,gif')
            );
        },
        
        // Controllers
        FormController::class => function (ContainerInterface $c) {
            return new FormController(
                $c->get(Twig::class),
                $c->get(ConfigService::class),
                $c->get(DatabaseService::class),
                $c->get(FreeScoutService::class),
                $c->get(FileUploadService::class),
                $c->get(Logger::class)
            );
        },
        
        AuthController::class => function (ContainerInterface $c) {
            return new AuthController(
                $c->get(Twig::class),
                $c->get(LdapService::class),
                $c->get(LocalAuthService::class),
                $c->get(DatabaseService::class),
                $c->get(Logger::class)
            );
        },
        
        ApiController::class => function (ContainerInterface $c) {
            return new ApiController(
                $c->get(ConfigService::class),
                $c->get(DatabaseService::class),
                $c->get(FreeScoutService::class),
                $c->get(Logger::class)
            );
        }
    ]);
};
