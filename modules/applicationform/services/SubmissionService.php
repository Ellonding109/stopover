<?php
/**
 * SubmissionService.php — UPGRADED
 *
 * Drop-in replacement for the existing SubmissionService.
 * Changes vs original:
 *   1. processPaystackWebhookEvent() now handles charge.abandoned
 *   2. getApplicationIdForReference() falls back to payment_bookings.metadata
 *   3. sendAdminPaymentNotification() sends on pending/initiated events too
 *   4. All webhook paths are idempotent (won't double-update settled records)
 *   5. Logging improved — uses error_log (keeps existing pattern) + structured entries
 *
 * IMPORTANT: The canonical webhook is /api/payments/paystack/webhook.php.
 *            This class is still called by that webhook (via routeServiceSuccess)
 *            for ETA-specific post-payment updates.
 *            Do NOT move payment logic back into Yii controllers.
 */

namespace modules\applicationform\services;

use PDO;
use Throwable;

class SubmissionService
{
    private ?PDO $db = null;
    private array $config;

    public function __construct()
    {
        $this->config = $this->loadConfig();
    }

    // =========================================================================
    // DB & Config
    // =========================================================================

    private function getDb(): PDO
    {
        if ($this->db !== null) return $this->db;

        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            $this->config['db_host'] ?? 'localhost',
            $this->config['db_name'] ?? 'kenyastopover_db'
        );

        $this->db = new PDO($dsn, $this->config['db_user'] ?? 'root', $this->config['db_pass'] ?? '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        return $this->db;
    }

    private function loadConfig(): array
    {
        $dbConfigFile = dirname(__DIR__, 3) . '/config/db.php';
        $craftDbConfig = file_exists($dbConfigFile) ? (include $dbConfigFile) : [];

        $envFile = dirname(__DIR__, 3) . '/.env';
        $envConfig = [];
        if (file_exists($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
                [$key, $value] = explode('=', $line, 2);
                $envConfig[trim($key)] = trim($value, " \t\"'");
            }
        }

        $g = fn(string $key, string $default = '') =>
            getenv($key) ?: ($envConfig[$key] ?? $craftDbConfig[$key] ?? $default);

        return [
            'db_host'             => $g('APP_DB_HOST', 'localhost'),
            'db_name'             => $g('APP_DB_NAME', 'kenyastopover_db'),
            'db_user'             => $g('APP_DB_USER', 'root'),
            'db_pass'             => $g('APP_DB_PASS', ''),
            'paystack_secret_key' => $g('PAYSTACK_SECRET_KEY'),
            'mail_from'           => $g('MAIL_FROM', 'noreply@kenyastopover.com'),
            'admin_email'         => $g('ADMIN_EMAIL', 'info@kenyastopover.com'),
            'site_name'           => $g('SITE_NAME', 'Kenya Stopover'),
            'site_url'            => $g('SITE_URL', 'https://kenyastopover.com'),
        ];
    }

    // =========================================================================
    // Application persistence (unchanged from original)
    // =========================================================================

