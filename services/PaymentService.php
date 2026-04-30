<?php
/**
 * PaymentService.php
 *
 * Central payment service used by BOTH the ETA and Meet-Greet flows.
 * Plain PHP — does NOT extend or require any Yii class.
 *
 * Responsibilities:
 *   - Create & retrieve unified payment_bookings records
 *   - Verify Paystack webhook signatures
 *   - Update booking status idempotently
 *   - Route post-payment logic to the correct service table
 *   - Write structured logs
 */

namespace services;

use PDO;
use PDOException;
use Throwable;

class PaymentService
{
    private ?PDO $db = null;
    private array $config;

    // -------------------------------------------------------------------------
    // Boot
    // -------------------------------------------------------------------------

    public function __construct()
    {
        $this->config = $this->loadConfig();
    }

    // -------------------------------------------------------------------------
    // Config & DB
    // -------------------------------------------------------------------------

    private function loadConfig(): array
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
            'db_host'             => $g('APP_DB_HOST', 'localhost'),
            'db_name'             => $g('APP_DB_NAME', 'kenyastopover_db'),
            'db_user'             => $g('APP_DB_USER', 'root'),
            'db_pass'             => $g('APP_DB_PASS', ''),
            'paystack_secret_key' => $g('PAYSTACK_SECRET_KEY'),
            'admin_email'         => $g('ADMIN_EMAIL', 'info@kenyastopover.com'),
            'mail_from'           => $g('MAIL_FROM', 'noreply@kenyastopover.com'),
            'site_name'           => $g('SITE_NAME', 'Kenya Stopover'),
            'site_url'            => $g('SITE_URL', 'https://kenyastopover.com'),
        ];
    }

    public function getDb(): PDO
    {
        if ($this->db !== null) return $this->db;

        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            $this->config['db_host'],
            $this->config['db_name']
        );

        $this->db = new PDO($dsn, $this->config['db_user'], $this->config['db_pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        return $this->db;
    }

    // -------------------------------------------------------------------------
    // Booking lifecycle
    // -------------------------------------------------------------------------

    /**
     * Create a pending booking record BEFORE sending user to Paystack.
     * This is the single source-of-truth insert.
     *
     * @param array $data {
     *   reference:    string  — unique payment ref (e.g. KS-ETA-XXXX)
     *   email:        string
     *   full_name:    string
     *   phone:        string
     *   service_type: 'eta' | 'meetgreet'
     *   amount:       float   — in base currency unit (e.g. KES or USD)
     *   currency:     string  — e.g. 'KES'
     *   metadata:     array   — arbitrary bag stored as JSON
     * }
     * @return int|false  Inserted row ID, or false on failure
     */
    public function createPendingBooking(array $data): int|false
    {
        $this->log('info', 'create_pending_booking', [
            'reference'    => $data['reference'],
            'service_type' => $data['service_type'],
            'email'        => $data['email'],
            'amount'       => $data['amount'],
        ]);

        try {
            $db = $this->getDb();
            $stmt = $db->prepare("
                INSERT INTO payment_bookings
                    (reference, email, full_name, phone, service_type,
                     amount, currency, status, metadata, created_at, updated_at)
                VALUES
                    (:reference, :email, :full_name, :phone, :service_type,
                     :amount, :currency, 'pending', :metadata, NOW(), NOW())
            ");

            $stmt->execute([
                ':reference'    => $data['reference'],
                ':email'        => $data['email'],
                ':full_name'    => $data['full_name'],
                ':phone'        => $data['phone'] ?? '',
                ':service_type' => $data['service_type'],
                ':amount'       => $data['amount'],
                ':currency'     => $data['currency'] ?? 'KES',
                ':metadata'     => json_encode($data['metadata'] ?? []),
            ]);

            $id = (int) $db->lastInsertId();
            $this->log('info', 'booking_created', ['id' => $id, 'reference' => $data['reference']]);
            return $id;

        } catch (Throwable $e) {
            $this->log('error', 'create_pending_booking_failed', [
                'reference' => $data['reference'],
                'error'     => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Retrieve a booking by Paystack reference.
     */
    public function getBookingByReference(string $reference): ?array
    {
        try {
            $stmt = $this->getDb()->prepare(
                "SELECT * FROM payment_bookings WHERE reference = :ref LIMIT 1"
            );
            $stmt->execute([':ref' => $reference]);
            $row = $stmt->fetch();
            return $row ?: null;
        } catch (Throwable $e) {
            $this->log('error', 'get_booking_failed', ['reference' => $reference, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Idempotent status update — only moves forward, never backwards.
     * Status order:  pending → paid | failed | abandoned | reversed
     *
     * @param string $reference
     * @param string $newStatus   'paid' | 'failed' | 'abandoned' | 'reversed'
     * @param array  $extra       Additional columns to set (transaction_id, paystack_payload)
     * @return bool
     */
    public function updateBookingStatus(string $reference, string $newStatus, array $extra = []): bool
    {
        $this->log('info', 'update_booking_status', [
            'reference' => $reference,
            'new_status' => $newStatus,
        ]);

        // Terminal statuses — never overwrite with a lesser status
        $terminalStatuses = ['paid', 'failed', 'abandoned', 'reversed'];
        $booking = $this->getBookingByReference($reference);

        if (!$booking) {
            $this->log('warning', 'booking_not_found_for_update', ['reference' => $reference]);
            return false;
        }

        // Idempotency: already paid → don't overwrite with failed/abandoned
        if ($booking['status'] === 'paid' && $newStatus !== 'reversed') {
            $this->log('info', 'idempotent_skip', [
                'reference'      => $reference,
                'current_status' => $booking['status'],
                'new_status'     => $newStatus,
            ]);
            return true;
        }

        try {
            $db = $this->getDb();
            $sets   = ['status = :status', 'updated_at = NOW()'];
            $params = [':status' => $newStatus, ':reference' => $reference];

            if (!empty($extra['transaction_id'])) {
                $sets[]                   = 'transaction_id = :transaction_id';
                $params[':transaction_id'] = $extra['transaction_id'];
            }
            if (!empty($extra['paystack_payload'])) {
                $sets[]                    = 'paystack_payload = :paystack_payload';
                $params[':paystack_payload'] = is_array($extra['paystack_payload'])
                    ? json_encode($extra['paystack_payload'])
                    : $extra['paystack_payload'];
            }
            if ($newStatus === 'paid') {
                $sets[] = 'paid_at = NOW()';
            }

            $sql = 'UPDATE payment_bookings SET ' . implode(', ', $sets)
                 . ' WHERE reference = :reference';

            $db->prepare($sql)->execute($params);
            return true;

        } catch (Throwable $e) {
            $this->log('error', 'update_booking_status_failed', [
                'reference' => $reference,
                'error'     => $e->getMessage(),
            ]);
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Paystack Webhook signature verification
    // -------------------------------------------------------------------------

    /**
     * Verify that an incoming webhook payload is genuinely from Paystack.
     *
     * @param string $rawPayload   Raw body from php://input
     * @param string $signature    Value of X-Paystack-Signature header
     * @return bool
     */
    public function verifyWebhookSignature(string $rawPayload, string $signature): bool
    {
        $secret = $this->config['paystack_secret_key'];
        if (empty($secret) || empty($signature)) {
            $this->log('warning', 'webhook_sig_missing', [
                'has_secret' => !empty($secret),
                'has_sig'    => !empty($signature),
            ]);
            return false;
        }

        $expected = hash_hmac('sha512', $rawPayload, $secret);
        $valid    = hash_equals($expected, $signature);

        if (!$valid) {
            $this->log('warning', 'webhook_sig_invalid', ['signature' => substr($signature, 0, 20) . '…']);
        }

        return $valid;
    }

    // -------------------------------------------------------------------------
    // Paystack: verify a transaction via API
    // -------------------------------------------------------------------------

    /**
     * Call Paystack verify endpoint for a given reference.
     * NOTE: this is NOT the source of truth for payment state.
     *       The webhook is. This is only used for the status-page fallback.
     */
    public function verifyPaystackTransaction(string $reference): array
    {
        $url    = 'https://api.paystack.co/transaction/verify/' . urlencode($reference);
        $secret = $this->config['paystack_secret_key'];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $secret,
                'Cache-Control: no-cache',
            ],
            CURLOPT_TIMEOUT        => 15,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode < 200 || $httpCode >= 300) {
            $this->log('error', 'paystack_verify_failed', [
                'reference' => $reference,
                'http_code' => $httpCode,
            ]);
            return ['status' => false, 'message' => 'Could not reach Paystack'];
        }

        return json_decode($response, true) ?? [];
    }

    // -------------------------------------------------------------------------
    // Config accessor (for helpers)
    // -------------------------------------------------------------------------

    public function getConfig(): array
    {
        return $this->config;
    }

    // -------------------------------------------------------------------------
    // Logging
    // -------------------------------------------------------------------------

    /**
     * Structured append-only log written to storage/logs/payment_YYYY-MM-DD.log
     */
    public function log(string $level, string $event, array $context = []): void
    {
        $logDir = dirname(__DIR__) . '/storage/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        $line = json_encode([
            'ts'      => date('c'),
            'level'   => strtoupper($level),
            'event'   => $event,
            'context' => $context,
        ]);

        $file = $logDir . '/payment_' . date('Y-m-d') . '.log';
        @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
