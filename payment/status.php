<?php
/**
 * /payment/status.php
 *
 * Payment status page.
 * Plain PHP — NOT a Yii controller.
 *
 * URL:  /payment/status?ref=KS-ETA-XXXX
 *
 * CRITICAL RULE:
 *   This page ONLY reads the database.
 *   It NEVER calls the Paystack API.
 *   It NEVER trusts the URL to determine payment success.
 *   The authoritative status always comes from payment_bookings.status,
 *   which is set by the webhook.
 *
 * Status display map:
 *   pending   → "Processing…"  (webhook not yet received)
 *   paid      → "Confirmed ✅"
 *   failed    → "Failed ❌"
 *   abandoned → "Abandoned ⚠️"
 *   reversed  → "Reversed 🔄"
 */

declare(strict_types=1);

$rootDir = dirname(__DIR__);

require_once $rootDir . '/services/PaymentService.php';

use services\PaymentService;

// ── Helpers ───────────────────────────────────────────────────────────────────

function h(string $val): string
{
    return htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
}

// ── Read reference from query string ─────────────────────────────────────────

$reference = trim($_GET['ref'] ?? '');

if ($reference === '') {
    http_response_code(400);
    $errorMessage = 'No payment reference supplied.';
    $booking      = null;
} else {
    $service = new PaymentService();
    $booking = $service->getBookingByReference($reference);

    if (!$booking) {
        http_response_code(404);
        $errorMessage = 'Payment reference not found.';
    }
}

// ── Status config ─────────────────────────────────────────────────────────────

$statusConfig = [
    'pending'   => [
        'label'   => 'Processing',
        'icon'    => '⏳',
        'class'   => 'status-pending',
        'heading' => 'Your payment is being processed',
        'message' => 'We haven\'t received confirmation from Paystack yet. '
                   . 'This page will update automatically. '
                   . 'If you completed the payment, please allow a few minutes.',
        'refresh' => true,
    ],
    'paid'      => [
        'label'   => 'Confirmed',
        'icon'    => '✅',
        'class'   => 'status-paid',
        'heading' => 'Payment Confirmed!',
        'message' => 'Your payment has been received and your booking is confirmed. '
                   . 'You will receive a confirmation email shortly.',
        'refresh' => false,
    ],
    'failed'    => [
        'label'   => 'Failed',
        'icon'    => '❌',
        'class'   => 'status-failed',
        'heading' => 'Payment Failed',
        'message' => 'Your payment was not successful. '
                   . 'Please try again or contact support if this problem persists.',
        'refresh' => false,
    ],
    'abandoned' => [
        'label'   => 'Abandoned',
        'icon'    => '⚠️',
        'class'   => 'status-abandoned',
        'heading' => 'Payment Abandoned',
        'message' => 'The payment process was not completed. '
                   . 'If this was a mistake, please go back and try again.',
        'refresh' => false,
    ],
    'reversed'  => [
        'label'   => 'Reversed',
        'icon'    => '🔄',
        'class'   => 'status-reversed',
        'heading' => 'Payment Reversed',
        'message' => 'This payment has been reversed. '
                   . 'Please contact support for assistance.',
        'refresh' => false,
    ],
];

$currentStatus = $booking['status'] ?? 'pending';
$statusInfo    = $statusConfig[$currentStatus] ?? $statusConfig['pending'];

