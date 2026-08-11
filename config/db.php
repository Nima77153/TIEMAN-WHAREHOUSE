<?php

// ==========================================
// DATABASE CONFIGURATION
// XAMPP LOCAL + RAILWAY ONLINE
// ==========================================

$host     = getenv('mysql.railway.internal');
$user     = getenv('root');
$password = getenv('AAtuqmkJIgjAQnNWlpmfZuDFnWsKVAzG');
$database = getenv('railway');
$port     = getenv('3306');

// ==========================================
// LOCAL XAMPP FALLBACK
// ==========================================

if (!$host) {
    $host     = "mysql.railway.internal";
    $user     = "root";
    $password = "";
    $database = "tieman_warehouse";
    $port     = 3306;
}

// ==========================================
// DATABASE CONNECTION
// ==========================================

$conn = @mysqli_connect(
    $host,
    $user,
    $password,
    $database,
    (int)$port
);

// ==========================================
// CONNECTION ERROR
// ==========================================

if (!$conn) {
    die("Database Connection Error: " . mysqli_connect_error());
}

// ==========================================
// CHARACTER SET
// ==========================================

mysqli_set_charset($conn, "utf8mb4");

?>