<?php
/**
 * /payment/webhook.php — UPGRADED
 *
 * Thin shim that delegates to the canonical webhook handler.
 *
 * Preserved for backwards compatibility in case Paystack is still configured
 * to POST here. If you have already updated the Paystack webhook URL to:
 *
 *   https://yourdomain.com/api/payments/paystack/webhook.php
 *
 * then this file is only a safety net. It adds no logic of its own.
 *
 * UPGRADE CHECKLIST:
 *   [ ] Update Paystack Dashboard webhook URL →
 *         https://yourdomain.com/api/payments/paystack/webhook.php
 *   [ ] Keep this file as a redirect shim until DNS/Paystack config is confirmed
 */

declare(strict_types=1);
file_put_contents('webhook_log.txt', file_get_contents('php://input') . PHP_EOL, FILE_APPEND);//this is for testing the webhook accessibility.

// All the real logic lives in the canonical webhook.
// Forward this request there by including it directly.

$canonicalWebhook = dirname(__DIR__) . '/api/payments/paystack/webhook.php';

if (file_exists($canonicalWebhook)) {
    // Pass control to canonical handler
    include $canonicalWebhook;
    exit;
}

// ── Fallback: run the legacy SubmissionService path ──────────────────────────
// Only reached if the canonical webhook file doesn't exist yet.

require_once __DIR__ . '/../modules/applicationform/services/SubmissionService.php';

use modules\applicationform\services\SubmissionService;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo 'Method Not Allowed';
    exit;
}

$payload   = file_get_contents('php://input');
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
