<?php
namespace modules\applicationform\services;

use PDO;

class ContactService
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

    public function saveMessage(array $data): string|int|false
    {
        try {
            $db = $this->getDb();
            $stmt = $db->prepare("
                INSERT INTO contact_messages (name, email, subject, message, ip_address, user_agent, created_at)
                VALUES (:name, :email, :subject, :message, :ip_address, :user_agent, NOW())");
            $stmt->execute([
                ':name' => $data['name'], ':email' => $data['email'],
                ':subject' => $data['subject'], ':message' => $data['message'],
                ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            ]);
            return $db->lastInsertId();
        } catch (\Throwable $e) {
            error_log('Contact DB failed, using file storage: ' . $e->getMessage());
            return $this->saveToFile($data);
        }
    }

    private function saveToFile(array $data): string|int|false
    {
        $dir = dirname(__DIR__, 3) . '/storage/contact_messages';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $id = time() . '_' . bin2hex(random_bytes(4));
        $record = array_merge($data, [
            'id' => $id, 'status' => 'new',
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return file_put_contents($dir . '/' . $id . '.json', json_encode($record, JSON_PRETTY_PRINT)) !== false ? $id : false;
    }

    public function sendAdminNotification(array $data): bool
    {
        $adminEmail = $this->config['admin_email'] ?? '';
        if (empty($adminEmail)) {
            error_log('Contact admin notification skipped: ADMIN_EMAIL not configured');
            return false;
        }

        $subject = 'New Contact Message: ' . $data['subject'];
        $body = "<p><strong>{$data['name']}</strong> ({$data['email']})</p><p>Subject: {$data['subject']}</p><p>{$data['message']}</p>";
        $headers = "From: {$this->config['mail_from']}\r\nReply-To: {$data['email']}\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";

        $sent = mail($adminEmail, $subject, $body, $headers);
        if (!$sent) {
            error_log("Contact admin notification failed for: {$adminEmail}");
        }
        return $sent;
    }

    public function sendAutoReply(array $data): bool
    {
        $subject = 'We received your message - Kenya Stopover';
        $body = "<p>Dear {$data['name']},</p><p>We received your message about: <strong>{$data['subject']}</strong></p><p>We'll respond within 24-48 hours.</p>";
        return mail($data['email'], $subject, $body,
            "From: {$this->config['mail_from']}\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n");
    }
}

/**
namespace modules\applicationform\services;

use PDO;

class ContactService
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
                $this->config['db_name'] ?? 'craft_db');
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

    public function saveMessage(array $data): string|int|false
    {
        try {
            $db = $this->getDb();
            $stmt = $db->prepare("
                INSERT INTO contact_messages (name, email, subject, message, ip_address, user_agent, created_at)
                VALUES (:name, :email, :subject, :message, :ip_address, :user_agent, NOW())");
            $stmt->execute([
                ':name' => $data['name'], ':email' => $data['email'],
                ':subject' => $data['subject'], ':message' => $data['message'],
                ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            ]);
            return $db->lastInsertId();
        } catch (\Throwable $e) {
            error_log('Contact DB failed, using file storage: ' . $e->getMessage());
            return $this->saveToFile($data);
        }
    }

    private function saveToFile(array $data): string|int|false
    {
        $dir = dirname(__DIR__, 3) . '/storage/contact_messages';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $id = time() . '_' . bin2hex(random_bytes(4));
        $record = array_merge($data, [
            'id' => $id, 'status' => 'new',
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return file_put_contents($dir . '/' . $id . '.json', json_encode($record, JSON_PRETTY_PRINT)) !== false ? $id : false;
    }

    public function sendAdminNotification(array $data): bool
    {
        $subject = 'New Contact Message: ' . $data['subject'];
        $body = "<p><strong>{$data['name']}</strong> ({$data['email']})</p><p>Subject: {$data['subject']}</p><p>{$data['message']}</p>";
        return mail($this->config['admin_email'], $subject, $body,
            "From: {$this->config['mail_from']}\r\nReply-To: {$data['email']}\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n");
    }

    public function sendAutoReply(array $data): bool
    {
        $subject = 'We received your message - Kenya Stopover';
        $body = "<p>Dear {$data['name']},</p><p>We received your message about: <strong>{$data['subject']}</strong></p><p>We'll respond within 24-48 hours.</p>";
        return mail($data['email'], $subject, $body,
            "From: {$this->config['mail_from']}\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n");
    }
}
**/
