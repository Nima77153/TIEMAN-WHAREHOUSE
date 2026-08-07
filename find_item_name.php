<?php
include('../config/db.php');

if (isset($_POST['identifier'])) {
    $identifier = mysqli_real_escape_string($conn, trim($_POST['identifier']));
    
    // Adjust your column names here if necessary (e.g., 'item_name' or 'description')
    $query = mysqli_query($conn, "SELECT item_code, stock_qty FROM items WHERE barcode = '$identifier' OR item_code = '$identifier' LIMIT 1");
    
    if (mysqli_num_rows($query) > 0) {
        $row = mysqli_fetch_assoc($query);
        // Returning the item code/name and current available stock back to the page
        echo "📦 Found Item: <b>" . htmlspecialchars($row['item_code']) . "</b> (Current Stock: " . $row['stock_qty'] . ")";
    } else {
        echo "<span class='text-danger'>❌ No matching item found in database.</span>";
    }
}
?>