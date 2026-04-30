<?php
/**
 * /api/payments/paystack/webhook.php
 *
 * THE single Paystack webhook handler.
 * Plain PHP — NOT a Yii controller.
 *
 * Register ONE URL with Paystack:
 *   https://yourdomain.com/api/payments/paystack/webhook.php
 *
 * This file handles all Paystack events:
 *   charge.success   → booking status: pending → paid
 *   charge.failed    → booking status: pending → failed
 *   charge.abandoned → booking status: pending → abandoned
 *   (other events)   → logged and acknowledged, no action
 *
 * Design principles:
 *   - Idempotent: safe to call multiple times for the same event
 *   - Responds 200 immediately so Paystack doesn't retry needlessly
 *   - Signature verified first — rejects unsigned requests with 401
 *   - After status update, routes to service-specific post-payment logic
 *   - Notifies admin on every terminal status change
 */

declare(strict_types=1);

// ── Bootstrap ────────────────────────────────────────────────────────────────

$rootDir = dirname(__DIR__, 3);   // /api/payments/paystack → root

require_once $rootDir . '/services/PaymentService.php';
require_once $rootDir . '/helpers/notification.php';

file_put_contents(
    __DIR__ . '/webhook_log.txt',
    date('Y-m-d H:i:s') . " | " . file_get_contents('php://input') . PHP_EOL,
    FILE_APPEND
);

// Load service-specific handlers if they exist
$etaHandlerPath       = $rootDir . '/modules/applicationform/services/SubmissionService.php';
$meetGreetHandlerPath = $rootDir . '/services/MeetGreetService.php';

if (file_exists($etaHandlerPath)) {
    require_once $etaHandlerPath;
}
if (file_exists($meetGreetHandlerPath)) {
    require_once $meetGreetHandlerPath;
}

use services\PaymentService;
use helpers\Notification;

$paymentService = new PaymentService();

// ── Helper: respond and exit ─────────────────────────────────────────────────

function webhookRespond(int $code, string $message, array $ctx = []): never
{
    global $paymentService;
    $paymentService->log(
        $code >= 400 ? 'warning' : 'info',
        'webhook_respond',
        array_merge(['http_code' => $code, 'message' => $message], $ctx)
    );
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['message' => $message]);
    exit;
}

// ── Gate: POST only ──────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    webhookRespond(405, 'Method Not Allowed');
}

// ── Read raw body (must happen before any echo/output) ───────────────────────

$rawPayload = file_get_contents('php://input');
if (empty($rawPayload)) {
    webhookRespond(400, 'Empty payload');
}

// ── Verify Paystack signature ─────────────────────────────────────────────────

$signature = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';


if (!$paymentService->verifyWebhookSignature($rawPayload, $signature)) {
    $paymentService->log('warning', 'webhook_invalid_signature', [
        'ip'        => $_SERVER['REMOTE_ADDR'] ?? '?',
        'sig_head'  => substr($signature, 0, 20),
    ]);
    webhookRespond(401, 'Invalid signature');
}

// ── Parse JSON ────────────────────────────────────────────────────────────────

$event = json_decode($rawPayload, true);
if (!is_array($event)) {
    webhookRespond(400, 'Invalid JSON payload');
}

$eventType = $event['event'] ?? '';
$data      = $event['data'] ?? [];
$reference = $data['reference'] ?? '';

$paymentService->log('info', 'webhook_received', [
    'event'     => $eventType,
    'reference' => $reference,
]);

// ── Guard: we need a reference ───────────────────────────────────────────────

if (empty($reference)) {
    webhookRespond(200, 'No reference — event ignored');
}

// ── Look up booking ───────────────────────────────────────────────────────────

$booking = $paymentService->getBookingByReference($reference);

if (!$booking) {
    // Could be a payment for a different system on the same Paystack account.
    // Return 200 so Paystack doesn't retry.
    $paymentService->log('warning', 'webhook_booking_not_found', ['reference' => $reference]);
    webhookRespond(200, 'Reference not recognised — skipped');
}

$serviceType = $booking['service_type'] ?? '';

// ── Idempotency: already in terminal state? ───────────────────────────────────

$terminalStates = ['paid', 'failed', 'abandoned', 'reversed'];

if (in_array($booking['status'], $terminalStates, true) && $eventType !== 'charge.reversed') {
    $paymentService->log('info', 'webhook_idempotent_skip', [
        'reference'      => $reference,
        'current_status' => $booking['status'],
        'event'          => $eventType,
    ]);
    webhookRespond(200, 'Already processed — idempotent skip');
}

// ── Route by event type ───────────────────────────────────────────────────────

$transactionId   = (string) ($data['id'] ?? $data['transaction'] ?? '');
$paystackPayload = $data;   // store the full Paystack data object

