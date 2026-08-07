<?php
session_start();

// Dynamic database path checking to eliminate directory mismatch errors
if (file_exists('../config/db.php')) {
    include('../config/db.php');
} else if (file_exists('config/db.php')) {
    include('config/db.php');
} else {
    include('../../config/db.php');
}

$message = "";
$message_type = "";
$box_contents = [];
$scanned_box_code = "";
$searched_item = null;

// 1. HANDLE BARCODE SCAN SEARCH
if (isset($_POST['execute_search'])) {
    $identifier = mysqli_real_escape_string($conn, trim($_POST['item_identifier']));

    if (!empty($identifier)) {
        $check_item = mysqli_query($conn, "SELECT id, item_code, barcode, stock_qty FROM items WHERE barcode = '$identifier' OR item_code = '$identifier'");
        
        if (mysqli_num_rows($check_item) > 0) {
            $searched_item = mysqli_fetch_assoc($check_item);
        } else {
            $scanned_box_code = htmlspecialchars($identifier);
            loadBoxItems($conn, $identifier);
        }
        
        if (!$searched_item && empty($box_contents)) {
            $message = "❌ Code '" . htmlspecialchars($identifier) . "' not found in warehouse records.";
            $message_type = "danger";
        }
    }
}

// 2. HANDLE QUANTITY SUBMISSION MODS (IN / OUT STOCKS)
if (isset($_POST['adjust_stock_action'])) {
    $item_id = (int)$_POST['action_item_id'];
    $qty = (int)$_POST['adjust_qty'];
    $action_type = $_POST['action_type'];

    if ($qty > 0) {
        if ($action_type === 'in') {
            mysqli_query($conn, "UPDATE items SET stock_qty = stock_qty + $qty WHERE id = $item_id");
            $message = "✅ Added $qty units to inventory successfully!";
            $message_type = "success";
        } else {
            mysqli_query($conn, "UPDATE items SET stock_qty = stock_qty - $qty WHERE id = $item_id");
            $message = "⬇️ Deducted $qty units from inventory successfully!";
            $message_type = "success";
        }
        // Retain focus item with renewed data values
        $recheck = mysqli_query($conn, "SELECT * FROM items WHERE id = $item_id");
        $searched_item = mysqli_fetch_assoc($recheck);
    }
}

// 3. BOX INNER LIST DIRECT DEDUCTIONS
if (isset($_POST['box_action_out'])) {
    $item_id = (int)$_POST['action_item_id'];
    $deduct_qty = (int)$_POST['action_qty'];
    $scanned_box_code = htmlspecialchars($_POST['current_box_code']);

    mysqli_query($conn, "UPDATE items SET stock_qty = stock_qty - $deduct_qty WHERE id = $item_id");
    $message = "⬇️ Box component stock updated.";
    $message_type = "success";
    
    loadBoxItems($conn, $scanned_box_code);
}

