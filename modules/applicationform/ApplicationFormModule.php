<?php
/**
 * ApplicationFormModule - Standalone module (no Craft dependency)
 * 
 * Place at: /modules/applicationform/ApplicationFormModule.php
 * 
 * Routes /actions/* requests directly to controllers.
 * Everything else passes through to Craft CMS untouched.
 */
namespace modules\applicationform;

class ApplicationFormModule
{
    private static ?self $instance = null;
    private array $routes = [];

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init(): void
    {
        $this->registerRoutes();
        $this->handleRequest();
    }

    private function registerRoutes(): void
    {
        $this->routes = [
            'POST /actions/application-form/application/submit' => [
                'controller' => \modules\applicationform\controllers\SubmissionController::class,
                'action' => 'submit'
            ],
            'POST /actions/application-form/application/verify-payment' => [
                'controller' => \modules\applicationform\controllers\SubmissionController::class,
                'action' => 'verifyPayment'
            ],
            'POST /actions/application-form/application/webhook' => [
                'controller' => \modules\applicationform\controllers\SubmissionController::class,
                'action' => 'webhook'
            ],
            'POST /actions/contact/submit' => [
                'controller' => \modules\applicationform\controllers\ContactController::class,
                'action' => 'submit'
            ],
            'POST /actions/newsletter/subscribe' => [
                'controller' => \modules\applicationform\controllers\NewsletterController::class,
                'action' => 'subscribe'
            ],
            'POST /actions/newsletter/unsubscribe' => [
                'controller' => \modules\applicationform\controllers\NewsletterController::class,
                'action' => 'unsubscribe'
            ],
            
            'POST /actions/meet-greet/book' => [
                'controller' => \modules\applicationform\controllers\MeetGreetController::class,
                'action' => 'book'
            ],
            'POST /actions/meet-greet/verify-payment' => [
                'controller' => \modules\applicationform\controllers\MeetGreetController::class,
                'action' => 'verifyPayment'
            ],
        ];
    }

    private function handleRequest(): void
    {
        $requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $requestMethod = $_SERVER['REQUEST_METHOD'];
        $routeKey = $requestMethod . ' ' . $requestUri;

        if (!isset($this->routes[$routeKey])) {
            return; // Not our route — let Craft handle it
        }

        $route = $this->routes[$routeKey];
        $controllerClass = $route['controller'];
        $action = $route['action'];

        header('Content-Type: application/json');

        try {
            $controller = new $controllerClass();

            if (!method_exists($controller, $action)) {
                http_response_code(500);
                echo json_encode(['success' => false, 'errors' => ['server' => 'Action not found']]);
                return;
            }

            $result = $controller->$action();
            echo json_encode($result);
        } catch (\Throwable $e) {
            error_log('ApplicationFormModule error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'errors' => ['server' => 'Internal server error']]);
        }

        exit;
    }
}


/*
/**
 * ApplicationFormModule - Standalone module (no Craft dependency)
 * 
 * Place at: /modules/applicationform/ApplicationFormModule.php
 * 
 * Routes /actions/* requests directly to controllers.
 * Everything else passes through to Craft CMS untouched.
 *
namespace modules\applicationform;

class ApplicationFormModule
{
    private static ?self $instance = null;
    private array $routes = [];

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init(): void
    {
        $this->registerRoutes();
        $this->handleRequest();
    }

    private function registerRoutes(): void
    {
        $this->routes = [
            'POST /actions/application-form/application/submit' => [
                'controller' => \modules\applicationform\controllers\SubmissionController::class,
                'action' => 'submit'
            ],
            'POST /actions/application-form/application/verify-payment' => [
                'controller' => \modules\applicationform\controllers\SubmissionController::class,
                'action' => 'verifyPayment'
            ],
            'POST /actions/contact/submit' => [
                'controller' => \modules\applicationform\controllers\ContactController::class,
                'action' => 'submit'
            ],
            'POST /actions/newsletter/subscribe' => [
                'controller' => \modules\applicationform\controllers\NewsletterController::class,
                'action' => 'subscribe'
            ],
            'POST /actions/newsletter/unsubscribe' => [
                'controller' => \modules\applicationform\controllers\NewsletterController::class,
                'action' => 'unsubscribe'
            ],
        ];
    }

    private function handleRequest(): void
    {
        $requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $requestMethod = $_SERVER['REQUEST_METHOD'];
        $routeKey = $requestMethod . ' ' . $requestUri;

        if (!isset($this->routes[$routeKey])) {
            return; // Not our route — let Craft handle it
        }

        $route = $this->routes[$routeKey];
        $controllerClass = $route['controller'];
        $action = $route['action'];

        header('Content-Type: application/json');

        try {
            $controller = new $controllerClass();

            if (!method_exists($controller, $action)) {
                http_response_code(500);
                echo json_encode(['success' => false, 'errors' => ['server' => 'Action not found']]);
                return;
            }

            $result = $controller->$action();
            echo json_encode($result);
        } catch (\Throwable $e) {
            error_log('ApplicationFormModule error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'errors' => ['server' => 'Internal server error']]);
        }

        exit;
    }
}




/**
 * ApplicationFormModule - Standalone module (no Craft dependency)
 * 
 * Place at: /modules/applicationform/ApplicationFormModule.php
 * 
 * Routes /actions/* requests directly to controllers.
 * Everything else passes through to Craft CMS untouched.
 */


/**namespace modules\applicationform;

class ApplicationFormModule
{
    private static ?self $instance = null;
    private array $routes = [];

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init(): void
    {
        $this->registerRoutes();
        $this->handleRequest();
    }

    private function registerRoutes(): void
    {
        $this->routes = [
            'POST /actions/application-form/application/submit' => [
                'controller' => \modules\applicationform\controllers\SubmissionController::class,
                'action' => 'submit'
            ],
            'POST /actions/application-form/application/verify-payment' => [
                'controller' => \modules\applicationform\controllers\SubmissionController::class,
                'action' => 'verifyPayment'
            ],
            'POST /actions/contact/submit' => [
                'controller' => \modules\applicationform\controllers\ContactController::class,
                'action' => 'submit'
            ],
        ];
    }

    private function handleRequest(): void
    {
        $requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $requestMethod = $_SERVER['REQUEST_METHOD'];
        $routeKey = $requestMethod . ' ' . $requestUri;

        if (!isset($this->routes[$routeKey])) {
            return; // Not our route — let Craft handle it
        }

        $route = $this->routes[$routeKey];
        $controllerClass = $route['controller'];
        $action = $route['action'];

        header('Content-Type: application/json');

        try {
            $controller = new $controllerClass();

            if (!method_exists($controller, $action)) {
                http_response_code(500);
                echo json_encode(['success' => false, 'errors' => ['server' => 'Action not found']]);
                return;
            }

            $result = $controller->$action();
            echo json_encode($result);
        } catch (\Throwable $e) {
            error_log('ApplicationFormModule error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'errors' => ['server' => 'Internal server error']]);
        }

        exit;
    }
}**/
