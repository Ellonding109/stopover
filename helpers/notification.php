<?php
/**
 * notification.php
 *
 * Admin notification helper.
 * Plain PHP — no Yii dependency.
 *
 * Usage:
 *   require_once __DIR__ . '/notification.php';
 *   \helpers\Notification::paymentInitiated($booking);
 *   \helpers\Notification::paymentSuccess($booking);
 *   \helpers\Notification::paymentFailed($booking, 'charge.failed');
 *   \helpers\Notification::paymentAbandoned($booking);
 */

namespace helpers;

class Notification
{
    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Fired the moment a booking is created (status = pending).
     * Lets admin know someone started a payment.
     */
    public static function paymentInitiated(array $booking): void
    {
        $cfg     = self::loadConfig();
        $subject = '[' . $cfg['site_name'] . '] Payment Initiated — ' . $booking['reference'];
        $body    = self::buildBody('Payment Initiated', $booking, 'pending', $cfg);
        self::send($cfg['admin_email'], $subject, $body, $cfg);
        self::log('initiated', $booking['reference']);
    }

    /**
     * Fired after webhook confirms charge.success.
     */
    public static function paymentSuccess(array $booking): void
    {
        $cfg     = self::loadConfig();
        $subject = '[' . $cfg['site_name'] . '] ✅ Payment Confirmed — ' . $booking['reference'];
        $body    = self::buildBody('Payment Confirmed', $booking, 'paid', $cfg);
        self::send($cfg['admin_email'], $subject, $body, $cfg);
        self::log('success', $booking['reference']);
    }

    /**
     * Fired after webhook confirms charge.failed.
     */
    public static function paymentFailed(array $booking, string $paystackEvent = 'charge.failed'): void
    {
        $cfg     = self::loadConfig();
        $subject = '[' . $cfg['site_name'] . '] ❌ Payment Failed — ' . $booking['reference'];
        $body    = self::buildBody('Payment Failed', $booking, 'failed', $cfg, [
            'Paystack Event' => $paystackEvent,
        ]);
        self::send($cfg['admin_email'], $subject, $body, $cfg);
        self::log('failed', $booking['reference']);
    }

    /**
     * Fired after webhook confirms charge.abandoned.
     */
    public static function paymentAbandoned(array $booking): void
    {
        $cfg     = self::loadConfig();
        $subject = '[' . $cfg['site_name'] . '] ⚠️ Payment Abandoned — ' . $booking['reference'];
        $body    = self::buildBody('Payment Abandoned', $booking, 'abandoned', $cfg);
        self::send($cfg['admin_email'], $subject, $body, $cfg);
        self::log('abandoned', $booking['reference']);
    }

    /**
     * Customer confirmation email — sent on successful payment.
     */
    public static function customerPaymentConfirmed(array $booking): void
    {
        $cfg     = self::loadConfig();
        $service = strtoupper($booking['service_type'] ?? 'booking');
        $subject = 'Your ' . $cfg['site_name'] . ' booking is confirmed — ' . $booking['reference'];

        $amount = number_format((float) ($booking['amount'] ?? 0), 2);
        $currency = $booking['currency'] ?? 'KES';
        $name   = htmlspecialchars($booking['full_name'] ?? 'Customer', ENT_QUOTES, 'UTF-8');

        $body = "Dear {$name},\n\n"
              . "Great news! We have received your payment and your booking is now confirmed.\n\n"
              . "Reference: {$booking['reference']}\n"
              . "Service:   {$service}\n"
              . "Amount:    {$currency} {$amount}\n\n"
              . "We will be in touch shortly with further details.\n\n"
              . "Thank you for choosing {$cfg['site_name']}.\n\n"
              . "— The {$cfg['site_name']} Team\n";

        self::send($booking['email'], $subject, $body, $cfg);
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private static function buildBody(
        string $title,
        array  $booking,
        string $status,
        array  $cfg,
        array  $extra = []
    ): string {
        $amount   = number_format((float) ($booking['amount'] ?? 0), 2);
        $currency = $booking['currency'] ?? 'KES';
        $service  = strtoupper($booking['service_type'] ?? '—');
        $txnId    = $booking['transaction_id'] ?? '—';

        $lines = [
            $title,
            str_repeat('=', strlen($title)),
            '',
            'Customer:     ' . ($booking['full_name'] ?? '—'),
            'Email:        ' . ($booking['email'] ?? '—'),
            'Phone:        ' . ($booking['phone'] ?? '—'),
            'Reference:    ' . ($booking['reference'] ?? '—'),
            'Service:      ' . $service,
            'Amount:       ' . $currency . ' ' . $amount,
            'Status:       ' . strtoupper($status),
            'Transaction:  ' . $txnId,
            'Time:         ' . date('Y-m-d H:i:s T'),
        ];

        foreach ($extra as $label => $value) {
            $lines[] = str_pad($label . ':', 14) . $value;
        }

        $lines[] = '';
        $lines[] = 'Admin panel: ' . rtrim($cfg['site_url'], '/') . '/admin';
        $lines[] = '';
        $lines[] = '— ' . $cfg['site_name'] . ' Payment System';

        return implode("\n", $lines);
    }

    /**
     * Thin mail() wrapper. For production, swap this body for an SMTP call
     * (PHPMailer, Symfony Mailer, etc.) while keeping the same signature.
     */
    private static function send(string $to, string $subject, string $body, array $cfg): void
    {
        $from    = $cfg['mail_from'];
        $headers = implode("\r\n", [
            'From: ' . $cfg['site_name'] . ' <' . $from . '>',
            'Reply-To: ' . $from,
            'Content-Type: text/plain; charset=UTF-8',
            'X-Mailer: KenyaStopover-PaymentSystem/2.0',
        ]);

        $result = @mail($to, $subject, $body, $headers);

        if (!$result) {
            self::log('mail_send_failed', $to, ['subject' => $subject]);
        }
    }

    private static function loadConfig(): array
    {
        $envFile = dirname(__DIR__) . '/.env';
        $env = [];
        if (file_exists($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
                [$k, $v] = explode('=', $line, 2);
                $env[trim($k)] = trim($v, " \t\"'");
            }
        }
        $g = fn(string $key, string $default = '') =>
            getenv($key) ?: ($env[$key] ?? $default);

        return [
            'admin_email' => $g('ADMIN_EMAIL', 'info@kenyastopover.com'),
            'mail_from'   => $g('MAIL_FROM', 'noreply@kenyastopover.com'),
            'site_name'   => $g('SITE_NAME', 'Kenya Stopover'),
            'site_url'    => $g('SITE_URL', 'https://kenyastopover.com'),
        ];
    }

    private static function log(string $event, string $reference, array $ctx = []): void
    {
        $logDir = dirname(__DIR__) . '/storage/logs';
        if (!is_dir($logDir)) @mkdir($logDir, 0755, true);

        $line = json_encode([
            'ts'        => date('c'),
            'level'     => 'INFO',
            'event'     => 'notification.' . $event,
            'reference' => $reference,
            'context'   => $ctx,
        ]);
        @file_put_contents($logDir . '/payment_' . date('Y-m-d') . '.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
