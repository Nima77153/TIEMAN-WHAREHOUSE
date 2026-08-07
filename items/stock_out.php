<?php
session_start();
include('../config/db.php');

$message = "";
$message_type = "";

// Handle updating stock live
if (isset($_POST['execute_stock_out'])) {
    $identifier = mysqli_real_escape_string($conn, trim($_POST['item_identifier']));
    $adjust_qty = (int)$_POST['adjust_qty'];

    if (!empty($identifier) && $adjust_qty > 0) {
        $check = mysqli_query($conn, "SELECT id, item_code, stock_qty FROM items WHERE barcode = '$identifier' OR item_code = '$identifier'");
        
        if (mysqli_num_rows($check) > 0) {
            $item = mysqli_fetch_assoc($check);
            $item_id = $item['id'];
            $current_available = $item['stock_qty'];

            // Operational Boundary Safeguard Rule: Protect against negative counts
            if ($current_available < $adjust_qty) {
                $message = "⚠️ Shortage Warning! Cannot complete subtraction. Only <b>" . $current_available . "</b> parts are left in inventory allocation profile.";
                $message_type = "danger";
            } else {
                // Execute clean dynamic deduction math query update logic
                $update = mysqli_query($conn, "UPDATE items SET stock_qty = stock_qty - $adjust_qty WHERE id = $item_id");
                if ($update) {
                    $message = "✅ Success! Dispatched " . $adjust_qty . " units from Part No: " . $item['item_code'];
                    $message_type = "success";
                } else {
                    $message = "❌ System fault error allocating ledger output update.";
                    $message_type = "danger";
                }
            }
        } else {
            $message = "❌ Item matching identity input parameter reference '" . htmlspecialchars($identifier) . "' not found inside structural master indices.";
            $message_type = "danger";
        }
    } else {
        $message = "⚠️ Incomplete form structural variable specifications.";
        $message_type = "warning";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Warehouse - Stock Out Dispatch Station</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://unpkg.com/html5-qrcode"></script>
    <style>
        body { background:#f4f6f9; font-family:'Segoe UI', sans-serif; }
        .sidebar { width:250px; height:100vh; background:#111827; position:fixed; left:0; top:0; overflow:auto; z-index: 100; }
        .logo { background:#f97316; padding:20px; text-align:center; font-size:22px; font-weight:bold; color:white; }
        .sidebar a { display:block; padding:15px; color:white; text-decoration:none; transition:.3s; }
        .sidebar a:hover { background:#f97316; }
        .main { margin-left:250px; padding:20px; }
        .card-box { background:white; padding:20px; border-radius:12px; box-shadow:0 2px 10px rgba(0,0,0,.08); margin-bottom: 20px;}
        #qr-reader { width: 100%; max-width: 450px; margin: 0 auto; border: 2px dashed #ef4444 !important; border-radius: 8px; }
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
        <a href="stock_in.php">⬆️ Stock In</a>
        <a href="stock_out.php" style="background:#f97316;">⬇️ Stock Out</a>
        <a href="../jobs/job_list.php">📝 Job List</a>
        <a href="../logout.php">🚪 Logout</a>
    </div>

    <div class="main">
        <div class="topbar card-box d-flex justify-content-between align-items-center">
            <h4 class="m-0 text-danger fw-bold">⬇️ Stock Out Dispatch Station</h4>
            <div class="badge bg-dark p-2">Device: Phone/Tablet Active</div>
        </div>

        <?php if(!empty($message)): ?>
            <div class="alert alert-<?= $message_type ?> alert-dismissible fade show" role="alert">
                <?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="row g-3">
            <div class="col-lg-6">
                <div class="card-box text-center">
                    <h5 class="mb-3 text-secondary">📷 Mobile Camera Barcode Scan Panel</h5>
                    <div id="qr-reader"></div>
                    <div id="qr-reader-results" class="mt-2 fw-bold text-danger"></div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card-box">
                    <h5 class="mb-4 text-secondary">⚙️ Execution Metrics Form</h5>
                    <form method="POST" id="stockOutForm">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Scanned Barcode / Item Code</label>
                            <input type="text" name="item_identifier" id="item_identifier" class="form-control form-control-lg border-2" placeholder="Scan barcode or type item code..." required>
                        </div>
                        <div class="mb-4">
                            <label class="form-label fw-bold">Quantity Value to Subtract (-)</label>
                            <input type="number" name="adjust_qty" class="form-control form-control-lg border-2 text-center fw-bold" min="1" value="1" required>
                        </div>
                        <button type="submit" name="execute_stock_out" class="btn btn-danger btn-lg w-100 py-3 fw-bold shadow-sm">💾 Book Quantities Outward</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let html5QrcodeScanner;
        
        function onScanSuccess(decodedText, decodedResult) {
            document.getElementById('item_identifier').value = decodedText;
            document.getElementById('qr-reader-results').innerText = "🎯 Scanned Value: " + decodedText;
            if(navigator.vibrate) navigator.vibrate(100);
        }

        function initScanner() {
            html5QrcodeScanner = new Html5QrcodeScanner("qr-reader", { 
                fps: 15, 
                qrbox: { width: 280, height: 160 },
                rememberLastUsedCamera: true,
                supportedScanTypes: [Html5QrcodeScanType.SCAN_TYPE_CAMERA]
            });
            html5QrcodeScanner.render(onScanSuccess);
        }

        window.addEventListener('DOMContentLoaded', initScanner);
    </script>
</body>
</html>