<?php
ob_start();
session_start();
include('config/db.php');

// -------------------------------------------------------------
// AJAX STATUS UPDATE ENDPOINT
// -------------------------------------------------------------
if (isset($_POST['ajax_update_status'])) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json');
    
    $job_id = (int)$_POST['job_id'];
    $status = mysqli_real_escape_string($conn, trim($_POST['status']));
    
    if ($job_id > 0 && !empty($status)) {
        $update = mysqli_query($conn, "UPDATE jobs SET status='$status' WHERE id=$job_id");
        if ($update) {
            echo json_encode(['success' => true, 'status' => $status, 'job_id' => $job_id]);
        } else {
            echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    }
    exit;
}

// -------------------------------------------------------------
// AJAX COPY/PASTE ITEMS ENDPOINT
// -------------------------------------------------------------
if (isset($_POST['ajax_copy_paste_items'])) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json');
    
    $source_job_id = (int)$_POST['source_job_id'];
    $target_job_id = (int)$_POST['target_job_id'];

    if ($source_job_id > 0 && $target_job_id > 0 && $source_job_id !== $target_job_id) {
        $source_items = mysqli_query($conn, "SELECT * FROM job_items WHERE job_id = $source_job_id");
        $copied_count = 0;
        
        while ($item = mysqli_fetch_assoc($source_items)) {
            $item_id = $item['item_id'];
            $qty = $item['qty'];
            $qty_per_tanker = mysqli_real_escape_string($conn, $item['qty_per_tanker']);
            $prod_units = (int)$item['production_units'];
            $after_qty = (int)$item['after_qty'];
            $remark = mysqli_real_escape_string($conn, $item['remark']);

            // Prevent Duplicates
            $check = mysqli_query($conn, "SELECT id FROM job_items WHERE job_id = $target_job_id AND item_id = $item_id");
            if (mysqli_num_rows($check) == 0) {
                $insert = mysqli_query($conn, "INSERT INTO job_items (job_id, item_id, qty, qty_per_tanker, production_units, after_qty, remark) 
                                              VALUES ($target_job_id, $item_id, $qty, '$qty_per_tanker', $prod_units, $after_qty, '$remark')");
                if ($insert) $copied_count++;
            }
        }
        echo json_encode(['success' => true, 'copied_count' => $copied_count]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid source or target job']);
    }
    exit;
}

// -------------------------------------------------------------
// ACTION: DELETE ALL ITEMS FROM A JOB
// -------------------------------------------------------------
if (isset($_GET['delete_all_items'])) {
    $del_job_id = (int)$_GET['delete_all_items'];
    if ($del_job_id > 0) {
        mysqli_query($conn, "DELETE FROM job_items WHERE job_id = $del_job_id");
    }
    header("Location: job_list.php?view_bom=$del_job_id");
    exit;
}

// -------------------------------------------------------------
// ACTION: BOX MANAGEMENT (CREATE, RENAME, DELETE, ASSIGN MULTIPLE ITEMS, REMOVE ITEM)
// -------------------------------------------------------------
if (isset($_POST['create_box'])) {
    $jid = (int)$_POST['job_id'];
    $box_name = mysqli_real_escape_string($conn, trim($_POST['box_name']));
    if (!empty($box_name) && $jid > 0) {
        mysqli_query($conn, "INSERT INTO job_boxes (job_id, box_name) VALUES ($jid, '$box_name')");
    }
    header("Location: job_list.php?view_bom=$jid&open_box_modal=1");
    exit;
}

if (isset($_POST['rename_box'])) {
    $jid = (int)$_POST['job_id'];
    $box_id = (int)$_POST['box_id'];
    $box_name = mysqli_real_escape_string($conn, trim($_POST['box_name']));
    if ($box_id > 0 && !empty($box_name)) {
        mysqli_query($conn, "UPDATE job_boxes SET box_name='$box_name' WHERE id=$box_id AND job_id=$jid");
    }
    header("Location: job_list.php?view_bom=$jid&open_box_modal=1");
    exit;
}

if (isset($_POST['add_item_to_box'])) {
    $jid = (int)$_POST['job_id'];
    $box_id = (int)$_POST['box_id'];
    $item_ids = $_POST['item_ids'] ?? [];

    if ($box_id > 0 && $jid > 0 && !empty($item_ids) && is_array($item_ids)) {
        $clean_ids = array_map('intval', $item_ids);
        $ids_string = implode(',', $clean_ids);
        
        mysqli_query($conn, "UPDATE job_items SET box_id = $box_id WHERE job_id = $jid AND item_id IN ($ids_string)");
    }
    header("Location: job_list.php?view_bom=$jid&open_box_modal=1");
    exit;
}

if (isset($_GET['remove_item_from_box']) && isset($_GET['from_job'])) {
    $item_id = (int)$_GET['remove_item_from_box'];
    $from_job_id = (int)$_GET['from_job'];
    mysqli_query($conn, "UPDATE job_items SET box_id = 0 WHERE job_id = $from_job_id AND item_id = $item_id");
    header("Location: job_list.php?view_bom=$from_job_id&open_box_modal=1");
    exit;
}

if (isset($_GET['delete_box']) && isset($_GET['from_job'])) {
    $box_id = (int)$_GET['delete_box'];
    $from_job_id = (int)$_GET['from_job'];
    mysqli_query($conn, "UPDATE job_items SET box_id = 0 WHERE box_id = $box_id AND job_id = $from_job_id");
    mysqli_query($conn, "DELETE FROM job_boxes WHERE id = $box_id AND job_id = $from_job_id");
    header("Location: job_list.php?view_bom=$from_job_id&open_box_modal=1");
    exit;
}

// -------------------------------------------------------------
// BACKEND MANAGEMENT ACTIONS
// -------------------------------------------------------------
if (isset($_GET['delete_job'])) {
    $del_job_id = (int)$_GET['delete_job'];
    mysqli_query($conn, "DELETE FROM job_items WHERE job_id = $del_job_id");
    mysqli_query($conn, "DELETE FROM job_boxes WHERE job_id = $del_job_id");
    mysqli_query($conn, "DELETE FROM jobs WHERE id = $del_job_id");
    header("Location: job_list.php");
    exit;
}

if (isset($_GET['delete_item']) && isset($_GET['from_job'])) {
    $del_item_id = (int)$_GET['delete_item'];
    $from_job_id = (int)$_GET['from_job'];
    mysqli_query($conn, "DELETE FROM job_items WHERE job_id = $from_job_id AND item_id = $del_item_id");
    header("Location: job_list.php?view_bom=$from_job_id");
    exit;
}

if (isset($_POST['update_manual_data'])) {
    $jid = (int)$_POST['job_id'];
    $cust = mysqli_real_escape_string($conn, $_POST['customer_name']);
    mysqli_query($conn, "UPDATE jobs SET customer_name='$cust' WHERE id=$jid");
    header("Location: job_list.php?view_bom=$jid");
    exit;
}

if (isset($_POST['adjust_qty'])) {
    $jid = (int)$_POST['job_id'];
    $itid = (int)$_POST['item_id'];
    $qty_before = (int)$_POST['qty'];
    $pcs_tanker = (int)$_POST['qty_per_tanker'];
    $prod_units = (int)$_POST['production_units'];
    $box_id = (int)($_POST['box_id'] ?? 0);
    $remark = mysqli_real_escape_string($conn, $_POST['remark']);

    // Calculate After Qty automatically
    $deduction = $pcs_tanker * $prod_units;
    $after_qty = ($prod_units > 0) ? ($qty_before - $deduction) : $qty_before;

    mysqli_query($conn, "UPDATE job_items SET 
                            qty = $qty_before, 
                            qty_per_tanker = '$pcs_tanker', 
                            production_units = $prod_units, 
                            after_qty = $after_qty, 
                            box_id = $box_id,
                            remark = '$remark' 
                         WHERE job_id = $jid AND item_id = $itid");
                         
    header("Location: job_list.php?view_bom=$jid");
    exit;
}

$view_job_id = isset($_GET['view_bom']) ? (int)$_GET['view_bom'] : 0;
$search_job  = isset($_GET['search_job']) ? mysqli_real_escape_string($conn, trim($_GET['search_job'])) : '';
$search_item = isset($_GET['search_item']) ? mysqli_real_escape_string($conn, trim($_GET['search_item'])) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Warehouse Item List & Job Manager</title>
    <!-- Font Awesome CDN -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', sans-serif; }
        body { background: #f4f6f9; display: flex; }

        /* EXACT SIDEBAR STYLING MATCHING YOUR CODE & SCREENSHOT */
        .sidebar { 
            width: 250px; 
            height: 100vh; 
            background: #131924; 
            position: fixed; 
            left: 0; 
            top: 0; 
            overflow-y: auto; 
            z-index: 100; 
        }
        .sidebar-header {
            padding: 20px;
            font-size: 20px;
            font-weight: bold;
            color: #ffffff;
            background: #f97316;
            text-align: center;
            letter-spacing: 1px;
        }
        .sidebar a { 
            display: flex; 
            align-items: center; 
            padding: 13px 20px; 
            color: #94a3b8; 
            text-decoration: none; 
            font-size: 15px;
            font-weight: 600;
            transition: all 0.2s; 
            border-left: 4px solid transparent;
        }
        .sidebar a i {
            font-size: 18px;
            width: 32px;
            text-align: center;
            margin-right: 12px;
            color: #94a3b8;
        }
        .sidebar a:hover, .sidebar a.active { 
            background: #1e293b; 
            color: #ffffff;
        }
        .sidebar a:hover i, .sidebar a.active i {
            color: #ffffff;
        }
        .sidebar a.active {
            border-left: 4px solid #f97316;
        }

        /* MAIN CONTENT AREA LAYOUT ADJUSTMENT */
        .main-content { 
            margin-left: 250px; 
            flex: 1; 
            padding: 30px; 
            width: calc(100% - 250px);
        }
        .card { background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); margin-bottom: 25px; }

        .dual-search-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
        .search-box-wrapper { display: flex; flex-direction: column; gap: 6px; }
        .search-box-wrapper label { font-size: 13px; font-weight: bold; color: #4b5563; }
        .search-container { display: flex; gap: 8px; }
        .search-input { flex: 1; padding: 10px 14px; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 14px; outline: none; }
        .search-input:focus { border-color: #f97316; }

        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { padding: 12px; border-bottom: 1px solid #e5e7eb; text-align: left; vertical-align: middle; }
        th { background: #f8fafc; color: #4b5563; font-weight: 600; font-size: 13px; }

        .img-zoom-container { position: relative; width: 60px; height: 60px; }
        .item-img { width: 60px; height: 60px; object-fit: contain; border: 1px solid #e2e8f0; border-radius: 6px; background: #fff; cursor: zoom-in; }
        .item-img:hover { transform: scale(3.5); box-shadow: 0 10px 20px rgba(0,0,0,0.2); position: relative; z-index: 999; }

        .btn-action { background: #f97316; color: white; padding: 8px 14px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 13px; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
        .btn-green { background: #10b981; }
        .btn-danger { background: #ef4444; }
        .btn-blue { background: #3b82f6; }
        .input-inline { padding: 6px; border: 1px solid #cbd5e1; border-radius: 6px; font-weight: bold; text-align: center; }

        /* Context Menu Styles */
        .status-context-menu { display: none; position: absolute; z-index: 10000; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; box-shadow: 0 10px 15px rgba(0,0,0,0.1); width: 180px; }
        .status-context-menu ul { list-style: none; margin: 0; padding: 0; }
        .status-context-menu button { width: 100%; text-align: left; background: none; border: none; padding: 10px 14px; font-size: 13px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px; }
        .status-context-menu button:hover { background: #f8fafc; }
        .status-context-menu hr { border: 0; border-top: 1px solid #e2e8f0; margin: 4px 0; }

        .status-badge { padding: 4px 10px; border-radius: 6px; font-weight: 600; font-size: 13px; display: inline-block; }
        .status-done { background: #d1fae5; color: #065f46; }
        .status-in-progress { background: #e0f2fe; color: #0369a1; }
        .status-pending { background: #fef3c7; color: #92400e; }

        /* MODAL STYLES FOR BOX MANAGEMENT */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; justify-content: center; align-items: center; }
        .modal-overlay.active { display: flex; }
        .modal-box { background: #ffffff; padding: 25px; border-radius: 12px; width: 720px; max-width: 90%; max-height: 85vh; overflow-y: auto; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); }
        .box-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px; margin-bottom: 14px; }
    </style>
</head>
<body>

    <!-- EXACT MATCHING SIDEBAR WITH UNIFIED FONT AWESOME ICONS -->
    <div class="sidebar">
        <div class="sidebar-header">WAREHOUSE</div>
        <a href="dashboard.php"><i class="fa-solid fa-gauge"></i> Dashboard</a>
        <a href="items/item_list.php"><i class="fa-solid fa-box-archive"></i> Items</a>
        <a href="items/add_item.php"><i class="fa-solid fa-plus"></i> Add Item</a>
        <a href="import_excel.php"><i class="fa-solid fa-file-import"></i> Import Excel</a>
        <a href="create_job.php"><i class="fa-solid fa-file-circle-plus"></i> Create Job</a>
        <a href="job_list.php" class="active"><i class="fa-solid fa-file-lines"></i> Job List</a>
        
        <!-- Stock In & Stock Out with trending arrow icons -->
        <a href="items/stock_in.php"><i class="fa-solid fa-arrow-trend-up"></i> Stock In</a>
        <a href="items/stock_out.php"><i class="fa-solid fa-arrow-trend-down"></i> Stock Out</a>
        
        <a href="return_item.php"><i class="fa-solid fa-rotate-left"></i> Returns</a>
        <a href="stock/missing_item.php"><i class="fa-solid fa-triangle-exclamation"></i> Missing</a>
        <a href="scaner.php"><i class="fa-solid fa-barcode"></i> Scanner</a>
        <a href="reports/stock_report.php"><i class="fa-solid fa-chart-pie"></i> Reports</a>
        <a href="logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </div>

    <div class="main-content">
        <div class="card">
            <h2 style="margin-bottom: 18px; color: #1e293b;">Warehouse Inventory Control Center</h2>
            
            <form method="GET" class="dual-search-grid">
                <div class="search-box-wrapper">
                    <label><i class="fa-solid fa-gear"></i> Filter via Job Specifications:</label>
                    <div class="search-container">
                        <input type="text" name="search_job" class="search-input" placeholder="Search Job No or Customer..." value="<?= htmlspecialchars($search_job) ?>">
                    </div>
                </div>

                <div class="search-box-wrapper">
                    <label><i class="fa-solid fa-boxes-stacked"></i> Filter via Component Item Names:</label>
                    <div class="search-container">
                        <input type="text" name="search_item" class="search-input" placeholder="Search Part Code or Item Name..." value="<?= htmlspecialchars($search_item) ?>">
                        <button type="submit" class="btn-action"><i class="fa-solid fa-filter"></i> Apply Filters</button>
                        <?php if(!empty($search_job) || !empty($search_item)): ?>
                            <a href="job_list.php" class="btn-action btn-danger" style="line-height:20px;"><i class="fa-solid fa-rotate-right"></i> Reset</a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>

            <table>
                <thead>
                    <tr>
                        <th>Job Specs Reference</th>
                        <th>Client / Customer (Manual)</th>
                        <th>Status (Right-Click for Actions)</th>
                        <th style="text-align: center;">Control Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $where_clauses = [];
                    if (!empty($search_job)) {
                        $where_clauses[] = "(j.job_no LIKE '%$search_job%' OR j.customer_name LIKE '%$search_job%')";
                    }
                    if (!empty($search_item)) {
                        $where_clauses[] = "(i.item_name LIKE '%$search_item%' OR i.item_code LIKE '%$search_item%')";
                    }

                    $clause_string = (count($where_clauses) > 0) ? "WHERE " . implode(' AND ', $where_clauses) : "";
                    $raw_query = "SELECT DISTINCT j.* FROM jobs j 
                                  LEFT JOIN job_items ji ON j.id = ji.job_id 
                                  LEFT JOIN items i ON ji.item_id = i.id 
                                  $clause_string ORDER BY j.id DESC";

                    $jobs = mysqli_query($conn, $raw_query);
                    if($jobs && mysqli_num_rows($jobs) > 0) {
                        while($row = mysqli_fetch_assoc($jobs)) {
                            $cust_display = !empty($row['customer_name']) ? htmlspecialchars($row['customer_name']) : '<em style="color:#f59e0b;">[Pending Manual Entry]</em>';
                            $status_val = isset($row['status']) && !empty(trim($row['status'])) ? trim($row['status']) : 'Pending';
                            
                            $badge_class = 'status-pending';
                            if (strcasecmp($status_val, 'Done') === 0) $badge_class = 'status-done';
                            elseif (strcasecmp($status_val, 'In Progress') === 0) $badge_class = 'status-in-progress';
                            ?>
                            <tr oncontextmenu="openStatusMenu(event, <?= $row['id'] ?>)">
                                <td><strong style='color:#f97316; font-size:16px; font-family: monospace;'><?= $row['job_no'] ?></strong></td>
                                <td><?= $cust_display ?></td>
                                
                                <td style="cursor: pointer;">
                                    <span id="badge-<?= $row['id'] ?>" class="status-badge <?= $badge_class ?>">
                                        <?= htmlspecialchars($status_val) ?>
                                    </span>
                                </td>

                                <td style="text-align: center;">
                                    <a href='job_list.php?view_bom=<?= $row['id'] ?>' class='btn-action'><i class="fa-solid fa-list-check"></i> View Items Matrix</a>
                                    <a href='job_list.php?delete_job=<?= $row['id'] ?>' class='btn-action btn-danger' onclick="return confirm('Delete this job completely?');"><i class="fa-solid fa-trash"></i> Delete</a>
                                </td>
                            </tr>
                            <?php
                        }
                    } else {
                        echo "<tr><td colspan='4' style='text-align:center; padding:20px; color:#94a3b8;'>No records found.</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>

        <!-- BOM ITEMS & CALCULATION MATRIX SECTION -->
        <?php if($view_job_id > 0): 
            $job_profile = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM jobs WHERE id = $view_job_id"));
            ?>
            <div class="card" style="border-top: 4px solid #f97316;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h3>BOM Parts Breakdown for Job: <span style="color:#f97316; font-family: monospace;"><?= $job_profile['job_no'] ?></span></h3>
                    
                    <!-- DELETE ALL ITEMS OPTION -->
                    <div>
                        <a href="job_list.php?delete_all_items=<?= $view_job_id ?>" class="btn-action btn-danger" onclick="return confirm('Are you sure you want to remove ALL items from this job?');">
                            <i class="fa-solid fa-trash-can"></i> Delete All Items
                        </a>
                    </div>
                </div>

                <div style="background:#f8fafc; padding:15px; border-radius:8px; display:flex; gap:15px; align-items:flex-end; margin-bottom: 20px; justify-content: space-between;">
                    <form method="POST" style="display:flex; gap:12px; align-items:flex-end;">
                        <input type="hidden" name="job_id" value="<?= $view_job_id ?>">
                        <div>
                            <label style="display:block; font-size:12px; font-weight:bold; margin-bottom:4px;">Set Customer Name:</label>
                            <input type="text" name="customer_name" class="input-inline" style="width:250px; text-align:left;" value="<?= htmlspecialchars($job_profile['customer_name'] ?? '') ?>" placeholder="Enter Entity Name">
                        </div>
                        <button type="submit" name="update_manual_data" class="btn-action" style="height: 35px;"><i class="fa-solid fa-floppy-disk"></i> Save Info</button>
                    </form>

                    <!-- VIEW BOXES BUTTON -->
                    <div>
                        <button type="button" class="btn-action btn-blue" style="height: 35px;" onclick="toggleBoxModal(true)">
                            <i class="fa-solid fa-box"></i> View & Manage Job Boxes
                        </button>
                    </div>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th>Part Graphic Image</th>
                            <th>Part Code</th>
                            <th>Item Name Descriptors</th>
                            <th>Pcs Per Tank</th>
                            <th>Production Units</th>
                            <th>Before Qty</th>
                            <th>After Qty</th>
                            <th>Assigned Box</th>
                            <th>Remark / Location</th>
                            <th>Control</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $bom_items = mysqli_query($conn, "SELECT ji.*, i.id as item_id, i.item_code, i.item_name, i.image, jb.box_name 
                                                           FROM job_items ji 
                                                           JOIN items i ON ji.item_id = i.id 
                                                           LEFT JOIN job_boxes jb ON ji.box_id = jb.id
                                                           WHERE ji.job_id = $view_job_id");
                        if(mysqli_num_rows($bom_items) > 0) {
                            while($item = mysqli_fetch_assoc($bom_items)) {
                                $img_path = !empty($item['image']) && file_exists('uploads/items/' . $item['image']) 
                                            ? 'uploads/items/' . $item['image'] 
                                            : 'uploads/items/placeholder.png';
                                            
                                $before_qty = (int)($item['qty'] ?? 0);
                                $pcs_tanker = (int)($item['qty_per_tanker'] ?? 1);
                                $prod_units = (int)($item['production_units'] ?? 0);
                                $after_qty  = (int)($item['after_qty'] ?? $before_qty);
                                $current_box_id = (int)($item['box_id'] ?? 0);
                                ?>
                                <tr>
                                    <td>
                                        <div class="img-zoom-container">
                                            <img src="<?= $img_path ?>" class="item-img" alt="Part Pic">
                                        </div>
                                    </td>
                                    <td><strong style="font-family: monospace; font-size:14px;"><?= $item['item_code'] ?></strong></td>
                                    <td><?= htmlspecialchars($item['item_name']) ?></td>
                                    
                                    <!-- EDIT & CALCULATION ROW FORM -->
                                    <td colspan="6">
                                        <form method="POST" style="display:flex; align-items:center; gap:8px; width: 100%;">
                                            <input type="hidden" name="job_id" value="<?= $view_job_id ?>">
                                            <input type="hidden" name="item_id" value="<?= $item['item_id'] ?>">
                                            
                                            <!-- Pcs Per Tank -->
                                            <div style="flex: 1;">
                                                <input type="number" name="qty_per_tanker" id="pcs_<?= $item['item_id'] ?>" class="input-inline" style="width: 100%;" value="<?= $pcs_tanker ?>" oninput="calculateQty(<?= $item['item_id'] ?>)">
                                            </div>

                                            <!-- Production Units -->
                                            <div style="flex: 1;">
                                                <input type="number" name="production_units" id="prod_<?= $item['item_id'] ?>" class="input-inline" style="width: 100%;" value="<?= $prod_units ?>" placeholder="0" oninput="calculateQty(<?= $item['item_id'] ?>)">
                                            </div>

                                            <!-- Before Qty -->
                                            <div style="flex: 1;">
                                                <input type="number" name="qty" id="before_<?= $item['item_id'] ?>" class="input-inline" style="width: 100%; background:#f1f5f9;" value="<?= $before_qty ?>" oninput="calculateQty(<?= $item['item_id'] ?>)">
                                            </div>

                                            <!-- After Qty (Calculated live) -->
                                            <div style="flex: 1;">
                                                <input type="number" name="after_qty" id="after_<?= $item['item_id'] ?>" class="input-inline" style="width: 100%; background:#e0f2fe; color:#0369a1;" value="<?= $after_qty ?>" readonly>
                                            </div>

                                            <!-- Box Selection Dropdown -->
                                            <div style="flex: 1.5;">
                                                <select name="box_id" class="input-inline" style="width:100%; text-align:left;">
                                                    <option value="0">-- No Box --</option>
                                                    <?php
                                                    $boxes_list = mysqli_query($conn, "SELECT * FROM job_boxes WHERE job_id = $view_job_id ORDER BY box_name ASC");
                                                    while($b = mysqli_fetch_assoc($boxes_list)) {
                                                        $selected = ($b['id'] == $current_box_id) ? 'selected' : '';
                                                        echo "<option value='{$b['id']}' $selected>📦 " . htmlspecialchars($b['box_name']) . "</option>";
                                                    }
                                                    ?>
                                                </select>
                                            </div>

                                            <!-- Remark -->
                                            <div style="flex: 2;">
                                                <input type="text" name="remark" class="input-inline" style="width: 100%; text-align: left; font-weight: normal;" value="<?= htmlspecialchars($item['remark'] ?? '') ?>" placeholder="Location Notes">
                                            </div>

                                            <div>
                                                <button type="submit" name="adjust_qty" class="btn-action btn-green" style="padding:6px 12px;"><i class="fa-solid fa-check"></i> Save</button>
                                            </div>
                                        </form>
                                    </td>
                                    
                                    <td>
                                        <a href="job_list.php?delete_item=<?= $item['item_id'] ?>&from_job=<?= $view_job_id ?>" class="btn-action btn-danger" style="padding: 5px 10px;" onclick="return confirm('Remove part?');"><i class="fa-solid fa-trash"></i> Delete</a>
                                    </td>
                                </tr>
                                <?php
                            }
                        } else {
                            echo "<tr><td colspan='10' style='text-align:center; padding:25px; color:#94a3b8;'>No parts found for this job.</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>

            <!-- MODAL FOR CREATING, RENAMING, DELETING & ADDING MULTIPLE ITEMS TO BOXES -->
            <div id="boxModal" class="modal-overlay <?= isset($_GET['open_box_modal']) ? 'active' : '' ?>">
                <div class="modal-box">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; border-bottom:2px solid #e2e8f0; padding-bottom:10px;">
                        <h3 style="color:#1e293b;"><i class="fa-solid fa-box"></i> Manage Boxes for Job: <span style="color:#f97316; font-family:monospace;"><?= $job_profile['job_no'] ?></span></h3>
                        <button type="button" onclick="toggleBoxModal(false)" style="background:none; border:none; font-size:22px; cursor:pointer; color:#64748b;">&times;</button>
                    </div>

                    <!-- CREATE NEW BOX FORM -->
                    <form method="POST" style="display:flex; gap:10px; margin-bottom:20px; background:#fff7ed; padding:12px; border-radius:8px; border:1px solid #ffedd5;">
                        <input type="hidden" name="job_id" value="<?= $view_job_id ?>">
                        <input type="text" name="box_name" class="search-input" placeholder="Enter Box Name / Code (e.g., Box A, Box 1)..." required>
                        <button type="submit" name="create_box" class="btn-action btn-green" style="white-space:nowrap;"><i class="fa-solid fa-plus"></i> Create Box</button>
                    </form>

                    <!-- BOXES LIST AND ITEMS INSIDE -->
                    <h4 style="margin-bottom:10px; color:#475569;">Boxes List & Contained Items:</h4>
                    <?php
                    $job_boxes = mysqli_query($conn, "SELECT * FROM job_boxes WHERE job_id = $view_job_id ORDER BY id DESC");
                    if (mysqli_num_rows($job_boxes) > 0) {
                        while ($box = mysqli_fetch_assoc($job_boxes)) {
                            $b_id = $box['id'];
                            ?>
                            <div class="box-card">
                                <!-- RENAME AND DELETE BOX -->
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                                    <form method="POST" style="display:flex; gap:8px; align-items:center; flex:1;">
                                        <input type="hidden" name="job_id" value="<?= $view_job_id ?>">
                                        <input type="hidden" name="box_id" value="<?= $b_id ?>">
                                        <i class="fa-solid fa-box" style="color:#f97316; font-size:16px;"></i>
                                        <input type="text" name="box_name" value="<?= htmlspecialchars($box['box_name']) ?>" class="input-inline" style="text-align:left; font-size:14px; width:200px;" required>
                                        <button type="submit" name="rename_box" class="btn-action" style="padding:4px 8px; font-size:12px;"><i class="fa-solid fa-pen"></i> Rename</button>
                                    </form>
                                    <a href="job_list.php?delete_box=<?= $b_id ?>&from_job=<?= $view_job_id ?>" class="btn-action btn-danger" style="padding:4px 8px; font-size:12px;" onclick="return confirm('Delete this box? Items in it will become unassigned.');"><i class="fa-solid fa-trash"></i> Delete Box</a>
                                </div>

                                <!-- ADD MULTIPLE UNASSIGNED ITEMS DIRECTLY INTO THIS BOX -->
                                <form method="POST" style="display:flex; flex-direction:column; gap:8px; background:#ffffff; padding:10px; border-radius:6px; border:1px solid #cbd5e1; margin-bottom:10px;">
                                    <input type="hidden" name="job_id" value="<?= $view_job_id ?>">
                                    <input type="hidden" name="box_id" value="<?= $b_id ?>">
                                    <div style="display:flex; justify-content:space-between; align-items:center;">
                                        <span style="font-size:12px; font-weight:bold; color:#0f172a;"><i class="fa-solid fa-plus"></i> Add Items to Box (Hold Ctrl / Cmd to select multiple):</span>
                                        <button type="submit" name="add_item_to_box" class="btn-action btn-green" style="padding:4px 10px; font-size:12px;"><i class="fa-solid fa-plus"></i> Add Selected Items</button>
                                    </div>
                                    <select name="item_ids[]" class="input-inline" style="width:100%; height:90px; text-align:left; font-size:13px; padding:4px;" multiple required>
                                        <?php
                                        $unassigned_items = mysqli_query($conn, "SELECT ji.item_id, i.item_code, i.item_name FROM job_items ji JOIN items i ON ji.item_id = i.id WHERE ji.job_id = $view_job_id AND (ji.box_id = 0 OR ji.box_id IS NULL OR ji.box_id != $b_id)");
                                        while ($u_item = mysqli_fetch_assoc($unassigned_items)) {
                                            echo "<option value='{$u_item['item_id']}'>[" . htmlspecialchars($u_item['item_code']) . "] " . htmlspecialchars($u_item['item_name']) . "</option>";
                                        }
                                        ?>
                                    </select>
                                </form>

                                <!-- ITEMS INSIDE THIS BOX LIST -->
                                <div style="padding-left: 10px;">
                                    <span style="font-size:12px; font-weight:bold; color:#64748b;">Items Currently Inside:</span>
                                    <ul style="margin-top:6px; padding-left:20px; font-size:13px; color:#334155;">
                                        <?php
                                        $box_items = mysqli_query($conn, "SELECT ji.*, i.item_code, i.item_name FROM job_items ji JOIN items i ON ji.item_id = i.id WHERE ji.job_id = $view_job_id AND ji.box_id = $b_id");
                                        if (mysqli_num_rows($box_items) > 0) {
                                            while ($b_item = mysqli_fetch_assoc($box_items)) {
                                                echo "<li style='margin-bottom:4px;'>
                                                        <strong>[" . htmlspecialchars($b_item['item_code']) . "]</strong> " . htmlspecialchars($b_item['item_name']) . " (Qty: " . $b_item['qty'] . ")
                                                        <a href='job_list.php?remove_item_from_box={$b_item['item_id']}&from_job={$view_job_id}' style='color:#ef4444; font-size:11px; margin-left:8px; text-decoration:none;' onclick=\"return confirm('Remove this item from box?');\">[✕ Remove]</a>
                                                      </li>";
                                            }
                                        } else {
                                            echo "<li style='color:#94a3b8; list-style:none;'><em>No items assigned to this box yet.</em></li>";
                                        }
                                        ?>
                                    </ul>
                                </div>
                            </div>
                            <?php
                        }
                    } else {
                        echo "<p style='text-align:center; color:#94a3b8; padding:15px;'>No boxes created for this job yet.</p>";
                    }
                    ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- CONTEXT MENU FOR STATUS & COPY / PASTE -->
    <div id="statusContextMenu" class="status-context-menu">
        <ul>
            <li><button type="button" onclick="changeStatus('Done')"><i class="fa-solid fa-circle-check" style="color:#10b981;"></i> Done</button></li>
            <li><button type="button" onclick="changeStatus('In Progress')"><i class="fa-solid fa-spinner" style="color:#3b82f6;"></i> In Progress</button></li>
            <li><button type="button" onclick="changeStatus('Pending')"><i class="fa-solid fa-clock" style="color:#f59e0b;"></i> Pending</button></li>
            <hr>
            <li><button type="button" onclick="copyJobItems()"><i class="fa-solid fa-copy"></i> Copy Items</button></li>
            <li><button type="button" id="btnPasteItems" onclick="pasteJobItems()"><i class="fa-solid fa-paste"></i> Paste Items</button></li>
        </ul>
    </div>

    <script>
        let copiedSourceJobId = null;

        // TOGGLE BOX MODAL
        function toggleBoxModal(show) {
            const modal = document.getElementById('boxModal');
            if (modal) {
                if (show) modal.classList.add('active');
                else modal.classList.remove('active');
            }
        }

        // LIVE BEFORE / AFTER QTY DYNAMIC CALCULATOR
        function calculateQty(itemId) {
            const pcs = parseInt(document.getElementById('pcs_' + itemId).value) || 0;
            const prod = parseInt(document.getElementById('prod_' + itemId).value) || 0;
            const before = parseInt(document.getElementById('before_' + itemId).value) || 0;

            const afterInput = document.getElementById('after_' + itemId);

            if (prod > 0) {
                const totalDeduction = pcs * prod;
                afterInput.value = before - totalDeduction;
            } else {
                afterInput.value = before;
            }
        }

        // CONTEXT MENU FUNCTIONALITY
        const statusMenu = document.getElementById('statusContextMenu');
        const btnPaste = document.getElementById('btnPasteItems');
        let activeJobId = null;

        function openStatusMenu(e, jobId) {
            e.preventDefault();
            activeJobId = jobId;
            statusMenu.style.top = e.pageY + 'px';
            statusMenu.style.left = e.pageX + 'px';
            statusMenu.style.display = 'block';

            // Disable Paste option if no items copied yet or if clicking the same copied job
            if (!copiedSourceJobId || copiedSourceJobId === activeJobId) {
                btnPaste.style.opacity = '0.5';
                btnPaste.style.cursor = 'not-allowed';
            } else {
                btnPaste.style.opacity = '1';
                btnPaste.style.cursor = 'pointer';
            }
        }

        function changeStatus(newStatus) {
            if (!activeJobId) return;
            const badge = document.getElementById('badge-' + activeJobId);

            if (badge) {
                badge.innerText = newStatus;
                badge.className = 'status-badge ' + (newStatus === 'Done' ? 'status-done' : (newStatus === 'In Progress' ? 'status-in-progress' : 'status-pending'));
            }

            statusMenu.style.display = 'none';

            const formData = new FormData();
            formData.append('ajax_update_status', '1');
            formData.append('job_id', activeJobId);
            formData.append('status', newStatus);

            fetch('job_list.php', { method: 'POST', body: formData });
        }

        function copyJobItems() {
            if (!activeJobId) return;
            copiedSourceJobId = activeJobId;
            alert('Job items copied! Right-click on another job and click "Paste Items".');
            statusMenu.style.display = 'none';
        }

        function pasteJobItems() {
            if (!copiedSourceJobId) {
                alert('No job items copied yet. Right-click a job and choose "Copy Items" first.');
                return;
            }
            if (copiedSourceJobId === activeJobId) {
                alert('Cannot paste items into the same job.');
                return;
            }

            const formData = new FormData();
            formData.append('ajax_copy_paste_items', '1');
            formData.append('source_job_id', copiedSourceJobId);
            formData.append('target_job_id', activeJobId);

            fetch('job_list.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        alert(data.copied_count + ' item(s) pasted successfully!');
                        window.location.href = 'job_list.php?view_bom=' + activeJobId;
                    } else {
                        alert('Error pasting items: ' + (data.error || 'Unknown error'));
                    }
                });

            statusMenu.style.display = 'none';
        }

        document.addEventListener('click', (e) => { if (!statusMenu.contains(e.target)) statusMenu.style.display = 'none'; });
    </script>
</body>
</html>