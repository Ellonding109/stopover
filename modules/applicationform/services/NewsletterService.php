<?php
namespace modules\applicationform\services;

use PDO;

class NewsletterService
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
            $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4',
                $this->config['db_host'] ?? 'localhost',
                $this->config['db_name'] ?? 'kenyastopover_db');
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
        $envFile = dirname(__DIR__, 3) . '/.env';
        $config = [];
        if (file_exists($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (strpos(trim($line), '#') === 0) continue;
                if (strpos($line, '=') !== false) {
                    [$key, $value] = explode('=', $line, 2);
                    $config[trim($key)] = trim($value, " \"'\t\n\r\0\x0B");
                }
            }
        }
        return [
            'db_host' => getenv('APP_DB_HOST') ?: $config['APP_DB_HOST'] ?? 'localhost',
            'db_name' => getenv('APP_DB_NAME') ?: $config['APP_DB_NAME'] ?? 'kenyastopover_db',
            'db_user' => getenv('APP_DB_USER') ?: $config['APP_DB_USER'] ?? 'root',
            'db_pass' => getenv('APP_DB_PASS') ?: $config['APP_DB_PASS'] ?? '',
            'mail_from' => getenv('MAIL_FROM') ?: $config['MAIL_FROM'] ?? 'noreply@kenyastopover.com',
            'admin_email' => getenv('ADMIN_EMAIL') ?: $config['ADMIN_EMAIL'] ?? 'info@kenyastopover.com',
        ];
    }

    /**
     * Check if email is already subscribed
     */
    public function isSubscribed(string $email): bool
    {
        try {
            $db = $this->getDb();
            $stmt = $db->prepare("SELECT COUNT(*) FROM newsletter_subscribers WHERE email = :email AND status != 'unsubscribed'");
            $stmt->execute([':email' => strtolower($email)]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (\Throwable $e) {
            return $this->isSubscribedInFile($email);
        }
    }

    /**
     * Save subscriber to database
     */
    public function saveSubscriber(string $email): string|int|false
    {
        try {
            $db = $this->getDb();
            $stmt = $db->prepare("
                INSERT INTO newsletter_subscribers (email, status, subscribed_at)
                VALUES (:email, 'active', NOW())
            ");
            $stmt->execute([':email' => strtolower($email)]);
            return $db->lastInsertId();
        } catch (\Throwable $e) {
            error_log('Newsletter DB failed, using file storage: ' . $e->getMessage());
            return $this->saveSubscriberToFile($email);
        }
    }

    /**
     * Unsubscribe an email
     */
    public function unsubscribe(string $email): bool
    {
        try {
            $db = $this->getDb();
            $stmt = $db->prepare("UPDATE newsletter_subscribers SET status = 'unsubscribed', unsubscribed_at = NOW() WHERE email = :email");
            return $stmt->execute([':email' => strtolower($email)]);
        } catch (\Throwable $e) {
            return $this->unsubscribeInFile($email);
        }
    }

    private function isSubscribedInFile(string $email): bool
    {
        $dir = dirname(__DIR__, 3) . '/storage/newsletter_subscribers';
        if (!is_dir($dir)) return false;
        foreach (glob($dir . '/*.json') as $filepath) {
            $record = json_decode(file_get_contents($filepath), true);
            if ($record && strtolower($record['email'] ?? '') === strtolower($email) && ($record['status'] ?? 'active') !== 'unsubscribed') {
                return true;
            }
        }
        return false;
    }

    private function saveSubscriberToFile(string $email): string|int|false
    {
        $dir = dirname(__DIR__, 3) . '/storage/newsletter_subscribers';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $id = time() . '_' . bin2hex(random_bytes(4));
        $record = [
            'id' => $id,
            'email' => strtolower($email),
            'status' => 'active',
            'subscribed_at' => date('Y-m-d H:i:s'),
        ];
        return file_put_contents($dir . '/' . $id . '.json', json_encode($record, JSON_PRETTY_PRINT)) !== false ? $id : false;
    }

    private function unsubscribeInFile(string $email): bool
    {
        $dir = dirname(__DIR__, 3) . '/storage/newsletter_subscribers';
        if (!is_dir($dir)) return false;
        foreach (glob($dir . '/*.json') as $filepath) {
            $record = json_decode(file_get_contents($filepath), true);
            if ($record && strtolower($record['email'] ?? '') === strtolower($email)) {
                $record['status'] = 'unsubscribed';
                $record['unsubscribed_at'] = date('Y-m-d H:i:s');
                return file_put_contents($filepath, json_encode($record, JSON_PRETTY_PRINT)) !== false;
            }
        }
        return false;
    }

    /**
     * Send confirmation email to the new subscriber
     */
    public function sendSubscriberConfirmation(string $email): bool
    {
        $subject = 'Welcome to Kenya Stopover Newsletter';
        $body = $this->buildSubscriberHtml($email);
        $headers = "From: {$this->config['mail_from']}\r\nReply-To: {$this->config['mail_from']}\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
        return mail($email, $subject, $body, $headers);
    }

    /**
     * Notify admin of new subscriber
     */
    public function sendAdminNotification(string $email): bool
    {
        $subject = 'New Newsletter Subscriber: ' . $email;
        $body = "<p><strong>New subscriber:</strong> {$email}</p><p>Time: " . date('Y-m-d H:i:s') . "</p>";
        $headers = "From: {$this->config['mail_from']}\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
        return mail($this->config['admin_email'], $subject, $body, $headers);
    }

    private function buildSubscriberHtml(string $email): string
    {
        return <<<HTML
        <!DOCTYPE html>
        <html>
        <head><meta charset="UTF-8"></head>
        <body style="font-family:Arial,sans-serif;line-height:1.6;color:#333;max-width:600px;margin:0 auto;padding:20px;">
            <div style="background:#0A3D62;color:#fff;padding:20px;text-align:center;border-radius:8px 8px 0 0;">
                <h1 style="margin:0;">Welcome Aboard! ✈️</h1>
            </div>
            <div style="background:#f9f9f9;padding:30px;border-radius:0 0 8px 8px;">
                <p>Hi there,</p>
                <p>Thank you for subscribing to the <strong>Kenya Stopover</strong> newsletter. You'll now receive:</p>
                <ul>
                    <li>Latest travel news and updates</li>
                    <li>Travel guides and tips for Kenya</li>
                    <li>Exclusive deals on eTA and meet & greet services</li>
                </ul>
                <p>We'll be in touch soon!</p>
                <hr style="border:none;border-top:1px solid #ddd;margin:20px 0;">
                <p style="color:#888;font-size:12px;">Subscribed with: {$email}</p>
                <p style="color:#888;font-size:12px;">To unsubscribe, reply to this email with "UNSUBSCRIBE" in the subject line.</p>
            </div>
        </body>
        </html>
        HTML;
    }
}