switch ($eventType) {

    // ── charge.success ────────────────────────────────────────────────────────
    case 'charge.success':

        $paymentService->updateBookingStatus($reference, 'paid', [
            'transaction_id'    => $transactionId,
            'paystack_payload'  => $paystackPayload,
        ]);

        // Refresh booking after update
        $booking = $paymentService->getBookingByReference($reference) ?? $booking;

        // Admin notification
        try {
            Notification::paymentSuccess($booking);
        } catch (\Throwable $e) {
            $paymentService->log('warning', 'notify_admin_failed', ['error' => $e->getMessage()]);
        }

        // Customer confirmation email
        try {
            Notification::customerPaymentConfirmed($booking);
        } catch (\Throwable $e) {
            $paymentService->log('warning', 'notify_customer_failed', ['error' => $e->getMessage()]);
        }

        // Service-specific post-payment hook
        routeServiceSuccess($serviceType, $booking, $data, $paymentService);

        webhookRespond(200, 'Payment recorded as paid');

    // ── charge.failed ─────────────────────────────────────────────────────────
    case 'charge.failed':

        $paymentService->updateBookingStatus($reference, 'failed', [
            'transaction_id'   => $transactionId,
            'paystack_payload' => $paystackPayload,
        ]);

        $booking = $paymentService->getBookingByReference($reference) ?? $booking;

        try {
            Notification::paymentFailed($booking, 'charge.failed');
        } catch (\Throwable $e) {
            $paymentService->log('warning', 'notify_admin_failed', ['error' => $e->getMessage()]);
        }

        webhookRespond(200, 'Payment recorded as failed');

    // ── charge.abandoned ──────────────────────────────────────────────────────
    case 'charge.abandoned':

        $paymentService->updateBookingStatus($reference, 'abandoned', [
            'paystack_payload' => $paystackPayload,
        ]);

        $booking = $paymentService->getBookingByReference($reference) ?? $booking;

        try {
            Notification::paymentAbandoned($booking);
        } catch (\Throwable $e) {
            $paymentService->log('warning', 'notify_admin_failed', ['error' => $e->getMessage()]);
        }

        webhookRespond(200, 'Payment recorded as abandoned');

    // ── charge.reversed ───────────────────────────────────────────────────────
    case 'charge.reversed':

        $paymentService->updateBookingStatus($reference, 'reversed', [
            'paystack_payload' => $paystackPayload,
        ]);

        $paymentService->log('info', 'webhook_reversed', ['reference' => $reference]);

        webhookRespond(200, 'Payment recorded as reversed');

    // ── All other events ──────────────────────────────────────────────────────
    default:
        $paymentService->log('info', 'webhook_unhandled_event', [
            'event'     => $eventType,
            'reference' => $reference,
        ]);
        webhookRespond(200, 'Event acknowledged — no action taken');
}

// ── Service-specific success routing ─────────────────────────────────────────

/**
 * After a successful payment, update the service-specific table.
 * Routes by booking['service_type']:
 *   'eta'        → eta_applications (via SubmissionService)
 *   'meetgreet'  → meetgreet_bookings (via MeetGreetService)
 */
function routeServiceSuccess(
    string         $serviceType,
    array          $booking,
    array          $paystackData,
    PaymentService $paymentService
): void {
    $reference     = $booking['reference'];
    $transactionId = (string) ($paystackData['id'] ?? '');

    switch ($serviceType) {

        case 'eta':
            // Delegate to existing SubmissionService if available
            if (!class_exists('modules\applicationform\services\SubmissionService')) {
                $paymentService->log('warning', 'eta_service_class_not_found', ['reference' => $reference]);
                return;
            }

            try {
                $submissionService = new \modules\applicationform\services\SubmissionService();
                $applicationId     = $submissionService->getApplicationIdForReference($reference);

                if ($applicationId) {
                    $submissionService->updateApplicationPaymentRecord(
                        $applicationId,
                        $reference,
                        'paid',
                        'submitted',
                        $transactionId,
                        $paystackData
                    );
                    $submissionService->updateTransactionStatus($reference, 'settled', $transactionId, $paystackData);
                    $paymentService->log('info', 'eta_record_updated', [
                        'reference'      => $reference,
                        'application_id' => $applicationId,
                    ]);
                } else {
                    $paymentService->log('warning', 'eta_application_not_found', ['reference' => $reference]);
                }
            } catch (\Throwable $e) {
                $paymentService->log('error', 'eta_update_failed', [
                    'reference' => $reference,
                    'error'     => $e->getMessage(),
                ]);
            }
            break;

        case 'meetgreet':
            // Delegate to MeetGreetService if available
            if (!class_exists('services\MeetGreetService')) {
                $paymentService->log('warning', 'meetgreet_service_class_not_found', ['reference' => $reference]);
                return;
            }

            try {
                $mgService = new \services\MeetGreetService();
                $mgService->confirmPayment($reference, $transactionId, $paystackData);
                $paymentService->log('info', 'meetgreet_record_updated', ['reference' => $reference]);
            } catch (\Throwable $e) {
                $paymentService->log('error', 'meetgreet_update_failed', [
                    'reference' => $reference,
                    'error'     => $e->getMessage(),
                ]);
            }
            break;

        default:
            $paymentService->log('warning', 'unknown_service_type', [
                'service_type' => $serviceType,
                'reference'    => $reference,
            ]);
    }
}
