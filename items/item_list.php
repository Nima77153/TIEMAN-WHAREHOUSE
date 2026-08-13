<?php
session_start();
include('../config/db.php');

// Handle Multi-Select Batch Delete action safely
if (isset($_POST['batch_delete']) && !empty($_POST['selected_items'])) {
    $ids_to_delete = $_POST['selected_items'];
    $sanitized_ids = array_map('intval', $ids_to_delete);
    $id_list = implode(',', $sanitized_ids);
    
    if (mysqli_query($conn, "DELETE FROM items WHERE id IN ($id_list)")) {
        echo "<script>alert('Selected inventory records have been dropped successfully.'); window.location='item_list.php';</script>";
        exit;
    }
}

// Track Search Inputs
$search = isset($_GET['search']) ? trim($_GET['search']) : "";
$search_param = "%".$search."%";

// Track and apply user sorting selections
$sort_option = isset($_GET['sort_by']) ? trim($_GET['sort_by']) : "first_last";

// Resolve SQL order string dynamically based on selected option
switch ($sort_option) {
    case 'a_z':
        $order_query = "ORDER BY item_name ASC, description ASC";
        break;
    case '1_z':
        $order_query = "ORDER BY LENGTH(item_code) ASC, item_code ASC";
        break;
    case 'category':
        $order_query = "ORDER BY location ASC, id DESC";
        break;
    case 'first_last':
    default:
        $order_query = "ORDER BY id DESC";
        break;
}

