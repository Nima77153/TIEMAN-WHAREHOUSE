<?php

// ==========================================
// DYNAMIC BASE URL
// Works with XAMPP and Render
// ==========================================

$protocol = (
    isset($_SERVER['HTTPS']) &&
    $_SERVER['HTTPS'] === 'on'
) ? 'https' : 'http';

$base_url = $protocol . '://' . $_SERVER['HTTP_HOST'];

?>