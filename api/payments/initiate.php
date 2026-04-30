<?php
/**
 * /api/payments/initiate.php
 *
 * Plain PHP intercept endpoint (NOT a Yii controller).
 *
 * Called by the frontend immediately before the user is sent to Paystack.
 * Creates a pending booking record in payment_bookings so the system has a
 * record of the intent BEFORE any payment is attempted.
 *
 * Method:  POST
 * Payload: JSON
 * Returns: JSON { success, reference, amount, currency, public_key }
 *
 * FLOW:
 *   1. Validate incoming data
 *   2. Generate a unique reference
 *   3. Insert payment_bookings (status = pending)
 *   4. Notify admin that a payment has been initiated
 *   5. Return reference + Paystack public key to frontend
 *
 * The frontend then calls Paystack Popup/Inline with the returned reference.
 * Paystack redirects the user to /payment/status?ref=XXXX.
 * The webhook at /api/payments/paystack/webhook.php is the ONLY thing that
 * marks the booking as paid/failed/abandoned.
 */

declare(strict_types=1);

// ── Bootstrap ────────────────────────────────────────────────────────────────

$rootDir = dirname(__DIR__, 2);   // project root

require_once $rootDir . '/services/PaymentService.php';
require_once $rootDir . '/helpers/notification.php';

use services\PaymentService;
use helpers\Notification;

// ── Helpers ──────────────────────────────────────────────────────────────────

function jsonResponse(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function sanitize(string $val): string
{
    return trim(strip_tags($val));
}

// ── Gate: POST only ──────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    jsonResponse(['success' => false, 'message' => 'Method Not Allowed'], 405);
}

// ── Parse body ───────────────────────────────────────────────────────────────

$raw  = file_get_contents('php://input');
$body = json_decode($raw ?: '', true);

if (!is_array($body)) {
    jsonResponse(['success' => false, 'message' => 'Invalid JSON body'], 400);
}

// ── Validate required fields ─────────────────────────────────────────────────

$required = ['email', 'full_name', 'service_type', 'amount', 'currency'];
$errors   = [];
foreach ($required as $field) {
    if (empty($body[$field])) {
        $errors[$field] = "'{$field}' is required";
    }
}

$serviceType = sanitize($body['service_type'] ?? '');
if (!in_array($serviceType, ['eta', 'meetgreet'], true)) {
    $errors['service_type'] = "service_type must be 'eta' or 'meetgreet'";
}

$reference = sanitize($body['reference'] ?? '');
if ($reference !== '' && !preg_match('/^[A-Z0-9\-]+$/', $reference)) {
    $errors['reference'] = 'reference must contain only uppercase letters, numbers and dashes';
}

$amount = filter_var($body['amount'] ?? '', FILTER_VALIDATE_FLOAT);
if ($amount === false || $amount <= 0) {
    $errors['amount'] = 'amount must be a positive number';
}

if (!empty($errors)) {
    jsonResponse(['success' => false, 'message' => 'Validation failed', 'errors' => $errors], 422);
}

// ── Build booking data ────────────────────────────────────────────────────────

$service  = new PaymentService();
$config   = $service->getConfig();

if ($reference === '') {
    $prefix    = strtoupper($serviceType === 'eta' ? 'ETA' : 'MG');
    $reference = 'KS-' . $prefix . '-' . strtoupper(base_convert((string) time(), 10, 36))
               . '-' . strtoupper(bin2hex(random_bytes(4)));
}

$existingBooking = $service->getBookingByReference($reference);
if ($existingBooking) {
    if ($existingBooking['service_type'] !== $serviceType) {
        jsonResponse([
            'success' => false,
            'message' => 'Reference already exists for a different service type.',
            'errors'  => ['reference' => 'Reference conflict'],
        ], 409);
    }

    jsonResponse([
        'success'    => true,
        'reference'  => $existingBooking['reference'],
        'amount'     => (float) $existingBooking['amount'],
        'amount_kobo'=> (int) round((float) $existingBooking['amount'] * 100),
        'currency'   => $existingBooking['currency'],
        'public_key' => getenv('PAYSTACK_PUBLIC_KEY') ?: '',
        'status_url' => $config['site_url'] . '/payment/status?ref=' . urlencode($existingBooking['reference']),
        'message'    => 'Booking already reserved',
    ]);
}

$bookingData = [
    'reference'    => $reference,
    'email'        => sanitize($body['email']),
    'full_name'    => sanitize($body['full_name']),
    'phone'        => sanitize($body['phone'] ?? ''),
    'service_type' => $serviceType,
    'amount'       => (float) $amount,
    'currency'     => strtoupper(sanitize($body['currency'] ?? 'KES')),
    'metadata'     => array_merge(
        $body['metadata'] ?? [],
        [
            'service_type' => $serviceType,
            'user_agent'   => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'ip'           => $_SERVER['REMOTE_ADDR'] ?? '',
            'initiated_at' => date('c'),
        ]
    ),
];

// ── Persist pending booking ───────────────────────────────────────────────────

$insertId = $service->createPendingBooking($bookingData);

if ($insertId === false) {
    $service->log('error', 'initiate_db_failed', ['reference' => $reference]);
    jsonResponse(['success' => false, 'message' => 'Could not create booking record. Please try again.'], 500);
}

// ── Notify admin: payment initiated ──────────────────────────────────────────

try {
    // Retrieve the freshly-inserted row to pass full context to notifier
    $booking = $service->getBookingByReference($reference);
    if ($booking) {
        Notification::paymentInitiated($booking);
    }
} catch (\Throwable $e) {
    // Non-fatal — log and continue
    $service->log('warning', 'admin_notification_failed', [
        'reference' => $reference,
        'error'     => $e->getMessage(),
    ]);
}

// ── Respond to frontend ───────────────────────────────────────────────────────

// Resolve Paystack public key from env
$paystackPublicKey = getenv('PAYSTACK_PUBLIC_KEY')
    ?: (function () use ($rootDir): string {
        $envFile = $rootDir . '/.env';
        if (!file_exists($envFile)) return '';
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
            [$k, $v] = explode('=', $line, 2);
            if (trim($k) === 'PAYSTACK_PUBLIC_KEY') return trim($v, " \t\"'");
        }
        return '';
    })();

jsonResponse([
    'success'    => true,
    'reference'  => $reference,
    'amount'     => (float) $amount,                   // in base unit (e.g. KES)
    'amount_kobo'=> (int) round((float) $amount * 100), // Paystack expects kobo/cents
    'currency'   => $bookingData['currency'],
    'public_key' => $paystackPublicKey,
    //'status_url' => $config['site_url'] . '/payment/status?ref=' . urlencode($reference),//this is for the live system, the frontend should use this URL to redirect the user after payment.
    'status_url' => 'http://localhost:8080/payment/status?ref=' . urlencode($reference),
    'message'    => 'Booking created. Proceed to payment.',
]);
