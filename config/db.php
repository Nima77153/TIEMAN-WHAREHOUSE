<?php

// Get database settings from environment variables
$host     = getenv('MYSQLHOST');
$user     = getenv('MYSQLUSER');
$password = getenv('MYSQLPASSWORD');
$database = getenv('MYSQLDATABASE');
$port     = getenv('MYSQLPORT');

// Local XAMPP settings
if (!$host) {
    $host     = "localhost";
    $user     = "root";
    $password = "";
    $database = "tieman_warehouse";
    $port     = 3306;
}

// Connect to database
$conn = @mysqli_connect(
    $host,
    $user,
    $password,
    $database,
    (int)$port
);

// Check connection
if (!$conn) {
    die("Database Connection Error: " . mysqli_connect_error());
}

// UTF-8 support
mysqli_set_charset($conn, "utf8mb4");

?>