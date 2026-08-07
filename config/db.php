<?php
// Dynamic database settings for Local, Railway, and Render
$host     = getenv('MYSQLHOST')     ?: "localhost";
$user     = getenv('MYSQLUSER')     ?: "root";
$password = getenv('MYSQLPASSWORD') ?: "";
$database = getenv('MYSQLDATABASE') ?: "tieman_warehouse";
$port     = getenv('MYSQLPORT')     ?: 3306;

// Suppress raw crash errors to handle connection safely
$conn = @mysqli_connect($host, $user, $password, $database, (int)$port);

if (!$conn) {
    die("Database Connection Error: " . mysqli_connect_error());
}
?>