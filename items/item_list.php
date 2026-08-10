<?php
session_start();
include('../config/db.php');

if (!$conn) {
    die("Database Connection Error: " . mysqli_connect_error());
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
    $search_param = "%".$search."%";
    $category_filter = isset($_POST['export_category']) ? trim($_POST['export_category']) : "";
    $sort_option = isset($_POST['export_sort']) ? trim($_POST['export_sort']) : "first_last";

    switch ($sort_option) {
        case 'a_z': $order_query = "ORDER BY item_name ASC, description ASC"; break;
        case '1_z': $order_query = "ORDER BY LENGTH(item_code) ASC, item_code ASC"; break;
        case 'category': $order_query = "ORDER BY category ASC, id ASC"; break; 
        case 'first_last': default: $order_query = "ORDER BY id DESC"; break;
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
            $export_query = "SELECT * FROM items WHERE item_name LIKE ? OR item_code LIKE ? OR barcode LIKE ? OR description LIKE ? OR location LIKE ?";
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

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $domain_url = $protocol . $_SERVER['HTTP_HOST'];
    
    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
    echo '<head><meta http-equiv="content-type" content="text/html; charset=UTF-8"></head>';
    echo '<body>';
    echo '<table border="1">';
    echo '<thead>';
    echo '<tr style="background-color: #2e7d32; color: #ffffff; font-weight: bold; text-align: center; height: 35px;">';
    echo '<th>IMAGE</th>';
    echo '<th>PART NO</th>';
    echo '<th>DESCRIPTION</th>';
    echo '<th>CATEGORY</th>';
    echo '<th>STOCK DATE</th>';
    echo '<th>CURRENT QTY</th>';
    echo '<th>REMARK / LOCATION</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';

    $exported_part_numbers = [];

    while ($row = $export_result->fetch_assoc()) {
        $part_no_clean = trim($row['item_code']);
        
        if (in_array($part_no_clean, $exported_part_numbers)) {
            continue;
        }
        $exported_part_numbers[] = $part_no_clean;

        $image_file = $row['image'];
        $web_image_path = "";

        if (!empty($image_file)) {
            if (file_exists("../uploads/" . $image_file)) {
                $web_image_path = $domain_url . "/TIEMAN%20WAREHOUSE/uploads/" . $image_file;
            } elseif (file_exists("../uploads/items/" . $image_file)) {
                $web_image_path = $domain_url . "/TIEMAN%20WAREHOUSE/uploads/items/" . $image_file;
            } elseif (file_exists("../assets/images/" . $image_file)) {
                $web_image_path = $domain_url . "/TIEMAN%20WAREHOUSE/assets/images/" . $image_file;
            }
        }

        echo '<tr style="height: 60px; vertical-align: middle;">';
        
        if (!empty($web_image_path)) {
            echo '<td align="center" style="width: 70px; height: 60px;"><img src="' . $web_image_path . '" width="55" height="55" style="display:block;" alt="Item"></td>';
        } else {
            echo '<td align="center" style="color: #9ca3af; font-size: 11px; width: 70px;">NO IMAGE</td>';
        }
        
        echo '<td style="font-weight: bold; vnd.ms-excel.numberformat:@;">' . htmlspecialchars($row['item_code']) . '</td>';
        echo '<td>' . htmlspecialchars($row['description'] ?? $row['item_name'] ?? '-') . '</td>';
        echo '<td align="center">' . htmlspecialchars($row['category'] ?? 'General') . '</td>';
        echo '<td align="center">' . htmlspecialchars($row['stock_date'] ?? '-') . '</td>';
        echo '<td align="center" style="font-weight: bold;">' . $row['stock_qty'] . '</td>';
        echo '<td>' . htmlspecialchars($row['location'] ?? '-') . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></body></html>';
    exit;
}

$search = isset($_GET['search']) ? trim($_GET['search']) : "";
$search_param = "%".$search."%";
$category_filter = isset($_GET['category_filter']) ? trim($_GET['category_filter']) : "";
$sort_option = isset($_GET['sort_by']) ? trim($_GET['sort_by']) : "first_last";

switch ($sort_option) {
    case 'a_z': $order_query = "ORDER BY item_name ASC, description ASC"; break;
    case '1_z': $order_query = "ORDER BY LENGTH(item_code) ASC, item_code ASC"; break;
    case 'category': $order_query = "ORDER BY category ASC, id ASC"; break;
    case 'first_last': default: $order_query = "ORDER BY id DESC"; break;
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
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome Icons CDN -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <style>
        body { background:#f4f6f9; font-family:'Segoe UI', sans-serif; }
        
        /* SIDEBAR WITH MODERN ICON STYLING */
        .sidebar { 
            width: 250px; 
            height: 100vh; 
            background: #1a2232; 
            position: fixed; 
            left: 0; 
            top: 0; 
            overflow-y: auto; 
            z-index: 100; 
        }
        .logo { 
            background: #f97316; 
            padding: 18px 20px; 
            text-align: center; 
            font-size: 20px; 
            font-weight: bold; 
            color: white; 
            letter-spacing: 0.5px;
        }
        .sidebar-menu {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .sidebar a { 
            display: flex; 
            align-items: center; 
            padding: 13px 20px; 
            color: #d1d5db; 
            text-decoration: none; 
            font-size: 15px;
            font-weight: 500;
            transition: background 0.2s, color 0.2s; 
            border-left: 4px solid transparent;
        }
        .sidebar a i {
            font-size: 18px;
            width: 30px;
            text-align: center;
            margin-right: 12px;
            color: #9ca3af;
            transition: color 0.2s;
        }
        .sidebar a:hover { 
            background: #131924; 
            color: #ffffff;
        }
        .sidebar a:hover i {
            color: #ffffff;
        }
        .sidebar a.active {
            background: #131924;
            color: #ffffff;
            border-left: 4px solid #f97316;
        }
        .sidebar a.active i {
            color: #ffffff;
        }

        .main { margin-left:250px; padding:20px; }
        .topbar { background:white; padding:15px 20px; border-radius:10px; box-shadow:0 2px 10px rgba(0,0,0,.1); margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; }
        .card-box { background:white; padding:20px; border-radius:12px; box-shadow:0 2px 10px rgba(0,0,0,.08); }
        .excel-header { background-color: #2e7d32 !important; color: white !important; font-size: 14px; text-align: center; }
        
        .img-zoom-container { position: relative; width: 65px; height: 65px; margin: 0 auto; }
        .zoomable-thumbnail { width: 65px; height: 65px; border-radius: 6px; border: 1px solid #cbd5e1; object-fit: contain; background: #ffffff; cursor: pointer; }
        .zoom-popup-view { display: none; position: absolute; top: 50%; left: calc(100% + var(--shift-x, 20px)); transform: translateY(-50%); width: 280px; height: 280px; background: #ffffff; border: 2px solid #111827; border-radius: 12px; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3); z-index: 9999; padding: 5px; }
        .zoom-popup-view img { width: 100%; height: 100%; object-fit: contain; background: #ffffff; border-radius: 8px; }
        .img-zoom-container:hover .zoom-popup-view { display: block; }

        .low-stock { color:red; font-weight:bold; text-align: center; }
        .good-stock { color:green; font-weight:bold; text-align: center; }
        .batch-delete-panel { background: #fff1f2; border: 1px solid #fecdd3; border-radius: 6px; padding: 10px 15px; display: none; align-items: center; justify-content: space-between; }
        
        .scroll-top-btn {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: #f97316;
            color: white;
            border: none;
            cursor: pointer;
            display: none;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 10px rgba(0,0,0,0.2);
            z-index: 9999;
            transition: opacity 0.3s, background-color 0.2s;
        }
        .scroll-top-btn:hover {
            background-color: #ea580c;
        }

        /* CUSTOM RIGHT CLICK CONTEXT MENU STYLING */
        .date-context-menu {
            display: none;
            position: absolute;
            z-index: 10000;
            width: 250px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
            padding: 12px;
        }
    </style>
</head>
<body>

    <!-- COMPLETE SIDEBAR WITH ALL SPECIFIED MENU ITEMS -->
    <div class="sidebar">
        <div class="logo">WAREHOUSE</div>
        <div class="sidebar-menu">
            <a href="http://172.20.10.7/TIEMAN%20WAREHOUSE/dashboard.php">
                <i class="fa-solid fa-gauge-high"></i> Dashboard
            </a>
            <a href="http://172.20.10.7/TIEMAN%20WAREHOUSE/items/item_list.php" class="active">
                <i class="fa-solid fa-box-archive"></i> Items
            </a>
            <a href="http://172.20.10.7/TIEMAN%20WAREHOUSE/items/add_item.php">
                <i class="fa-solid fa-plus"></i> Add Item
            </a>
            <a href="http://172.20.10.7/TIEMAN%20WAREHOUSE/import_excel.php">
                <i class="fa-solid fa-file-import"></i> Import Excel
            </a>
            <a href="http://172.20.10.7/TIEMAN%20WAREHOUSE/create_job.php">
                <i class="fa-solid fa-file-circle-plus"></i> Create Job
            </a>
            <a href="http://172.20.10.7/TIEMAN%20WAREHOUSE/job_list.php">
                <i class="fa-solid fa-file-lines"></i> Job List
            </a>
            <a href="http://localhost/TIEMAN%20WAREHOUSE/stock/stock_in.php">
                <i class="fa-solid fa-arrow-trend-up"></i> Stock In
            </a>
            <a href="http://172.20.10.7/TIEMAN%20WAREHOUSE/items/stock_out.php">
                <i class="fa-solid fa-arrow-trend-down"></i> Stock Out
            </a>
            <a href="http://172.20.10.7/TIEMAN%20WAREHOUSE/return_item.php">
                <i class="fa-solid fa-rotate-left"></i> Returns
            </a>
            <a href="http://172.20.10.7/TIEMAN%20WAREHOUSE/stock/missing_item.php">
                <i class="fa-solid fa-triangle-exclamation"></i> Missing
            </a>
            <a href="http://172.20.10.7/TIEMAN%20WAREHOUSE/scaner.php">
                <i class="fa-solid fa-barcode"></i> Scanner
            </a>
            <a href="http://172.20.10.7/TIEMAN%20WAREHOUSE/reports/stock_report.php">
                <i class="fa-solid fa-chart-pie"></i> Reports
            </a>
            <a href="http://172.20.10.7/TIEMAN%20WAREHOUSE/logout.php">
                <i class="fa-solid fa-right-from-bracket"></i> Logout
            </a>
        </div>
    </div>

    <div class="main">
        <div class="topbar">
            <h4>Warehouse Item List</h4>
            <div>Welcome, <strong><?= isset($_SESSION['user']) ? htmlspecialchars($_SESSION['user']) : 'Admin'; ?></strong></div>
        </div>

        <div class="card-box">
            <form id="bulkActionForm" method="POST" action="item_list.php">
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
                            <option value="Store Tieman" <?= ($category_filter == 'Store Tieman') ? 'selected' : '' ?>>Store Tieman</option>
                            <option value="Extrusion" <?= ($category_filter == 'Extrusion') ? 'selected' : '' ?>>Extrusion</option>
                            <option value="General" <?= ($category_filter == 'General') ? 'selected' : '' ?>>General</option>
                            <option value="Civacon" <?= ($category_filter == 'Civacon') ? 'selected' : '' ?>>Civacon</option>
                            <option value="Pneumatic" <?= ($category_filter == 'Pneumatic') ? 'selected' : '' ?>>Pneumatic</option>
                            <option value="Lower Chassis Parts" <?= ($category_filter == 'Lower Chassis Parts') ? 'selected' : '' ?>>Lower Chassis Parts</option>
                            <option value="Air Brake Parts" <?= ($category_filter == 'Air Brake Parts') ? 'selected' : '' ?>>Air Brake Parts</option>
                            <option value="Other items" <?= ($category_filter == 'Other items') ? 'selected' : '' ?>>Other items</option>
                            <option value="Valve & Pipe Parts" <?= ($category_filter == 'Valve & Pipe Parts') ? 'selected' : '' ?>>Valve & Pipe Parts</option>
                            <option value="Liquip Parts" <?= ($category_filter == 'Liquip Parts') ? 'selected' : '' ?>>Liquip Parts</option>
                            <option value="Electrical Parts" <?= ($category_filter == 'Electrical Parts') ? 'selected' : '' ?>>Electrical Parts</option>
                            <option value="Lamp and fitting parts" <?= ($category_filter == 'Lamp and fitting parts') ? 'selected' : '' ?>>Lamp and fitting parts</option>
                            <option value="Malayisa items" <?= ($category_filter == 'Malayisa items') ? 'selected' : '' ?>>Malayisa items</option>
                            <option value="China items" <?= ($category_filter == 'China items') ? 'selected' : '' ?>>China items</option>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <select id="ui_sort" class="form-select">
                            <option value="first_last" <?= ($sort_option == 'first_last') ? 'selected' : '' ?>>🔃 Sort: Newest First</option>
                            <option value="a_z" <?= ($sort_option == 'a_z') ? 'selected' : '' ?>>🔤 Sort: Item Name (A-Z)</option>
                            <option value="1_z" <?= ($sort_option == '1_z') ? 'selected' : '' ?>>🔢 Sort: Part No (1-Z)</option>
                            <option value="category" <?= ($sort_option == 'category') ? 'selected' : '' ?>>📍 Sort: Oldest First</option>
                        </select>
                    </div>

                    <div class="col-md-3 d-flex gap-2">
                        <button type="button" onclick="executeCatalogSearch()" class="btn btn-primary px-3 w-100">Search</button>
                        <?php if(!empty($search) || !empty($category_filter) || $sort_option !== 'first_last'): ?>
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
                    <table id="inventoryTable" class="table table-bordered table-hover align-middle m-0">
                        <thead>
                            <tr class="excel-header">
                                <th width="40" class="text-center">
                                    <input type="checkbox" id="selectAllRows" class="form-check-input">
                                </th>
                                <th>IMAGE</th>
                                <th>PART NO</th>
                                <th>DESCRIPTION</th>
                                <th>CATEGORY</th>
                                <th width="150" class="date-context-trigger" style="cursor: context-menu;" title="Right-click here to set month/year for all rows">STOCK DATE</th>
                                <th>CURRENT QTY</th>
                                <th>REMARK / LOCATION</th>
                                <th width="260">ACTION</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($result->num_rows > 0) {
                                while($row = $result->fetch_assoc()) { 
                                    $image_file = $row['image'];
                                    $image_src = "../assets/images/no-image.png";
                                    
                                    if (!empty($image_file)) {
                                        if (file_exists("../uploads/" . $image_file)) { $image_src = "../uploads/" . $image_file; }
                                        elseif (file_exists("../uploads/items/" . $image_file)) { $image_src = "../uploads/items/" . $image_file; }
                                        elseif (file_exists("../assets/images/" . $image_file)) { $image_src = "../assets/images/" . $image_file; }
                                    }

                                    $formatted_stock_date = "";
                                    if (!empty($row['stock_date']) && $row['stock_date'] !== '-') {
                                        $timestamp = strtotime($row['stock_date']);
                                        if ($timestamp) {
                                            $formatted_stock_date = date('Y-m-d', $timestamp);
                                        }
                                    }
                                ?>
                                <tr>
                                    <td class="text-center">
                                        <input type="checkbox" name="selected_items[]" value="<?= $row['id'] ?>" class="form-check-input row-select-checkbox" onclick="evaluateCheckboxState()">
                                    </td>
                                    <td class="text-center">
                                        <div class="img-zoom-container">
                                            <img src="<?= $image_src ?>" class="zoomable-thumbnail" alt="Item">
                                            <div class="zoom-popup-view">
                                                <img src="<?= $image_src ?>" alt="Full Asset Display View">
                                            </div>
                                        </div>
                                    </td>
                                    <td class="fw-bold text-secondary">
                                        <a href="../barcode/print_barcode.php?code=<?= urlencode($row['item_code']) ?>" target="_blank" class="text-decoration-none text-primary">
                                            <?= htmlspecialchars($row['item_code']) ?>
                                        </a>
                                    </td>
                                    <td style="font-size: 13px; max-width: 300px; font-weight: 500;">
                                        <?= htmlspecialchars($row['description'] ?? $row['item_name'] ?? '-') ?>
                                    </td>
                                    <td class="text-center font-weight-bold text-dark">
                                        <span class="badge bg-secondary px-2.5 py-1.5 text-uppercase"><?= htmlspecialchars($row['category'] ?? 'General') ?></span>
                                    </td>
                                    <td class="text-center date-context-trigger" style="cursor: context-menu;">
                                        <input type="date" class="form-control form-control-sm stock-date-input" data-id="<?= $row['id'] ?>" value="<?= $formatted_stock_date ?>" onchange="autoSaveSingleDate(this)">
                                    </td>
                                    <td>
                                        <?= ($row['stock_qty'] <= 0) ? '<span class="low-stock">'.$row['stock_qty'].'</span>' : '<span class="good-stock">'.$row['stock_qty'].'</span>' ?>
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
                                <tr><td colspan="9" class="text-center py-4 text-muted">No Matching Warehouse Items Found</td></tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </form>
        </div>
    </div>

    <!-- RIGHT CLICK CONTEXT MENU FOR STOCK DATE -->
    <div id="dateContextMenu" class="date-context-menu">
        <div class="fw-bold mb-2 text-dark" style="font-size: 13px;">📅 Bulk Set Month & Year</div>
        <div class="mb-2">
            <input type="month" id="auto_month_year" class="form-control form-control-sm">
        </div>
        <button type="button" class="btn btn-primary btn-sm w-100" onclick="applyAndAutoSaveBulkMonthYear()">Apply to All Rows</button>
    </div>

    <button type="button" id="scrollToTopBtn" class="scroll-top-btn" title="Go to top">▲</button>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const masterCheckbox = document.getElementById('selectAllRows');
        const standardCheckboxes = document.querySelectorAll('.row-select-checkbox');
        const batchDeleteBar = document.getElementById('batchDeleteBar');
        const selectedCountLabel = document.getElementById('selectedCount');
        const scrollTopBtn = document.getElementById('scrollToTopBtn');

        masterCheckbox.addEventListener('change', function() {
            standardCheckboxes.forEach(box => box.checked = this.checked);
            evaluateCheckboxState();
        });

        function evaluateCheckboxState() {
            let activeSelections = 0;
            standardCheckboxes.forEach(box => { if(box.checked) activeSelections++; });
            selectedCountLabel.textContent = activeSelections;
            batchDeleteBar.style.display = (activeSelections > 0) ? 'flex' : 'none';
        }

        function executeCatalogSearch() {
            const searchVal = encodeURIComponent(document.getElementById('ui_search').value);
            const catVal = encodeURIComponent(document.getElementById('ui_category').value);
            const sortVal = encodeURIComponent(document.getElementById('ui_sort').value);
            window.location.href = `item_list.php?search=${searchVal}&category_filter=${catVal}&sort_by=${sortVal}`;
        }

        document.getElementById('ui_category').addEventListener('change', executeCatalogSearch);
        document.getElementById('ui_sort').addEventListener('change', executeCatalogSearch);

        let globalShiftX = 20; let activelyHoveredBox = null;
        document.querySelectorAll('.img-zoom-container').forEach(container => {
            const popupElement = container.querySelector('.zoom-popup-view');
            container.addEventListener('mouseenter', () => { activelyHoveredBox = popupElement; activelyHoveredBox.style.setProperty('--shift-x', `${globalShiftX}px`); });
            container.addEventListener('mouseleave', () => { activelyHoveredBox = null; });
        });
        window.addEventListener('keydown', (event) => {
            if (!activelyHoveredBox) return;
            if (event.key === 'ArrowRight') { event.preventDefault(); globalShiftX += 15; activelyHoveredBox.style.setProperty('--shift-x', `${globalShiftX}px`); }
            else if (event.key === 'ArrowLeft') { event.preventDefault(); globalShiftX -= 15; activelyHoveredBox.style.setProperty('--shift-x', `${globalShiftX}px`); }
        });

        window.addEventListener('scroll', () => {
            if (window.scrollY > 300) {
                scrollTopBtn.style.display = 'flex';
            } else {
                scrollTopBtn.style.display = 'none';
            }
        });

        scrollTopBtn.addEventListener('click', () => {
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        });

        // ==========================================
        // RIGHT-CLICK CONTEXT MENU & BULK AUTO-SAVE
        // ==========================================
        const contextMenu = document.getElementById('dateContextMenu');

        document.querySelectorAll('.date-context-trigger').forEach(target => {
            target.addEventListener('contextmenu', function(e) {
                e.preventDefault();
                contextMenu.style.top = `${e.pageY}px`;
                contextMenu.style.left = `${e.pageX}px`;
                contextMenu.style.display = 'block';
            });
        });

        // Close context menu when clicking anywhere else
        document.addEventListener('click', function(e) {
            if (!contextMenu.contains(e.target)) {
                contextMenu.style.display = 'none';
            }
        });

        function applyAndAutoSaveBulkMonthYear() {
            const monthYearVal = document.getElementById('auto_month_year').value;
            if (!monthYearVal) {
                alert('Please pick a Month and Year first!');
                return;
            }

            const inputs = document.querySelectorAll('.stock-date-input');
            inputs.forEach(input => {
                let currentDay = '01';
                if (input.value) {
                    const parts = input.value.split('-');
                    if (parts.length === 3) {
                        currentDay = parts[2];
                    }
                }
                input.value = `${monthYearVal}-${currentDay}`;
            });

            // Auto save all changes directly to DB
            saveAllStockDates();
            contextMenu.style.display = 'none';
        }

        function saveAllStockDates() {
            const inputs = document.querySelectorAll('.stock-date-input');
            const datesData = {};

            inputs.forEach(input => {
                const id = input.getAttribute('data-id');
                datesData[id] = input.value;
            });

            const formData = new FormData();
            formData.append('action', 'update_stock_dates');
            for (const [id, dateVal] of Object.entries(datesData)) {
                formData.append(`dates[${id}]`, dateVal);
            }

            fetch('item_list.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    console.log('Stock dates auto-updated successfully.');
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error updating stock dates:', error);
            });
        }

        function autoSaveSingleDate(inputElem) {
            saveAllStockDates();
        }
    </script>
</body>
</html>