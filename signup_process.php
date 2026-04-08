<?php
// File: signup_process.php
require_once __DIR__ . '/config.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: signup.php');
    exit;
}

$role = $_POST['role'] ?? '';
$name = trim($_POST['name'] ?? '');
$email = trim($_POST['email'] ?? '');
$tel = trim($_POST['tel'] ?? '');
$password = $_POST['password'] ?? '';

// Basic validation
if (empty($role) || empty($name) || empty($email) || empty($password)) {
    die("Please fill in all required fields.");
}

// Check if email already exists across all roles
$roleConfigs = [
    ['table' => 'Client', 'email_col' => 'cemail'],
    ['table' => 'Designer', 'email_col' => 'demail'],
    ['table' => 'Manager', 'email_col' => 'memail'],
    ['table' => 'Supplier', 'email_col' => 'semail'],
    ['table' => 'Contractors', 'email_col' => 'cemail']
];

foreach ($roleConfigs as $cfg) {
    $sql = "SELECT 1 FROM " . $cfg['table'] . " WHERE " . $cfg['email_col'] . " = ? LIMIT 1";
    $stmt = $mysqli->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("s", $email);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            die("Error: Email already registered.");
        }
    }
}

// Insert into specific table based on role
switch ($role) {
    case 'client':
        $address = trim($_POST['address'] ?? '');
        $budget = 0; // Default budget to 0 as it's no longer in the signup form
        $sql = "INSERT INTO Client (cname, ctel, cemail, cpassword, address, budget) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("sssssi", $name, $tel, $email, $password, $address, $budget);
        break;

    case 'designer':
        // Default status is 'Available' as per DB schema
        $sql = "INSERT INTO Designer (dname, dtel, demail, dpassword, status) VALUES (?, ?, ?, ?, 'Available')";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("ssss", $name, $tel, $email, $password);
        break;

    case 'supplier':
        $sql = "INSERT INTO Supplier (sname, stel, semail, spassword) VALUES (?, ?, ?, ?)";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("ssss", $name, $tel, $email, $password);
        break;

    case 'manager':
        $sql = "INSERT INTO Manager (mname, mtel, memail, mpassword) VALUES (?, ?, ?, ?)";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("ssss", $name, $tel, $email, $password);
        break;

    default:
        die("Invalid role selected.");
}

if ($stmt->execute()) {
    // Registration successful, redirect to login
    echo "<script>alert('Registration successful! Please login.'); window.location.href='login.php';</script>";
} else {
    echo "Error: " . $stmt->error;
}
?>
