<?php
session_start();
include('../config/db.php');

if (!$conn) {
    die("Database Connection Error: " . mysqli_connect_error());
}

// Helper function to dynamically check local images OR full Cloudinary URLs
function getDynamicImagePath($image_file) {
    if (empty($image_file)) {
        return false;
    }

    $image_file = trim($image_file);

    // If database already contains a Cloudinary or full URL
    if (preg_match('/^https?:\/\//i', $image_file)) {
        return $image_file;
    }

    // Local image processing
    $image_file = str_replace('\\', '/', $image_file);
    $image_file = basename($image_file);
    $filenameWithoutExt = pathinfo($image_file, PATHINFO_FILENAME);

    $possibleExtensions = ['jpg', 'jpeg', 'png', 'JPG', 'JPEG', 'PNG', 'webp', 'emf'];

    $search_directories = [
        $_SERVER['DOCUMENT_ROOT'] . "/uploads/items/",
        $_SERVER['DOCUMENT_ROOT'] . "/uploads/",
        $_SERVER['DOCUMENT_ROOT'] . "/assets/images/"
    ];

    // Check exact name first
    foreach ($search_directories as $dir) {
        if (file_exists($dir . $image_file)) {
            return str_replace($_SERVER['DOCUMENT_ROOT'], '..', $dir . $image_file);
        }
    }

    // Fallback: check alternative extensions
    foreach ($search_directories as $dir) {
        foreach ($possibleExtensions as $ext) {
            $test_filename = $filenameWithoutExt . '.' . $ext;
            if (file_exists($dir . $test_filename)) {
                return str_replace($_SERVER['DOCUMENT_ROOT'], '..', $dir . $test_filename);
            }
        }
    }

    // Direct relative fallback pointing to uploads/items/
    return "../uploads/items/" . $image_file;
}

// ==========================================
// BATCH DELETE HANDLING
// ==========================================
if (isset($_POST['batch_delete']) && isset($_POST['selected_items'])) {
    $selected_ids = array_map('intval', $_POST['selected_items']);
    if (!empty($selected_ids)) {
        $id_list = implode(',', $selected_ids);
        $conn->query("DELETE FROM items WHERE id IN ($id_list)");
        header("Location: item_list.php?msg=deleted");
        exit;
    }
}

// ==========================================
// UPLOAD HANDLING BASED ON ITEM CODE
// ==========================================
if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
    $fileTmpPath = $_FILES['image']['tmp_name'];
    $fileExtension = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
    $newItemCode = trim($_POST['item_code']);
    $newFileName = $newItemCode . '.' . strtolower($fileExtension);

    $uploadFileDir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/items/';
    if (!file_exists($uploadFileDir)) {
        mkdir($uploadFileDir, 0777, true);
    }

    $dest_path = $uploadFileDir . $newFileName;

    if (move_uploaded_file($fileTmpPath, $dest_path)) {
        $stmt = $conn->prepare("UPDATE items SET image = ? WHERE item_code = ?");
        $stmt->bind_param("ss", $newFileName, $newItemCode);
        $stmt->execute();
        $stmt->close();
    }
}

// ==========================================
// AJAX BACKEND: UPDATE ALL STOCK DATES
// ==========================================
if (isset($_POST['action']) && $_POST['action'] === 'update_stock_dates') {
    header('Content-Type: application/json');
    $dates = $_POST['dates'] ?? [];

    if (!empty($dates) && is_array($dates)) {
        $stmt_update = $conn->prepare("UPDATE items SET stock_date = ? WHERE id = ?");

        foreach ($dates as $id => $date_val) {
            $clean_id = (int)$id;
            $clean_date = !empty($date_val) ? $date_val : NULL;
            $stmt_update->bind_param("si", $clean_date, $clean_id);
            $stmt_update->execute();
        }

        $stmt_update->close();
        echo json_encode(['status' => 'success', 'message' => 'Stock dates updated automatically!']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'No dates submitted.']);
    }
    exit;
}