$amount    = isset($booking['amount'])   ? number_format((float) $booking['amount'], 2) : '—';
$currency  = $booking['currency']        ?? 'KES';
$service   = strtoupper($booking['service_type'] ?? '');
$name      = $booking['full_name']       ?? '';
$email     = $booking['email']           ?? '';
$txnId     = $booking['transaction_id'] ?? '';
$createdAt = $booking['created_at']      ?? '';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Status — Kenya Stopover</title>
    <?php if ($statusInfo['refresh'] ?? false): ?>
    <meta http-equiv="refresh" content="10">
    <?php endif; ?>
    <style>
        *, *::before, *::after { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, sans-serif;
            background: #f5f5f5;
            color: #333;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
        }

        .card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 24px rgba(0,0,0,.08);
            max-width: 540px;
            width: 100%;
            overflow: hidden;
        }

        .card-header {
            padding: 2rem 2rem 1.5rem;
            text-align: center;
            border-bottom: 1px solid #eee;
        }

        .status-icon {
            font-size: 3rem;
            display: block;
            margin-bottom: .5rem;
        }

        .card-header h1 {
            margin: 0 0 .5rem;
            font-size: 1.4rem;
        }

        .card-header p {
            margin: 0;
            color: #666;
            font-size: .95rem;
            line-height: 1.5;
        }

        .status-badge {
            display: inline-block;
            padding: .25rem .75rem;
            border-radius: 20px;
            font-size: .8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .04em;
            margin-bottom: 1rem;
        }

        .status-pending  { background: #FFF3CD; color: #856404; }
        .status-paid     { background: #D1E7DD; color: #0A3622; }
        .status-failed   { background: #F8D7DA; color: #58151C; }
        .status-abandoned{ background: #FFF3CD; color: #664D03; }
        .status-reversed { background: #CFE2FF; color: #084298; }

        .card-body {
            padding: 1.5rem 2rem;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: .5rem 0;
            border-bottom: 1px solid #f0f0f0;
            font-size: .9rem;
        }

        .detail-row:last-child { border-bottom: none; }

        .detail-label {
            color: #888;
            font-weight: 500;
        }

        .detail-value {
            font-weight: 600;
            text-align: right;
            max-width: 60%;
            word-break: break-all;
        }

        .card-footer {
            padding: 1rem 2rem 1.5rem;
            text-align: center;
        }

        .btn {
            display: inline-block;
            padding: .7rem 1.5rem;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            font-size: .95rem;
            cursor: pointer;
            border: none;
        }

        .btn-primary   { background: #1a6640; color: #fff; }
        .btn-secondary { background: #eee;    color: #333; margin-left: .5rem; }

        .refresh-note {
            font-size: .8rem;
            color: #999;
            margin-top: .5rem;
        }

        .error-box {
            background: #F8D7DA;
            color: #58151C;
            border-radius: 8px;
            padding: 1rem 1.5rem;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>

<div class="card">
    <div class="card-header">
        <?php if (!empty($errorMessage)): ?>
            <span class="status-icon">🚫</span>
            <h1>Reference Error</h1>
            <p><?= h($errorMessage) ?></p>

        <?php else: ?>
            <span class="status-badge <?= h($statusInfo['class']) ?>">
                <?= h($statusInfo['icon'] . ' ' . $statusInfo['label']) ?>
            </span>

            <span class="status-icon"><?= $statusInfo['icon'] ?></span>
            <h1><?= h($statusInfo['heading']) ?></h1>
            <p><?= h($statusInfo['message']) ?></p>

            <?php if ($statusInfo['refresh']): ?>
                <p class="refresh-note">This page refreshes automatically every 10 seconds.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php if ($booking): ?>
    <div class="card-body">

        <div class="detail-row">
            <span class="detail-label">Reference</span>
            <span class="detail-value"><?= h($reference) ?></span>
        </div>

        <?php if ($name): ?>
        <div class="detail-row">
            <span class="detail-label">Name</span>
            <span class="detail-value"><?= h($name) ?></span>
        </div>
        <?php endif; ?>

        <?php if ($email): ?>
        <div class="detail-row">
            <span class="detail-label">Email</span>
            <span class="detail-value"><?= h($email) ?></span>
        </div>
        <?php endif; ?>

        <?php if ($service): ?>
        <div class="detail-row">
            <span class="detail-label">Service</span>
            <span class="detail-value"><?= h($service) ?></span>
        </div>
        <?php endif; ?>

        <div class="detail-row">
            <span class="detail-label">Amount</span>
            <span class="detail-value"><?= h($currency . ' ' . $amount) ?></span>
        </div>

        <div class="detail-row">
            <span class="detail-label">Status</span>
            <span class="detail-value">
                <span class="status-badge <?= h($statusInfo['class']) ?>" style="font-size:.75rem">
                    <?= h($statusInfo['label']) ?>
                </span>
            </span>
        </div>

        <?php if ($txnId): ?>
        <div class="detail-row">
            <span class="detail-label">Transaction ID</span>
            <span class="detail-value"><?= h($txnId) ?></span>
        </div>
        <?php endif; ?>

        <?php if ($createdAt): ?>
        <div class="detail-row">
            <span class="detail-label">Date</span>
            <span class="detail-value"><?= h(date('d M Y, H:i', strtotime($createdAt))) ?></span>
        </div>
        <?php endif; ?>

    </div>
    <?php endif; ?>

    <div class="card-footer">
        <?php if ($currentStatus === 'failed' || $currentStatus === 'abandoned'): ?>
            <a href="/" class="btn btn-primary">Try Again</a>
            <a href="/contact" class="btn btn-secondary">Contact Support</a>
        <?php elseif ($currentStatus === 'paid'): ?>
            <a href="/" class="btn btn-primary">Back to Home</a>
        <?php else: ?>
            <a href="/" class="btn btn-secondary">Back to Home</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($booking && $currentStatus === 'pending'): ?>
<script>
    // Auto-reload after 10s for pending bookings
    setTimeout(function () {
        window.location.reload();
    }, 10000);
</script>
<?php endif; ?>

</body>
</html>
