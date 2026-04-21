<?php
require_once __DIR__ . '/../modules/applicationform/services/SubmissionService.php';

use modules\applicationform\services\SubmissionService;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo 'Method Not Allowed';
    exit;
}

$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';

$service = new SubmissionService();
if (!$service->verifyWebhookSignature($payload, $signature)) {
    http_response_code(401);
    echo 'Invalid signature';
    exit;
}

$event = json_decode($payload, true);
if (!is_array($event)) {
    http_response_code(400);
    echo 'Invalid JSON payload';
    exit;
}

$result = $service->processPaystackWebhookEvent($event);

http_response_code(200);
header('Content-Type: application/json');
echo json_encode([
    'success' => $result['success'],
    'message' => $result['message'] ?? 'Webhook processed',
]);
