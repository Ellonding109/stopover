<?php
namespace modules\applicationform\controllers;

use modules\applicationform\services\SubmissionService;

class MeetGreetController
{
    private SubmissionService $service;

    public function __construct()
    {
        $this->service = new SubmissionService();
    }

    /**
     * Handle Meet & Greet booking submission
     */
    public function book(): array
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            return ['success' => false, 'errors' => ['method' => 'POST required']];
        }

        try {
            $rawBody = file_get_contents('php://input');
            $data = json_decode($rawBody, true) ?? [];

            // Validate required fields
            $required = [
                'serviceType', 'firstName', 'lastName', 'pagerName', 
                'email', 'phone', 'flightNumber', 'flightDate', 'flightTime', 'adults'
            ];
            $errors = [];

            foreach ($required as $field) {
                if (empty($data[$field])) {
                    $errors[$field] = 'This field is required';
                }
            }

            if (!empty($errors)) {
                http_response_code(422);
                return ['success' => false, 'errors' => $errors];
            }

            // Validate email
            if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Invalid email format';
            }

            // Validate phone (basic international format)
            if (!empty($data['phone'])) {
                $cleanPhone = preg_replace('/[\s\-\(\)]/', '', $data['phone']);
                if (!preg_match('/^\+?[1-9]\d{1,14}$/', $cleanPhone)) {
                    $errors['phone'] = 'Invalid phone number format';
                }
            }

            if (!empty($errors)) {
                http_response_code(422);
                return ['success' => false, 'errors' => $errors];
            }

            // Generate booking reference
            $reference = 'MNG-' . strtoupper(uniqid()) . '-' . random_int(1000, 9999);
            
            // Calculate total (KES)
            $adults = (int)($data['adults'] ?? 1);
            $children = (int)($data['children'] ?? 0);
            $infants = (int)($data['infants'] ?? 0);
            
            // Pricing: Adult=5000, Child=3000, Infant=0
            $totalAmount = ($adults * 5000) + ($children * 3000);

            // Save booking to database
            $bookingId = $this->service->saveMeetGreetBooking([
                'reference' => $reference,
                'service_type' => $this->sanitize($data['serviceType']), // 'arrival' or 'departure'
                'passenger_data' => json_encode($this->sanitizeArray($data)),
                'total_amount' => $totalAmount,
                'payment_status' => !empty($data['paymentReference']) ? 'paid' : 'pending',
                'paystack_reference' => $data['paymentReference'] ?? null,
                'paystack_transaction_id' => $data['transactionId'] ?? null,
            ]);

            if ($bookingId === false) {
                throw new \RuntimeException('Failed to save booking');
            }

            // Send confirmation email
            $this->service->sendMeetGreetConfirmation($data['email'], $reference, $data);

            // Notify admin
            $this->service->sendAdminMeetGreetNotification($reference, (int) $bookingId, $data);

            return [
                'success' => true,
                'bookingId' => (int) $bookingId,
                'bookingReference' => $reference,
                'totalAmount' => $totalAmount,
                'message' => 'Booking confirmed successfully'
            ];

        } catch (\Throwable $e) {
            error_log('MeetGreet booking error: ' . $e->getMessage());
            http_response_code(500);
            return ['success' => false, 'errors' => ['server' => 'Booking failed. Please try again.']];
        }
    }

    /**
     * Verify Paystack payment for Meet & Greet booking
     */
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
        $bookingId = $data['bookingId'] ?? null;

        if (!$reference || !$bookingId) {
            http_response_code(400);
            return ['success' => false, 'errors' => ['params' => 'Missing reference or bookingId']];
        }

        try {
            $paystackResponse = $this->service->verifyPaystackTransaction($reference);
            $paystackStatus = $paystackResponse['status'] ?? '';
            $paystackData = $paystackResponse['data'] ?? [];
            $paystackMessage = $paystackResponse['message'] ?? '';

            if ($paystackStatus === 'success') {
                // Payment succeeded
                $updated = $this->service->updateMeetGreetPaymentStatus((int) $bookingId, [
                    'payment_status' => 'paid',
                    'status' => 'confirmed',
                    'paystack_reference' => $reference,
                    'paystack_transaction_id' => $transactionId,
                    'paystack_response' => json_encode($paystackData),
                    'paid_at' => date('Y-m-d H:i:s'),
                ]);

                if (!$updated) {
                    throw new \RuntimeException('Failed to update payment status');
                }

                // Send payment confirmation
                $this->service->sendMeetGreetPaymentConfirmation($reference);
                $this->service->sendAdminMeetGreetPaymentNotification($reference, (int) $bookingId, 'settled');

                return [
                    'success' => true,
                    'message' => 'Payment verified and booking confirmed',
                    'status' => 'settled',
                ];

            } elseif ($paystackStatus === 'failed' || ($paystackData['status'] ?? '') === 'failed') {
                $this->service->updateMeetGreetPaymentStatus((int) $bookingId, [
                    'payment_status' => 'failed',
                    'paystack_reference' => $reference,
                    'paystack_transaction_id' => $transactionId,
                ]);

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
            error_log('MeetGreet payment verification error: ' . $e->getMessage());
            http_response_code(500);
            return ['success' => false, 'errors' => ['server' => 'Verification failed. Please try again.']];
        }
    }

    private function sanitize(string $input): string
    {
        return trim($input);
    }

    private function sanitizeArray(array $data): array
    {
        $sanitized = [];
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $sanitized[$key] = $this->sanitize($value);
            } else {
                $sanitized[$key] = $value;
            }
        }
        return $sanitized;
    }
}