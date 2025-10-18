<?php
// Database configuration for EAMTS (E-Asset Management System)

// Database credentials
$host = 'localhost';        // Database host
$dbname = 'eamts';         // Database name
$username = 'root';        // Database username (change this)
$password = '';            // Database password (change this)
$charset = 'utf8mb4';

// Data Source Name (DSN)
$dsn = "mysql:host=$host;dbname=$dbname;charset=$charset";

// PDO options
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    // Create PDO connection
    $pdo = new PDO($dsn, $username, $password, $options);
    
    // Optional: Set timezone
    $pdo->exec("SET time_zone = '+08:00'"); // Malaysia timezone
    
} catch (PDOException $e) {
    // Log the error (in production, don't display the actual error message)
    error_log("Database Connection Error: " . $e->getMessage());
    
    // Display user-friendly error message
    die("Database connection failed. Please try again later.");
}

// Optional: Function to close connection
function closeConnection() {
    global $pdo;
    $pdo = null;
}
?>