// ==========================================
// PHP BACKEND EMBEDDED IMAGE EXCEL EXPORTER
// ==========================================
if (isset($_POST['export_excel_action'])) {
    $selected_ids = isset($_POST['selected_items']) ? $_POST['selected_items'] : [];
    $search = isset($_POST['export_search']) ? trim($_POST['export_search']) : "";
    $search_param = "%" . $search . "%";
    $category_filter = isset($_POST['export_category']) ? trim($_POST['export_category']) : "";
    $sort_option = isset($_POST['export_sort']) ? trim($_POST['export_sort']) : "first_last";

    switch ($sort_option) {
        case 'a_z':
            $order_query = "ORDER BY item_name ASC, description ASC";
            break;
        case '1_z':
            $order_query = "ORDER BY LENGTH(item_code) ASC, item_code ASC";
            break;
        case 'category':
            $order_query = "ORDER BY category ASC, id ASC";
            break;
        case 'first_last':
        default:
            $order_query = "ORDER BY id DESC";
            break;
    }

    if (!empty($selected_ids)) {
        $sanitized_ids = array_map('intval', $selected_ids);
        $id_list = implode(',', $sanitized_ids);
        $export_query = "SELECT * FROM items WHERE id IN ($id_list) $order_query";
        $stmt_export = $conn->prepare($export_query);
    } else {
        if (!empty($category_filter)) {
            $export_query = "SELECT * FROM items WHERE (item_name LIKE ? OR item_code LIKE ? OR barcode LIKE ? OR description LIKE ? OR location LIKE ?) AND category = ? $order_query";
            $stmt_export = $conn->prepare($export_query);
            $stmt_export->bind_param("ssssss", $search_param, $search_param, $search_param, $search_param, $search_param, $category_filter);
        } else {
            $export_query = "SELECT * FROM items WHERE item_name LIKE ? OR item_code LIKE ? OR barcode LIKE ? OR description LIKE ? OR location LIKE ? $order_query";
            $stmt_export = $conn->prepare($export_query);
            $stmt_export->bind_param("sssss", $search_param, $search_param, $search_param, $search_param, $search_param);
        }
    }

    $stmt_export->execute();
    $export_result = $stmt_export->get_result();

    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=Warehouse_Report_" . date('Ymd_His') . ".xls");
    header("Expires: 0");
    header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
    header("Pragma: public");

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443 ? "https://" : "http://";
    $domain_url = $protocol . $_SERVER['HTTP_HOST'];

    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
    echo '<head><meta http-equiv="content-type" content="text/html; charset=UTF-8"></head>';
    echo '<body><table border="1">';
    echo '<thead><tr style="background-color:#2e7d32; color:#ffffff; font-weight:bold; text-align:center; height:35px;">';
    echo '<th>IMAGE</th><th>PART NO</th><th>DESCRIPTION</th><th>CATEGORY</th><th>STOCK DATE</th><th>CURRENT QTY</th><th>REMARK / LOCATION</th>';
    echo '</tr></thead><tbody>';

    $exported_part_numbers = [];

    while ($row = $export_result->fetch_assoc()) {
        $part_no_clean = trim($row['item_code']);
        if (in_array($part_no_clean, $exported_part_numbers)) continue;
        $exported_part_numbers[] = $part_no_clean;

        $image_file = trim($row['image'] ?? '');
        $web_image_path = "";
        $found_path = getDynamicImagePath($image_file);

        if ($found_path !== false) {
            $web_image_path = preg_match('/^https?:\/\//i', $found_path) ? $found_path : $domain_url . str_replace('..', '', $found_path);
        }

        echo '<tr style="height:60px; vertical-align:middle;">';
        if (!empty($web_image_path)) {
            echo '<td align="center" style="width:70px; height:60px;"><img src="' . htmlspecialchars($web_image_path) . '" width="55" height="55" style="display:block;" alt="Item"></td>';
        } else {
            echo '<td align="center" style="color:#9ca3af; font-size:11px; width:70px;">NO IMAGE</td>';
        }
        echo '<td style="font-weight:bold; mso-number-format:\@;">' . htmlspecialchars($row['item_code']) . '</td>';
        echo '<td>' . htmlspecialchars($row['description'] ?? $row['item_name'] ?? '-') . '</td>';
        echo '<td align="center">' . htmlspecialchars($row['category'] ?? 'General') . '</td>';
        echo '<td align="center">' . htmlspecialchars($row['stock_date'] ?? '-') . '</td>';
        echo '<td align="center" style="font-weight:bold;">' . $row['stock_qty'] . '</td>';
        echo '<td>' . htmlspecialchars($row['location'] ?? '-') . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></body></html>';
    exit;
}

// ==========================================
// SEARCH / FILTER / SORT
// ==========================================
$search = isset($_GET['search']) ? trim($_GET['search']) : "";
$search_param = "%" . $search . "%";
$category_filter = isset($_GET['category_filter']) ? trim($_GET['category_filter']) : "";
$sort_option = isset($_GET['sort_by']) ? trim($_GET['sort_by']) : "first_last";

switch ($sort_option) {
    case 'a_z':
        $order_query = "ORDER BY item_name ASC, description ASC";
        break;
    case '1_z':
        $order_query = "ORDER BY LENGTH(item_code) ASC, item_code ASC";
        break;
    case 'category':
        $order_query = "ORDER BY category ASC, id ASC";
        break;
    case 'first_last':
    default:
        $order_query = "ORDER BY id DESC";
        break;
}

if (!empty($category_filter)) {
    $stmt = $conn->prepare("SELECT * FROM items WHERE (item_name LIKE ? OR item_code LIKE ? OR barcode LIKE ? OR description LIKE ? OR location LIKE ?) AND category = ? $order_query");
    $stmt->bind_param("ssssss", $search_param, $search_param, $search_param, $search_param, $search_param, $category_filter);
} else {
    $stmt = $conn->prepare("SELECT * FROM items WHERE item_name LIKE ? OR item_code LIKE ? OR barcode LIKE ? OR description LIKE ? OR location LIKE ? $order_query");
    $stmt->bind_param("sssss", $search_param, $search_param, $search_param, $search_param, $search_param);
}

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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <style>
        body { background:#f4f6f9; font-family:'Segoe UI', sans-serif; }
        .sidebar { width:250px; height:100vh; background:#1a2232; position:fixed; left:0; top:0; overflow-y:auto; z-index:100; }
        .logo { background:#f97316; padding:18px 20px; text-align:center; font-size:20px; font-weight:bold; color:white; letter-spacing:0.5px; }
        .sidebar-menu { list-style:none; padding:0; margin:0; }
        .sidebar a { display:flex; align-items:center; padding:13px 20px; color:#d1d5db; text-decoration:none; font-size:15px; font-weight:500; transition: background 0.2s, color 0.2s; border-left:4px solid transparent; }
        .sidebar a i { font-size:18px; width:30px; text-align:center; margin-right:12px; color:#9ca3af; transition:color 0.2s; }
        .sidebar a:hover, .sidebar a.active { background:#131924; color:#ffffff; }
        .sidebar a.active { border-left:4px solid #f97316; }
        .sidebar a:hover i, .sidebar a.active i { color:#ffffff; }
        .main { margin-left:250px; padding:20px; }
        .topbar { background:white; padding:15px 20px; border-radius:10px; box-shadow:0 2px 10px rgba(0,0,0,.1); margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; }
        .card-box { background:white; padding:20px; border-radius:12px; box-shadow:0 2px 10px rgba(0,0,0,.08); }
        .img-zoom-container { position:relative; width:65px; height:65px; margin:0 auto; }
        .zoomable-thumbnail { width:65px; height:65px; border-radius:6px; border:1px solid #cbd5e1; object-fit:contain; background:#ffffff; cursor:pointer; }
        .zoom-popup-view { display:none; position:absolute; top:50%; left:calc(100% + 20px); transform:translateY(-50%); width:280px; height:280px; background:#ffffff; border:2px solid #111827; border-radius:12px; box-shadow:0 10px 30px rgba(0,0,0,0.3); z-index:9999; padding:5px; }
        .zoom-popup-view img { width:100%; height:100%; object-fit:contain; background:#ffffff; border-radius:8px; }
        .img-zoom-container:hover .zoom-popup-view { display:block; }
        .low-stock { color:red; font-weight:bold; text-align:center; }
        .good-stock { color:green; font-weight:bold; text-align:center; }
        .batch-delete-panel { background:#fff1f2; border:1px solid #fecdd3; border-radius:6px; padding:10px 15px; display:none; align-items:center; justify-content:space-between; }
        .scroll-top-btn { position:fixed; bottom:20px; right:20px; width:40px; height:40px; border-radius:50%; background-color:#f97316; color:white; border:none; cursor:pointer; display:none; align-items:center; justify-content:center; box-shadow:0 4px 10px rgba(0,0,0,0.2); z-index:9999; }
        .date-context-menu { display:none; position:absolute; z-index:10000; width:250px; background:#ffffff; border:1px solid #e2e8f0; border-radius:8px; box-shadow:0 10px 25px rgba(0,0,0,0.15); padding:12px; }
    </style>
</head>
<body>

    <div class="sidebar">
        <div class="logo">WAREHOUSE</div>
        <div class="sidebar-menu">
            <a href="../dashboard.php"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
            <a href="item_list.php" class="active"><i class="fa-solid fa-box-archive"></i> Items</a>
            <a href="add_item.php"><i class="fa-solid fa-plus"></i> Add Item</a>
            <a href="../import_excel.php"><i class="fa-solid fa-file-import"></i> Import Excel</a>
            <a href="../create_job.php"><i class="fa-solid fa-file-circle-plus"></i> Create Job</a>
            <a href="../job_list.php"><i class="fa-solid fa-file-lines"></i> Job List</a>
            <a href="../stock/stock_in.php"><i class="fa-solid fa-arrow-trend-up"></i> Stock In</a>
            <a href="stock_out.php"><i class="fa-solid fa-arrow-trend-down"></i> Stock Out</a>
            <a href="../return_item.php"><i class="fa-solid fa-rotate-left"></i> Returns</a>
            <a href="../stock/missing_item.php"><i class="fa-solid fa-triangle-exclamation"></i> Missing</a>
            <a href="../scaner.php"><i class="fa-solid fa-barcode"></i> Scanner</a>
            <a href="../reports/stock_report.php"><i class="fa-solid fa-chart-pie"></i> Reports</a>
            <a href="../logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
        </div>
    </div>

    <div class="main">
        <div class="topbar">
            <h4>Warehouse Item List</h4>
            <div>Welcome, <strong><?= isset($_SESSION['user']) ? htmlspecialchars($_SESSION['user']) : 'Admin'; ?></strong></div>
        </div>

        <div class="card-box">
            <form id="bulkActionForm" method="POST" action="item_list.php" enctype="multipart/form-data">
                <input type="hidden" name="export_search" value="<?= htmlspecialchars($search) ?>">
                <input type="hidden" name="export_category" value="<?= htmlspecialchars($category_filter) ?>">
                <input type="hidden" name="export_sort" value="<?= htmlspecialchars($sort_option) ?>">

                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h3>Item Log Catalog View</h3>
                    <div class="d-flex gap-2">
                        <a href="../barcode/print_barcode.php" target="_blank" class="btn btn-dark d-flex align-items-center">📋 Print Selected Barcodes</a>
                        <button type="submit" name="export_excel_action" class="btn btn-success d-flex align-items-center">📥 Export Excel</button>
                        <a href="add_item.php" class="btn btn-warning d-flex align-items-center">+ Add Item</a>
                    </div>
                </div>

                <div class="row g-2 align-items-center mb-4">
                    <div class="col-md-3">
                        <input type="text" id="ui_search" class="form-control" placeholder="Search Part No / Desc / Location..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                    <div class="col-md-3">
                        <select id="ui_category" class="form-select">
                            <option value="">-- All Categories --</option>
                            <?php 
                            $categories = ['Store Tieman', 'Extrusion', 'General', 'Civacon', 'Pneumatic', 'Lower Chassis Parts', 'Air Brake Parts', 'Other items', 'Valve & Pipe Parts', 'Liquip Parts', 'Electrical Parts', 'Lamp and fitting parts', 'Malayisa items', 'China items'];
                            foreach ($categories as $cat) {
                                $selected = ($category_filter == $cat) ? 'selected' : '';
                                echo "<option value=\"$cat\" $selected>$cat</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select id="ui_sort" class="form-select">
                            <option value="first_last" <?= ($sort_option == 'first_last') ? 'selected' : '' ?>>🔃 Sort: Newest First</option>
                            <option value="a_z" <?= ($sort_option == 'a_z') ? 'selected' : '' ?>>🔤 Sort: Item Name (A-Z)</option>
                            <option value="1_z" <?= ($sort_option == '1_z') ? 'selected' : '' ?>>🔢 Sort: Part No (1-Z)</option>
                            <option value="category" <?= ($sort_option == 'category') ? 'selected' : '' ?>>📍 Sort: Category</option>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex gap-2">
                        <button type="button" onclick="executeCatalogSearch()" class="btn btn-primary px-3 w-100">Search</button>
                        <?php if (!empty($search) || !empty($category_filter) || $sort_option !== 'first_last'): ?>
                            <a href="item_list.php" class="btn btn-secondary px-3">Clear</a>
                        <?php endif; ?>
                    </div>
                </div>

                <div id="batchDeleteBar" class="batch-delete-panel mb-3">
                    <div class="text-danger fw-semibold">
                        ⚠️ <span id="selectedCount">0</span> row items selected for action.
                    </div>
                    <button type="submit" name="batch_delete" onclick="return confirm('Are you sure you want to completely remove the selected item assets? This cannot be undone.');" class="btn btn-danger btn-sm px-3">Delete Selected Records</button>
                </div>

                <div class="table-responsive">
                    <table class="table table-bordered table-hover align-middle">
                        <thead class="table-dark">
                            <tr>
                                <th class="text-center" width="40"><input type="checkbox" id="selectAll"></th>
                                <th class="text-center" width="80">Image</th>
                                <th>Part No</th>
                                <th>Description</th>
                                <th>Category</th>
                                <th>Stock Date</th>
                                <th class="text-center">Qty</th>
                                <th>Location</th>
                                <th class="text-center" width="100">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($result && $result->num_rows > 0): ?>
                                <?php while ($row = $result->fetch_assoc()): ?>
                                    <?php 
                                        $image_path = getDynamicImagePath($row['image'] ?? '');
                                        $qty_class = ($row['stock_qty'] <= 5) ? 'low-stock' : 'good-stock';
                                    ?>
                                    <tr>
                                        <td class="text-center">
                                            <input type="checkbox" name="selected_items[]" value="<?= $row['id'] ?>" class="item-checkbox">
                                        </td>
                                        <td class="text-center">
                                            <div class="img-zoom-container">
                                                <img src="<?php echo !empty($image_path) ? htmlspecialchars($image_path) : '../uploads/items/' . htmlspecialchars($row['image'] ?? ''); ?>" 
                                                     class="zoomable-thumbnail" 
                                                     alt="Item" 
                                                     onerror="this.onerror=null; this.src='../assets/images/no-image.png';">
                                                <div class="zoom-popup-view">
                                                    <img src="<?php echo !empty($image_path) ? htmlspecialchars($image_path) : '../uploads/items/' . htmlspecialchars($row['image'] ?? ''); ?>" 
                                                         alt="Full" 
                                                         onerror="this.onerror=null; this.src='../assets/images/no-image.png';">
                                                </div>
                                            </div>
                                        </td>
                                        <td class="fw-bold"><?= htmlspecialchars($row['item_code']) ?></td>
                                        <td><?= htmlspecialchars($row['description'] ?? $row['item_name'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($row['category'] ?? 'General') ?></td>
                                        <td>
                                            <input type="date" name="stock_dates[<?= $row['id'] ?>]" value="<?= htmlspecialchars($row['stock_date'] ?? '') ?>" class="form-control form-control-sm date-picker-input">
                                        </td>
                                        <td class="<?= $qty_class ?>"><?= (int)$row['stock_qty'] ?></td>
                                        <td><?= htmlspecialchars($row['location'] ?? '-') ?></td>
                                        <td class="text-center">
                                            <a href="edit_item.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-primary"><i class="fa-solid fa-pen-to-square"></i></a>
                                            <a href="delete_item.php?id=<?= $row['id'] ?>" onclick="return confirm('Delete this item?');" class="btn btn-sm btn-danger"><i class="fa-solid fa-trash"></i></a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-4">No records found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </form>
        </div>
    </div>

    <button type="button" class="scroll-top-btn" id="scrollTopBtn" onclick="window.scrollTo({top: 0, behavior: 'smooth'});">
        <i class="fa-solid fa-arrow-up"></i>
    </button>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function executeCatalogSearch() {
            const search = document.getElementById('ui_search').value;
            const category = document.getElementById('ui_category').value;
            const sort = document.getElementById('ui_sort').value;
            window.location.href = `item_list.php?search=${encodeURIComponent(search)}&category_filter=${encodeURIComponent(category)}&sort_by=${encodeURIComponent(sort)}`;
        }

        const selectAll = document.getElementById('selectAll');
        const checkboxes = document.querySelectorAll('.item-checkbox');
        const batchBar = document.getElementById('batchDeleteBar');
        const selectedCount = document.getElementById('selectedCount');

        function updateBatchBar() {
            const checked = document.querySelectorAll('.item-checkbox:checked');
            selectedCount.textContent = checked.length;
            batchBar.style.display = checked.length > 0 ? 'flex' : 'none';
        }

        if (selectAll) {
            selectAll.addEventListener('change', function() {
                checkboxes.forEach(cb => cb.checked = this.checked);
                updateBatchBar();
            });
        }

        checkboxes.forEach(cb => cb.addEventListener('change', updateBatchBar));

        window.addEventListener('scroll', () => {
            const btn = document.getElementById('scrollTopBtn');
            btn.style.display = window.scrollY > 300 ? 'flex' : 'none';
        });
    </script>
</body>
</html>