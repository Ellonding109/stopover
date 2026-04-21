<?php
namespace modules\applicationform\controllers;

use modules\applicationform\services\NewsletterService;

class NewsletterController
{
    private NewsletterService $service;

    public function __construct()
    {
        $this->service = new NewsletterService();
    }

    public function subscribe(): array
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            return ['success' => false, 'errors' => ['method' => 'POST required']];
        }

        try {
            $email = $this->sanitize($_POST['email'] ?? '');

            $errors = $this->validateSubscribe($email);
            if (!empty($errors)) {
                http_response_code(422);
                return ['success' => false, 'errors' => $errors];
            }

            // Check if already subscribed
            if ($this->service->isSubscribed($email)) {
                return ['success' => false, 'errors' => ['email' => 'This email is already subscribed to our newsletter.']];
            }

            // Save subscriber
            $subscriberId = $this->service->saveSubscriber($email);
            if ($subscriberId === false) {
                throw new \RuntimeException('Failed to save subscriber');
            }

            // Send confirmation email to subscriber
            $this->service->sendSubscriberConfirmation($email);

            // Notify admin
            $this->service->sendAdminNotification($email);

            return [
                'success' => true,
                'subscriberId' => $subscriberId,
                'message' => 'Thank you! You\'ve been subscribed. We\'ll keep you updated with news and travel guides.'
            ];

        } catch (\Throwable $e) {
            error_log('Newsletter subscription error: ' . $e->getMessage());
            http_response_code(500);
            return ['success' => false, 'errors' => ['server' => 'Subscription failed. Please try again.']];
        }
    }

    public function unsubscribe(): array
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            return ['success' => false, 'errors' => ['method' => 'POST required']];
        }

        try {
            $rawBody = file_get_contents('php://input');
            $data = json_decode($rawBody, true) ?? [];
            $email = $this->sanitize($data['email'] ?? '');

            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                return ['success' => false, 'errors' => ['email' => 'Valid email is required']];
            }

            $unsubscribed = $this->service->unsubscribe($email);
            if ($unsubscribed) {
                return ['success' => true, 'message' => 'You have been unsubscribed successfully.'];
            }

            // Email not found — still return success (don't reveal DB state)
            return ['success' => true, 'message' => 'If this email was subscribed, it has been removed.'];

        } catch (\Throwable $e) {
            error_log('Newsletter unsubscribe error: ' . $e->getMessage());
            http_response_code(500);
            return ['success' => false, 'errors' => ['server' => 'Failed to unsubscribe.']];
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

    private function validateSubscribe(string $email): array
    {
        $errors = [];

        if (empty($email)) {
            $errors['email'] = 'Email address is required';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Please enter a valid email address';
        }

        return $errors;
    }
}
