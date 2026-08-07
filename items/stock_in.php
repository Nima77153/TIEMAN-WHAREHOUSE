<?php
session_start();
include('../config/db.php');

$message = "";
$message_type = "";

// Handle updating stock live
if (isset($_POST['execute_stock_in'])) {
    $identifier = mysqli_real_escape_string($conn, trim($_POST['item_identifier']));
    $adjust_qty = (int)$_POST['adjust_qty'];

    if (!empty($identifier) && $adjust_qty > 0) {
        // Find item matching barcode or part no
        $check = mysqli_query($conn, "SELECT id, item_code, stock_qty FROM items WHERE barcode = '$identifier' OR item_code = '$identifier'");
        
        if (mysqli_num_rows($check) > 0) {
            $item = mysqli_fetch_assoc($check);
            $item_id = $item['id'];
            
            // Perform Math increment safely 
            $update = mysqli_query($conn, "UPDATE items SET stock_qty = stock_qty + $adjust_qty WHERE id = $item_id");
            
            if ($update) {
                $message = "✅ Success! Added " . $adjust_qty . " units to Part No: " . $item['item_code'];
                $message_type = "success";
            } else {
                $message = "❌ Database error updating stock metrics.";
                $message_type = "danger";
            }
        } else {
            $message = "❌ Item code or barcode matching '" . htmlspecialchars($identifier) . "' not found.";
            $message_type = "danger";
        }
    } else {
        $message = "⚠️ Please provide a valid item identifier and quantity.";
        $message_type = "warning";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Warehouse - Stock In Processing</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background:#f4f6f9; font-family:'Segoe UI', sans-serif; }
        .sidebar { width:250px; height:100vh; background:#111827; position:fixed; left:0; top:0; overflow:auto; z-index: 100; }
        .logo { background:#f97316; padding:20px; text-align:center; font-size:22px; font-weight:bold; color:white; }
        .sidebar a { display:block; padding:15px; color:white; text-decoration:none; transition:.3s; }
        .sidebar a:hover { background:#f97316; }
        .main { margin-left:250px; padding:20px; }
        .card-box { background:white; padding:20px; border-radius:12px; box-shadow:0 2px 10px rgba(0,0,0,.08); margin-bottom: 20px;}
        @media(max-width: 768px) {
            .sidebar { display: none; }
            .main { margin-left: 0; padding: 10px; }
        }
    </style>
</head>
<body>

    <div class="sidebar">
        <div class="logo">WAREHOUSE</div>
        <a href="../dashboard.php">🏠 Dashboard</a>
        <a href="item_list.php">📦 Items</a>
        <a href="stock_in.php" style="background:#f97316;">⬆️ Stock In</a>
        <a href="stock_out.php">⬇️ Stock Out</a>
        <a href="../jobs/job_list.php">📝 Job List</a>
        <a href="../logout.php">🚪 Logout</a>
    </div>

    <div class="main">
        <div class="topbar card-box d-flex justify-content-between align-items-center">
            <h4 class="m-0 text-success fw-bold">⬆️ Stock In Entry Station</h4>
            <div class="badge bg-dark p-2">Device: Phone/Tablet Active</div>
        </div>

        <?php if(!empty($message)): ?>
            <div class="alert alert-<?= $message_type ?> alert-dismissible fade show" role="alert">
                <?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="row g-3 justify-content-center">
            <div class="col-lg-8">
                <div class="card-box">
                    <h5 class="mb-4 text-secondary text-center">⚙️ Warehouse Mobile Scan Input Form</h5>
                    <form method="POST" id="stockInForm">
                        
                        <div class="mb-4 text-center">
                            <label class="form-label fw-bold d-block mb-2">Tap Below to Scan Barcode Label</label>
                            <input type="text" 
                                   name="item_identifier" 
                                   id="item_identifier" 
                                   class="form-control form-control-lg border-2 text-center" 
                                   placeholder="👉 Tap here to trigger scanner 👈" 
                                   autofocus
                                   scanner="true"
                                   data-scanner="true"
                                   required>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-bold d-block text-center">Quantity Value to Add (+)</label>
                            <input type="number" name="adjust_qty" class="form-control form-control-lg border-2 text-center fw-bold" min="1" value="1" required>
                        </div>
                        
                        <button type="submit" name="execute_stock_in" class="btn btn-success btn-lg w-100 py-3 fw-bold shadow-sm">💾 Book Quantities Inward</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>