<?php
namespace modules\applicationform\services;

use PDO;

class SubmissionService
{
    private ?PDO $db = null;
    private array $config;

    public function __construct()
    {
        $this->config = $this->loadConfig();
    }

    private function getDb(): PDO
    {
        if ($this->db === null) {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=utf8mb4',
                $this->config['db_host'] ?? 'localhost',
                $this->config['db_name'] ?? 'craft_db'
            );

            $this->db = new PDO($dsn, $this->config['db_user'] ?? 'root', $this->config['db_pass'] ?? '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }

        return $this->db;
    }

    private function loadConfig(): array
    {
        // Try Craft's db.php config first
        $dbConfigFile = dirname(__DIR__, 3) . '/config/db.php';
        $craftDbConfig = [];
        if (file_exists($dbConfigFile)) {
            $craftDbConfig = include $dbConfigFile;
        }

        // Try .env file
        $envFile = dirname(__DIR__, 3) . '/.env';
        $envConfig = [];
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos(trim($line), '#') === 0) continue;
                if (strpos($line, '=') !== false) {
                    [$key, $value] = explode('=', $line, 2);
                    $envConfig[trim($key)] = trim($value, " \"'\t\n\r\0\x0B");
                }
            }
        }

        return [
            'db_host' => getenv('APP_DB_HOST') ?: $envConfig['APP_DB_HOST'] ?? 'localhost',
            'db_name' => getenv('APP_DB_NAME') ?: $envConfig['APP_DB_NAME'] ?? 'kenyastopover_db',
            'db_user' => getenv('APP_DB_USER') ?: $envConfig['APP_DB_USER'] ?? 'root',
            'db_pass' => getenv('APP_DB_PASS') ?: $envConfig['APP_DB_PASS'] ?? '',
            'paystack_secret_key' => getenv('PAYSTACK_SECRET_KEY') ?: $envConfig['PAYSTACK_SECRET_KEY'] ?? '',
            'mail_from' => getenv('MAIL_FROM') ?: $envConfig['MAIL_FROM'] ?? 'noreply@kenyastopover.com',
            'admin_email' => getenv('ADMIN_EMAIL') ?: $envConfig['ADMIN_EMAIL'] ?? 'info@kenyastopover.com',
        ];
    }

    public function saveApplication(array $data): string|int|false
    {
        try {
            $db = $this->getDb();
            $applicantData = json_decode($data['applicant_data'] ?? '{}', true);
            $services = $applicantData['services'] ?? [];
            $documents = json_decode($data['documents'] ?? '{}', true);

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
                ':reference_number'   => $data['reference'],
                ':first_name'         => $applicantData['firstName'] ?? '',
                ':last_name'          => $applicantData['lastName'] ?? '',
                ':email'              => $applicantData['email'] ?? '',
                ':phone'              => $applicantData['phone'] ?? '',
                ':date_of_birth'      => $applicantData['dateOfBirth'] ?? null,
                ':nationality'        => $applicantData['nationality'] ?? '',
                ':passport_number'    => $applicantData['passportNumber'] ?? '',
                ':passport_issue_date'=> $applicantData['passportIssueDate'] ?? null,
                ':passport_expiry_date' => $applicantData['passportExpiryDate'] ?? null,
                ':arrival_date'       => $applicantData['arrivalDate'] ?? null,
                ':departure_date'     => $applicantData['departureDate'] ?? null,
                ':flight_number'      => $applicantData['flightNumber'] ?? null,
                ':airline'            => $applicantData['airline'] ?? null,
                ':purpose'            => $applicantData['purpose'] ?? '',
                ':accommodation'      => $applicantData['accommodation'] ?? '',
                ':special_requests'   => $applicantData['specialRequests'] ?? null,
                ':services'           => json_encode($services),
                ':total_amount'       => $data['total_amount'],
                ':payment_status'     => $data['payment_status'],
            ]);

            $insertId = $db->lastInsertId();

            // Save uploaded file paths
            if (!empty($documents)) {
                $updateStmt = $db->prepare("
                    UPDATE eta_applications SET
                    passport_file_path = :passport_file_path,
                    photo_file_path = :photo_file_path,
                    itinerary_file_path = :itinerary_file_path,
                    accommodation_file_path = :accommodation_file_path
                    WHERE id = :id
                ");
                $updateStmt->execute([
                    ':passport_file_path' => $documents['passportUpload'] ?? null,
                    ':photo_file_path'    => $documents['photoUpload'] ?? null,
                    ':itinerary_file_path' => $documents['itineraryUpload'] ?? null,
                    ':accommodation_file_path' => $documents['accommodationUpload'] ?? null,
                    ':id' => $insertId,
                ]);
            }

            // Log payment transaction
            try {
                $txnStmt = $db->prepare("
                    INSERT INTO payment_transactions
                    (application_id, reference, amount, currency, status, created_at)
                    VALUES (:application_id, :reference, :amount, 'USD', 'pending', NOW())
                ");
                $txnStmt->execute([
                    ':application_id' => $insertId,
                    ':reference' => $data['reference'],
                    ':amount' => $data['total_amount'],
                ]);
            } catch (\Throwable $e) {
                error_log('Failed to create payment transaction record: ' . $e->getMessage());
            }

            // Save travelers to eta_travelers table
            if (!empty($applicantData['travelers'])) {
                $this->saveTravelers($db, $insertId, $applicantData['travelers']);
            }

            return $insertId;
        } catch (\Throwable $e) {
            error_log('DB save failed, falling back to file: ' . $e->getMessage());
            return $this->saveToFile($data);
        }
    }

    private function saveToFile(array $data): string|int|false
    {
        $storageDir = dirname(__DIR__, 3) . '/storage/applications';
        if (!is_dir($storageDir)) mkdir($storageDir, 0755, true);

        $filepath = $storageDir . '/' . $data['reference'] . '.json';
        $record = [
            'reference' => $data['reference'],
            'applicant_data' => json_decode($data['applicant_data'], true),
            'documents' => json_decode($data['documents'], true),
            'total_amount' => $data['total_amount'],
            'payment_status' => $data['payment_status'],
            'created_at' => date('Y-m-d H:i:s'),
        ];

        if (file_put_contents($filepath, json_encode($record, JSON_PRETTY_PRINT)) === false) {
            return false;
        }

        return preg_replace('/^ETA-/', '', $data['reference']);
    }

    public function updatePaymentStatus(int $applicationId, array $data): bool
    {
        try {
            $db = $this->getDb();
            $setClauses = [];
            $params = [':id' => $applicationId];
            foreach ($data as $key => $value) {
                $setClauses[] = "{$key} = :{$key}";
                $params[":{$key}"] = $value;
            }

            $sql = "UPDATE eta_applications SET " . implode(', ', $setClauses) . " WHERE id = :id";
            return $db->prepare($sql)->execute($params);
        } catch (\Throwable $e) {
            error_log('DB update failed, falling back to file: ' . $e->getMessage());
            return $this->updatePaymentInFile($applicationId, $data);
        }
    }

    private function updatePaymentInFile(int $applicationId, array $data): bool
    {
        $storageDir = dirname(__DIR__, 3) . '/storage/applications';
        $files = glob($storageDir . '/*.json');
        foreach ($files as $filepath) {
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
     * Update the payment_transactions record status after Paystack verification.
     * Status flows: pending → settled / failed
     */
    public function updateTransactionStatus(string $reference, string $status, ?string $transId = null, array $responseData = []): bool
    {
        try {
            $db = $this->getDb();
            $setClauses = ['status = :status', 'updated_at = NOW()'];
            $params = [':status' => $status, ':ref' => $reference];

            if ($transId) {
                $setClauses[] = 'paystack_trans_id = :transId';
                $params[':transId'] = $transId;
            }
            $setClauses[] = 'paystack_ref = :paystackRef';
            $params[':paystackRef'] = $reference;
            if (!empty($responseData)) {
                $setClauses[] = 'response_data = :response';
                $params[':response'] = json_encode($responseData);
            }
            if ($status === 'settled') {
                $setClauses[] = 'paid_at = NOW()';
            }

            $sql = "UPDATE payment_transactions SET " . implode(', ', $setClauses) . " WHERE reference = :ref";
            return $db->prepare($sql)->execute($params);
        } catch (\Throwable $e) {
            error_log('Failed to update transaction status: ' . $e->getMessage());
            return false;
        }
    }

    public function getTransactionByReference(string $reference): ?array
    {
        try {
            $db = $this->getDb();
            $stmt = $db->prepare("SELECT * FROM payment_transactions WHERE reference = :ref LIMIT 1");
            $stmt->execute([':ref' => $reference]);
            $row = $stmt->fetch();
            return $row ?: null;
        } catch (\Throwable $e) {
            error_log('Failed to load transaction by reference: ' . $e->getMessage());
            return null;
        }
    }

    public function getApplicationIdForReference(string $reference): ?int
    {
        $transaction = $this->getTransactionByReference($reference);
        return $transaction ? (int) $transaction['application_id'] : null;
    }

    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        $secretKey = $this->config['paystack_secret_key'];
        if (empty($secretKey) || empty($signature)) {
            return false;
        }

        return hash_equals(hash_hmac('sha512', $payload, $secretKey), $signature);
    }

    public function updateApplicationPaymentRecord(int $applicationId, string $reference, string $paymentStatus, string $applicationStatus, ?string $transactionId = null, array $responseData = []): bool
    {
        try {
            $db = $this->getDb();
            $setClauses = [
                'payment_status = :payment_status',
                'status = :status',
                'paystack_reference = :paystack_reference',
                'updated_at = NOW()',
            ];
            $params = [
                ':payment_status' => $paymentStatus,
                ':status' => $applicationStatus,
                ':paystack_reference' => $reference,
            ];

            if ($transactionId !== null) {
                $setClauses[] = 'paystack_transaction_id = :paystack_transaction_id';
                $params[':paystack_transaction_id'] = $transactionId;
            }

            if (!empty($responseData)) {
                $setClauses[] = 'paystack_response = :paystack_response';
                $params[':paystack_response'] = json_encode($responseData);
            }

            if ($paymentStatus === 'paid') {
                $setClauses[] = 'paid_at = NOW()';
            }

            $sql = 'UPDATE eta_applications SET ' . implode(', ', $setClauses) . ' WHERE id = :id';
            $params[':id'] = $applicationId;

            return $db->prepare($sql)->execute($params);
        } catch (\Throwable $e) {
            error_log('Failed to update application payment record: ' . $e->getMessage());
            return false;
        }
    }

    public function processPaystackWebhookEvent(array $payload): array
    {
        $event = $payload['event'] ?? '';
        $data = $payload['data'] ?? [];
        $reference = $data['reference'] ?? '';

        if (empty($reference)) {
            return ['success' => false, 'message' => 'Missing payment reference'];
        }

        $transaction = $this->getTransactionByReference($reference);
        if (!$transaction) {
            return ['success' => false, 'message' => 'Transaction not found'];
        }

        $applicationId = (int) $transaction['application_id'];
        $transactionId = $data['id'] ?? $data['transaction'] ?? null;
        $status = strtolower($event === 'charge.success' ? 'settled' : ($event === 'charge.failed' ? 'failed' : 'pending'));
        $paymentStatus = $status === 'settled' ? 'paid' : ($status === 'failed' ? 'failed' : 'pending');

        if ($transaction['status'] === 'settled' && $status === 'settled') {
            return ['success' => true, 'message' => 'Transaction already processed'];
        }

        if ($event === 'charge.success') {
            $this->updateApplicationPaymentRecord($applicationId, $reference, $paymentStatus, 'submitted', $transactionId, $data);
            $this->updateTransactionStatus($reference, $status, $transactionId, $data);
            $this->sendPaymentConfirmation($reference);
            $this->sendAdminPaymentNotification($reference, $applicationId, 'settled');
            return ['success' => true, 'message' => 'Payment settled successfully'];
        }

        if ($event === 'charge.failed') {
            $this->updateApplicationPaymentRecord($applicationId, $reference, $paymentStatus, 'pending_payment', $transactionId, $data);
            $this->updateTransactionStatus($reference, $status, $transactionId, $data);
            $this->sendAdminPaymentNotification($reference, $applicationId, 'failed');
            return ['success' => true, 'message' => 'Payment failed and has been recorded'];
        }

        return ['success' => false, 'message' => 'Unhandled webhook event'];
    }

    /**
     * Save all travelers to the eta_travelers table
     */
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
                    ':application_id'     => $applicationId,
                    ':traveler_type'      => 'adult',
                    ':first_name'         => $traveler['firstName'] ?? null,
                    ':last_name'          => $traveler['lastName'] ?? null,
                    ':email'              => $traveler['email'] ?? null,
                    ':phone'              => $traveler['phone'] ?? null,
                    ':nationality'        => $traveler['nationality'] ?? null,
                    ':full_name'          => null,
                    ':relationship'       => null,
                    ':date_of_birth'      => $traveler['dateOfBirth'] ?? null,
                    ':passport_number'    => $traveler['passportNumber'] ?? null,
                    ':passport_issue_date'=> $traveler['passportIssueDate'] ?? null,
                    ':passport_expiry_date' => $traveler['passportExpiryDate'] ?? null,
                    ':guardian_agreed'    => 0,
                    ':sort_order'         => $index,
                ]);
            } else {
                $stmt->execute([
                    ':application_id'     => $applicationId,
                    ':traveler_type'      => 'dependent',
                    ':first_name'         => null,
                    ':last_name'          => null,
                    ':email'              => null,
                    ':phone'              => null,
                    ':nationality'        => null,
                    ':full_name'          => $traveler['fullName'] ?? null,
                    ':relationship'       => $traveler['relationship'] ?? null,
                    ':date_of_birth'      => $traveler['dateOfBirth'] ?? null,
                    ':passport_number'    => $traveler['passportNumber'] ?? null,
                    ':passport_issue_date'=> null,
                    ':passport_expiry_date' => null,
                    ':guardian_agreed'    => !empty($traveler['guardianAgreed']) ? 1 : 0,
                    ':sort_order'         => $index,
                ]);
            }
        }
    }

    /**
     * Calculate total — dynamic pricing based on selected service
     * Travelers inherit the price of the first selected service
     */
    public function calculateTotal(array $services, int $travelers): float
    {
        $pricing = ['eta' => 50.00, 'one_day_eta' => 70.00, 'premium_eta' => 100.00];
        $total = 0;
        foreach ($services as $service) $total += $pricing[$service] ?? 0;

        // Travelers inherit the price of the first selected service
        if ($travelers > 0 && !empty($services)) {
            $travelerPrice = $pricing[$services[0]] ?? $pricing['eta'];
            $total += ($travelers * $travelerPrice);
        }
        return round($total, 2);
    }

    public function verifyPaystackTransaction(string $reference): array
    {
        $secretKey = $this->config['paystack_secret_key'];
        if (empty($secretKey)) return ['status' => 'error', 'message' => 'Payment gateway not configured'];

        $safeReference = rawurlencode($reference);
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => "https://api.paystack.co/transaction/verify/{$safeReference}",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$secretKey}", "Cache-Control: no-cache"],
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || $httpCode !== 200) return ['status' => 'error', 'message' => 'API request failed'];
        return json_decode($response, true) ?? ['status' => 'error'];
    }

    public function sendConfirmationEmail(string $email, string $reference): bool
    {
        $subject = 'eTA Application Received - Reference: ' . $reference;
        $body = "<p>Thank you! Your reference: <strong>{$reference}</strong></p>";
        return $this->sendEmail($email, $subject, $body);
    }

    public function sendPaymentConfirmation(string $reference): bool
    {
        $email = null;
        try {
            $db = $this->getDb();
            $stmt = $db->prepare("SELECT email, first_name, last_name FROM eta_applications WHERE paystack_reference = :ref LIMIT 1");
            $stmt->execute([':ref' => $reference]);
            $row = $stmt->fetch();
            if ($row) $email = $row['email'] ?? null;
        } catch (\Throwable $e) {
            $storageDir = dirname(__DIR__, 3) . '/storage/applications';
            foreach (glob($storageDir . '/*.json') as $filepath) {
                $record = json_decode(file_get_contents($filepath), true);
                if ($record && ($record['paystack_reference'] ?? '') === $reference) {
                    $email = $record['applicant_data']['email'] ?? null;
                    break;
                }
            }
        }
        if (!$email) return false;
        return $this->sendEmail($email, 'eTA Payment Confirmed - Reference: ' . $reference, "<p>Payment verified. Reference: <strong>{$reference}</strong></p>");
    }

    /**
     * Send admin notification when a new application is submitted (payment pending)
     */
    public function sendAdminApplicationNotification(string $reference, int $applicationId): bool
    {
        $adminEmail = $this->config['admin_email'] ?? '';
        if (empty($adminEmail)) {
            error_log('Application admin notification skipped: ADMIN_EMAIL not configured');
            return false;
        }

        $applicantName = '';
        $totalAmount = '';
        $email = '';

        try {
            $db = $this->getDb();
            $stmt = $db->prepare("
                SELECT first_name, last_name, email, total_amount, payment_status
                FROM eta_applications WHERE id = :id LIMIT 1
            ");
            $stmt->execute([':id' => $applicationId]);
            $row = $stmt->fetch();
            if ($row) {
                $applicantName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                $totalAmount = $row['total_amount'] ?? '';
                $email = $row['email'] ?? '';
            }
        } catch (\Throwable $e) {
            // Fallback to file storage lookup
            $storageDir = dirname(__DIR__, 3) . '/storage/applications';
            foreach (glob($storageDir . '/*.json') as $filepath) {
                $record = json_decode(file_get_contents($filepath), true);
                $refId = preg_replace('/^ETA-/', '', $record['reference'] ?? '');
                if ((string) $refId === (string) $applicationId || ($record['reference'] ?? '') === $reference) {
                    $data = $record['applicant_data'] ?? [];
                    $applicantName = trim(($data['firstName'] ?? '') . ' ' . ($data['lastName'] ?? ''));
                    $totalAmount = $record['total_amount'] ?? '';
                    $email = $data['email'] ?? '';
                    break;
                }
            }
        }

        $subject = '📋 New eTA Application — Ref: ' . $reference;
        $body = <<<HTML
        <p>A new eTA application has been submitted and is awaiting payment.</p>
        <table style="border-collapse:collapse;font-size:14px;">
            <tr><td style="padding:6px 12px 6px 0;font-weight:bold;color:#0A3D62;">Reference:</td><td>{$reference}</td></tr>
            <tr><td style="padding:6px 12px 6px 0;font-weight:bold;color:#0A3D62;">Applicant:</td><td>{$applicantName}</td></tr>
            <tr><td style="padding:6px 12px 6px 0;font-weight:bold;color:#0A3D62;">Email:</td><td>{$email}</td></tr>
            <tr><td style="padding:6px 12px 6px 0;font-weight:bold;color:#0A3D62;">Amount:</td><td>{$totalAmount}</td></tr>
            <tr><td style="padding:6px 12px 6px 0;font-weight:bold;color:#0A3D62;">Status:</td><td style="color:#E8A838;font-weight:bold;">Pending Payment ⏳</td></tr>
        </table>
        HTML;

        $sent = $this->sendEmail($adminEmail, $subject, $body);
        if (!$sent) {
            error_log("Application admin notification failed for: {$adminEmail}");
        }
        return $sent;
    }

    /**
     * Send admin notification email after successful payment
     */
    public function sendAdminPaymentNotification(string $reference, int $applicationId, string $status = 'settled'): bool
    {
        $adminEmail = $this->config['admin_email'] ?? '';
        if (empty($adminEmail)) {
            error_log('Payment admin notification skipped: ADMIN_EMAIL not configured');
            return false;
        }

        $applicantName = '';
        $totalAmount = '';
        $email = '';

        try {
            $db = $this->getDb();
            $stmt = $db->prepare("
                SELECT first_name, last_name, email, total_amount, payment_status
                FROM eta_applications WHERE id = :id LIMIT 1
            ");
            $stmt->execute([':id' => $applicationId]);
            $row = $stmt->fetch();
            if ($row) {
                $applicantName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                $totalAmount = $row['total_amount'] ?? '';
                $email = $row['email'] ?? '';
            }
        } catch (\Throwable $e) {
            // Fallback to file storage lookup
            $storageDir = dirname(__DIR__, 3) . '/storage/applications';
            foreach (glob($storageDir . '/*.json') as $filepath) {
                $record = json_decode(file_get_contents($filepath), true);
                $refId = preg_replace('/^ETA-/', '', $record['reference'] ?? '');
                if ((string) $refId === (string) $applicationId || ($record['reference'] ?? '') === $reference) {
                    $data = $record['applicant_data'] ?? [];
                    $applicantName = trim(($data['firstName'] ?? '') . ' ' . ($data['lastName'] ?? ''));
                    $totalAmount = $record['total_amount'] ?? '';
                    $email = $data['email'] ?? '';
                    break;
                }
            }
        }

        $statusLabels = [
            'settled'  => '<span style="color:#2D7D4F;font-weight:bold;">Paid ✓</span>',
            'failed'   => '<span style="color:#C7433D;font-weight:bold;">Failed ✗</span>',
            'refunded' => '<span style="color:#E8A838;font-weight:bold;">Refunded ↩</span>',
        ];
        $statusHtml = $statusLabels[$status] ?? $statusLabels['settled'];

        $subject = '💳 eTA Payment ' . ucfirst($status) . ' — Ref: ' . $reference;
        $body = <<<HTML
        <p>An eTA application payment has been processed.</p>
        <table style="border-collapse:collapse;font-size:14px;">
            <tr><td style="padding:6px 12px 6px 0;font-weight:bold;color:#0A3D62;">Reference:</td><td>{$reference}</td></tr>
            <tr><td style="padding:6px 12px 6px 0;font-weight:bold;color:#0A3D62;">Applicant:</td><td>{$applicantName}</td></tr>
            <tr><td style="padding:6px 12px 6px 0;font-weight:bold;color:#0A3D62;">Email:</td><td>{$email}</td></tr>
            <tr><td style="padding:6px 12px 6px 0;font-weight:bold;color:#0A3D62;">Amount:</td><td>{$totalAmount}</td></tr>
            <tr><td style="padding:6px 12px 6px 0;font-weight:bold;color:#0A3D62;">Status:</td><td>{$statusHtml}</td></tr>
        </table>
        HTML;

        $sent = $this->sendEmail($adminEmail, $subject, $body);
        if (!$sent) {
            error_log("Payment admin notification failed for: {$adminEmail}");
        }
        return $sent;
    }

    private function sendEmail(string $to, string $subject, string $body): bool
    {
        $from = $this->config['mail_from'];
        $headers = "From: {$from}\r\nReply-To: {$from}\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
        return mail($to, $subject, $body, $headers);
    }

        /**
     * Save Meet & Greet booking to database
     */
    public function saveMeetGreetBooking(array $data): string|int|false
    {
        try {
            $db = $this->getDb();
            $passengerData = json_decode($data['passenger_data'] ?? '{}', true);

            $stmt = $db->prepare("
                INSERT INTO meet_greet_bookings
                (
                    reference_number, service_type,
                    first_name, last_name, pager_name, email, phone,
                    flight_number, flight_class, flight_date, flight_time, terminal,
                    adults, children, infants, total_bags, special_assistance,
                    additional_info,
                    total_amount, payment_status, status,
                    paystack_reference, paystack_transaction_id, paystack_response,
                    created_at
                )
                VALUES (
                    :reference_number, :service_type,
                    :first_name, :last_name, :pager_name, :email, :phone,
                    :flight_number, :flight_class, :flight_date, :flight_time, :terminal,
                    :adults, :children, :infants, :total_bags, :special_assistance,
                    :additional_info,
                    :total_amount, :payment_status, 'pending_payment',
                    :paystack_reference, :paystack_transaction_id, :paystack_response,
                    NOW()
                )
            ");

            $stmt->execute([
                ':reference_number' => $data['reference'],
                ':service_type' => $data['service_type'],
                ':first_name' => $passengerData['firstName'] ?? '',
                ':last_name' => $passengerData['lastName'] ?? '',
                ':pager_name' => $passengerData['pagerName'] ?? '',
                ':email' => $passengerData['email'] ?? '',
                ':phone' => $passengerData['phone'] ?? '',
                ':flight_number' => $passengerData['flightNumber'] ?? '',
                ':flight_class' => $passengerData['flightClass'] ?? 'economy',
                ':flight_date' => $passengerData['flightDate'] ?? null,
                ':flight_time' => $passengerData['flightTime'] ?? null,
                ':terminal' => $passengerData['terminal'] ?? null,
                ':adults' => $passengerData['adults'] ?? 1,
                ':children' => $passengerData['children'] ?? 0,
                ':infants' => $passengerData['infants'] ?? 0,
                ':total_bags' => $passengerData['totalBags'] ?? 1,
                ':special_assistance' => $passengerData['specialAssistance'] ?? 'none',
                ':additional_info' => $passengerData['additionalInfo'] ?? null,
                ':total_amount' => $data['total_amount'],
                ':payment_status' => $data['payment_status'],
                ':paystack_reference' => $data['paystack_reference'] ?? null,
                ':paystack_transaction_id' => $data['paystack_transaction_id'] ?? null,
                ':paystack_response' => $data['paystack_response'] ?? null,
            ]);

            return $db->lastInsertId();
        } catch (\Throwable $e) {
            error_log('MeetGreet DB save failed: ' . $e->getMessage());
            return $this->saveMeetGreetToFile($data);
        }
    }

    /**
     * Fallback: Save Meet & Greet booking to file
     */
    private function saveMeetGreetToFile(array $data): string|int|false
    {
        $storageDir = dirname(__DIR__, 3) . '/storage/meet_greet';
        if (!is_dir($storageDir)) mkdir($storageDir, 0755, true);

        $filepath = $storageDir . '/' . $data['reference'] . '.json';
        $record = [
            'reference' => $data['reference'],
            'service_type' => $data['service_type'],
            'passenger_data' => json_decode($data['passenger_data'], true),
            'total_amount' => $data['total_amount'],
            'payment_status' => $data['payment_status'],
            'created_at' => date('Y-m-d H:i:s'),
        ];

        if (file_put_contents($filepath, json_encode($record, JSON_PRETTY_PRINT)) === false) {
            return false;
        }
        return preg_replace('/^MNG-/', '', $data['reference']);
    }

    /**
     * Update Meet & Greet payment status
     */
    public function updateMeetGreetPaymentStatus(int $bookingId, array $data): bool
    {
        try {
            $db = $this->getDb();
            $setClauses = [];
            $params = [':id' => $bookingId];
            
            foreach ($data as $key => $value) {
                $setClauses[] = "{$key} = :{$key}";
                $params[":{$key}"] = $value;
            }

            $sql = "UPDATE meet_greet_bookings SET " . implode(', ', $setClauses) . " WHERE id = :id";
            return $db->prepare($sql)->execute($params);
        } catch (\Throwable $e) {
            error_log('MeetGreet payment update failed: ' . $e->getMessage());
            return $this->updateMeetGreetInFile($bookingId, $data);
        }
    }

    /**
     * Send Meet & Greet confirmation email
     */
    public function sendMeetGreetConfirmation(string $email, string $reference, array $passengerData): bool
    {
        $subject = 'Meet & Greet Booking Confirmed - Reference: ' . $reference;
        $serviceType = ucfirst($passengerData['serviceType'] ?? 'arrival');
        
        $body = <<<HTML
        <p>Thank you for booking our {$serviceType} Meet & Greet service!</p>
        <p><strong>Booking Reference:</strong> {$reference}</p>
        <p><strong>Passenger:</strong> {$passengerData['firstName']} {$passengerData['lastName']}</p>
        <p><strong>Flight:</strong> {$passengerData['flightNumber']} on {$passengerData['flightDate']} at {$passengerData['flightTime']}</p>
        <p>Our representative will meet you at the airport with a welcome board displaying: <strong>{$passengerData['pagerName']}</strong></p>
        <p>If you have any questions, contact us at +254 116 81 81 81</p>
        HTML;
        
        return $this->sendEmail($email, $subject, $body);
    }

    /**
     * Send admin notification for new Meet & Greet booking
     */
    public function sendAdminMeetGreetNotification(string $reference, int $bookingId, array $data): bool
    {
        $adminEmail = $this->config['admin_email'] ?? '';
        if (empty($adminEmail)) return false;

        $subject = '🛬 New Meet & Greet Booking — Ref: ' . $reference;
        $serviceLabel = $data['serviceType'] === 'arrival' ? 'Arrival' : 'Departure';
        
        $body = <<<HTML
        <p>A new <strong>{$serviceLabel} Meet & Greet</strong> booking has been submitted.</p>
        <table style="border-collapse:collapse;font-size:14px;">
            <tr><td style="padding:6px 12px 6px 0;font-weight:bold;">Reference:</td><td>{$reference}</td></tr>
            <tr><td style="padding:6px 12px 6px 0;font-weight:bold;">Passenger:</td><td>{$data['firstName']} {$data['lastName']}</td></tr>
            <tr><td style="padding:6px 12px 6px 0;font-weight:bold;">Email:</td><td>{$data['email']}</td></tr>
            <tr><td style="padding:6px 12px 6px 0;font-weight:bold;">Flight:</td><td>{$data['flightNumber']} • {$data['flightDate']} {$data['flightTime']}</td></tr>
            <tr><td style="padding:6px 12px 6px 0;font-weight:bold;">Passengers:</td><td>{$data['adults']} adult(s), {$data['children']} child(ren)</td></tr>
            <tr><td style="padding:6px 12px 6px 0;font-weight:bold;">Amount:</td><td>KES {$data['total_amount']}</td></tr>
            <tr><td style="padding:6px 12px 6px 0;font-weight:bold;">Status:</td><td style="color:#E8A838;">⏳ Pending Payment</td></tr>
        </table>
        HTML;

        return $this->sendEmail($adminEmail, $subject, $body);
    }

    /**
     * Send Meet & Greet payment confirmation
     */
    public function sendMeetGreetPaymentConfirmation(string $reference): bool
    {
        $email = null;
        try {
            $db = $this->getDb();
            $stmt = $db->prepare("SELECT email FROM meet_greet_bookings WHERE reference_number = :ref LIMIT 1");
            $stmt->execute([':ref' => $reference]);
            $row = $stmt->fetch();
            if ($row) $email = $row['email'] ?? null;
        } catch (\Throwable $e) {
            // Fallback to file lookup if needed
        }
        if (!$email) return false;
        
        return $this->sendEmail($email, '✅ Meet & Greet Payment Confirmed - Ref: ' . $reference, 
            "<p>Your payment has been verified. Booking reference: <strong>{$reference}</strong></p>");
    }

    /**
     * Send admin notification for Meet & Greet payment
     */
    public function sendAdminMeetGreetPaymentNotification(string $reference, int $bookingId, string $status): bool
    {
        $adminEmail = $this->config['admin_email'] ?? '';
        if (empty($adminEmail)) return false;

        $statusLabels = [
            'settled' => '<span style="color:#2D7D4F;">✓ Paid</span>',
            'failed' => '<span style="color:#C7433D;">✗ Failed</span>',
        ];
        
        $subject = '💳 Meet & Greet Payment ' . ucfirst($status) . ' — Ref: ' . $reference;
        $body = "<p>Payment {$status} for Meet & Greet booking {$reference}.</p>";
        
        return $this->sendEmail($adminEmail, $subject, $body);
    }

    /**
     * Fallback: Update Meet & Greet booking in file storage
     */
    private function updateMeetGreetInFile(int $bookingId, array $data): bool
    {
        $storageDir = dirname(__DIR__, 3) . '/storage/meet_greet';
        $files = glob($storageDir . '/*.json');
        
        foreach ($files as $filepath) {
            $record = json_decode(file_get_contents($filepath), true);
            if (!$record) continue;
            
            $refId = preg_replace('/^MNG-/', '', $record['reference'] ?? '');
            if ((string) $refId === (string) $bookingId) {
                foreach ($data as $key => $value) $record[$key] = $value;
                $record['updated_at'] = date('Y-m-d H:i:s');
                return file_put_contents($filepath, json_encode($record, JSON_PRETTY_PRINT)) !== false;
            }
        }
        return false;
    }
}


/*
namespace modules\applicationform\services;

use PDO;

class SubmissionService
{
    private ?PDO $db = null;
    private array $config;

    public function __construct()
    {
        $this->config = $this->loadConfig();
    }

    private function getDb(): PDO
    {
        if ($this->db === null) {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=utf8mb4',
                $this->config['db_host'] ?? 'localhost',
                $this->config['db_name'] ?? 'craft_db'
            );

            $this->db = new PDO($dsn, $this->config['db_user'] ?? 'root', $this->config['db_pass'] ?? '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }

        return $this->db;
    }

    private function loadConfig(): array
    {
        // Try Craft's db.php config first
        $dbConfigFile = dirname(__DIR__, 3) . '/config/db.php';
        $craftDbConfig = [];
        if (file_exists($dbConfigFile)) {
            $craftDbConfig = include $dbConfigFile;
        }

        // Try .env file
        $envFile = dirname(__DIR__, 3) . '/.env';
        $envConfig = [];
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos(trim($line), '#') === 0) continue;
                if (strpos($line, '=') !== false) {
                    [$key, $value] = explode('=', $line, 2);
                    $envConfig[trim($key)] = trim($value, " \"'\t\n\r\0\x0B");
                }
            }
        }

        return [
            'db_host' => getenv('APP_DB_HOST') ?: $envConfig['APP_DB_HOST'] ?? 'localhost',
            'db_name' => getenv('APP_DB_NAME') ?: $envConfig['APP_DB_NAME'] ?? 'kenyastopover_db',
            'db_user' => getenv('APP_DB_USER') ?: $envConfig['APP_DB_USER'] ?? 'root',
            'db_pass' => getenv('APP_DB_PASS') ?: $envConfig['APP_DB_PASS'] ?? '',
            'paystack_secret_key' => getenv('PAYSTACK_SECRET_KEY') ?: $envConfig['PAYSTACK_SECRET_KEY'] ?? '',
            'mail_from' => getenv('MAIL_FROM') ?: $envConfig['MAIL_FROM'] ?? 'noreply@kenyastopover.com',
            'admin_email' => getenv('ADMIN_EMAIL') ?: $envConfig['ADMIN_EMAIL'] ?? 'kibetbravin584@gmail.com',
        ];
    }

    public function saveApplication(array $data): string|int|false
    {
        try {
            $db = $this->getDb();
            $applicantData = json_decode($data['applicant_data'] ?? '{}', true);
            $services = $applicantData['services'] ?? [];
            $documents = json_decode($data['documents'] ?? '{}', true);

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
                ':reference_number'   => $data['reference'],
                ':first_name'         => $applicantData['firstName'] ?? '',
                ':last_name'          => $applicantData['lastName'] ?? '',
                ':email'              => $applicantData['email'] ?? '',
                ':phone'              => $applicantData['phone'] ?? '',
                ':date_of_birth'      => $applicantData['dateOfBirth'] ?? null,
                ':nationality'        => $applicantData['nationality'] ?? '',
                ':passport_number'    => $applicantData['passportNumber'] ?? '',
                ':passport_issue_date'=> $applicantData['passportIssueDate'] ?? null,
                ':passport_expiry_date' => $applicantData['passportExpiryDate'] ?? null,
                ':arrival_date'       => $applicantData['arrivalDate'] ?? null,
                ':departure_date'     => $applicantData['departureDate'] ?? null,
                ':flight_number'      => $applicantData['flightNumber'] ?? null,
                ':airline'            => $applicantData['airline'] ?? null,
                ':purpose'            => $applicantData['purpose'] ?? '',
                ':accommodation'      => $applicantData['accommodation'] ?? '',
                ':special_requests'   => $applicantData['specialRequests'] ?? null,
                ':services'           => json_encode($services),
                ':total_amount'       => $data['total_amount'],
                ':payment_status'     => $data['payment_status'],
            ]);

            $insertId = $db->lastInsertId();

            // Save uploaded file paths
            if (!empty($documents)) {
                $updateStmt = $db->prepare("
                    UPDATE eta_applications SET
                    passport_file_path = :passport_file_path,
                    photo_file_path = :photo_file_path,
                    itinerary_file_path = :itinerary_file_path,
                    accommodation_file_path = :accommodation_file_path
                    WHERE id = :id
                ");
                $updateStmt->execute([
                    ':passport_file_path' => $documents['passportUpload'] ?? null,
                    ':photo_file_path'    => $documents['photoUpload'] ?? null,
                    ':itinerary_file_path' => $documents['itineraryUpload'] ?? null,
                    ':accommodation_file_path' => $documents['accommodationUpload'] ?? null,
                    ':id' => $insertId,
                ]);
            }

            // Log payment transaction
            try {
                $txnStmt = $db->prepare("
                    INSERT INTO payment_transactions
                    (application_id, reference, amount, currency, status, created_at)
                    VALUES (:application_id, :reference, :amount, 'USD', 'pending', NOW())
                ");
                $txnStmt->execute([
                    ':application_id' => $insertId,
                    ':reference' => $data['reference'],
                    ':amount' => $data['total_amount'],
                ]);
            } catch (\Throwable $e) {
                error_log('Failed to create payment transaction record: ' . $e->getMessage());
            }

            // Save travelers to eta_travelers table
            if (!empty($applicantData['travelers'])) {
                $this->saveTravelers($db, $insertId, $applicantData['travelers']);
            }

            return $insertId;
        } catch (\Throwable $e) {
            error_log('DB save failed, falling back to file: ' . $e->getMessage());
            return $this->saveToFile($data);
        }
    }

    private function saveToFile(array $data): string|int|false
    {
        $storageDir = dirname(__DIR__, 3) . '/storage/applications';
        if (!is_dir($storageDir)) mkdir($storageDir, 0755, true);

        $filepath = $storageDir . '/' . $data['reference'] . '.json';
        $record = [
            'reference' => $data['reference'],
            'applicant_data' => json_decode($data['applicant_data'], true),
            'documents' => json_decode($data['documents'], true),
            'total_amount' => $data['total_amount'],
            'payment_status' => $data['payment_status'],
            'created_at' => date('Y-m-d H:i:s'),
        ];

        if (file_put_contents($filepath, json_encode($record, JSON_PRETTY_PRINT)) === false) {
            return false;
        }

        return preg_replace('/^ETA-/', '', $data['reference']);
    }

    public function updatePaymentStatus(int $applicationId, array $data): bool
    {
        try {
            $db = $this->getDb();
            $setClauses = [];
            $params = [':id' => $applicationId];
            foreach ($data as $key => $value) {
                $setClauses[] = "{$key} = :{$key}";
                $params[":{$key}"] = $value;
            }

            $sql = "UPDATE eta_applications SET " . implode(', ', $setClauses) . " WHERE id = :id";
            return $db->prepare($sql)->execute($params);
        } catch (\Throwable $e) {
            error_log('DB update failed, falling back to file: ' . $e->getMessage());
            return $this->updatePaymentInFile($applicationId, $data);
        }
    }

    private function updatePaymentInFile(int $applicationId, array $data): bool
    {
        $storageDir = dirname(__DIR__, 3) . '/storage/applications';
        $files = glob($storageDir . '/*.json');
        foreach ($files as $filepath) {
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
     * Update the payment_transactions record status after Paystack verification.
     * Status flows: pending → settled / failed
     *
    public function updateTransactionStatus(string $reference, string $status, ?string $transId = null, array $responseData = []): bool
    {
        try {
            $db = $this->getDb();
            $setClauses = ['status = :status', 'updated_at = NOW()'];
            $params = [':status' => $status, ':ref' => $reference];

            if ($transId) {
                $setClauses[] = 'paystack_trans_id = :transId';
                $params[':transId'] = $transId;
            }
            if (!empty($responseData)) {
                $setClauses[] = 'response_data = :response';
                $params[':response'] = json_encode($responseData);
            }
            if ($status === 'settled') {
                $setClauses[] = 'paid_at = NOW()';
            }

            $sql = "UPDATE payment_transactions SET " . implode(', ', $setClauses) . " WHERE reference = :ref";
            return $db->prepare($sql)->execute($params);
        } catch (\Throwable $e) {
            error_log('Failed to update transaction status: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Save all travelers to the eta_travelers table
     *
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
                    ':application_id'     => $applicationId,
                    ':traveler_type'      => 'adult',
                    ':first_name'         => $traveler['firstName'] ?? null,
                    ':last_name'          => $traveler['lastName'] ?? null,
                    ':email'              => $traveler['email'] ?? null,
                    ':phone'              => $traveler['phone'] ?? null,
                    ':nationality'        => $traveler['nationality'] ?? null,
                    ':full_name'          => null,
                    ':relationship'       => null,
                    ':date_of_birth'      => $traveler['dateOfBirth'] ?? null,
                    ':passport_number'    => $traveler['passportNumber'] ?? null,
                    ':passport_issue_date'=> $traveler['passportIssueDate'] ?? null,
                    ':passport_expiry_date' => $traveler['passportExpiryDate'] ?? null,
                    ':guardian_agreed'    => 0,
                    ':sort_order'         => $index,
                ]);
            } else {
                $stmt->execute([
                    ':application_id'     => $applicationId,
                    ':traveler_type'      => 'dependent',
                    ':first_name'         => null,
                    ':last_name'          => null,
                    ':email'              => null,
                    ':phone'              => null,
                    ':nationality'        => null,
                    ':full_name'          => $traveler['fullName'] ?? null,
                    ':relationship'       => $traveler['relationship'] ?? null,
                    ':date_of_birth'      => $traveler['dateOfBirth'] ?? null,
                    ':passport_number'    => $traveler['passportNumber'] ?? null,
                    ':passport_issue_date'=> null,
                    ':passport_expiry_date' => null,
                    ':guardian_agreed'    => !empty($traveler['guardianAgreed']) ? 1 : 0,
                    ':sort_order'         => $index,
                ]);
            }
        }
    }

    /**
     * Calculate total — dynamic pricing based on selected service
     * Travelers inherit the price of the first selected service
     *
    public function calculateTotal(array $services, int $travelers): float
    {
        $pricing = ['eta' => 50.00, 'one_day_eta' => 70.00, 'premium_eta' => 100.00];
        $total = 0;
        foreach ($services as $service) $total += $pricing[$service] ?? 0;

        // Travelers inherit the price of the first selected service
        if ($travelers > 0 && !empty($services)) {
            $travelerPrice = $pricing[$services[0]] ?? $pricing['eta'];
            $total += ($travelers * $travelerPrice);
        }
        return round($total, 2);
    }

    public function verifyPaystackTransaction(string $reference): array
    {
        $secretKey = $this->config['paystack_secret_key'];
        if (empty($secretKey)) return ['status' => 'error', 'message' => 'Payment gateway not configured'];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => "https://api.paystack.co/transaction/verify/{$reference}",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$secretKey}", "Cache-Control: no-cache"],
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || $httpCode !== 200) return ['status' => 'error', 'message' => 'API request failed'];
        return json_decode($response, true) ?? ['status' => 'error'];
    }

    public function sendConfirmationEmail(string $email, string $reference): bool
    {
        $subject = 'eTA Application Received - Reference: ' . $reference;
        $body = "<p>Thank you! Your reference: <strong>{$reference}</strong></p>";
        return $this->sendEmail($email, $subject, $body);
    }

    public function sendPaymentConfirmation(string $reference): bool
    {
        $email = null;
        try {
            $db = $this->getDb();
            $stmt = $db->prepare("SELECT email, first_name, last_name FROM eta_applications WHERE paystack_reference = :ref LIMIT 1");
            $stmt->execute([':ref' => $reference]);
            $row = $stmt->fetch();
            if ($row) $email = $row['email'] ?? null;
        } catch (\Throwable $e) {
            $storageDir = dirname(__DIR__, 3) . '/storage/applications';
            foreach (glob($storageDir . '/*.json') as $filepath) {
                $record = json_decode(file_get_contents($filepath), true);
                if ($record && ($record['paystack_reference'] ?? '') === $reference) {
                    $email = $record['applicant_data']['email'] ?? null;
                    break;
                }
            }
        }
        if (!$email) return false;
        return $this->sendEmail($email, 'eTA Payment Confirmed - Reference: ' . $reference, "<p>Payment verified. Reference: <strong>{$reference}</strong></p>");
    }

    /**
     * Send admin notification when a new application is submitted (payment pending)
     *
    public function sendAdminApplicationNotification(string $reference, int $applicationId): bool
    {
        $adminEmail = $this->config['admin_email'] ?? '';
        if (empty($adminEmail)) {
            error_log('Application admin notification skipped: ADMIN_EMAIL not configured');
            return false;
        }

        $applicantName = '';
        $totalAmount = '';
        $email = '';

        try {
            $db = $this->getDb();
            $stmt = $db->prepare("
                SELECT first_name, last_name, email, total_amount, payment_status
                FROM eta_applications WHERE id = :id LIMIT 1
            ");
            $stmt->execute([':id' => $applicationId]);
            $row = $stmt->fetch();
            if ($row) {
                $applicantName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                $totalAmount = $row['total_amount'] ?? '';
                $email = $row['email'] ?? '';
            }
        } catch (\Throwable $e) {
            // Fallback to file storage lookup
            $storageDir = dirname(__DIR__, 3) . '/storage/applications';
            foreach (glob($storageDir . '/*.json') as $filepath) {
                $record = json_decode(file_get_contents($filepath), true);
                $refId = preg_replace('/^ETA-/', '', $record['reference'] ?? '');
                if ((string) $refId === (string) $applicationId || ($record['reference'] ?? '') === $reference) {
                    $data = $record['applicant_data'] ?? [];
                    $applicantName = trim(($data['firstName'] ?? '') . ' ' . ($data['lastName'] ?? ''));
                    $totalAmount = $record['total_amount'] ?? '';
                    $email = $data['email'] ?? '';
                    break;
                }
            }
        }

        $subject = '📋 New eTA Application — Ref: ' . $reference;
        $body = <<<HTML
        <p>A new eTA application has been submitted and is awaiting payment.</p>
        <table style="border-collapse:collapse;font-size:14px;">
            <tr><td style="padding:6px 12px 6px 0;font-weight:bold;color:#0A3D62;">Reference:</td><td>{$reference}</td></tr>
            <tr><td style="padding:6px 12px 6px 0;font-weight:bold;color:#0A3D62;">Applicant:</td><td>{$applicantName}</td></tr>
            <tr><td style="padding:6px 12px 6px 0;font-weight:bold;color:#0A3D62;">Email:</td><td>{$email}</td></tr>
            <tr><td style="padding:6px 12px 6px 0;font-weight:bold;color:#0A3D62;">Amount:</td><td>{$totalAmount}</td></tr>
            <tr><td style="padding:6px 12px 6px 0;font-weight:bold;color:#0A3D62;">Status:</td><td style="color:#E8A838;font-weight:bold;">Pending Payment ⏳</td></tr>
        </table>
        HTML;

        $sent = $this->sendEmail($adminEmail, $subject, $body);
        if (!$sent) {
            error_log("Application admin notification failed for: {$adminEmail}");
        }
        return $sent;
    }

    /**
     * Send admin notification email after successful payment
     *
    public function sendAdminPaymentNotification(string $reference, int $applicationId, string $status = 'settled'): bool
    {
        $adminEmail = $this->config['admin_email'] ?? '';
        if (empty($adminEmail)) {
            error_log('Payment admin notification skipped: ADMIN_EMAIL not configured');
            return false;
        }

        $applicantName = '';
        $totalAmount = '';
        $email = '';

        try {
            $db = $this->getDb();
            $stmt = $db->prepare("
                SELECT first_name, last_name, email, total_amount, payment_status
                FROM eta_applications WHERE id = :id LIMIT 1
            ");
            $stmt->execute([':id' => $applicationId]);
            $row = $stmt->fetch();
            if ($row) {
                $applicantName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                $totalAmount = $row['total_amount'] ?? '';
                $email = $row['email'] ?? '';
            }
        } catch (\Throwable $e) {
            // Fallback to file storage lookup
            $storageDir = dirname(__DIR__, 3) . '/storage/applications';
            foreach (glob($storageDir . '/*.json') as $filepath) {
                $record = json_decode(file_get_contents($filepath), true);
                $refId = preg_replace('/^ETA-/', '', $record['reference'] ?? '');
                if ((string) $refId === (string) $applicationId || ($record['reference'] ?? '') === $reference) {
                    $data = $record['applicant_data'] ?? [];
                    $applicantName = trim(($data['firstName'] ?? '') . ' ' . ($data['lastName'] ?? ''));
                    $totalAmount = $record['total_amount'] ?? '';
                    $email = $data['email'] ?? '';
                    break;
                }
            }
        }

        $statusLabels = [
            'settled'  => '<span style="color:#2D7D4F;font-weight:bold;">Paid ✓</span>',
            'failed'   => '<span style="color:#C7433D;font-weight:bold;">Failed ✗</span>',
            'refunded' => '<span style="color:#E8A838;font-weight:bold;">Refunded ↩</span>',
        ];
        $statusHtml = $statusLabels[$status] ?? $statusLabels['settled'];

        $subject = '💳 eTA Payment ' . ucfirst($status) . ' — Ref: ' . $reference;
        $body = <<<HTML
        <p>An eTA application payment has been processed.</p>
        <table style="border-collapse:collapse;font-size:14px;">
            <tr><td style="padding:6px 12px 6px 0;font-weight:bold;color:#0A3D62;">Reference:</td><td>{$reference}</td></tr>
            <tr><td style="padding:6px 12px 6px 0;font-weight:bold;color:#0A3D62;">Applicant:</td><td>{$applicantName}</td></tr>
            <tr><td style="padding:6px 12px 6px 0;font-weight:bold;color:#0A3D62;">Email:</td><td>{$email}</td></tr>
            <tr><td style="padding:6px 12px 6px 0;font-weight:bold;color:#0A3D62;">Amount:</td><td>{$totalAmount}</td></tr>
            <tr><td style="padding:6px 12px 6px 0;font-weight:bold;color:#0A3D62;">Status:</td><td>{$statusHtml}</td></tr>
        </table>
        HTML;

        $sent = $this->sendEmail($adminEmail, $subject, $body);
        if (!$sent) {
            error_log("Payment admin notification failed for: {$adminEmail}");
        }
        return $sent;
    }

    private function sendEmail(string $to, string $subject, string $body): bool
    {
        $from = $this->config['mail_from'];
        $headers = "From: {$from}\r\nReply-To: {$from}\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
        return mail($to, $subject, $body, $headers);
    }
}


/**
namespace modules\applicationform\services;

use PDO;

class SubmissionService
{
    private ?PDO $db = null;
    private array $config;

    public function __construct()
    {
        $this->config = $this->loadConfig();
    }

    private function getDb(): PDO
    {
        if ($this->db === null) {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=utf8mb4',
                $this->config['db_host'] ?? 'localhost',
                $this->config['db_name'] ?? 'craft_db'
            );

            $this->db = new PDO($dsn, $this->config['db_user'] ?? 'root', $this->config['db_pass'] ?? '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }

        return $this->db;
    }

    private function loadConfig(): array
    {
        // Try Craft's db.php config first
        $dbConfigFile = dirname(__DIR__, 3) . '/config/db.php';
        $craftDbConfig = [];
        if (file_exists($dbConfigFile)) {
            $craftDbConfig = include $dbConfigFile;
        }

        // Try .env file
        $envFile = dirname(__DIR__, 3) . '/.env';
        $envConfig = [];
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos(trim($line), '#') === 0) continue;
                if (strpos($line, '=') !== false) {
                    [$key, $value] = explode('=', $line, 2);
                    $envConfig[trim($key)] = trim($value, " \"'\t\n\r\0\x0B");
                }
            }
        }

        return [
            'db_host' => getenv('APP_DB_HOST') ?: $envConfig['APP_DB_HOST'] ?? 'localhost',
            'db_name' => getenv('APP_DB_NAME') ?: $envConfig['APP_DB_NAME'] ?? 'kenyastopover_db',
            'db_user' => getenv('APP_DB_USER') ?: $envConfig['APP_DB_USER'] ?? 'root',
            'db_pass' => getenv('APP_DB_PASS') ?: $envConfig['APP_DB_PASS'] ?? '',
            'paystack_secret_key' => getenv('PAYSTACK_SECRET_KEY') ?: $envConfig['PAYSTACK_SECRET_KEY'] ?? '',
            'mail_from' => getenv('MAIL_FROM') ?: $envConfig['MAIL_FROM'] ?? 'noreply@kenyastopover.com',
            'admin_email' => getenv('ADMIN_EMAIL') ?: $envConfig['ADMIN_EMAIL'] ?? 'info@kenyastopover.com',
        ];
    }

    public function saveApplication(array $data): string|int|false
    {
        try {
            $db = $this->getDb();
            $applicantData = json_decode($data['applicant_data'] ?? '{}', true);
            $services = $applicantData['services'] ?? [];
            $documents = json_decode($data['documents'] ?? '{}', true);

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
                ':reference_number'   => $data['reference'],
                ':first_name'         => $applicantData['firstName'] ?? '',
                ':last_name'          => $applicantData['lastName'] ?? '',
                ':email'              => $applicantData['email'] ?? '',
                ':phone'              => $applicantData['phone'] ?? '',
                ':date_of_birth'      => $applicantData['dateOfBirth'] ?? null,
                ':nationality'        => $applicantData['nationality'] ?? '',
                ':passport_number'    => $applicantData['passportNumber'] ?? '',
                ':passport_issue_date'=> $applicantData['passportIssueDate'] ?? null,
                ':passport_expiry_date' => $applicantData['passportExpiryDate'] ?? null,
                ':arrival_date'       => $applicantData['arrivalDate'] ?? null,
                ':departure_date'     => $applicantData['departureDate'] ?? null,
                ':flight_number'      => $applicantData['flightNumber'] ?? null,
                ':airline'            => $applicantData['airline'] ?? null,
                ':purpose'            => $applicantData['purpose'] ?? '',
                ':accommodation'      => $applicantData['accommodation'] ?? '',
                ':special_requests'   => $applicantData['specialRequests'] ?? null,
                ':services'           => json_encode($services),
                ':total_amount'       => $data['total_amount'],
                ':payment_status'     => $data['payment_status'],
            ]);

            $insertId = $db->lastInsertId();

            // Save uploaded file paths
            if (!empty($documents)) {
                $updateStmt = $db->prepare("
                    UPDATE eta_applications SET
                    passport_file_path = :passport_file_path,
                    photo_file_path = :photo_file_path,
                    itinerary_file_path = :itinerary_file_path,
                    accommodation_file_path = :accommodation_file_path
                    WHERE id = :id
                ");
                $updateStmt->execute([
                    ':passport_file_path' => $documents['passportUpload'] ?? null,
                    ':photo_file_path'    => $documents['photoUpload'] ?? null,
                    ':itinerary_file_path' => $documents['itineraryUpload'] ?? null,
                    ':accommodation_file_path' => $documents['accommodationUpload'] ?? null,
                    ':id' => $insertId,
                ]);
            }

            // Log payment transaction
            try {
                $txnStmt = $db->prepare("
                    INSERT INTO payment_transactions
                    (application_id, reference, amount, currency, status, created_at)
                    VALUES (:application_id, :reference, :amount, 'USD', 'pending', NOW())
                ");
                $txnStmt->execute([
                    ':application_id' => $insertId,
                    ':reference' => $data['reference'],
                    ':amount' => $data['total_amount'],
                ]);
            } catch (\Throwable $e) {
                error_log('Failed to create payment transaction record: ' . $e->getMessage());
            }

            // Save travelers to eta_travelers table
            if (!empty($applicantData['travelers'])) {
                $this->saveTravelers($db, $insertId, $applicantData['travelers']);
            }

            return $insertId;
        } catch (\Throwable $e) {
            error_log('DB save failed, falling back to file: ' . $e->getMessage());
            return $this->saveToFile($data);
        }
    }

    private function saveToFile(array $data): string|int|false
    {
        $storageDir = dirname(__DIR__, 3) . '/storage/applications';
        if (!is_dir($storageDir)) mkdir($storageDir, 0755, true);

        $filepath = $storageDir . '/' . $data['reference'] . '.json';
        $record = [
            'reference' => $data['reference'],
            'applicant_data' => json_decode($data['applicant_data'], true),
            'documents' => json_decode($data['documents'], true),
            'total_amount' => $data['total_amount'],
            'payment_status' => $data['payment_status'],
            'created_at' => date('Y-m-d H:i:s'),
        ];

        if (file_put_contents($filepath, json_encode($record, JSON_PRETTY_PRINT)) === false) {
            return false;
        }

        return preg_replace('/^ETA-/', '', $data['reference']);
    }

    public function updatePaymentStatus(int $applicationId, array $data): bool
    {
        try {
            $db = $this->getDb();
            $setClauses = [];
            $params = [':id' => $applicationId];
            foreach ($data as $key => $value) {
                $setClauses[] = "{$key} = :{$key}";
                $params[":{$key}"] = $value;
            }

            $sql = "UPDATE eta_applications SET " . implode(', ', $setClauses) . " WHERE id = :id";
            return $db->prepare($sql)->execute($params);
        } catch (\Throwable $e) {
            error_log('DB update failed, falling back to file: ' . $e->getMessage());
            return $this->updatePaymentInFile($applicationId, $data);
        }
    }

    private function updatePaymentInFile(int $applicationId, array $data): bool
    {
        $storageDir = dirname(__DIR__, 3) . '/storage/applications';
        $files = glob($storageDir . '/*.json');
        foreach ($files as $filepath) {
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
     * Update the payment_transactions record status after Paystack verification.
     * Status flows: pending → settled / failed
     /
    public function updateTransactionStatus(string $reference, string $status, ?string $transId = null, array $responseData = []): bool
    {
        try {
            $db = $this->getDb();
            $setClauses = ['status = :status', 'updated_at = NOW()'];
            $params = [':status' => $status, ':ref' => $reference];

            if ($transId) {
                $setClauses[] = 'paystack_trans_id = :transId';
                $params[':transId'] = $transId;
            }
            if (!empty($responseData)) {
                $setClauses[] = 'response_data = :response';
                $params[':response'] = json_encode($responseData);
            }
            if ($status === 'settled') {
                $setClauses[] = 'paid_at = NOW()';
            }

            $sql = "UPDATE payment_transactions SET " . implode(', ', $setClauses) . " WHERE reference = :ref";
            return $db->prepare($sql)->execute($params);
        } catch (\Throwable $e) {
            error_log('Failed to update transaction status: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Save all travelers to the eta_travelers table
     /
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
                    ':application_id'     => $applicationId,
                    ':traveler_type'      => 'adult',
                    ':first_name'         => $traveler['firstName'] ?? null,
                    ':last_name'          => $traveler['lastName'] ?? null,
                    ':email'              => $traveler['email'] ?? null,
                    ':phone'              => $traveler['phone'] ?? null,
                    ':nationality'        => $traveler['nationality'] ?? null,
                    ':full_name'          => null,
                    ':relationship'       => null,
                    ':date_of_birth'      => $traveler['dateOfBirth'] ?? null,
                    ':passport_number'    => $traveler['passportNumber'] ?? null,
                    ':passport_issue_date'=> $traveler['passportIssueDate'] ?? null,
                    ':passport_expiry_date' => $traveler['passportExpiryDate'] ?? null,
                    ':guardian_agreed'    => 0,
                    ':sort_order'         => $index,
                ]);
            } else {
                $stmt->execute([
                    ':application_id'     => $applicationId,
                    ':traveler_type'      => 'dependent',
                    ':first_name'         => null,
                    ':last_name'          => null,
                    ':email'              => null,
                    ':phone'              => null,
                    ':nationality'        => null,
                    ':full_name'          => $traveler['fullName'] ?? null,
                    ':relationship'       => $traveler['relationship'] ?? null,
                    ':date_of_birth'      => $traveler['dateOfBirth'] ?? null,
                    ':passport_number'    => $traveler['passportNumber'] ?? null,
                    ':passport_issue_date'=> null,
                    ':passport_expiry_date' => null,
                    ':guardian_agreed'    => !empty($traveler['guardianAgreed']) ? 1 : 0,
                    ':sort_order'         => $index,
                ]);
            }
        }
    }

    /**
     * Calculate total — dynamic pricing based on selected service
     * Travelers inherit the price of the first selected service
     /
    public function calculateTotal(array $services, int $travelers): float
    {
        $pricing = ['eta' => 50.00, 'one_day_eta' => 70.00, 'premium_eta' => 100.00];
        $total = 0;
        foreach ($services as $service) $total += $pricing[$service] ?? 0;

        // Travelers inherit the price of the first selected service
        if ($travelers > 0 && !empty($services)) {
            $travelerPrice = $pricing[$services[0]] ?? $pricing['eta'];
            $total += ($travelers * $travelerPrice);
        }
        return round($total, 2);
    }

    public function verifyPaystackTransaction(string $reference): array
    {
        $secretKey = $this->config['paystack_secret_key'];
        if (empty($secretKey)) return ['status' => 'error', 'message' => 'Payment gateway not configured'];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => "https://api.paystack.co/transaction/verify/{$reference}",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$secretKey}", "Cache-Control: no-cache"],
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || $httpCode !== 200) return ['status' => 'error', 'message' => 'API request failed'];
        return json_decode($response, true) ?? ['status' => 'error'];
    }

    public function sendConfirmationEmail(string $email, string $reference): bool
    {
        $subject = 'eTA Application Received - Reference: ' . $reference;
        $body = "<p>Thank you! Your reference: <strong>{$reference}</strong></p>";
        return $this->sendEmail($email, $subject, $body);
    }

    public function sendPaymentConfirmation(string $reference): bool
    {
        $email = null;
        try {
            $db = $this->getDb();
            $stmt = $db->prepare("SELECT email, first_name, last_name FROM eta_applications WHERE paystack_reference = :ref LIMIT 1");
            $stmt->execute([':ref' => $reference]);
            $row = $stmt->fetch();
            if ($row) $email = $row['email'] ?? null;
        } catch (\Throwable $e) {
            $storageDir = dirname(__DIR__, 3) . '/storage/applications';
            foreach (glob($storageDir . '/*.json') as $filepath) {
                $record = json_decode(file_get_contents($filepath), true);
                if ($record && ($record['paystack_reference'] ?? '') === $reference) {
                    $email = $record['applicant_data']['email'] ?? null;
                    break;
                }
            }
        }
        if (!$email) return false;
        return $this->sendEmail($email, 'eTA Payment Confirmed - Reference: ' . $reference, "<p>Payment verified. Reference: <strong>{$reference}</strong></p>");
    }

    /**
     * Send admin notification email after successful payment
     /
    public function sendAdminPaymentNotification(string $reference, int $applicationId): bool
    {
        $adminEmail = $this->config['admin_email'] ?? '';
        if (empty($adminEmail)) return false;

        $applicantName = '';
        $totalAmount = '';
        $email = '';

        try {
            $db = $this->getDb();
            $stmt = $db->prepare("
                SELECT first_name, last_name, email, total_amount, payment_status
                FROM eta_applications WHERE id = :id LIMIT 1
            ");
            $stmt->execute([':id' => $applicationId]);
            $row = $stmt->fetch();
            if ($row) {
                $applicantName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                $totalAmount = $row['total_amount'] ?? '';
                $email = $row['email'] ?? '';
            }
        } catch (\Throwable $e) {
            // Fallback to file storage lookup
            $storageDir = dirname(__DIR__, 3) . '/storage/applications';
            foreach (glob($storageDir . '/*.json') as $filepath) {
                $record = json_decode(file_get_contents($filepath), true);
                $refId = preg_replace('/^ETA-/', '', $record['reference'] ?? '');
                if ((string) $refId === (string) $applicationId || ($record['reference'] ?? '') === $reference) {
                    $data = $record['applicant_data'] ?? [];
                    $applicantName = trim(($data['firstName'] ?? '') . ' ' . ($data['lastName'] ?? ''));
                    $totalAmount = $record['total_amount'] ?? '';
                    $email = $data['email'] ?? '';
                    break;
                }
            }
        }

        $subject = '✅ eTA Payment Received — Ref: ' . $reference;
        $body = <<<HTML
        <p>A new eTA application payment has been successfully processed.</p>
        <table style="border-collapse:collapse;font-size:14px;">
            <tr><td style="padding:6px 12px 6px 0;font-weight:bold;color:#0A3D62;">Reference:</td><td>{$reference}</td></tr>
            <tr><td style="padding:6px 12px 6px 0;font-weight:bold;color:#0A3D62;">Applicant:</td><td>{$applicantName}</td></tr>
            <tr><td style="padding:6px 12px 6px 0;font-weight:bold;color:#0A3D62;">Email:</td><td>{$email}</td></tr>
            <tr><td style="padding:6px 12px 6px 0;font-weight:bold;color:#0A3D62;">Amount:</td><td>{$totalAmount}</td></tr>
            <tr><td style="padding:6px 12px 6px 0;font-weight:bold;color:#0A3D62;">Status:</td><td style="color:#2D7D4F;font-weight:bold;">Paid ✓</td></tr>
        </table>
        HTML;

        return $this->sendEmail($adminEmail, $subject, $body);
    }

    private function sendEmail(string $to, string $subject, string $body): bool
    {
        $from = $this->config['mail_from'];
        $headers = "From: {$from}\r\nReply-To: {$from}\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
        return mail($to, $subject, $body, $headers);
    }
}
**/
