<?php
session_start();
include('../config/db.php');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_inline'])) {
    $id = (int)$_POST['id'];
    $item_code = mysqli_real_escape_string($conn, trim($_POST['item_code']));
    $description = mysqli_real_escape_string($conn, trim($_POST['description']));
    $qty_per_tanker = mysqli_real_escape_string($conn, trim($_POST['qty_per_tanker']));
    $stock_date = mysqli_real_escape_string($conn, trim($_POST['stock_date']));
    $stock_qty = (int)$_POST['stock_qty'];
    $location = mysqli_real_escape_string($conn, trim($_POST['location']));

    if ($id > 0 && !empty($item_code)) {
        $update_query = "UPDATE items SET 
            item_code = '$item_code', 
            description = '$description', 
            qty_per_tanker = '$qty_per_tanker', 
            stock_date = '$stock_date', 
            stock_qty = $stock_qty, 
            location = '$location' 
            WHERE id = $id";

        if (mysqli_query($conn, $update_query)) {
            echo json_encode(['success' => true]);
            exit;
        } else {
            echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
            exit;
        }
    }
}

echo json_encode(['success' => false, 'error' => 'Invalid parameters submitted']);