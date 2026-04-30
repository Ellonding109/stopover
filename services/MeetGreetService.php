<?php
/**
 * MeetGreetService.php
 *
 * Handles Meet-Greet specific booking logic.
 * Plain PHP — no Yii dependency.
 *
 * Responsible for:
 *   - Saving a meet-greet booking to meetgreet_bookings table
 *   - Confirming payment (called by the webhook router)
 *   - Providing booking lookup helpers
 */

namespace services;

use PDO;
use Throwable;

class MeetGreetService
{
    private PaymentService $paymentService;

    public function __construct()
    {
        $this->paymentService = new PaymentService();
    }

    private function getDb(): PDO
    {
        return $this->paymentService->getDb();
    }

    // -------------------------------------------------------------------------
    // Create a meet-greet booking record (called from the booking form)
    // -------------------------------------------------------------------------

    /**
     * Persist a Meet-Greet booking.
     * This should be called when the user submits the booking form,
     * BEFORE payment initiation.
     *
     * @param array $data {
     *   reference:         string
     *   full_name:         string
     *   email:             string
     *   phone:             string
     *   flight_number:     string
     *   airline:           string
     *   arrival_date:      string (Y-m-d)
     *   arrival_time:      string (H:i)
     *   departure_date:    string|null
     *   passengers:        int
     *   special_requests:  string|null
     *   service_option:    string  (e.g. 'standard', 'premium')
     *   amount:            float
     *   currency:          string
     * }
     * @return int|false
     */
    public function saveBooking(array $data): int|false
    {
        $this->paymentService->log('info', 'meetgreet_save_booking', [
            'reference' => $data['reference'],
            'email'     => $data['email'],
        ]);

        try {
            $db   = $this->getDb();
            $stmt = $db->prepare("
                INSERT INTO meetgreet_bookings
                    (reference, full_name, email, phone,
                     flight_number, airline,
                     arrival_date, arrival_time,
                     departure_date,
                     passengers, service_option,
                     special_requests,
                     amount, currency,
                     payment_status, status,
                     created_at, updated_at)
                VALUES
                    (:reference, :full_name, :email, :phone,
                     :flight_number, :airline,
                     :arrival_date, :arrival_time,
                     :departure_date,
                     :passengers, :service_option,
                     :special_requests,
                     :amount, :currency,
                     'pending', 'pending_payment',
                     NOW(), NOW())
            ");

            $stmt->execute([
                ':reference'       => $data['reference'],
                ':full_name'       => $data['full_name'],
                ':email'           => $data['email'],
                ':phone'           => $data['phone'] ?? '',
                ':flight_number'   => $data['flight_number'] ?? '',
                ':airline'         => $data['airline'] ?? '',
                ':arrival_date'    => $data['arrival_date'] ?? null,
                ':arrival_time'    => $data['arrival_time'] ?? null,
                ':departure_date'  => $data['departure_date'] ?? null,
                ':passengers'      => (int) ($data['passengers'] ?? 1),
                ':service_option'  => $data['service_option'] ?? 'standard',
                ':special_requests'=> $data['special_requests'] ?? null,
                ':amount'          => (float) ($data['amount'] ?? 0),
                ':currency'        => $data['currency'] ?? 'KES',
            ]);

            $id = (int) $db->lastInsertId();
            $this->paymentService->log('info', 'meetgreet_booking_saved', [
                'id'        => $id,
                'reference' => $data['reference'],
            ]);
            return $id;

        } catch (Throwable $e) {
            $this->paymentService->log('error', 'meetgreet_save_failed', [
                'reference' => $data['reference'],
                'error'     => $e->getMessage(),
            ]);
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Post-payment confirmation (called by webhook router)
    // -------------------------------------------------------------------------

    /**
     * Update the meet-greet booking to confirmed after successful payment.
     *
     * @param string $reference      Paystack reference
     * @param string $transactionId  Paystack transaction ID
     * @param array  $paystackData   Full Paystack event data object
     * @return bool
     */
    public function confirmPayment(string $reference, string $transactionId, array $paystackData): bool
    {
        $this->paymentService->log('info', 'meetgreet_confirm_payment', ['reference' => $reference]);

        try {
            $db   = $this->getDb();
            $stmt = $db->prepare("
                UPDATE meetgreet_bookings
                SET
                    payment_status     = 'paid',
                    status             = 'confirmed',
                    transaction_id     = :transaction_id,
                    paystack_payload   = :paystack_payload,
                    paid_at            = NOW(),
                    updated_at         = NOW()
                WHERE reference = :reference
                  AND payment_status != 'paid'
            ");

            $stmt->execute([
                ':reference'        => $reference,
                ':transaction_id'   => $transactionId,
                ':paystack_payload' => json_encode($paystackData),
            ]);

            $affected = $stmt->rowCount();
            $this->paymentService->log('info', 'meetgreet_payment_confirmed', [
                'reference' => $reference,
                'rows'      => $affected,
            ]);

            return true;

        } catch (Throwable $e) {
            $this->paymentService->log('error', 'meetgreet_confirm_failed', [
                'reference' => $reference,
                'error'     => $e->getMessage(),
            ]);
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Lookup helpers
    // -------------------------------------------------------------------------

    public function getBookingByReference(string $reference): ?array
    {
        try {
            $stmt = $this->getDb()->prepare(
                "SELECT * FROM meetgreet_bookings WHERE reference = :ref LIMIT 1"
            );
            $stmt->execute([':ref' => $reference]);
            $row = $stmt->fetch();
            return $row ?: null;
        } catch (Throwable $e) {
            $this->paymentService->log('error', 'meetgreet_lookup_failed', [
                'reference' => $reference,
                'error'     => $e->getMessage(),
            ]);
            return null;
        }
    }

    // -------------------------------------------------------------------------
    // Pricing
    // -------------------------------------------------------------------------

    /**
     * Calculate the total for a meet-greet booking.
     *
     * @param string $serviceOption  'standard' | 'premium'
     * @param int    $passengers
     * @return float  Amount in KES
     */
    public function calculateTotal(string $serviceOption, int $passengers): float
    {
        $pricing = [
            'standard' => 5000.00,
            'premium'  => 9500.00,
        ];

        $basePrice = $pricing[$serviceOption] ?? $pricing['standard'];
        // Additional passengers: 50% of base per extra person
        $extra     = max(0, $passengers - 1) * ($basePrice * 0.5);

        return round($basePrice + $extra, 2);
    }

    public function updatePaymentOutcome(
        string  $reference,
        string  $paymentStatus,
        string  $serviceStatus = 'pending_payment',
        ?string $transactionId = null,
        array   $paystackData  = []
    ): bool {
        if ($paymentStatus === 'paid') {
            return $this->confirmPayment($reference, (string) ($transactionId ?? ''), $paystackData);
        }

        try {
            $db    = $this->getDb();
            $sets  = [
                'payment_status = :payment_status',
                'status = :status',
                'updated_at = NOW()',
            ];
            $params = [
                ':payment_status' => $paymentStatus,
                ':status'         => $serviceStatus,
                ':reference'      => $reference,
            ];

            if ($transactionId !== null) {
                $sets[] = 'transaction_id = :transaction_id';
                $params[':transaction_id'] = $transactionId;
            }

            if (!empty($paystackData)) {
                $sets[] = 'paystack_payload = :paystack_payload';
                $params[':paystack_payload'] = json_encode($paystackData);
            }

            $sql = 'UPDATE meetgreet_bookings SET ' . implode(', ', $sets) . ' WHERE reference = :reference';
            $db->prepare($sql)->execute($params);

            return true;
        } catch (Throwable $e) {
            $this->paymentService->log('error', 'meetgreet_update_payment_outcome_failed', [
                'reference' => $reference,
                'error'     => $e->getMessage(),
            ]);
            return false;
        }
    }
}
