<?php
require_once __DIR__ . '/../modules/applicationform/services/SubmissionService.php';

use modules\applicationform\services\SubmissionService;

$reference = trim($_GET['reference'] ?? '');
if ($reference === '') {
    http_response_code(400);
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Payment Verification</title></head><body><h1>Payment Verification</h1><p>No reference supplied.</p></body></html>';
    exit;
}

$service = new SubmissionService();
$paystackResponse = $service->verifyPaystackTransaction($reference);
$status = strtolower($paystackResponse['status'] ?? 'error');
$data = $paystackResponse['data'] ?? [];
$message = $paystackResponse['message'] ?? '';
$transactionStatus = strtolower($data['status'] ?? '');

$success = ($status === 'success' && $transactionStatus === 'success');

$title = $success ? 'Payment Successful' : 'Payment Not Confirmed';
$description = $success
    ? 'Thank you. Paystack has verified the transaction successfully.'
    : 'We could not confirm the payment from this browser callback. The payment must still be confirmed by the webhook before it is considered paid.';

$details = htmlspecialchars($message ?: ($data['gateway_response'] ?? ''), ENT_QUOTES, 'UTF-8');
$displayDetails = $details ? '<p><strong>Paystack response:</strong> ' . $details . '</p>' : '';

if ($success) {
    $displayDetails .= '<p>Please allow a few minutes for the system to complete processing. If you do not receive confirmation, contact support with your payment reference.</p>';
} else {
    $displayDetails .= '<p>If you just completed payment, keep the browser open and wait a moment. If this page still reports failure, contact support with your reference.</p>';
}

echo '<!doctype html><html><head><meta charset="utf-8"><title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title></head><body>';
echo '<h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
echo '<p><strong>Reference:</strong> ' . htmlspecialchars($reference, ENT_QUOTES, 'UTF-8') . '</p>';
echo '<p>' . htmlspecialchars($description, ENT_QUOTES, 'UTF-8') . '</p>';
echo $displayDetails;
echo '<p><a href="/">Return to home</a></p>';
echo '</body></html>';
