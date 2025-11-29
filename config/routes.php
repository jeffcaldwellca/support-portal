<?php
declare(strict_types=1);

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use HelpdeskForm\Controllers\FormController;
use HelpdeskForm\Controllers\AuthController;
use HelpdeskForm\Controllers\ApiController;
use HelpdeskForm\Middleware\AuthMiddleware;

return function (App $app) {
    
    // Authentication routes (no auth middleware)
    $app->get('/auth/login', [AuthController::class, 'showLogin'])->setName('auth.login');
    $app->post('/auth/login', [AuthController::class, 'processLogin'])->setName('auth.process');
    $app->get('/auth/register', [AuthController::class, 'showRegister'])->setName('auth.register');
    $app->post('/auth/register', [AuthController::class, 'processRegister'])->setName('auth.register.process');
    $app->post('/auth/logout', [AuthController::class, 'logout'])->setName('auth.logout');
    $app->get('/auth/logout', [AuthController::class, 'logout'])->setName('auth.logout.get');
    
    // Main routes (with auth middleware)
    $app->get('/', [FormController::class, 'index'])->setName('home')->add(AuthMiddleware::class);
    // Success route MUST be before /form/{type} to avoid conflicts
    $app->get('/form/success/{uuid}', [FormController::class, 'showSuccess'])->setName('form.success')->add(AuthMiddleware::class);
    $app->get('/form/{type}', [FormController::class, 'showForm'])->setName('form.show')->add(AuthMiddleware::class);
    $app->post('/form/{type}', [FormController::class, 'submitForm'])->setName('form.submit')->add(AuthMiddleware::class);
    
    // API routes (with auth middleware)
    $app->get('/api/fields/{type}', [ApiController::class, 'getFormFields'])->setName('api.fields')->add(AuthMiddleware::class);
    $app->post('/api/upload', [FormController::class, 'uploadFile'])->setName('api.upload')->add(AuthMiddleware::class);
    $app->post('/api/autosave', [FormController::class, 'autosave'])->setName('api.autosave')->add(AuthMiddleware::class);
    $app->get('/api/autosave/{type}', [FormController::class, 'getAutosave'])->setName('api.autosave.get')->add(AuthMiddleware::class);
    $app->post('/api/validate', [ApiController::class, 'validateForm'])->setName('api.validate')->add(AuthMiddleware::class);
    
    // Ticket status (with auth middleware)
    $app->get('/ticket/{id}', [FormController::class, 'showTicket'])->setName('ticket.show')->add(AuthMiddleware::class);
    $app->post('/ticket/{id}/reply', [FormController::class, 'addReply'])->setName('ticket.reply')->add(AuthMiddleware::class);
    
    // Health check (no auth required)
    $app->get('/health', function ($request, $response) {
        $data = [
            'status' => 'ok',
            'timestamp' => time(),
            'version' => '1.0.0'
        ];
        
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    })->setName('health');
    
    // Static assets (CSS, JS, Images)
    $app->get('/assets/{path:.*}', function ($request, $response, $args) {
        $path = $args['path'];
        $filePath = __DIR__ . '/../public/assets/' . $path;
        
        if (!file_exists($filePath) || !is_file($filePath)) {
            return $response->withStatus(404);
        }
        
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $filePath);
        finfo_close($finfo);
        
        $response->getBody()->write(file_get_contents($filePath));
        return $response->withHeader('Content-Type', $mimeType);
    })->setName('assets');
};
