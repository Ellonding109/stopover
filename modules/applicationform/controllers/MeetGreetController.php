<?php

declare(strict_types=1);

namespace modules\applicationform\controllers;

$rootDir = dirname(__DIR__, 3);
require_once $rootDir . '/services/PaymentService.php';
require_once $rootDir . '/services/MeetGreetService.php';
require_once $rootDir . '/helpers/notification.php';

use helpers\Notification;
use services\MeetGreetService;
use services\PaymentService;

class MeetGreetController
{
    private PaymentService $paymentService;
    private MeetGreetService $meetGreetService;

    public function __construct()
    {
        $this->paymentService = new PaymentService();
        $this->meetGreetService = new MeetGreetService();
    }

    public function book(): array
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            return ['success' => false, 'errors' => ['method' => 'POST required']];
        }

        $rawBody = file_get_contents('php://input');
        $data = json_decode($rawBody, true) ?? [];

        $required = [
            'serviceType', 'firstName', 'lastName', 'pagerName', 'email', 'phone',
            'flightNumber', 'flightDate', 'flightTime', 'adults', 'amount'
        ];

        $errors = [];
        foreach ($required as $field) {
            if (empty($data[$field]) && $data[$field] !== '0') {
                $errors[$field] = 'This field is required';
            }
        }

        if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Invalid email format';
        }

        if (!empty($data['phone'])) {
            $cleanPhone = preg_replace('/[\s\-\(\)]/', '', $data['phone']);
            if (!preg_match('/^\+?[1-9]\d{1,14}$/', $cleanPhone)) {
                $errors['phone'] = 'Invalid phone number format';
            }
        }

        $amount = filter_var($data['amount'] ?? '', FILTER_VALIDATE_FLOAT);
        if ($amount === false || $amount <= 0) {
            $errors['amount'] = 'amount must be a positive number';
        }

        if (!empty($errors)) {
            http_response_code(422);
            return ['success' => false, 'errors' => $errors];
        }

        $reference = trim($data['reference'] ?? '');
        if ($reference === '') {
            $reference = 'KS-MG-' . strtoupper(uniqid()) . '-' . random_int(1000, 9999);
        }

        // Create the meet-greet booking first so the webhook has a service record to update.
        $bookingId = $this->meetGreetService->saveBooking([
            'reference'        => $reference,
            'full_name'        => trim($data['firstName'] . ' ' . $data['lastName']),
            'email'            => $data['email'], 
            'phone'            => $data['phone'] ?? '',
            'flight_number'    => $data['flightNumber'] ?? '',
            'airline'          => $data['airline'] ?? '',
            'arrival_date'     => $data['flightDate'] ?? null,
            'arrival_time'     => $data['flightTime'] ?? null,
            'departure_date'   => $data['departureDate'] ?? null,
            'passengers'       => (int) ($data['adults'] ?? 1) + (int) ($data['children'] ?? 0) + (int) ($data['infants'] ?? 0),
            'service_option'   => $data['serviceOption'] ?? 'standard',
            'special_requests' => $data['specialRequests'] ?? null,
            'amount'           => (float) $amount,
            'currency'         => strtoupper($data['currency'] ?? 'KES'),
        ]);

        if ($bookingId === false) {
            error_log('MeetGreet booking error: failed to save service booking');
            http_response_code(500);
            return ['success' => false, 'errors' => ['server' => 'Booking failed. Please try again.']];
        }

        return [
            'success' => true,
            'bookingId' => (int) $bookingId,
            'reference' => $reference,
            'amount' => (float) $amount,
            'currency' => strtoupper($data['currency'] ?? 'KES'),
            'message' => 'Meet & Greet booking saved successfully',
        ];
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
        $bookingId = $data['bookingId'] ?? null;

        if (!$reference || !$bookingId) {
            http_response_code(400);
            return ['success' => false, 'errors' => ['params' => 'Missing reference or bookingId']];
        }

        try {
            $paystackResponse = $this->paymentService->verifyPaystackTransaction($reference);
            $paystackStatus = $paystackResponse['status'] ?? '';
            $paystackData = $paystackResponse['data'] ?? [];
            $paystackMessage = $paystackResponse['message'] ?? '';

            if ($paystackStatus === 'success') {
                $this->paymentService->updateBookingStatus($reference, 'paid', [
                    'transaction_id'   => $transactionId,
                    'paystack_payload' => $paystackData,
                ]);

                $this->meetGreetService->confirmPayment($reference, (string) $transactionId, $paystackData);

                try {
                    Notification::customerPaymentConfirmed($this->meetGreetService->getBookingByReference($reference) ?? []);
                } catch (\Throwable $e) {
                    error_log('MeetGreet email notification failed: ' . $e->getMessage());
                }

                return [
                    'success' => true,
                    'message' => 'Payment verified and booking confirmed',
                    'status' => 'paid',
                ];
            }

            if ($paystackStatus === 'failed' || ($paystackData['status'] ?? '') === 'failed') {
                $this->paymentService->updateBookingStatus($reference, 'failed', [
                    'transaction_id'   => $transactionId,
                    'paystack_payload' => $paystackData,
                ]);
                $this->meetGreetService->updatePaymentOutcome($reference, 'failed', 'pending_payment', (string) $transactionId, $paystackData);

                return [
                    'success' => false,
                    'errors' => ['payment' => 'Payment failed: ' . ($paystackMessage ?: 'Transaction declined')],
                ];
            }

            return [
                'success' => false,
                'errors' => ['payment' => 'Payment not yet completed. Please try again shortly.'],
            ];

        } catch (\Throwable $e) {
            error_log('MeetGreet payment verification error: ' . $e->getMessage());
            http_response_code(500);
            return ['success' => false, 'errors' => ['server' => 'Verification failed. Please try again.']];
        }
    }

    public function info(): array
    {
        return [
            'success' => true,
            'service' => 'meetgreet',
            'currencies' => ['KES'],
            'payment_status' => 'pending',
            'message' => 'Meet & Greet service endpoint is available.',
        ];
    }

    private function sanitize(string $input): string
    {
        return trim($input);
    }
}




