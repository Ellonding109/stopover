<?php
namespace modules\applicationform\controllers;

use modules\applicationform\services\SubmissionService;

class SubmissionController
{
    private SubmissionService $service;

    public function __construct()
    {
        $this->service = new SubmissionService();
    }

    public function submit(): array
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            return ['success' => false, 'errors' => ['method' => 'POST required']];
        }

        try {
            $data = [
                'firstName' => $this->sanitize($_POST['firstName'] ?? ''),
                'lastName' => $this->sanitize($_POST['lastName'] ?? ''),
                'email' => $this->sanitize($_POST['email'] ?? ''),
                'phone' => $this->sanitize($_POST['phone'] ?? ''),
                'dateOfBirth' => $this->sanitize($_POST['dateOfBirth'] ?? ''),
                'nationality' => $this->sanitize($_POST['nationality'] ?? ''),
                'passportNumber' => $this->sanitize($_POST['passportNumber'] ?? ''),
                'passportIssueDate' => $this->sanitize($_POST['passportIssueDate'] ?? ''),
                'passportExpiryDate' => $this->sanitize($_POST['passportExpiryDate'] ?? ''),
                'arrivalDate' => $this->sanitize($_POST['arrivalDate'] ?? ''),
                'departureDate' => $this->sanitize($_POST['departureDate'] ?? ''),
                'flightNumber' => $this->sanitize($_POST['flightNumber'] ?? ''),
                'airline' => $this->sanitize($_POST['airline'] ?? ''),
                'purpose' => $this->sanitize($_POST['purpose'] ?? ''),
                'accommodation' => $this->sanitize($_POST['accommodation'] ?? ''),
                'specialRequests' => $this->sanitize($_POST['specialRequests'] ?? ''),
                'services' => $this->decodeJson($_POST['services'] ?? '[]'),
                'travelers' => $this->decodeJson($_POST['travelers'] ?? '[]'),
            ];

            $errors = $this->validateApplication($data);
            if (!empty($errors)) {
                http_response_code(422);
                return ['success' => false, 'errors' => $errors];
            }

            $uploads = $this->handleUploads();
            if ($uploads['error']) {
                http_response_code(422);
                return ['success' => false, 'errors' => ['upload' => $uploads['error']]];
            }

            $reference = 'ETA-' . strtoupper(uniqid()) . '-' . random_int(1000, 9999);
            $totalAmount = $this->service->calculateTotal($data['services'], count($data['travelers']));

            $applicationId = $this->service->saveApplication([
                'reference' => $reference,
                'applicant_data' => json_encode($data),
                'documents' => json_encode($uploads['files']),
                'total_amount' => $totalAmount,
                'payment_status' => 'pending',
            ]);

            if ($applicationId === false) {
                throw new \RuntimeException('Failed to save application');
            }

            $this->service->sendConfirmationEmail($data['email'], $reference);

            // Notify admin of new application (payment pending)
            $this->service->sendAdminApplicationNotification($reference, (int) $applicationId);

            return [
                'success' => true,
                'applicationId' => (int) $applicationId,
                'referenceNumber' => $reference,
                'totalAmount' => $totalAmount,
                'message' => 'Application submitted successfully'
            ];

        } catch (\Throwable $e) {
            error_log('Application submission error: ' . $e->getMessage());
            http_response_code(500);
            return ['success' => false, 'errors' => ['server' => 'Submission failed. Please try again.']];
        }
    }

    public function verifyPayment(): array
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            return ['success' => false, 'errors' => ['method' => 'POST required']];
        }

        $rawBody = file_get_contents('php://input');
        $data = json_decode($rawBody, true) ?? [];

        $reference = $data['reference'] ?? null;
        $transactionId = $data['transactionId'] ?? null;
        $applicationId = $data['applicationId'] ?? null;

        if (!$reference || !$applicationId) {
            http_response_code(400);
            return ['success' => false, 'errors' => ['params' => 'Missing reference or applicationId']];
        }

        $existingTxn = $this->service->getTransactionByReference($reference);
        if ($existingTxn && ($existingTxn['status'] ?? '') === 'settled') {
            return [
                'success' => true,
                'message' => 'Payment already confirmed',
                'status' => 'settled',
            ];
        }

        try {
            $paystackResponse = $this->service->verifyPaystackTransaction($reference);
            $paystackStatus = $paystackResponse['status'] ?? '';
            $paystackData = $paystackResponse['data'] ?? [];
            $paystackMessage = $paystackResponse['message'] ?? '';

            if ($paystackStatus === 'success') {
                // Payment succeeded — mark as paid/settled
                $updated = $this->service->updatePaymentStatus((int) $applicationId, [
                    'payment_status' => 'paid',
                    'status' => 'submitted',
                    'paystack_reference' => $reference,
                    'paystack_transaction_id' => $transactionId,
                    'paystack_response' => json_encode($paystackData),
                    'paid_at' => date('Y-m-d H:i:s'),
                ]);

                if (!$updated) {
                    throw new \RuntimeException('Failed to update payment status');
                }

                // Update payment_transactions record to settled
                $this->service->updateTransactionStatus($reference, 'settled', $transactionId, $paystackData);

                // Send confirmation email to applicant
                $this->service->sendPaymentConfirmation($reference);

                // Send admin notification (settled)
                $this->service->sendAdminPaymentNotification($reference, (int) $applicationId, 'settled');

                return [
                    'success' => true,
                    'message' => 'Payment verified and settled',
                    'status' => 'settled',
                ];

            } elseif ($paystackStatus === 'failed' || ($paystackData['status'] ?? '') === 'failed') {
                
                $this->service->updatePaymentStatus((int) $applicationId, [
                    'payment_status' => 'failed',
                    'paystack_reference' => $reference,
                    'paystack_transaction_id' => $transactionId,
                    'paystack_response' => json_encode($paystackData),
                ]);
                $this->service->updateTransactionStatus($reference, 'failed', $transactionId, $paystackData);

                // Notify admin of failed payment
                $this->service->sendAdminPaymentNotification($reference, (int) $applicationId, 'failed');

                return [
                    'success' => false,
                    'errors' => ['payment' => 'Payment failed: ' . ($paystackMessage ?: 'Transaction declined')],
                ];
            } else {
                
                return [
                    'success' => false,
                    'errors' => ['payment' => 'Payment not yet completed. You can retry with the same reference.'],
                ];
            }
        } catch (\Throwable $e) {
            error_log('Payment verification error: ' . $e->getMessage());
            http_response_code(500);
            return ['success' => false, 'errors' => ['server' => 'Verification failed. Please try again.']];
        }
    }

    public function webhook(): array
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            return ['success' => false, 'errors' => ['method' => 'POST required']];
        }

        $payload = file_get_contents('php://input');
        $signature = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';

        if (!$this->service->verifyWebhookSignature($payload, $signature)) {
            http_response_code(401);
            return ['success' => false, 'errors' => ['signature' => 'Invalid webhook signature']];
        }

        $data = json_decode($payload, true);
        if (!is_array($data)) {
            http_response_code(400);
            return ['success' => false, 'errors' => ['payload' => 'Invalid JSON payload']];
        }

        $result = $this->service->processPaystackWebhookEvent($data);
        http_response_code(200);
        return ['success' => $result['success'], 'message' => $result['message'] ?? 'Webhook processed'];
    }

    private function sanitize(string $input): string
    {
        return trim($input);
    }

    private function sanitizeHtml(string $input): string
    {
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }

    private function decodeJson(string $json): array
    {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function validateApplication(array $data): array
    {
        $errors = [];
        $required = ['firstName', 'lastName', 'email', 'phone', 'nationality', 'passportNumber'];

        foreach ($required as $field) {
            if (empty($data[$field])) {
                $errors[$field] = 'This field is required';
            }
        }

        if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Invalid email format';
        }

        // Validate travelers (adult/dependent support)
        if (!empty($data['travelers'])) {
            $primaryPassport = strtoupper(trim($data['passportNumber'] ?? ''));
            $passports = $primaryPassport ? [$primaryPassport] : [];
            $today = new \DateTime();
            $today->setTime(0, 0, 0);

            foreach ($data['travelers'] as $index => $traveler) {
                $travelerType = $traveler['type'] ?? 'adult';

                if ($travelerType === 'adult') {
                    if (empty($traveler['firstName'])) $errors["traveler_{$index}_firstName"] = 'First name is required';
                    if (empty($traveler['lastName'])) $errors["traveler_{$index}_lastName"] = 'Last name is required';
                    if (empty($traveler['email'])) {
                        $errors["traveler_{$index}_email"] = 'Email is required';
                    } elseif (!filter_var($traveler['email'], FILTER_VALIDATE_EMAIL)) {
                        $errors["traveler_{$index}_email"] = 'Invalid email format';
                    }
                    if (empty($traveler['phone'])) $errors["traveler_{$index}_phone"] = 'Phone is required';
                    if (empty($traveler['passportNumber'])) {
                        $errors["traveler_{$index}_passportNumber"] = 'Passport number is required';
                    } elseif (!preg_match('/^[A-Za-z0-9]{6,12}$/', $traveler['passportNumber'])) {
                        $errors["traveler_{$index}_passportNumber"] = 'Passport must be 6-12 alphanumeric characters';
                    }

                    // Adult must be 18+
                    if (!empty($traveler['dateOfBirth'])) {
                        try {
                            $dob = new \DateTime($traveler['dateOfBirth']);
                            $age = $today->diff($dob)->y;
                            if ($age < 18) $errors["traveler_{$index}_dateOfBirth"] = 'Adult travelers must be at least 18';
                        } catch (\Exception $e) {
                            $errors["traveler_{$index}_dateOfBirth"] = 'Invalid date of birth';
                        }
                    }
                } else {
                    // Dependent
                    if (empty($traveler['fullName'])) $errors["traveler_{$index}_fullName"] = 'Full name is required';
                    if (empty($traveler['relationship'])) $errors["traveler_{$index}_relationship"] = 'Relationship is required';
                    if (empty($traveler['passportNumber'])) {
                        $errors["traveler_{$index}_passportNumber"] = 'Passport number is required';
                    } elseif (!preg_match('/^[A-Za-z0-9]{6,12}$/', $traveler['passportNumber'])) {
                        $errors["traveler_{$index}_passportNumber"] = 'Passport must be 6-12 alphanumeric characters';
                    }
                    if (empty($traveler['guardianAgreed'])) {
                        $errors["traveler_{$index}_guardianAgree"] = 'Guardian agreement is required';
                    }
                    if (!empty($traveler['dateOfBirth'])) {
                        try {
                            $dob = new \DateTime($traveler['dateOfBirth']);
                            if ($dob > $today) $errors["traveler_{$index}_dateOfBirth"] = 'Date of birth cannot be in the future';
                        } catch (\Exception $e) {
                            $errors["traveler_{$index}_dateOfBirth"] = 'Invalid date of birth';
                        }
                    }
                }

                // Duplicate passport check
                if (!empty($traveler['passportNumber'])) {
                    $pp = strtoupper(trim($traveler['passportNumber']));
                    if (in_array($pp, $passports)) {
                        $errors["traveler_{$index}_passportNumber"] = 'Passport number already in use';
                    } else {
                        $passports[] = $pp;
                    }
                }
            }
        }

        return $errors;
    }

    private function handleUploads(): array
    {
        $files = [];
        $uploadFields = [
            'passportUpload' => ['image/jpeg', 'image/png', 'application/pdf'],
            'photoUpload' => ['image/jpeg', 'image/png'],
            'itineraryUpload' => ['image/jpeg', 'image/png', 'application/pdf'],
            'accommodationUpload' => ['image/jpeg', 'image/png', 'application/pdf'],
        ];

        $maxSize = 5 * 1024 * 1024;
        $uploadDir = dirname(__DIR__, 4) . '/storage/eta_uploads';

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        foreach ($uploadFields as $field => $allowedTypes) {
            if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
                continue;
            }

            $file = $_FILES[$field];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if (!in_array($mimeType, $allowedTypes, true)) {
                return ['error' => "Invalid file type for {$field}", 'files' => []];
            }

            if ($file['size'] > $maxSize) {
                return ['error' => "File {$field} exceeds 5MB limit", 'files' => []];
            }

            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $extension = $extension ? strtolower($extension) : 'bin';
            $filename = bin2hex(random_bytes(8)) . '.' . $extension;
            $destination = $uploadDir . DIRECTORY_SEPARATOR . $filename;

            if (!move_uploaded_file($file['tmp_name'], $destination)) {
                return ['error' => "Failed to save {$field}", 'files' => []];
            }

            $files[$field] = $filename;
        }

        return ['error' => null, 'files' => $files];
    }
}