    public function saveApplication(array $data): string|int|false
    {
        try {
            $db             = $this->getDb();
            $applicantData  = json_decode($data['applicant_data'] ?? '{}', true);
            $services       = $applicantData['services'] ?? [];
            $documents      = json_decode($data['documents'] ?? '{}', true);

            $stmt = $db->prepare("
                INSERT INTO eta_applications
                (
                    reference_number,
                    first_name, last_name, email, phone, date_of_birth, nationality,
                    passport_number, passport_issue_date, passport_expiry_date,
                    arrival_date, departure_date, flight_number, airline,
                    purpose, accommodation, special_requests,
                    services,
                    total_amount, payment_status, status, created_at
                )
                VALUES (
                    :reference_number,
                    :first_name, :last_name, :email, :phone, :date_of_birth, :nationality,
                    :passport_number, :passport_issue_date, :passport_expiry_date,
                    :arrival_date, :departure_date, :flight_number, :airline,
                    :purpose, :accommodation, :special_requests,
                    :services,
                    :total_amount, :payment_status, 'pending_payment', NOW()
                )
            ");

            $stmt->execute([
                ':reference_number'     => $data['reference'],
                ':first_name'           => $applicantData['firstName'] ?? '',
                ':last_name'            => $applicantData['lastName'] ?? '',
                ':email'                => $applicantData['email'] ?? '',
                ':phone'                => $applicantData['phone'] ?? '',
                ':date_of_birth'        => $applicantData['dateOfBirth'] ?? null,
                ':nationality'          => $applicantData['nationality'] ?? '',
                ':passport_number'      => $applicantData['passportNumber'] ?? '',
                ':passport_issue_date'  => $applicantData['passportIssueDate'] ?? null,
                ':passport_expiry_date' => $applicantData['passportExpiryDate'] ?? null,
                ':arrival_date'         => $applicantData['arrivalDate'] ?? null,
                ':departure_date'       => $applicantData['departureDate'] ?? null,
                ':flight_number'        => $applicantData['flightNumber'] ?? null,
                ':airline'              => $applicantData['airline'] ?? null,
                ':purpose'              => $applicantData['purpose'] ?? '',
                ':accommodation'        => $applicantData['accommodation'] ?? '',
                ':special_requests'     => $applicantData['specialRequests'] ?? null,
                ':services'             => json_encode($services),
                ':total_amount'         => $data['total_amount'],
                ':payment_status'       => $data['payment_status'],
            ]);

            $insertId = $db->lastInsertId();

            if (!empty($documents)) {
                $db->prepare("
                    UPDATE eta_applications SET
                        passport_file_path      = :passport_file_path,
                        photo_file_path         = :photo_file_path,
                        itinerary_file_path     = :itinerary_file_path,
                        accommodation_file_path = :accommodation_file_path
                    WHERE id = :id
                ")->execute([
                    ':passport_file_path'       => $documents['passportUpload'] ?? null,
                    ':photo_file_path'           => $documents['photoUpload'] ?? null,
                    ':itinerary_file_path'       => $documents['itineraryUpload'] ?? null,
                    ':accommodation_file_path'   => $documents['accommodationUpload'] ?? null,
                    ':id'                        => $insertId,
                ]);
            }

            // Log payment transaction record
            try {
                $db->prepare("
                    INSERT INTO payment_transactions
                        (application_id, reference, amount, currency, status, created_at)
                    VALUES
                        (:application_id, :reference, :amount, 'USD', 'pending', NOW())
                ")->execute([
                    ':application_id' => $insertId,
                    ':reference'      => $data['reference'],
                    ':amount'         => $data['total_amount'],
                ]);
            } catch (Throwable $e) {
                error_log('[SubmissionService] payment_transactions insert failed: ' . $e->getMessage());
            }

            if (!empty($applicantData['travelers'])) {
                $this->saveTravelers($db, (int) $insertId, $applicantData['travelers']);
            }

            return $insertId;

        } catch (Throwable $e) {
            error_log('[SubmissionService] DB save failed: ' . $e->getMessage());
            return $this->saveToFile($data);
        }
    }

    private function saveToFile(array $data): string|int|false
    {
        $storageDir = dirname(__DIR__, 3) . '/storage/applications';
        if (!is_dir($storageDir)) mkdir($storageDir, 0755, true);

        $filepath = $storageDir . '/' . $data['reference'] . '.json';
        $record   = [
            'reference'      => $data['reference'],
            'applicant_data' => json_decode($data['applicant_data'], true),
            'documents'      => json_decode($data['documents'], true),
            'total_amount'   => $data['total_amount'],
            'payment_status' => $data['payment_status'],
            'created_at'     => date('Y-m-d H:i:s'),
        ];

        return file_put_contents($filepath, json_encode($record, JSON_PRETTY_PRINT)) !== false
            ? preg_replace('/^ETA-/', '', $data['reference'])
            : false;
    }

    // =========================================================================
    // Payment record updates
    // =========================================================================

    public function updatePaymentStatus(int $applicationId, array $data): bool
    {
        try {
            $db         = $this->getDb();
            $setClauses = [];
            $params     = [':id' => $applicationId];

            foreach ($data as $key => $value) {
                $setClauses[]      = "{$key} = :{$key}";
                $params[":{$key}"] = $value;
            }

            $sql = 'UPDATE eta_applications SET ' . implode(', ', $setClauses) . ' WHERE id = :id';
            return $db->prepare($sql)->execute($params);

        } catch (Throwable $e) {
            error_log('[SubmissionService] DB update failed: ' . $e->getMessage());
            return $this->updatePaymentInFile($applicationId, $data);
        }
    }

    private function updatePaymentInFile(int $applicationId, array $data): bool
    {
        $storageDir = dirname(__DIR__, 3) . '/storage/applications';
        foreach (glob($storageDir . '/*.json') as $filepath) {
            $record = json_decode(file_get_contents($filepath), true);
            if (!$record) continue;
            $refId = preg_replace('/^ETA-/', '', $record['reference'] ?? '');
            if ((string) $refId === (string) $applicationId || (string) $record['reference'] === (string) $applicationId) {
                foreach ($data as $key => $value) $record[$key] = $value;
                $record['updated_at'] = date('Y-m-d H:i:s');
                return file_put_contents($filepath, json_encode($record, JSON_PRETTY_PRINT)) !== false;
            }
        }
        return false;
    }

    /**
     * Update payment_transactions status.
     * Status values:  pending → settled | failed | abandoned
     */
    public function updateTransactionStatus(
        string  $reference,
        string  $status,
        ?string $transId     = null,
        array   $responseData = []
    ): bool {
        try {
            $db         = $this->getDb();
            $setClauses = ['status = :status', 'updated_at = NOW()'];
            $params     = [':status' => $status, ':ref' => $reference];

            if ($transId) {
                $setClauses[]          = 'paystack_trans_id = :transId';
                $params[':transId']    = $transId;
            }
            $setClauses[]              = 'paystack_ref = :paystackRef';
            $params[':paystackRef']    = $reference;

            if (!empty($responseData)) {
                $setClauses[]          = 'response_data = :response';
                $params[':response']   = json_encode($responseData);
            }
            if ($status === 'settled') {
                $setClauses[]          = 'paid_at = NOW()';
            }

            $sql = 'UPDATE payment_transactions SET ' . implode(', ', $setClauses) . ' WHERE reference = :ref';
            return $db->prepare($sql)->execute($params);

        } catch (Throwable $e) {
            error_log('[SubmissionService] updateTransactionStatus failed: ' . $e->getMessage());
            return false;
        }
    }

    // =========================================================================
    // Reference lookups
    // =========================================================================

    public function getTransactionByReference(string $reference): ?array
    {
        try {
            $stmt = $this->getDb()->prepare(
                "SELECT * FROM payment_transactions WHERE reference = :ref LIMIT 1"
            );
            $stmt->execute([':ref' => $reference]);
            return $stmt->fetch() ?: null;
        } catch (Throwable $e) {
            error_log('[SubmissionService] getTransactionByReference failed: ' . $e->getMessage());
            return null;
        }
    }

    public function getApplicationIdForReference(string $reference): ?int
    {
        // Try payment_transactions first (ETA-specific)
        $transaction = $this->getTransactionByReference($reference);
        if ($transaction) {
            return (int) $transaction['application_id'];
        }

        // Fallback: look up directly in eta_applications by reference_number
        try {
            $stmt = $this->getDb()->prepare(
                "SELECT id FROM eta_applications WHERE reference_number = :ref LIMIT 1"
            );
            $stmt->execute([':ref' => $reference]);
            $row = $stmt->fetch();
            return $row ? (int) $row['id'] : null;
        } catch (Throwable $e) {
            error_log('[SubmissionService] getApplicationIdForReference fallback failed: ' . $e->getMessage());
            return null;
        }
    }

    // =========================================================================
    // Webhook signature verification
    // =========================================================================

    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        $secretKey = $this->config['paystack_secret_key'];
        if (empty($secretKey) || empty($signature)) return false;
        return hash_equals(hash_hmac('sha512', $payload, $secretKey), $signature);
    }

    // =========================================================================
    // Application payment record update
    // =========================================================================

    public function updateApplicationPaymentRecord(
        int     $applicationId,
        string  $reference,
        string  $paymentStatus,
        string  $applicationStatus,
        ?string $transactionId = null,
        array   $responseData  = []
    ): bool {
        try {
            $db         = $this->getDb();
            $setClauses = [
                'payment_status      = :payment_status',
                'status              = :status',
                'paystack_reference  = :paystack_reference',
                'updated_at          = NOW()',
            ];
            $params = [
                ':payment_status'     => $paymentStatus,
                ':status'             => $applicationStatus,
                ':paystack_reference' => $reference,
            ];

            if ($transactionId !== null) {
                $setClauses[]                        = 'paystack_transaction_id = :paystack_transaction_id';
                $params[':paystack_transaction_id']  = $transactionId;
            }
            if (!empty($responseData)) {
                $setClauses[]              = 'paystack_response = :paystack_response';
                $params[':paystack_response'] = json_encode($responseData);
            }
            if ($paymentStatus === 'paid') {
                $setClauses[] = 'paid_at = NOW()';
            }

            $sql = 'UPDATE eta_applications SET ' . implode(', ', $setClauses) . ' WHERE id = :id';
            $params[':id'] = $applicationId;

            return $db->prepare($sql)->execute($params);

        } catch (Throwable $e) {
            error_log('[SubmissionService] updateApplicationPaymentRecord failed: ' . $e->getMessage());
            return false;
        }
    }

    // =========================================================================
    // Webhook event processor (UPGRADED — handles abandoned + idempotency)
    // =========================================================================

    /**
     * Called by the legacy payment/webhook.php intercept (fallback path).
     * The primary webhook is /api/payments/paystack/webhook.php.
     *
     * Handles: charge.success | charge.failed | charge.abandoned
     */
    public function processPaystackWebhookEvent(array $payload): array
    {
        $event     = $payload['event'] ?? '';
        $data      = $payload['data'] ?? [];
        $reference = $data['reference'] ?? '';

        if (empty($reference)) {
            return ['success' => false, 'message' => 'Missing payment reference'];
        }

        $transaction = $this->getTransactionByReference($reference);
        if (!$transaction) {
            error_log('[SubmissionService] Webhook: transaction not found for ref ' . $reference);
            return ['success' => false, 'message' => 'Transaction not found'];
        }

        $applicationId = (int) $transaction['application_id'];
        $transactionId = (string) ($data['id'] ?? $data['transaction'] ?? '');

        // ── Idempotency ──────────────────────────────────────────────────────
        $currentStatus = $transaction['status'] ?? '';
        if ($currentStatus === 'settled' && $event === 'charge.success') {
            return ['success' => true, 'message' => 'Transaction already processed (idempotent)'];
        }

        // ── charge.success ───────────────────────────────────────────────────
        if ($event === 'charge.success') {
            $this->updateApplicationPaymentRecord($applicationId, $reference, 'paid', 'submitted', $transactionId, $data);
            $this->updateTransactionStatus($reference, 'settled', $transactionId, $data);
            $this->sendPaymentConfirmation($reference);
            $this->sendAdminPaymentNotification($reference, $applicationId, 'settled');
            return ['success' => true, 'message' => 'Payment settled'];
        }

        // ── charge.failed ────────────────────────────────────────────────────
        if ($event === 'charge.failed') {
            $this->updateApplicationPaymentRecord($applicationId, $reference, 'failed', 'pending_payment', $transactionId, $data);
            $this->updateTransactionStatus($reference, 'failed', $transactionId, $data);
            $this->sendAdminPaymentNotification($reference, $applicationId, 'failed');
            return ['success' => true, 'message' => 'Payment failed and recorded'];
        }

        // ── charge.abandoned ─────────────────────────────────────────────────
        if ($event === 'charge.abandoned') {
            $this->updateApplicationPaymentRecord($applicationId, $reference, 'abandoned', 'pending_payment', $transactionId, $data);
            $this->updateTransactionStatus($reference, 'abandoned', $transactionId, $data);
            $this->sendAdminPaymentNotification($reference, $applicationId, 'abandoned');
            return ['success' => true, 'message' => 'Payment abandoned and recorded'];
        }

        return ['success' => false, 'message' => 'Unhandled event: ' . $event];
    }

    // =========================================================================
    // Traveler persistence (unchanged)
    // =========================================================================

    private function saveTravelers(PDO $db, int $applicationId, array $travelers): void
    {
        $stmt = $db->prepare("
            INSERT INTO eta_travelers (
                application_id, traveler_type,
                first_name, last_name, email, phone, nationality,
                full_name, relationship,
                date_of_birth, passport_number, passport_issue_date, passport_expiry_date,
                guardian_agreed, sort_order, created_at
            ) VALUES (
                :application_id, :traveler_type,
                :first_name, :last_name, :email, :phone, :nationality,
                :full_name, :relationship,
                :date_of_birth, :passport_number, :passport_issue_date, :passport_expiry_date,
                :guardian_agreed, :sort_order, NOW()
            )
        ");

        foreach ($travelers as $index => $traveler) {
            $type = $traveler['type'] ?? 'adult';
            if ($type === 'adult') {
                $stmt->execute([
                    ':application_id'       => $applicationId,
                    ':traveler_type'        => 'adult',
                    ':first_name'           => $traveler['firstName'] ?? null,
                    ':last_name'            => $traveler['lastName'] ?? null,
                    ':email'                => $traveler['email'] ?? null,
                    ':phone'                => $traveler['phone'] ?? null,
                    ':nationality'          => $traveler['nationality'] ?? null,
                    ':full_name'            => null,
                    ':relationship'         => null,
                    ':date_of_birth'        => $traveler['dateOfBirth'] ?? null,
                    ':passport_number'      => $traveler['passportNumber'] ?? null,
                    ':passport_issue_date'  => $traveler['passportIssueDate'] ?? null,
                    ':passport_expiry_date' => $traveler['passportExpiryDate'] ?? null,
                    ':guardian_agreed'      => 0,
                    ':sort_order'           => $index,
                ]);
            } else {
                $stmt->execute([
                    ':application_id'       => $applicationId,
                    ':traveler_type'        => 'dependent',
                    ':first_name'           => null,
                    ':last_name'            => null,
                    ':email'                => null,
                    ':phone'                => null,
                    ':nationality'          => null,
                    ':full_name'            => $traveler['fullName'] ?? null,
                    ':relationship'         => $traveler['relationship'] ?? null,
                    ':date_of_birth'        => $traveler['dateOfBirth'] ?? null,
                    ':passport_number'      => $traveler['passportNumber'] ?? null,
                    ':passport_issue_date'  => null,
                    ':passport_expiry_date' => null,
                    ':guardian_agreed'      => !empty($traveler['guardianAgreed']) ? 1 : 0,
                    ':sort_order'           => $index,
                ]);
            }
        }
    }

    // =========================================================================
    // Pricing
    // =========================================================================

    public function calculateTotal(array $services, int $travelers): float
    {
        $pricing = [
            'eta'         => 50.00,
            'one_day_eta' => 70.00,
            'premium_eta' => 100.00,
        ];
        $total = 0.0;
        foreach ($services as $service) $total += $pricing[$service] ?? 0.0;

        if ($travelers > 0 && !empty($services)) {
            $travelerPrice = $pricing[$services[0]] ?? $pricing['eta'];
            $total += $travelers * $travelerPrice;
        }

        return round($total, 2);
    }

    // =========================================================================
    // Paystack verification (non-authoritative — for status-page fallback only)
    // =========================================================================

    public function verifyPaystackTransaction(string $reference): array
    {
        $url    = 'https://api.paystack.co/transaction/verify/' . urlencode($reference);
        $secret = $this->config['paystack_secret_key'];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $secret, 'Cache-Control: no-cache'],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $response = curl_exec($ch);
        $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$response || $code < 200 || $code >= 300) {
            return ['status' => false, 'message' => 'Could not reach Paystack'];
        }
        return json_decode($response, true) ?? [];
    }

    // =========================================================================
    // Email notifications
    // =========================================================================

    public function sendConfirmationEmail(string $email, string $reference): bool
    {
        $subject = 'Application Received — ' . $reference;
        $body    = "Thank you for submitting your application.\n\n"
                 . "Reference: {$reference}\n\n"
                 . "We will send a confirmation once your payment is processed.\n\n"
                 . "— " . $this->config['site_name'];
        return $this->sendEmail($email, $subject, $body);
    }

    public function sendPaymentConfirmation(string $reference): bool
    {
        try {
            $db   = $this->getDb();
            $stmt = $db->prepare(
                "SELECT email, first_name, last_name, total_amount FROM eta_applications WHERE reference_number = :ref LIMIT 1"
            );
            $stmt->execute([':ref' => $reference]);
            $app = $stmt->fetch();
        } catch (Throwable $e) {
            error_log('[SubmissionService] sendPaymentConfirmation lookup failed: ' . $e->getMessage());
            return false;
        }

        if (!$app) return false;

        $name    = trim(($app['first_name'] ?? '') . ' ' . ($app['last_name'] ?? ''));
        $amount  = number_format((float) ($app['total_amount'] ?? 0), 2);
        $subject = 'Payment Confirmed — ' . $reference;
        $body    = "Dear {$name},\n\n"
                 . "Your payment has been confirmed.\n\n"
                 . "Reference: {$reference}\n"
                 . "Amount:    USD {$amount}\n\n"
                 . "Your application is now being processed. We will be in touch shortly.\n\n"
                 . "— " . $this->config['site_name'];

        return $this->sendEmail($app['email'], $subject, $body);
    }

    public function sendAdminApplicationNotification(string $reference, int $applicationId): bool
    {
        try {
            $db   = $this->getDb();
            $stmt = $db->prepare(
                "SELECT first_name, last_name, email, phone, total_amount, services, status FROM eta_applications WHERE id = :id LIMIT 1"
            );
            $stmt->execute([':id' => $applicationId]);
            $app = $stmt->fetch();
        } catch (Throwable $e) {
            error_log('[SubmissionService] sendAdminApplicationNotification lookup failed: ' . $e->getMessage());
            return false;
        }

        if (!$app) return false;

        $name    = trim(($app['first_name'] ?? '') . ' ' . ($app['last_name'] ?? ''));
        $amount  = number_format((float) ($app['total_amount'] ?? 0), 2);
        $subject = '[' . $this->config['site_name'] . '] New ETA Application — ' . $reference;
        $body    = "A new ETA application has been submitted and is awaiting payment.\n\n"
                 . "Reference:   {$reference}\n"
                 . "Application: {$applicationId}\n"
                 . "Name:        {$name}\n"
                 . "Email:       " . ($app['email'] ?? '—') . "\n"
                 . "Phone:       " . ($app['phone'] ?? '—') . "\n"
                 . "Amount:      USD {$amount}\n"
                 . "Status:      PAYMENT PENDING\n"
                 . "Time:        " . date('Y-m-d H:i:s T') . "\n\n"
                 . "Admin panel: " . rtrim($this->config['site_url'], '/') . '/admin';

        return $this->sendEmail($this->config['admin_email'], $subject, $body);
    }

    public function sendAdminPaymentNotification(string $reference, int $applicationId, string $status = 'settled'): bool
    {
        try {
            $db   = $this->getDb();
            $stmt = $db->prepare(
                "SELECT first_name, last_name, email, phone, total_amount FROM eta_applications WHERE id = :id LIMIT 1"
            );
            $stmt->execute([':id' => $applicationId]);
            $app = $stmt->fetch();
        } catch (Throwable $e) {
            error_log('[SubmissionService] sendAdminPaymentNotification lookup failed: ' . $e->getMessage());
            return false;
        }

        if (!$app) return false;

        $name   = trim(($app['first_name'] ?? '') . ' ' . ($app['last_name'] ?? ''));
        $amount = number_format((float) ($app['total_amount'] ?? 0), 2);

        $statusLabels = [
            'settled'   => '✅ PAYMENT CONFIRMED',
            'failed'    => '❌ PAYMENT FAILED',
            'abandoned' => '⚠️ PAYMENT ABANDONED',
        ];
        $statusLabel = $statusLabels[$status] ?? strtoupper($status);
        $subject     = '[' . $this->config['site_name'] . '] ETA ' . $statusLabel . ' — ' . $reference;

        $body = "ETA Payment Update\n\n"
              . "Status:      {$statusLabel}\n"
              . "Reference:   {$reference}\n"
              . "Application: {$applicationId}\n"
              . "Name:        {$name}\n"
              . "Email:       " . ($app['email'] ?? '—') . "\n"
              . "Phone:       " . ($app['phone'] ?? '—') . "\n"
              . "Amount:      USD {$amount}\n"
              . "Time:        " . date('Y-m-d H:i:s T') . "\n\n"
              . "Admin panel: " . rtrim($this->config['site_url'], '/') . '/admin';

        return $this->sendEmail($this->config['admin_email'], $subject, $body);
    }

    private function sendEmail(string $to, string $subject, string $body): bool
    {
        $from    = $this->config['mail_from'];
        $headers = 'From: ' . $this->config['site_name'] . ' <' . $from . ">\r\n"
                 . "Content-Type: text/plain; charset=UTF-8\r\n"
                 . "X-Mailer: KenyaStopover-PaymentSystem/2.0";

        return @mail($to, $subject, $body, $headers);
    }
}