$stmt = $conn->prepare("
SELECT * FROM items
WHERE item_name LIKE ?
OR item_code LIKE ?
OR barcode LIKE ?
OR description LIKE ?
OR location LIKE ?
$order_query
");

$stmt->bind_param("sssss", $search_param, $search_param, $search_param, $search_param, $search_param);
$stmt->execute();
$result = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Warehouse Item List</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background:#f4f6f9; font-family:'Segoe UI', sans-serif; }
        .sidebar { width:250px; height:100vh; background:#111827; position:fixed; left:0; top:0; overflow:auto; z-index: 100; }
        .logo { background:#f97316; padding:20px; text-align:center; font-size:22px; font-weight:bold; color:white; }
        .sidebar a { display:block; padding:15px; color:white; text-decoration:none; transition:.3s; }
        .sidebar a:hover { background:#f97316; }
        .main { margin-left:250px; padding:20px; }
        .topbar { background:white; padding:15px 20px; border-radius:10px; box-shadow:0 2px 10px rgba(0,0,0,.1); margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; }
        .card-box { background:white; padding:20px; border-radius:12px; box-shadow:0 2px 10px rgba(0,0,0,.08); }
        
        .excel-header { background-color: #2e7d32 !important; color: white !important; font-size: 14px; text-align: center; }
        .excel-sub-header { background-color: #a5d6a7 !important; color: #1b5e20 !important; font-size: 12px; font-weight: bold; text-align: center; }
        .table img { border-radius:4px; border:1px solid #ccc; object-fit:contain; background: #fff; }
        .low-stock { color:red; font-weight:bold; text-align: center; }
        .good-stock { color:green; font-weight:bold; text-align: center; }
        .batch-delete-panel { background: #fff1f2; border: 1px solid #fecdd3; border-radius: 6px; padding: 10px 15px; display: none; align-items: center; justify-content: space-between; }
    </style>
</head>
<body>

    <div class="sidebar">
        <div class="logo">WAREHOUSE</div>
        <a href="http://localhost/TIEMAN%20WAREHOUSE/dashboard.php">🏠 Dashboard</a>
        <a href="http://localhost/TIEMAN%20WAREHOUSE/items/item_list.php">📦 Items</a>
        <a href="http://localhost/TIEMAN%20WAREHOUSE/items/add_item.php">➕ Add Item</a>
        <a href="http://localhost/TIEMAN%20WAREHOUSE/items/import_excel.php">📥 Import Excel</a>
        <a href="http://localhost/TIEMAN%20WAREHOUSE/jobs/job_list.php">📝 Job List</a>
        <a href="http://localhost/TIEMAN%20WAREHOUSE/logout.php">🚪 Logout</a>
    </div>

    <div class="main">
        <div class="topbar">
            <h4>Warehouse Item List</h4>
            <div>Welcome, <strong><?= isset($_SESSION['user']) ? htmlspecialchars($_SESSION['user']) : 'Admin'; ?></strong></div>
        </div>

        <div class="card-box">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h3>Item Log Catalog View</h3>
                <div class="d-flex gap-2">
                    <a href="../barcode/print_barcode.php" target="_blank" class="btn btn-dark d-flex align-items-center">📋 Print Selected Barcodes</a>
                    <a href="add_item.php" class="btn btn-warning d-flex align-items-center">+ Add Item</a>
                </div>
            </div>

            <form method="GET" class="mb-4">
                <div class="row g-2 align-items-center">
                    <div class="col-md-4">
                        <input type="text" name="search" class="form-control" placeholder="Search Part No / Description / Barcode / Location..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                    
                    <div class="col-md-3">
                        <select name="sort_by" class="form-select" onchange="this.form.submit()">
                            <option value="first_last" <?= ($sort_option == 'first_last') ? 'selected' : '' ?>>🔃 Sort: Newest First (First & Last)</option>
                            <option value="a_z" <?= ($sort_option == 'a_z') ? 'selected' : '' ?>>🔤 Sort: Item Name (A-Z)</option>
                            <option value="1_z" <?= ($sort_option == '1_z') ? 'selected' : '' ?>>🔢 Sort: Part No (1-Z)</option>
                            <option value="category" <?= ($sort_option == 'category') ? 'selected' : '' ?>>📍 Sort: Category / Location</option>
                        </select>
                    </div>

                    <div class="col-md-3 d-flex gap-2">
                        <button type="submit" class="btn btn-primary px-4">Search</button>
                        <?php if(!empty($search) || $sort_option !== 'first_last'): ?>
                            <a href="item_list.php" class="btn btn-secondary px-3">Clear</a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>

            <form id="bulkActionForm" method="POST" onsubmit="return confirm('Are you sure you want to completely remove the selected item assets? This cannot be undone.');">
                
                <div id="batchDeleteBar" class="batch-delete-panel mb-3">
                    <div class="text-danger fw-semibold">
                        ⚠️ <span id="selectedCount">0</span> row items selected for action.
                    </div>
                    <button type="submit" name="batch_delete" class="btn btn-danger btn-sm px-3">Delete Selected Records</button>
                </div>

                <div class="table-responsive">
                    <table class="table table-bordered table-hover align-middle">
                        <thead>
                            <tr class="excel-header">
                                <th rowspan="2" width="40" class="text-center">
                                    <input type="checkbox" id="selectAllRows" class="form-check-input">
                                </th>
                                <th rowspan="2">IMAGE</th>
                                <th rowspan="2">PART NO</th>
                                <th rowspan="2">DESCRIPTION</th>
                                <th>QTY PER TANKER</th>
                                <th>STOCK DATE</th>
                                <th rowspan="2">CURRENT QTY</th>
                                <th rowspan="2">REMARK / LOCATION</th>
                                <th rowspan="2" width="260">ACTION</th>
                            </tr>
                            <tr class="excel-sub-header">
                                <td>HOW MUCH USE PER TANK</td>
                                <td>MONTH</td>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($result->num_rows > 0) {
                                while($row = $result->fetch_assoc()) { 
                                    // Smart image URL handler logic
                                    $img_src = '../assets/images/no-image.png';
                                    if (!empty($row['image'])) {
                                        $db_img = trim($row['image']);
                                        // Check if full URL (e.g. Cloudinary or external link)
                                        if (preg_match('/^https?:\/\//i', $db_img)) {
                                            $img_src = $db_img;
                                        } 
                                        // Check if path already starts with /
                                        elseif (strpos($db_img, '/') === 0) {
                                            $img_src = '..' . $db_img;
                                        } 
                                        // Standard local filename
                                        else {
                                            $img_src = '../uploads/items/' . $db_img;
                                        }
                                    }
                                ?>
                                <tr>
                                    <td class="text-center">
                                        <input type="checkbox" name="selected_items[]" value="<?= $row['id'] ?>" class="form-check-input row-select-checkbox" onclick="evaluateCheckboxState()">
                                    </td>
                                    <td class="text-center">
                                        <img src="<?= htmlspecialchars($img_src) ?>" 
                                             width="65" 
                                             height="65" 
                                             alt="Item" 
                                             onerror="this.onerror=null; this.src='../assets/images/no-image.png';">
                                    </td>
                                    <td class="fw-bold text-secondary">
                                        <a href="../barcode/print_barcode.php?code=<?= urlencode($row['item_code']) ?>" target="_blank" class="text-decoration-none text-primary">
                                            <?= htmlspecialchars($row['item_code']) ?>
                                        </a>
                                    </td>
                                    <td style="font-size: 13px; max-width: 300px; font-weight: 500;">
                                        <?= htmlspecialchars($row['description'] ?? $row['item_name'] ?? '-') ?>
                                    </td>
                                    <td class="text-center"><?= htmlspecialchars($row['qty_per_tanker'] ?? '-') ?></td>
                                    <td class="text-center"><?= htmlspecialchars($row['stock_date'] ?? '-') ?></td>
                                    <td>
                                        <?php if($row['stock_qty'] <= 0) { ?>
                                            <span class="low-stock"><?= $row['stock_qty'] ?></span>
                                        <?php } else { ?>
                                            <span class="good-stock"><?= $row['stock_qty'] ?></span>
                                        <?php } ?>
                                    </td>
                                    <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($row['location'] ?? '-') ?></span></td>
                                    <td>
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="view_item.php?id=<?= $row['id'] ?>" class="btn btn-primary">View</a>
                                            <a href="edit_item.php?id=<?= $row['id'] ?>" class="btn btn-success">Edit</a>
                                            <a href="delete_item.php?id=<?= $row['id'] ?>" class="btn btn-danger" onclick="return confirm('Delete item?')">Delete</a>
                                            <a href="../barcode/print_barcode.php?code=<?= urlencode($row['item_code']) ?>" target="_blank" class="btn btn-dark">🏷️ Barcode</a>
                                        </div>
                                    </td>
                                </tr>
                                <?php } 
                            } else { ?>
                                <tr>
                                    <td colspan="9" class="text-center py-4 text-muted">No Matching Warehouse Items Found</td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Checkbox Batch Handling Selection Core
        const masterCheckbox = document.getElementById('selectAllRows');
        const standardCheckboxes = document.querySelectorAll('.row-select-checkbox');
        const batchDeleteBar = document.getElementById('batchDeleteBar');
        const selectedCountLabel = document.getElementById('selectedCount');

        masterCheckbox.addEventListener('change', function() {
            standardCheckboxes.forEach(box => {
                box.checked = this.checked;
            });
            evaluateCheckboxState();
        });

        function evaluateCheckboxState() {
            let activeSelections = 0;
            standardCheckboxes.forEach(box => {
                if(box.checked) activeSelections++;
            });

            selectedCountLabel.textContent = activeSelections;
            if(activeSelections > 0) {
                batchDeleteBar.style.display = 'flex';
            } else {
                batchDeleteBar.style.display = 'none';
                masterCheckbox.checked = false;
            }
        }
    </script>
</body>
</html>