function loadBoxItems($conn, $box_barcode) {
    global $box_contents;
    $box_query = mysqli_query($conn, "SELECT i.id, i.item_code, i.barcode, i.stock_qty, b.qty_inside 
                                      FROM box_items b 
                                      JOIN items i ON b.item_id = i.id 
                                      WHERE b.box_barcode = '$box_barcode'");
    if (mysqli_num_rows($box_query) > 0) {
        while ($row = mysqli_fetch_assoc($box_query)) {
            $box_contents[] = $row;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Warehouse - Automated PC Scan Center</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background:#f4f6f9; font-family:'Segoe UI', sans-serif; }
        .sidebar { width:250px; height:100vh; background:#111827; position:fixed; left:0; top:0; overflow:auto; z-index:100; }
        .logo { background:#f97316; padding:20px; text-align:center; font-size:22px; font-weight:bold; color:white; }
        .sidebar a { display:block; padding:15px; color:white; text-decoration:none; transition:.3s; }
        .sidebar a:hover { background:#f97316; }
        .main { margin-left:250px; padding:20px; }
        .card-box { background:white; padding:20px; border-radius:12px; box-shadow:0 2px 10px rgba(0,0,0,.08); margin-bottom:20px; }
        .item-img { max-width: 130px; max-height: 130px; object-fit: contain; border-radius: 8px; border: 1px solid #ddd; }
        .hotkey-badge { font-size: 12px; background: #e2e8f0; color: #475569; padding: 2px 6px; border-radius: 4px; font-weight: bold;}
    </style>
</head>
<body>

    <div class="sidebar">
        <div class="logo">WAREHOUSE</div>
        <a href="../dashboard.php">🏠 Dashboard</a>
        <a href="item_list.php">📦 Items</a>
        <a href="scaner.php" style="background:#f97316;">🔄 PC Scan Center</a>
        <a href="../jobs/job_list.php">📝 Job List</a>
        <a href="../logout.php">🚪 Logout</a>
    </div>

    <div class="main">
        <div class="topbar card-box d-flex justify-content-between align-items-center mb-4">
            <h4 class="m-0 text-dark fw-bold">🔄 Automated Hardware Scan Station</h4>
            <span class="badge bg-success p-2">⚡ Barcode to PC Active</span>
        </div>

        <?php if(!empty($message)): ?>
            <div class="alert alert-<?= $message_type ?> alert-dismissible fade show shadow-sm" role="alert">
                <?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="card-box text-center <?= $searched_item ? 'bg-light' : '' ?>">
            <h5 class="fw-bold text-secondary mb-2">Step 1: Scan Part or Box Barcode Code</h5>
            <form method="POST" id="pcScanForm">
                <input type="text" 
                       name="item_identifier" 
                       id="item_identifier" 
                       class="form-control form-control-lg border-2 text-center fw-bold text-uppercase" 
                       style="font-size: 24px; max-width: 500px; margin: 0 auto;"
                       placeholder="[ Ready for Scan Input ]" 
                       value="<?= isset($_POST['item_identifier']) && !$message ? htmlspecialchars($_POST['item_identifier']) : '' ?>"
                       autocomplete="off" 
                       required>
                <input type="hidden" name="execute_search" value="1">
            </form>
        </div>

        <?php if ($searched_item): ?>
            <div class="card-box border border-success bg-white shadow animate-fade-in">
                <div class="row align-items-center">
                    <div class="col-md-6 d-flex align-items-center gap-4">
                        <?php 
                        $img_path = "../images/" . $searched_item['item_code'] . ".png";
                        $src_img = file_exists($img_path) ? $img_path : "../images/default.png";
                        ?>
                        <img src="<?= $src_img ?>" class="item-img" alt="Product Frame">
                        <div>
                            <h2 class="text-dark fw-bold m-0"><?= htmlspecialchars($searched_item['item_code']) ?></h2>
                            <p class="text-muted mb-2">Barcode Link: <?= htmlspecialchars($searched_item['barcode']) ?></p>
                            <h4 class="text-dark m-0">Current Quantity: <span class="badge bg-warning text-dark px-3 font-monospace"><?= $searched_item['stock_qty'] ?> PCS</span></h4>
                        </div>
                    </div>
                    
                    <div class="col-md-6 border-start ps-4">
                        <form method="POST" id="stockAdjustmentForm" class="text-center">
                            <input type="hidden" name="action_item_id" value="<?= $searched_item['id'] ?>">
                            <input type="hidden" name="action_type" id="action_type" value="in">
                            <input type="hidden" name="adjust_stock_action" value="1">

                            <label class="form-label fw-bold mb-2 text-success fs-5">Step 2: Type Quantity & Press Key</label>
                            
                            <div class="input-group mb-3 mx-auto" style="max-width: 200px;">
                                <input type="number" 
                                       name="adjust_qty" 
                                       id="adjust_qty" 
                                       class="form-control form-control-lg text-center fw-bold border-success border-2" 
                                       value="1" 
                                       min="1" 
                                       required>
                            </div>

                            <div class="d-flex gap-2 justify-content-center">
                                <button type="button" onclick="submitAdjustment('in')" class="btn btn-success btn-lg px-4 fw-bold">
                                    ⬆️ Stock IN <span class="hotkey-badge">Press +</span>
                                </button>
                                <button type="button" onclick="submitAdjustment('out')" class="btn btn-danger btn-lg px-4 fw-bold">
                                    ⬇️ Stock OUT <span class="hotkey-badge">Press -</span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($box_contents)): ?>
            <div class="card-box border border-primary bg-white shadow">
                <h4 class="text-primary fw-bold mb-4">📦 Current Box Registry Breakdown: <?= $scanned_box_code ?></h4>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover align-middle m-0">
                        <thead class="table-dark">
                            <tr>
                                <th style="width: 100px;">Photo</th>
                                <th>Part Code</th>
                                <th class="text-center">Stored Inside Box</th>
                                <th class="text-center">Global Total Stock</th>
                                <th class="text-center" style="width: 250px;">Direct Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($box_contents as $contentItem): ?>
                                <tr>
                                    <td class="text-center">
                                        <?php 
                                        $b_img = "../images/" . $contentItem['item_code'] . ".png";
                                        $b_src = file_exists($b_img) ? $b_img : "../images/default.png";
                                        ?>
                                        <img src="<?= $b_src ?>" style="width:65px; height:65px; object-fit:contain;" class="rounded border">
                                    </td>
                                    <td>
                                        <div class="fw-bold fs-5 text-dark"><?= htmlspecialchars($contentItem['item_code']) ?></div>
                                        <small class="text-muted font-monospace"><?= htmlspecialchars($contentItem['barcode']) ?></small>
                                    </td>
                                    <td class="text-center fw-bold text-primary fs-5"><?= $contentItem['qty_inside'] ?> Units</td>
                                    <td class="text-center"><span class="badge bg-secondary fs-6"><?= $contentItem['stock_qty'] ?> PCS Left</span></td>
                                    <td>
                                        <form method="POST" class="d-flex gap-2 justify-content-center">
                                            <input type="hidden" name="action_item_id" value="<?= $contentItem['id'] ?>">
                                            <input type="hidden" name="current_box_code" value="<?= $scanned_box_code ?>">
                                            <input type="number" name="action_qty" class="form-control text-center fw-bold" style="width:75px;" value="1" min="1">
                                            <button type="submit" name="box_action_out" class="btn btn-danger fw-bold btn-sm px-3">⬇️ Out Stock</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        const searchInput = document.getElementById('item_identifier');
        const qtyInput = document.getElementById('adjust_qty');

        window.onload = function() {
            // Focus on quantity box if item is already loaded, otherwise focus on main search scanner field
            if (qtyInput) {
                qtyInput.focus();
                qtyInput.select();
            } else {
                searchInput.focus();
            }
        };

        // Automatic search listener for Barcode to PC transmissions
        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                if (searchInput.value.trim() !== "") {
                    document.getElementById('pcScanForm').submit();
                }
            }
        });

        // Fast Keyboard Hotkey Operations (+ for Stock IN, - for Stock OUT)
        document.addEventListener('keydown', function(e) {
            if (qtyInput && document.activeElement === qtyInput) {
                if (e.key === '+') {
                    e.preventDefault();
                    submitAdjustment('in');
                } else if (e.key === '-') {
                    e.preventDefault();
                    submitAdjustment('out');
                }
            }
        });

        function submitAdjustment(type) {
            document.getElementById('action_type').value = type;
            document.getElementById('stockAdjustmentForm').submit();
        }

        // Return cursor focus back to correct input form automatically if white background is tapped
        document.addEventListener('click', function(e) {
            if (e.target.tagName !== 'INPUT') {
                if (qtyInput) {
                    qtyInput.focus();
                } else {
                    searchInput.focus();
                }
            }
        });
    </script>
</body>
</html>