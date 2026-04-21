<?php
/**
 * Quick DB diagnostic — run once to verify connection
 * DELETE this file after checking!
 */

$possiblePaths = [
    __DIR__ . '/.env',
    dirname(__DIR__) . '/.env',
    dirname(__DIR__, 2) . '/.env',
    '/var/www/vhosts/africaballoonfiesta.com/dev.kenyastopover.com/.env',
];

$envFile = null;
foreach ($possiblePaths as $path) {
    if (file_exists($path)) {
        $envFile = $path;
        break;
    }
}

$config = [];
if ($envFile) {
    echo "✅ Found .env at: $envFile\n\n";
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            [$key, $value] = explode('=', $line, 2);
            $config[trim($key)] = trim($value, " \"'\t\n\r\0\x0B");
        }
    }
} else {
    echo "❌ Could not find .env file\n\n";
}

$host = $config['APP_DB_HOST'] ?? 'localhost';
$user = $config['APP_DB_USER'] ?? 'root';
$pass = $config['APP_DB_PASS'] ?? '';
$dbName = $config['APP_DB_NAME'] ?? 'kenyastopover_db';

echo "=== Connection Details ===\n";
echo "Host:     $host\n";
echo "User:     $user\n";
echo "Database: $dbName\n\n";

try {
    $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    echo "✅ MySQL connection SUCCESS\n\n";

    $pdo->query("USE `$dbName`");
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

    $expected = ['contact_messages', 'newsletter_subscribers', 'eta_applications', 'eta_travelers', 'payment_transactions'];

    foreach ($expected as $table) {
        if (in_array($table, $tables)) {
            echo "✅ Table '$table' exists — Columns:\n";
            $cols = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($cols as $col) {
                echo "   - {$col['Field']} ({$col['Type']})\n";
            }
            echo "\n";

            // Test actual insert for eta_applications
            if ($table === 'eta_applications') {
                echo "--- Testing insert into eta_applications ---\n";
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO eta_applications
                        (reference, applicant_data, documents, total_amount, payment_status, created_at)
                        VALUES (:reference, :applicant_data, :documents, :total_amount, :payment_status, NOW())
                    ");
                    $stmt->execute([
                        ':reference' => 'TEST-' . time(),
                        ':applicant_data' => '{}',
                        ':documents' => '{}',
                        ':total_amount' => 0,
                        ':payment_status' => 'pending',
                    ]);
                    $insertId = $pdo->lastInsertId();
                    echo "✅ INSERT SUCCESS (id=$insertId)\n";
                    $pdo->exec("DELETE FROM eta_applications WHERE reference LIKE 'TEST-%'");
                    echo "✅ Cleanup done\n\n";
                } catch (PDOException $e) {
                    echo "❌ INSERT FAILED: " . $e->getMessage() . "\n\n";
                }
            }

            // Test insert for newsletter_subscribers
            if ($table === 'newsletter_subscribers') {
                echo "--- Testing insert into newsletter_subscribers ---\n";
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO newsletter_subscribers (email, status, subscribed_at)
                        VALUES (:email, 'active', NOW())
                    ");
                    $stmt->execute([':email' => 'test_' . time() . '@test.com']);
                    $insertId = $pdo->lastInsertId();
                    echo "✅ INSERT SUCCESS (id=$insertId)\n";
                    $pdo->exec("DELETE FROM newsletter_subscribers WHERE email LIKE 'test_%@test.com'");
                    echo "✅ Cleanup done\n\n";
                } catch (PDOException $e) {
                    echo "❌ INSERT FAILED: " . $e->getMessage() . "\n\n";
                }
            }
        } else {
            echo "❌ Table '$table' MISSING — run schema.sql!\n\n";
        }
    }
} catch (PDOException $e) {
    echo "❌ Connection FAILED\n";
    echo "   Error: " . $e->getMessage() . "\n\n";
    echo "=== FIX ===\n";
    echo "1. Make sure your .env has: APP_DB_USER=bravox\n";
    echo "2. If user 'bravox' doesn't exist, create it:\n";
    echo "   mysql -u root -p\n";
    echo "   CREATE USER 'bravox'@'localhost' IDENTIFIED BY 'bravo@123';\n";
    echo "   GRANT ALL PRIVILEGES ON kenyastopover_db.* TO 'bravox'@'localhost';\n";
    echo "   FLUSH PRIVILEGES;\n";
}

echo "\n<p style='color:red;font-weight:bold;'>⚠ DELETE this file after checking!</p>";
