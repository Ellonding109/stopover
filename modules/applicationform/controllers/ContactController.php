<?php
namespace modules\applicationform\controllers;

use modules\applicationform\services\ContactService;

class ContactController
{
    private ContactService $service;

    public function __construct()
    {
        $this->service = new ContactService();
    }

    public function submit(): array
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            return ['success' => false, 'errors' => ['method' => 'POST required']];
        }

        try {
            $data = [
                'name' => $this->sanitizeHtml($_POST['username'] ?? ''),
                'email' => trim($_POST['email'] ?? ''),
                'subject' => $this->sanitizeHtml($_POST['subject'] ?? ''),
                'message' => $this->sanitizeHtml($_POST['message'] ?? ''),
            ];

            $errors = $this->validateContact($data);
            if (!empty($errors)) {
                http_response_code(422);
                return ['success' => false, 'errors' => $errors];
            }

            $messageId = $this->service->saveMessage($data);

            if ($messageId === false) {
                throw new \RuntimeException('Failed to save message');
            }

            $this->service->sendAdminNotification($data);
            $this->service->sendAutoReply($data);

            return [
                'success' => true,
                'messageId' => (int) $messageId,
                'message' => 'Your message has been sent successfully.'
            ];

        } catch (\Throwable $e) {
            error_log('Contact form error: ' . $e->getMessage());
            http_response_code(500);
            return ['success' => false, 'errors' => ['server' => 'Failed to send message. Please try again.']];
        }
    }

    private function sanitizeHtml(string $input): string
    {
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }

    private function validateContact(array $data): array
    {
        $errors = [];

        if (empty($data['name'])) {
            $errors['name'] = 'Your name is required';
        }

        if (empty($data['email'])) {
            $errors['email'] = 'Your email is required';
        } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Please enter a valid email address';
        }

        if (empty($data['subject'])) {
            $errors['subject'] = 'A subject is required';
        }

        if (empty($data['message'])) {
            $errors['message'] = 'Please enter your message';
        } elseif (strlen($data['message']) < 10) {
            $errors['message'] = 'Message must be at least 10 characters';
        }

        return $errors;
    }
}
