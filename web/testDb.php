<?php
// Database connection
$conn = new mysqli("localhost", "stopover", "GRT5cilzddoe78*%", "kenyastopover");

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}


$result = $conn->query("SHOW TABLES");
$payments = $conn->query("SELECT * FROM commerce_gateways");

while($row = $payments->fetch_assoc()){
    echo "ID: " . $row['id'] . "<br>";
    echo "Name: " . $row['name'] . "<br>";
    echo "Handle: " . $row['handle'] . "<br>";
    echo "Class: " . $row['class'] . "<br>";
    echo "Enabled: " . $row['enabled'] . "<br>";
    echo "<hr>";
}

/**
if ($result) {
    while ($row = $result->fetch_array()) {
        echo "Table: " . htmlspecialchars($row[0]) . "<br>";
    }
} else {
    echo "Error fetching tables: " . $conn->error;
}


$admin = $conn->query("SELECT id, username, email FROM users");



if ($admin) {
    while ($row = $admin->fetch_assoc()) {
        echo "ID: " . $row['id'] . 
             " | Username: " . htmlspecialchars($row['username']) . 
             " | Email: " . htmlspecialchars($row['email']) . "<br>";
    }
} else {
    echo "Error fetching users: " . $conn->error;
}
$payments = $conn->query('SELECT * FROM commerce_transactions');
while ($row = $payments->fetch_assoc()){
print_r($row);
}

$orders = $conn->query("SELECT * FROM commerce_orders LIMIT 5");
while ($row = $orders->fetch_assoc()) {
    print_r($row);
}

$payments = $conn-query("SELECT * FROM commerce_gateways");
while ($row = $payments->fetch_assoc()){
	print_r($row);
}
**/

/**
$newPassword = "bravo@123";
$hashed = password_hash($newPassword, PASSWORD_DEFAULT);

// Use prepared statement (IMPORTANT)
$stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");

$userId = 1;
$stmt->bind_param("si", $hashed, $userId);

if ($stmt->execute()) {
    echo "Password updated successfully!";
} else {
    echo "Error updating password: " . $stmt->error;
}
**/

//$stmt->close();
$conn->close();
?>