/*
namespace modules\applicationform\controllers;

use modules\applicationform\services\SubmissionService;

class SubmissionController
{
    private SubmissionService $service;

    public function __construct()
    {
        $this->service = new SubmissionService();
    }

    public function submit(): array
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            return ['success' => false, 'errors' => ['method' => 'POST required']];
        }

        try {
            $data = [
                'firstName' => $this->sanitize($_POST['firstName'] ?? ''),
                'lastName' => $this->sanitize($_POST['lastName'] ?? ''),
                'email' => $this->sanitize($_POST['email'] ?? ''),
                'phone' => $this->sanitize($_POST['phone'] ?? ''),
                'dateOfBirth' => $this->sanitize($_POST['dateOfBirth'] ?? ''),
                'nationality' => $this->sanitize($_POST['nationality'] ?? ''),
                'passportNumber' => $this->sanitize($_POST['passportNumber'] ?? ''),
                'passportIssueDate' => $this->sanitize($_POST['passportIssueDate'] ?? ''),
                'passportExpiryDate' => $this->sanitize($_POST['passportExpiryDate'] ?? ''),
                'arrivalDate' => $this->sanitize($_POST['arrivalDate'] ?? ''),
                'departureDate' => $this->sanitize($_POST['departureDate'] ?? ''),
                'flightNumber' => $this->sanitize($_POST['flightNumber'] ?? ''),
                'airline' => $this->sanitize($_POST['airline'] ?? ''),
                'purpose' => $this->sanitize($_POST['purpose'] ?? ''),
                'accommodation' => $this->sanitize($_POST['accommodation'] ?? ''),
                'specialRequests' => $this->sanitize($_POST['specialRequests'] ?? ''),
                'services' => $this->decodeJson($_POST['services'] ?? '[]'),
                'travelers' => $this->decodeJson($_POST['travelers'] ?? '[]'),
            ];

            $errors = $this->validateApplication($data);
            if (!empty($errors)) {
                http_response_code(422);
                return ['success' => false, 'errors' => $errors];
            }

            $uploads = $this->handleUploads();
            if ($uploads['error']) {
                http_response_code(422);
                return ['success' => false, 'errors' => ['upload' => $uploads['error']]];
            }

            $reference = 'ETA-' . strtoupper(uniqid()) . '-' . random_int(1000, 9999);
            $totalAmount = $this->service->calculateTotal($data['services'], count($data['travelers']));

            $applicationId = $this->service->saveApplication([
                'reference' => $reference,
                'applicant_data' => json_encode($data),
                'documents' => json_encode($uploads['files']),
                'total_amount' => $totalAmount,
                'payment_status' => 'pending',
            ]);

            if ($applicationId === false) {
                throw new \RuntimeException('Failed to save application');
            }

            $this->service->sendConfirmationEmail($data['email'], $reference);

            // Notify admin of new application (payment pending)
            $this->service->sendAdminApplicationNotification($reference, (int) $applicationId);

            return [
                'success' => true,
                'applicationId' => (int) $applicationId,
                'referenceNumber' => $reference,
                'totalAmount' => $totalAmount,
                'message' => 'Application submitted successfully'
            ];

        } catch (\Throwable $e) {
            error_log('Application submission error: ' . $e->getMessage());
            http_response_code(500);
            return ['success' => false, 'errors' => ['server' => 'Submission failed. Please try again.']];
        }
    }

    public function verifyPayment(): array
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            return ['success' => false, 'errors' => ['method' => 'POST required']];
        }

        $rawBody = file_get_contents('php://input');
        $data = json_decode($rawBody, true) ?? [];

        $reference = $data['reference'] ?? null;
        $transactionId = $data['transactionId'] ?? null;
        $applicationId = $data['applicationId'] ?? null;

        if (!$reference || !$applicationId) {
            http_response_code(400);
            return ['success' => false, 'errors' => ['params' => 'Missing reference or applicationId']];
        }

        try {
            $paystackResponse = $this->service->verifyPaystackTransaction($reference);
            $paystackStatus = $paystackResponse['status'] ?? '';
            $paystackData = $paystackResponse['data'] ?? [];
            $paystackMessage = $paystackResponse['message'] ?? '';

            if ($paystackStatus === 'success') {
                // Payment succeeded — mark as paid/settled
                $updated = $this->service->updatePaymentStatus((int) $applicationId, [
                    'payment_status' => 'paid',
                    'status' => 'submitted',
                    'paystack_reference' => $reference,
                    'paystack_transaction_id' => $transactionId,
                    'paystack_response' => json_encode($paystackData),
                    'paid_at' => date('Y-m-d H:i:s'),
                ]);

                if (!$updated) {
                    throw new \RuntimeException('Failed to update payment status');
                }

                // Update payment_transactions record to settled
                $this->service->updateTransactionStatus($reference, 'settled', $transactionId, $paystackData);

                // Send confirmation email to applicant
                $this->service->sendPaymentConfirmation($reference);

                // Send admin notification (settled)
                $this->service->sendAdminPaymentNotification($reference, (int) $applicationId, 'settled');

                return [
                    'success' => true,
                    'message' => 'Payment verified and settled',
                    'status' => 'settled',
                ];

            } elseif ($paystackStatus === 'failed' || ($paystackData['status'] ?? '') === 'failed') {
                
                $this->service->updatePaymentStatus((int) $applicationId, [
                    'payment_status' => 'failed',
                    'paystack_reference' => $reference,
                    'paystack_transaction_id' => $transactionId,
                    'paystack_response' => json_encode($paystackData),
                ]);
                $this->service->updateTransactionStatus($reference, 'failed', $transactionId, $paystackData);

                // Notify admin of failed payment
                $this->service->sendAdminPaymentNotification($reference, (int) $applicationId, 'failed');

                return [
                    'success' => false,
                    'errors' => ['payment' => 'Payment failed: ' . ($paystackMessage ?: 'Transaction declined')],
                ];
            } else {
                
                return [
                    'success' => false,
                    'errors' => ['payment' => 'Payment not yet completed. You can retry with the same reference.'],
                ];
            }
        } catch (\Throwable $e) {
            error_log('Payment verification error: ' . $e->getMessage());
            http_response_code(500);
            return ['success' => false, 'errors' => ['server' => 'Verification failed. Please try again.']];
        }
    }

    private function sanitize(string $input): string
    {
        return trim($input);
    }

    private function sanitizeHtml(string $input): string
    {
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }

    private function decodeJson(string $json): array
    {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function validateApplication(array $data): array
    {
        $errors = [];
        $required = ['firstName', 'lastName', 'email', 'phone', 'nationality', 'passportNumber'];

        foreach ($required as $field) {
            if (empty($data[$field])) {
                $errors[$field] = 'This field is required';
            }
        }

        if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Invalid email format';
        }

        // Validate travelers (adult/dependent support)
        if (!empty($data['travelers'])) {
            $primaryPassport = strtoupper(trim($data['passportNumber'] ?? ''));
            $passports = $primaryPassport ? [$primaryPassport] : [];
            $today = new \DateTime();
            $today->setTime(0, 0, 0);

            foreach ($data['travelers'] as $index => $traveler) {
                $travelerType = $traveler['type'] ?? 'adult';

                if ($travelerType === 'adult') {
                    if (empty($traveler['firstName'])) $errors["traveler_{$index}_firstName"] = 'First name is required';
                    if (empty($traveler['lastName'])) $errors["traveler_{$index}_lastName"] = 'Last name is required';
                    if (empty($traveler['email'])) {
                        $errors["traveler_{$index}_email"] = 'Email is required';
                    } elseif (!filter_var($traveler['email'], FILTER_VALIDATE_EMAIL)) {
                        $errors["traveler_{$index}_email"] = 'Invalid email format';
                    }
                    if (empty($traveler['phone'])) $errors["traveler_{$index}_phone"] = 'Phone is required';
                    if (empty($traveler['passportNumber'])) {
                        $errors["traveler_{$index}_passportNumber"] = 'Passport number is required';
                    } elseif (!preg_match('/^[A-Za-z0-9]{6,12}$/', $traveler['passportNumber'])) {
                        $errors["traveler_{$index}_passportNumber"] = 'Passport must be 6-12 alphanumeric characters';
                    }

                    // Adult must be 18+
                    if (!empty($traveler['dateOfBirth'])) {
                        try {
                            $dob = new \DateTime($traveler['dateOfBirth']);
                            $age = $today->diff($dob)->y;
                            if ($age < 18) $errors["traveler_{$index}_dateOfBirth"] = 'Adult travelers must be at least 18';
                        } catch (\Exception $e) {
                            $errors["traveler_{$index}_dateOfBirth"] = 'Invalid date of birth';
                        }
                    }
                } else {
                    // Dependent
                    if (empty($traveler['fullName'])) $errors["traveler_{$index}_fullName"] = 'Full name is required';
                    if (empty($traveler['relationship'])) $errors["traveler_{$index}_relationship"] = 'Relationship is required';
                    if (empty($traveler['passportNumber'])) {
                        $errors["traveler_{$index}_passportNumber"] = 'Passport number is required';
                    } elseif (!preg_match('/^[A-Za-z0-9]{6,12}$/', $traveler['passportNumber'])) {
                        $errors["traveler_{$index}_passportNumber"] = 'Passport must be 6-12 alphanumeric characters';
                    }
                    if (empty($traveler['guardianAgreed'])) {
                        $errors["traveler_{$index}_guardianAgree"] = 'Guardian agreement is required';
                    }
                    if (!empty($traveler['dateOfBirth'])) {
                        try {
                            $dob = new \DateTime($traveler['dateOfBirth']);
                            if ($dob > $today) $errors["traveler_{$index}_dateOfBirth"] = 'Date of birth cannot be in the future';
                        } catch (\Exception $e) {
                            $errors["traveler_{$index}_dateOfBirth"] = 'Invalid date of birth';
                        }
                    }
                }

                // Duplicate passport check
                if (!empty($traveler['passportNumber'])) {
                    $pp = strtoupper(trim($traveler['passportNumber']));
                    if (in_array($pp, $passports)) {
                        $errors["traveler_{$index}_passportNumber"] = 'Passport number already in use';
                    } else {
                        $passports[] = $pp;
                    }
                }
            }
        }

        return $errors;
    }

    private function handleUploads(): array
    {
        $files = [];
        $uploadFields = [
            'passportUpload' => ['image/jpeg', 'image/png', 'application/pdf'],
            'photoUpload' => ['image/jpeg', 'image/png'],
            'itineraryUpload' => ['image/jpeg', 'image/png', 'application/pdf'],
            'accommodationUpload' => ['image/jpeg', 'image/png', 'application/pdf'],
        ];

        $maxSize = 5 * 1024 * 1024;
        $uploadDir = dirname(__DIR__, 4) . '/storage/eta_uploads';

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        foreach ($uploadFields as $field => $allowedTypes) {
            if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
                continue;
            }

            $file = $_FILES[$field];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if (!in_array($mimeType, $allowedTypes, true)) {
                return ['error' => "Invalid file type for {$field}", 'files' => []];
            }

            if ($file['size'] > $maxSize) {
                return ['error' => "File {$field} exceeds 5MB limit", 'files' => []];
            }

            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $extension = $extension ? strtolower($extension) : 'bin';
            $filename = bin2hex(random_bytes(8)) . '.' . $extension;
            $destination = $uploadDir . DIRECTORY_SEPARATOR . $filename;

            if (!move_uploaded_file($file['tmp_name'], $destination)) {
                return ['error' => "Failed to save {$field}", 'files' => []];
            }

            $files[$field] = $filename;
        }

        return ['error' => null, 'files' => $files];
    }
}


/**
namespace modules\applicationform\controllers;

use modules\applicationform\services\SubmissionService;

class SubmissionController
{
    private SubmissionService $service;

    public function __construct()
    {
        $this->service = new SubmissionService();
    }

    public function submit(): array
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            return ['success' => false, 'errors' => ['method' => 'POST required']];
        }

        try {
            $data = [
                'firstName' => $this->sanitize($_POST['firstName'] ?? ''),
                'lastName' => $this->sanitize($_POST['lastName'] ?? ''),
                'email' => $this->sanitize($_POST['email'] ?? ''),
                'phone' => $this->sanitize($_POST['phone'] ?? ''),
                'dateOfBirth' => $this->sanitize($_POST['dateOfBirth'] ?? ''),
                'nationality' => $this->sanitize($_POST['nationality'] ?? ''),
                'passportNumber' => $this->sanitize($_POST['passportNumber'] ?? ''),
                'passportIssueDate' => $this->sanitize($_POST['passportIssueDate'] ?? ''),
                'passportExpiryDate' => $this->sanitize($_POST['passportExpiryDate'] ?? ''),
                'arrivalDate' => $this->sanitize($_POST['arrivalDate'] ?? ''),
                'departureDate' => $this->sanitize($_POST['departureDate'] ?? ''),
                'flightNumber' => $this->sanitize($_POST['flightNumber'] ?? ''),
                'airline' => $this->sanitize($_POST['airline'] ?? ''),
                'purpose' => $this->sanitize($_POST['purpose'] ?? ''),
                'accommodation' => $this->sanitize($_POST['accommodation'] ?? ''),
                'specialRequests' => $this->sanitize($_POST['specialRequests'] ?? ''),
                'services' => $this->decodeJson($_POST['services'] ?? '[]'),
                'travelers' => $this->decodeJson($_POST['travelers'] ?? '[]'),
            ];

            $errors = $this->validateApplication($data);
            if (!empty($errors)) {
                http_response_code(422);
                return ['success' => false, 'errors' => $errors];
            }

            $uploads = $this->handleUploads();
            if ($uploads['error']) {
                http_response_code(422);
                return ['success' => false, 'errors' => ['upload' => $uploads['error']]];
            }

            $reference = 'ETA-' . strtoupper(uniqid()) . '-' . random_int(1000, 9999);
            $totalAmount = $this->service->calculateTotal($data['services'], count($data['travelers']));

            $applicationId = $this->service->saveApplication([
                'reference' => $reference,
                'applicant_data' => json_encode($data),
                'documents' => json_encode($uploads['files']),
                'total_amount' => $totalAmount,
                'payment_status' => 'pending',
            ]);

            if ($applicationId === false) {
                throw new \RuntimeException('Failed to save application');
            }

            $this->service->sendConfirmationEmail($data['email'], $reference);

            return [
                'success' => true,
                'applicationId' => (int) $applicationId,
                'referenceNumber' => $reference,
                'totalAmount' => $totalAmount,
                'message' => 'Application submitted successfully'
            ];

        } catch (\Throwable $e) {
            error_log('Application submission error: ' . $e->getMessage());
            http_response_code(500);
            return ['success' => false, 'errors' => ['server' => 'Submission failed. Please try again.']];
        }
    }

    public function verifyPayment(): array
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            return ['success' => false, 'errors' => ['method' => 'POST required']];
        }

        $rawBody = file_get_contents('php://input');
        $data = json_decode($rawBody, true) ?? [];

        $reference = $data['reference'] ?? null;
        $transactionId = $data['transactionId'] ?? null;
        $applicationId = $data['applicationId'] ?? null;

        if (!$reference || !$applicationId) {
            http_response_code(400);
            return ['success' => false, 'errors' => ['params' => 'Missing reference or applicationId']];
        }

        try {
            $paystackResponse = $this->service->verifyPaystackTransaction($reference);
            $paystackStatus = $paystackResponse['status'] ?? '';
            $paystackData = $paystackResponse['data'] ?? [];
            $paystackMessage = $paystackResponse['message'] ?? '';

            if ($paystackStatus === 'success') {
                // Payment succeeded — mark as paid/settled
                $updated = $this->service->updatePaymentStatus((int) $applicationId, [
                    'payment_status' => 'paid',
                    'status' => 'submitted',
                    'paystack_reference' => $reference,
                    'paystack_transaction_id' => $transactionId,
                    'paystack_response' => json_encode($paystackData),
                    'paid_at' => date('Y-m-d H:i:s'),
                ]);

                if (!$updated) {
                    throw new \RuntimeException('Failed to update payment status');
                }

                // Update payment_transactions record to settled
                $this->service->updateTransactionStatus($reference, 'settled', $transactionId, $paystackData);

                // Send confirmation email to applicant
                $this->service->sendPaymentConfirmation($reference);

                // Send admin notification
                $this->service->sendAdminPaymentNotification($reference, (int) $applicationId);

                return [
                    'success' => true,
                    'message' => 'Payment verified and settled',
                    'status' => 'settled',
                ];

            } elseif ($paystackStatus === 'failed' || ($paystackData['status'] ?? '') === 'failed') {
                // Payment explicitly failed
                $this->service->updatePaymentStatus((int) $applicationId, [
                    'payment_status' => 'failed',
                    'paystack_reference' => $reference,
                    'paystack_transaction_id' => $transactionId,
                    'paystack_response' => json_encode($paystackData),
                ]);
                $this->service->updateTransactionStatus($reference, 'failed', $transactionId, $paystackData);

                return [
                    'success' => false,
                    'errors' => ['payment' => 'Payment failed: ' . ($paystackMessage ?: 'Transaction declined')],
                ];
            } else {
                // Pending or unknown — keep as pending, allow retry
                return [
                    'success' => false,
                    'errors' => ['payment' => 'Payment not yet completed. You can retry with the same reference.'],
                ];
            }
        } catch (\Throwable $e) {
            error_log('Payment verification error: ' . $e->getMessage());
            http_response_code(500);
            return ['success' => false, 'errors' => ['server' => 'Verification failed. Please try again.']];
        }
    }

    private function sanitize(string $input): string
    {
        return trim($input);
    }

    private function sanitizeHtml(string $input): string
    {
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }

    private function decodeJson(string $json): array
    {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function validateApplication(array $data): array
    {
        $errors = [];
        $required = ['firstName', 'lastName', 'email', 'phone', 'nationality', 'passportNumber'];

        foreach ($required as $field) {
            if (empty($data[$field])) {
                $errors[$field] = 'This field is required';
            }
        }

        if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Invalid email format';
        }

        // Validate travelers (adult/dependent support)
        if (!empty($data['travelers'])) {
            $primaryPassport = strtoupper(trim($data['passportNumber'] ?? ''));
            $passports = $primaryPassport ? [$primaryPassport] : [];
            $today = new \DateTime();
            $today->setTime(0, 0, 0);

            foreach ($data['travelers'] as $index => $traveler) {
                $travelerType = $traveler['type'] ?? 'adult';

                if ($travelerType === 'adult') {
                    if (empty($traveler['firstName'])) $errors["traveler_{$index}_firstName"] = 'First name is required';
                    if (empty($traveler['lastName'])) $errors["traveler_{$index}_lastName"] = 'Last name is required';
                    if (empty($traveler['email'])) {
                        $errors["traveler_{$index}_email"] = 'Email is required';
                    } elseif (!filter_var($traveler['email'], FILTER_VALIDATE_EMAIL)) {
                        $errors["traveler_{$index}_email"] = 'Invalid email format';
                    }
                    if (empty($traveler['phone'])) $errors["traveler_{$index}_phone"] = 'Phone is required';
                    if (empty($traveler['passportNumber'])) {
                        $errors["traveler_{$index}_passportNumber"] = 'Passport number is required';
                    } elseif (!preg_match('/^[A-Za-z0-9]{6,12}$/', $traveler['passportNumber'])) {
                        $errors["traveler_{$index}_passportNumber"] = 'Passport must be 6-12 alphanumeric characters';
                    }

                    // Adult must be 18+
                    if (!empty($traveler['dateOfBirth'])) {
                        try {
                            $dob = new \DateTime($traveler['dateOfBirth']);
                            $age = $today->diff($dob)->y;
                            if ($age < 18) $errors["traveler_{$index}_dateOfBirth"] = 'Adult travelers must be at least 18';
                        } catch (\Exception $e) {
                            $errors["traveler_{$index}_dateOfBirth"] = 'Invalid date of birth';
                        }
                    }
                } else {
                    // Dependent
                    if (empty($traveler['fullName'])) $errors["traveler_{$index}_fullName"] = 'Full name is required';
                    if (empty($traveler['relationship'])) $errors["traveler_{$index}_relationship"] = 'Relationship is required';
                    if (empty($traveler['passportNumber'])) {
                        $errors["traveler_{$index}_passportNumber"] = 'Passport number is required';
                    } elseif (!preg_match('/^[A-Za-z0-9]{6,12}$/', $traveler['passportNumber'])) {
                        $errors["traveler_{$index}_passportNumber"] = 'Passport must be 6-12 alphanumeric characters';
                    }
                    if (empty($traveler['guardianAgreed'])) {
                        $errors["traveler_{$index}_guardianAgree"] = 'Guardian agreement is required';
                    }
                    if (!empty($traveler['dateOfBirth'])) {
                        try {
                            $dob = new \DateTime($traveler['dateOfBirth']);
                            if ($dob > $today) $errors["traveler_{$index}_dateOfBirth"] = 'Date of birth cannot be in the future';
                        } catch (\Exception $e) {
                            $errors["traveler_{$index}_dateOfBirth"] = 'Invalid date of birth';
                        }
                    }
                }

                // Duplicate passport check
                if (!empty($traveler['passportNumber'])) {
                    $pp = strtoupper(trim($traveler['passportNumber']));
                    if (in_array($pp, $passports)) {
                        $errors["traveler_{$index}_passportNumber"] = 'Passport number already in use';
                    } else {
                        $passports[] = $pp;
                    }
                }
            }
        }

        return $errors;
    }

    private function handleUploads(): array
    {
        $files = [];
        $uploadFields = [
            'passportUpload' => ['image/jpeg', 'image/png', 'application/pdf'],
            'photoUpload' => ['image/jpeg', 'image/png'],
            'itineraryUpload' => ['image/jpeg', 'image/png', 'application/pdf'],
            'accommodationUpload' => ['image/jpeg', 'image/png', 'application/pdf'],
        ];

        $maxSize = 5 * 1024 * 1024;
        $uploadDir = dirname(__DIR__, 4) . '/storage/eta_uploads';

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        foreach ($uploadFields as $field => $allowedTypes) {
            if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
                continue;
            }

            $file = $_FILES[$field];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if (!in_array($mimeType, $allowedTypes, true)) {
                return ['error' => "Invalid file type for {$field}", 'files' => []];
            }

            if ($file['size'] > $maxSize) {
                return ['error' => "File {$field} exceeds 5MB limit", 'files' => []];
            }

            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $extension = $extension ? strtolower($extension) : 'bin';
            $filename = bin2hex(random_bytes(8)) . '.' . $extension;
            $destination = $uploadDir . DIRECTORY_SEPARATOR . $filename;

            if (!move_uploaded_file($file['tmp_name'], $destination)) {
                return ['error' => "Failed to save {$field}", 'files' => []];
            }

            $files[$field] = $filename;
        }

        return ['error' => null, 'files' => $files];
    }
}
**/
