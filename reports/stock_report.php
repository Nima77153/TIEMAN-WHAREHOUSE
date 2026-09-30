<?php
session_start();
// Config database inclusion
include('../config/db.php'); 

if (!$conn) {
    die("<div class='alert alert-danger m-3'><b>Database Connection Error:</b> Please verify your db.php configurations.</div>");
}

// ==========================================
// JOB REPORT TABLES (auto-create if missing)
// ==========================================
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS job_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_id INT NOT NULL,
    notes TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS job_report_files (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_id INT NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_type VARCHAR(50) NULL
)");
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS job_report_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_id INT NOT NULL,
    item_id INT NULL,
    item_code VARCHAR(100) NULL,
    item_name VARCHAR(255) NULL
)");

// ==========================================
// SAVE A NEW JOB REPORT (notes + files + linked scanned items)
// ==========================================
if (isset($_POST['save_job_report'])) {
    $report_job_id = (int)$_POST['job_id'];
    $report_notes  = mysqli_real_escape_string($conn, trim($_POST['report_notes'] ?? ''));

    mysqli_query($conn, "INSERT INTO job_reports (job_id, notes) VALUES ($report_job_id, '$report_notes')");
    $new_report_id = mysqli_insert_id($conn);

    // Save uploaded files (images, PDF, Excel, etc.)
    $report_upload_dir = '../uploads/job_reports/';
    if (!is_dir($report_upload_dir)) mkdir($report_upload_dir, 0777, true);

    if (!empty($_FILES['report_files']) && is_array($_FILES['report_files']['name'])) {
        foreach ($_FILES['report_files']['name'] as $idx => $orig_name) {
            if (empty($orig_name)) continue;
            if ($_FILES['report_files']['error'][$idx] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
                $safe_name = 'report_' . $new_report_id . '_' . time() . '_' . $idx . '.' . $ext;
                move_uploaded_file($_FILES['report_files']['tmp_name'][$idx], $report_upload_dir . $safe_name);

                $safe_name_escaped = mysqli_real_escape_string($conn, $safe_name);
                $ext_escaped = mysqli_real_escape_string($conn, $ext);
                mysqli_query($conn, "INSERT INTO job_report_files (report_id, file_name, file_type) VALUES ($new_report_id, '$safe_name_escaped', '$ext_escaped')");
            }
        }
    }

    // Save linked scanned/searched items
    $linked_items_json = $_POST['linked_items_json'] ?? '[]';
    $linked_items = json_decode($linked_items_json, true);
    if (is_array($linked_items)) {
        foreach ($linked_items as $li) {
            $li_code = mysqli_real_escape_string($conn, trim($li['item_code'] ?? ''));
            $li_name = mysqli_real_escape_string($conn, trim($li['item_name'] ?? ''));
            if (empty($li_code)) continue;

            $item_lookup_res = mysqli_query($conn, "SELECT id FROM items WHERE item_code = '$li_code' LIMIT 1");
            $item_lookup = $item_lookup_res ? mysqli_fetch_assoc($item_lookup_res) : null;
            $li_item_id = $item_lookup ? (int)$item_lookup['id'] : 'NULL';

            mysqli_query($conn, "INSERT INTO job_report_items (report_id, item_id, item_code, item_name) VALUES ($new_report_id, $li_item_id, '$li_code', '$li_name')");
        }
    }

    header("Location: stock_report.php?report_saved=1&opened_job=$report_job_id#job-panel");
    exit;
}

// Helper: render every saved report for one job (notes, file attachments, linked items)
function renderJobReports($conn, $job_id) {
    $reports_q = mysqli_query($conn, "SELECT * FROM job_reports WHERE job_id = $job_id ORDER BY id DESC");
    if (!$reports_q || mysqli_num_rows($reports_q) == 0) {
        return "<p class='text-muted small mb-0'>No reports created for this job yet.</p>";
    }

    $html = '';
    while ($rep = mysqli_fetch_assoc($reports_q)) {
        $rid = $rep['id'];
        $html .= "<div class='saved-report-card'>";
        $html .= "<div class='d-flex justify-content-between align-items-center mb-1'>";
        $html .= "<strong class='small text-dark'>📝 Report #$rid</strong>";
        $html .= "<span class='text-muted small'>" . date('d M Y H:i', strtotime($rep['created_at'])) . "</span>";
        $html .= "</div>";

        if (!empty($rep['notes'])) {
            $html .= "<p class='small mb-2' style='white-space:pre-wrap;'>" . htmlspecialchars($rep['notes']) . "</p>";
        }

        // Attached files
        $files_q = mysqli_query($conn, "SELECT * FROM job_report_files WHERE report_id = $rid");
        if ($files_q && mysqli_num_rows($files_q) > 0) {
            $html .= "<div class='d-flex flex-wrap gap-2 mb-2'>";
            while ($f = mysqli_fetch_assoc($files_q)) {
                $file_url = '../uploads/job_reports/' . rawurlencode($f['file_name']);
                $is_image = in_array(strtolower($f['file_type']), ['jpg','jpeg','png','gif','webp']);
                if ($is_image) {
                    $html .= "<a href='$file_url' target='_blank' title='" . htmlspecialchars($f['file_name']) . "'><img src='$file_url' class='report-file-thumb' alt='attachment'></a>";
                } else {
                    $html .= "<a href='$file_url' target='_blank' class='report-file-link'>📄 " . htmlspecialchars($f['file_name']) . "</a>";
                }
            }
            $html .= "</div>";
        }

        // Linked items
        $items_q = mysqli_query($conn, "SELECT * FROM job_report_items WHERE report_id = $rid");
        if ($items_q && mysqli_num_rows($items_q) > 0) {
            $html .= "<div class='d-flex flex-wrap gap-1'>";
            while ($li = mysqli_fetch_assoc($items_q)) {
                $html .= "<span class='linked-item-chip-view'>[" . htmlspecialchars($li['item_code']) . "] " . htmlspecialchars($li['item_name']) . "</span>";
            }
            $html .= "</div>";
        }

        $html .= "</div>";
    }
    return $html;
}

// EXACT MATCHING IMAGE RESOLVER FROM item_list.php
function getItemImage($imageName) {
    $cleanName = trim(strip_tags($imageName));
    
    // Check uploads/items/ folder (matching item_list.php)
    if (!empty($cleanName) && file_exists('../uploads/items/' . $cleanName)) {
        return '../uploads/items/' . $cleanName;
    }
    
    // Fallback if image stored directly in uploads/ folder
    if (!empty($cleanName) && file_exists('../uploads/' . $cleanName)) {
        return '../uploads/' . $cleanName;
    }
    
    // Fallback placeholder image if missing or broken
    return '../assets/images/no-image.png';
}

// --- 1. FETCH JOB ALLOCATIONS DATA ---
$job_query = mysqli_query($conn, "SELECT j.id AS job_id, j.job_no, j.customer_name, j.status AS job_status, j.created_at, i.item_code, i.barcode, i.stock_qty, i.image, i.item_name, i.remark FROM jobs j LEFT JOIN job_items ji ON j.id = ji.job_id LEFT JOIN items i ON ji.item_id = i.id ORDER BY j.id DESC");

$jobs = [];
if ($job_query) {
    while ($row = mysqli_fetch_assoc($job_query)) {
        $job_id = $row['job_id'];
        if (!isset($jobs[$job_id])) {
            $jobs[$job_id] = [
                'job_no' => $row['job_no'],
                'customer_name' => $row['customer_name'],
                'job_status' => $row['job_status'],
                'created_at' => $row['created_at'],
                'items' => []
            ];
        }
        if (!empty($row['item_code'])) {
            $jobs[$job_id]['items'][] = [
                'item_name' => $row['item_name'],
                'item_code' => $row['item_code'],
                'barcode' => $row['barcode'],
                'stock_qty' => $row['stock_qty'],
                'image' => $row['image'],
                'remark' => $row['remark']
            ];
        }
    }
}

// --- 2. FETCH STORE INVENTORY DATA (with optional category filter, e.g. Store Tieman) ---
$store_category_filter = isset($_GET['store_category']) ? trim($_GET['store_category']) : '';

if (!empty($store_category_filter)) {
    $safe_category = mysqli_real_escape_string($conn, $store_category_filter);
    $store_query = mysqli_query($conn, "SELECT id, item_code, item_name, barcode, stock_qty, image, remark, category FROM items WHERE category = '$safe_category' ORDER BY id DESC");
} else {
    $store_query = mysqli_query($conn, "SELECT id, item_code, item_name, barcode, stock_qty, image, remark, category FROM items ORDER BY id DESC");
}

// Pull the distinct categories actually in use, so the filter dropdown always matches real data
$category_options_query = mysqli_query($conn, "SELECT DISTINCT category FROM items WHERE category IS NOT NULL AND category != '' ORDER BY category ASC");
$category_options = [];
if ($category_options_query) {
    while ($c = mysqli_fetch_assoc($category_options_query)) {
        $category_options[] = $c['category'];
    }
}

$stock_in_query = mysqli_query($conn, "SELECT id, item_code, item_name, barcode, stock_qty, image, remark FROM items WHERE stock_qty > 0 ORDER BY id DESC LIMIT 15");
$stock_out_query = mysqli_query($conn, "SELECT id, item_code, item_name, barcode, stock_qty, image, remark FROM items WHERE stock_qty = 0 ORDER BY id DESC LIMIT 15");
$job_trans_query = mysqli_query($conn, "SELECT ji.job_id, j.job_no, j.customer_name, j.status, j.created_at AS trans_date, i.item_name, i.item_code, i.barcode, i.image, i.remark FROM job_items ji JOIN jobs j ON ji.job_id = j.id JOIN items i ON ji.item_id = i.id ORDER BY j.id DESC LIMIT 15");

$opened_job = isset($_GET['opened_job']) ? (int)$_GET['opened_job'] : 0;
$report_saved = isset($_GET['report_saved']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Warehouse - Stock Report Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js"></script>
    <!-- Font Awesome CDN for sidebar icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body { background:#f8fafc; font-family:'Segoe UI', sans-serif; }

        /* SIDEBAR WITH MODERN ICON STYLING (same as dashboard / items / job list / create job) */
        .sidebar {
            width: 260px;
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

        .main { margin-left:260px; padding:20px; }
        .card-box { background:white; padding:20px; border-radius:12px; box-shadow:0 1px 3px rgba(0,0,0,.05); margin-bottom: 20px;}
        
        /* TABLE & IMAGE CONTAINER MATCHING YOUR SCREENSHOT EXACTLY */
        .table-item-list { border-collapse: separate; border-spacing: 0 8px; }
        .table-item-list tr { background: #ffffff; border-bottom: 1px solid #f1f5f9; }
        .img-card {
            width: 55px;
            height: 55px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 3px;
            background: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }
        .img-card img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
            border-radius: 4px;
        }
        
        .part-code {
            color: #2563eb;
            font-weight: 700;
            font-size: 14px;
            text-decoration: none;
        }
        
        .item-desc {
            font-weight: 600;
            color: #334155;
            text-transform: uppercase;
            font-size: 13px;
        }

        /* STORE CATEGORY FILTER BAR */
        .category-filter-bar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 16px; }
        .category-chip { padding: 6px 14px; border-radius: 20px; border: 1px solid #cbd5e1; background: #ffffff; color: #334155; font-size: 13px; font-weight: 600; text-decoration: none; }
        .category-chip.active-chip { background: #f97316; border-color: #f97316; color: #ffffff; }
        .category-chip:hover { border-color: #f97316; color: #f97316; }
        .category-chip.active-chip:hover { color: #ffffff; }

        /* JOB REPORT BUILDER */
        .report-builder-box { background:#f8fafc; border:1px dashed #cbd5e1; border-radius:10px; padding:14px; margin-top:12px; }
        .linked-items-list { display:flex; flex-wrap:wrap; gap:6px; min-height: 28px; }
        .linked-item-chip { background:#eef2ff; color:#3730a3; padding:4px 10px; border-radius:14px; font-size:12px; font-weight:600; display:inline-flex; align-items:center; gap:6px; }
        .linked-item-chip a { color:#ef4444; text-decoration:none; font-weight:bold; }
        .saved-report-card { background:#ffffff; border:1px solid #e2e8f0; border-radius:8px; padding:12px; margin-top:10px; }
        .report-file-thumb { width:60px; height:60px; object-fit:cover; border-radius:6px; border:1px solid #cbd5e1; }
        .report-file-link { font-size:12px; background:#f1f5f9; padding:6px 10px; border-radius:6px; text-decoration:none; color:#334155; }
        .linked-item-chip-view { background:#f0fdf4; color:#166534; padding:3px 8px; border-radius:12px; font-size:11px; font-weight:600; }

        #qr-reader { width: 100%; max-width: 400px; margin: 0 auto; background: #111; border: 2px dashed #f97316!important; border-radius: 8px; overflow: hidden; }
        #qr-reader video { width: 100%!important; height: auto !important; }
        .nav-tabs .nav-link { font-weight: bold; color: #4b5563; border-radius: 8px 8px 0 0; padding: 12px 25px; }
        .nav-tabs .nav-link.active { background-color: white; color: #f97316; border-color: #dee2e6 #dee2e6 white; }
        .live-clock { background: #1e293b; color: #38bdf8; padding: 6px 14px; border-radius: 8px; font-weight: bold; font-family: monospace; display: inline-block; }
        @media(max-width: 768px) { .sidebar { display: none; } .main { margin-left: 0; padding: 10px; } }
        
        .color-theme-1 { background-color: #ffffff !important; border-left: 5px solid #ef4444 !important; }
        .color-theme-2 { background-color: #ffffff !important; border-left: 5px solid #22c55e !important; }
        .color-theme-3 { background-color: #ffffff !important; border-left: 5px solid #3b82f6 !important; }
        .color-theme-4 { background-color: #ffffff !important; border-left: 5px solid #eab308 !important; }
        .color-theme-5 { background-color: #ffffff !important; border-left: 5px solid #a855f7 !important; }
        .color-theme-6 { background-color: #ffffff !important; border-left: 5px solid #14b8a6 !important; }
        .color-theme-7 { background-color: #ffffff !important; border-left: 5px solid #f97316 !important; }
    </style>
</head>
<body>

    <!-- SIDEBAR -->
    <div class="sidebar">
        <div class="logo">WAREHOUSE</div>
        <div class="sidebar-menu">
            <a href="../dashboard.php">
                <i class="fa-solid fa-gauge-high"></i> Dashboard
            </a>
            <a href="../items/item_list.php">
                <i class="fa-solid fa-box-archive"></i> Items
            </a>
            <a href="../items/add_item.php">
                <i class="fa-solid fa-plus"></i> Add Item
            </a>
            <a href="../import_excel.php">
                <i class="fa-solid fa-file-import"></i> Import Excel
            </a>
            <a href="../create_job.php">
                <i class="fa-solid fa-file-circle-plus"></i> Create Job
            </a>
            <a href="../job_list.php">
                <i class="fa-solid fa-file-lines"></i> Job List
            </a>
            <a href="../stock/stock_in.php">
                <i class="fa-solid fa-arrow-trend-up"></i> Stock In
            </a>
            <a href="../items/stock_out.php">
                <i class="fa-solid fa-arrow-trend-down"></i> Stock Out
            </a>
            <a href="../return_item.php">
                <i class="fa-solid fa-rotate-left"></i> Returns
            </a>
            <a href="../stock/missing_item.php">
                <i class="fa-solid fa-triangle-exclamation"></i> Missing
            </a>
            <a href="../scaner.php">
                <i class="fa-solid fa-barcode"></i> Scanner
            </a>
            <a href="stock_report.php" class="active">
                <i class="fa-solid fa-chart-pie"></i> Reports
            </a>
            <a href="../logout.php">
                <i class="fa-solid fa-right-from-bracket"></i> Logout
            </a>
        </div>
    </div>

    <div class="main">
        <div class="topbar card-box d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h4 class="m-0 text-dark fw-bold">📊 Unified Stock & Job Report</h4>
                <small class="text-muted">Live view of customer parts orders and overall system inventory status</small>
            </div>
            
            <div class="text-end">
                <div class="live-clock" id="liveClockDisplay">🕒 Loading Live Time...</div>
            </div>

            <div class="d-flex gap-2">
                <a href="export_combined_excel.php?type=job" class="btn btn-warning fw-bold text-white shadow-sm">📥 Export Jobs to Excel</a>
                <a href="export_combined_excel.php?type=store" class="btn btn-success fw-bold shadow-sm">📥 Export Store to Excel</a>
            </div>
        </div>

        <?php if ($report_saved): ?>
            <div class="alert alert-success">✅ Report saved successfully.</div>
        <?php endif; ?>

        <div class="row g-3">
            <div class="col-lg-4">
                <div class="card-box text-center sticky-top" style="top: 20px; z-index: 10;">
                    <h5 class="mb-3 text-secondary fw-bold">🔍 Universal Search & Scanner</h5>
                    <div id="qr-reader"></div>
                    <div id="scan-results" class="mt-2 fw-bold text-success small"></div>
                    <button class="btn btn-warning btn-sm mt-2 w-100" onclick="switchCamera()">🔄 Switch Camera View</button>
                    
                    <div class="text-muted my-3 small">─ OR SNAP / CHOOSE FILE ─</div>
                    <input type="file" accept="image/*" capture="environment" id="file-selector" class="form-control border-2 mb-3">

                    <input type="text" id="search_box" class="form-control form-control-lg border-2 text-center" placeholder="Scan barcode or input part code..." autocomplete="off">
                    <div id="db-lookup-info" class="mt-2 fw-bold text-primary small"></div>
                    <div id="activeReportNotice" class="mt-2 small text-muted"></div>
                </div>
            </div>

            <div class="col-lg-8">
                <ul class="nav nav-tabs border-0 mb-3" id="reportTabs" role="tablist">
                    <li class="nav-item">
                        <button class="nav-link active" id="job-tab" data-bs-toggle="tab" data-bs-target="#job-panel" type="button" role="tab">📋 Customer Job Sheets</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="store-tab" data-bs-toggle="tab" data-bs-target="#store-panel" type="button" role="tab">🏢 Core Store Inventory</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="stockin-tab" data-bs-toggle="tab" data-bs-target="#stockin-panel" type="button" role="tab">📈 Stock In</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="stockout-tab" data-bs-toggle="tab" data-bs-target="#stockout-panel" type="button" role="tab">📉 Stock Out</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="jobtrans-tab" data-bs-toggle="tab" data-bs-target="#jobtrans-panel" type="button" role="tab">🔄 Job Item Out/In</button>
                    </li>
                </ul>

                <div class="tab-content" id="reportTabsContent">
                    
                    <!-- JOB PANEL -->
                    <div class="tab-pane fade show active" id="job-panel" role="tabpanel">
                        <?php 
                        $color_counter = 0;
                        if(!empty($jobs)): foreach($jobs as $id => $job): 
                            $selected_theme = "color-theme-" . (($color_counter % 7) + 1);
                            $color_counter++;
                        ?>
                            <div class="card-box mb-4 <?= $selected_theme ?>">
                                <div class="d-flex flex-wrap justify-content-between align-items-center border-bottom pb-2 mb-3">
                                    <div>
                                        <h5 class="m-0 fw-bold text-dark">Job Assignment: <span class="text-primary"><?= htmlspecialchars($job['job_no']) ?></span></h5>
                                        <small class="text-muted">👥 Customer Profile: <b><?= htmlspecialchars($job['customer_name']) ?></b></small>
                                    </div>
                                    <div class="text-end">
                                        <span class="badge bg-dark px-3 py-1.5 text-uppercase"><?= htmlspecialchars($job['job_status']) ?></span>
                                        <div class="text-muted small mt-1"><?= date('d M Y H:i', strtotime($job['created_at'])) ?></div>
                                    </div>
                                </div>

                                <div class="table-responsive">
                                    <table class="table align-middle table-item-list m-0">
                                        <tbody>
                                            <?php if(!empty($job['items'])): foreach($job['items'] as $item): ?>
                                                <tr class="item-row" data-barcode="<?= htmlspecialchars($item['barcode']) ?>" data-code="<?= htmlspecialchars($item['item_code']) ?>">
                                                    <td style="width: 40px;"><input type="checkbox" class="form-check-input"></td>
                                                    <td style="width: 70px;">
                                                        <div class="img-card">
                                                            <img src="<?= getItemImage($item['image']) ?>" alt="Image" onerror="this.onerror=null; this.src='https://via.placeholder.com/50?text=No+Img';">
                                                        </div>
                                                    </td>
                                                    <td style="width: 180px;"><span class="part-code"><?= htmlspecialchars($item['item_code']) ?></span></td>
                                                    <td><span class="item-desc"><?= htmlspecialchars($item['item_name']) ?></span></td>
                                                    <td class="text-end" style="width: 80px;"><span class="badge bg-primary fs-6"><?= $item['stock_qty'] ?></span></td>
                                                </tr>
                                            <?php endforeach; else: ?>
                                                <tr><td colspan="5" class="text-center text-muted py-3">No physical lines assigned to this job tracking sheet.</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- JOB REPORT BUILDER -->
                                <div class="d-flex justify-content-end mt-2">
                                    <button type="button" class="btn btn-sm btn-outline-dark" onclick="toggleReportBuilder(<?= $id ?>)">📝 Build Report</button>
                                </div>

                                <div id="reportBuilder-<?= $id ?>" class="report-builder-box" style="display: <?= ($opened_job === $id) ? 'block' : 'none' ?>;">
                                    <form method="POST" enctype="multipart/form-data">
                                        <input type="hidden" name="job_id" value="<?= $id ?>">
                                        <input type="hidden" name="linked_items_json" id="linkedItemsInput-<?= $id ?>" value="[]">

                                        <div class="mb-2">
                                            <label class="form-label small fw-bold">Report Notes</label>
                                            <textarea name="report_notes" class="form-control" rows="3" placeholder="Type your report notes here..."></textarea>
                                        </div>

                                        <div class="mb-2">
                                            <label class="form-label small fw-bold">Attach Images / Files (photos, PDF, Excel, etc.)</label>
                                            <input type="file" name="report_files[]" class="form-control" multiple accept="image/*,.pdf,.xls,.xlsx,.doc,.docx">
                                        </div>

                                        <div class="mb-2">
                                            <label class="form-label small fw-bold">Linked Items <span class="text-muted fw-normal">(open this box, then use the scanner/search on the left and click "Add to Report")</span></label>
                                            <div id="linkedItemsList-<?= $id ?>" class="linked-items-list">
                                                <span class="text-muted small">No items linked yet.</span>
                                            </div>
                                        </div>

                                        <button type="submit" name="save_job_report" class="btn btn-success btn-sm">💾 Save Report</button>
                                    </form>

                                    <div class="saved-reports">
                                        <h6 class="small fw-bold text-secondary mt-3 mb-1">Previous Reports</h6>
                                        <?= renderJobReports($conn, $id) ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; else: ?>
                            <div class="card-box text-center py-5 text-muted"><h3>No active client tracking records found.</h3></div>
                        <?php endif; ?>
                    </div>

                    <!-- STORE PANEL -->
                    <div class="tab-pane fade" id="store-panel" role="tabpanel">
                        <div class="card-box border-start border-5 border-success">

                            <!-- CATEGORY QUICK FILTER (e.g. Store Tieman) -->
                            <div class="category-filter-bar">
                                <span class="fw-bold text-secondary small me-1">Filter by Category:</span>
                                <a href="stock_report.php#store-panel" class="category-chip <?= empty($store_category_filter) ? 'active-chip' : '' ?>" onclick="return switchStoreTab();">All Items</a>
                                <?php foreach ($category_options as $cat): ?>
                                    <a href="stock_report.php?store_category=<?= urlencode($cat) ?>#store-panel"
                                       class="category-chip <?= ($store_category_filter === $cat) ? 'active-chip' : '' ?>"
                                       onclick="return switchStoreTab();">
                                        <?= htmlspecialchars($cat) ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>

                            <div class="table-responsive">
                                <table class="table align-middle table-item-list m-0">
                                    <tbody>
                                        <?php if($store_query && mysqli_num_rows($store_query) > 0): while($item = mysqli_fetch_assoc($store_query)): ?>
                                            <tr class="item-row" data-barcode="<?= htmlspecialchars($item['barcode']) ?>" data-code="<?= htmlspecialchars($item['item_code']) ?>">
                                                <td style="width: 40px;"><input type="checkbox" class="form-check-input"></td>
                                                <td style="width: 70px;">
                                                    <div class="img-card">
                                                        <img src="<?= getItemImage($item['image']) ?>" alt="Image" onerror="this.onerror=null; this.src='https://via.placeholder.com/50?text=No+Img';">
                                                    </div>
                                                </td>
                                                <td style="width: 180px;"><span class="part-code"><?= htmlspecialchars($item['item_code']) ?></span></td>
                                                <td>
                                                    <span class="item-desc"><?= htmlspecialchars($item['item_name']) ?></span>
                                                    <?php if(!empty($item['category'])): ?>
                                                        <br><span class="badge bg-secondary text-uppercase mt-1"><?= htmlspecialchars($item['category']) ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end" style="width: 100px;">
                                                    <?php $lbl = ($item['stock_qty'] > 0) ? 'bg-success' : 'bg-danger'; ?>
                                                    <span class="badge <?= $lbl ?> fs-6"><?= $item['stock_qty'] ?></span>
                                                </td>
                                            </tr>
                                        <?php endwhile; else: ?>
                                            <tr><td colspan="5" class="text-center text-muted py-4">No items found<?= !empty($store_category_filter) ? ' for category "' . htmlspecialchars($store_category_filter) . '"' : '' ?>.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- STOCK IN PANEL -->
                    <div class="tab-pane fade" id="stockin-panel" role="tabpanel">
                        <div class="card-box border-start border-5 border-info">
                            <h5 class="mb-3 text-info fw-bold">📈 Stock In Records</h5>
                            <div class="table-responsive">
                                <table class="table align-middle table-item-list m-0">
                                    <tbody>
                                        <?php if($stock_in_query && mysqli_num_rows($stock_in_query) > 0): mysqli_data_seek($stock_in_query, 0); while($in_row = mysqli_fetch_assoc($stock_in_query)): ?>
                                            <tr>
                                                <td style="width: 40px;"><input type="checkbox" class="form-check-input"></td>
                                                <td style="width: 70px;">
                                                    <div class="img-card">
                                                        <img src="<?= getItemImage($in_row['image']) ?>" alt="Image" onerror="this.onerror=null; this.src='https://via.placeholder.com/50?text=No+Img';">
                                                    </div>
                                                </td>
                                                <td style="width: 180px;"><span class="part-code"><?= htmlspecialchars($in_row['item_code']) ?></span></td>
                                                <td><span class="item-desc"><?= htmlspecialchars($in_row['item_name']) ?></span></td>
                                                <td class="text-end" style="width: 80px;"><span class="badge bg-info text-dark fs-6"><?= $in_row['stock_qty'] ?></span></td>
                                            </tr>
                                        <?php endwhile; else: ?>
                                            <tr><td colspan="5" class="text-center text-muted py-3">No entry items tracked.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- STOCK OUT PANEL -->
                    <div class="tab-pane fade" id="stockout-panel" role="tabpanel">
                        <div class="card-box border-start border-5 border-danger">
                            <h5 class="mb-3 text-danger fw-bold">📉 Stock Out Records</h5>
                            <div class="table-responsive">
                                <table class="table align-middle table-item-list m-0">
                                    <tbody>
                                        <?php if($stock_out_query && mysqli_num_rows($stock_out_query) > 0): mysqli_data_seek($stock_out_query, 0); while($out_row = mysqli_fetch_assoc($stock_out_query)): ?>
                                            <tr>
                                                <td style="width: 40px;"><input type="checkbox" class="form-check-input"></td>
                                                <td style="width: 70px;">
                                                    <div class="img-card">
                                                        <img src="<?= getItemImage($out_row['image']) ?>" alt="Image" onerror="this.onerror=null; this.src='https://via.placeholder.com/50?text=No+Img';">
                                                    </div>
                                                </td>
                                                <td style="width: 180px;"><span class="part-code"><?= htmlspecialchars($out_row['item_code']) ?></span></td>
                                                <td><span class="item-desc"><?= htmlspecialchars($out_row['item_name']) ?></span></td>
                                                <td class="text-end" style="width: 80px;"><span class="badge bg-danger fs-6"><?= $out_row['stock_qty'] ?></span></td>
                                            </tr>
                                        <?php endwhile; else: ?>
                                            <tr><td colspan="5" class="text-center text-muted py-3">No outbound items tracked.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- JOB TRANS PANEL -->
                    <div class="tab-pane fade" id="jobtrans-panel" role="tabpanel">
                        <div class="card-box border-start border-5 border-warning">
                            <h5 class="mb-3 text-warning fw-bold">🔄 Job Item Out/In Transactions</h5>
                            <div class="table-responsive">
                                <table class="table align-middle table-item-list m-0">
                                    <tbody>
                                        <?php if($job_trans_query && mysqli_num_rows($job_trans_query) > 0): mysqli_data_seek($job_trans_query, 0); while($j_trans = mysqli_fetch_assoc($job_trans_query)): ?>
                                            <tr>
                                                <td style="width: 40px;"><input type="checkbox" class="form-check-input"></td>
                                                <td style="width: 70px;">
                                                    <div class="img-card">
                                                        <img src="<?= getItemImage($j_trans['image']) ?>" alt="Image" onerror="this.onerror=null; this.src='https://via.placeholder.com/50?text=No+Img';">
                                                    </div>
                                                </td>
                                                <td style="width: 150px;"><span class="text-dark fw-bold"><?= htmlspecialchars($j_trans['job_no']) ?></span></td>
                                                <td style="width: 150px;"><span class="part-code"><?= htmlspecialchars($j_trans['item_code']) ?></span></td>
                                                <td><span class="item-desc"><?= htmlspecialchars($j_trans['item_name']) ?></span></td>
                                                <td class="text-end"><small class="text-muted"><?= date('d M Y H:i', strtotime($j_trans['trans_date'])) ?></small></td>
                                            </tr>
                                        <?php endwhile; else: ?>
                                            <tr><td colspan="6" class="text-center text-muted py-3">No client tracking lines updated.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <script>
        // Keep the Store Inventory tab open after a category filter reload
        function switchStoreTab() {
            sessionStorage.setItem('reportActiveTab', 'store-tab');
            return true;
        }
        window.addEventListener('DOMContentLoaded', () => {
            const savedTab = sessionStorage.getItem('reportActiveTab');
            if (savedTab) {
                const tabBtn = document.getElementById(savedTab);
                if (tabBtn) {
                    const tab = new bootstrap.Tab(tabBtn);
                    tab.show();
                }
                sessionStorage.removeItem('reportActiveTab');
            }
        });

        // ==========================================
        // JOB REPORT BUILDER: open/close, and linking
        // scanned/searched items into the open report
        // ==========================================
        let activeReportJobId = null;
        let linkedItemsMap = {}; // jobId -> array of {item_code, item_name}

        function toggleReportBuilder(jobId) {
            document.querySelectorAll('.report-builder-box').forEach(box => {
                if (box.id !== 'reportBuilder-' + jobId) box.style.display = 'none';
            });
            const box = document.getElementById('reportBuilder-' + jobId);
            const willShow = box.style.display === 'none';
            box.style.display = willShow ? 'block' : 'none';
            activeReportJobId = willShow ? jobId : null;
            updateActiveReportNotice();
        }

        function updateActiveReportNotice() {
            const notice = document.getElementById('activeReportNotice');
            notice.innerText = activeReportJobId
                ? '📝 Adding scanned/searched items to the open report...'
                : '';
        }

        function addLinkedItemToActiveReport(item) {
            if (!activeReportJobId) {
                alert('Open "Build Report" on a job first, then scan/search an item to link it.');
                return;
            }
            if (!linkedItemsMap[activeReportJobId]) linkedItemsMap[activeReportJobId] = [];
            if (linkedItemsMap[activeReportJobId].some(i => i.item_code === item.item_code)) return;
            linkedItemsMap[activeReportJobId].push(item);
            renderLinkedItems(activeReportJobId);
        }

        function renderLinkedItems(jobId) {
            const container = document.getElementById('linkedItemsList-' + jobId);
            const items = linkedItemsMap[jobId] || [];
            if (items.length === 0) {
                container.innerHTML = '<span class="text-muted small">No items linked yet.</span>';
            } else {
                container.innerHTML = items.map((it, idx) => `
                    <span class="linked-item-chip">
                        [${it.item_code}] ${it.item_name}
                        <a href="#" onclick="removeLinkedItem(${jobId}, ${idx}); return false;">✕</a>
                    </span>
                `).join('');
            }
            const hiddenInput = document.getElementById('linkedItemsInput-' + jobId);
            if (hiddenInput) hiddenInput.value = JSON.stringify(items);
        }

        function removeLinkedItem(jobId, idx) {
            linkedItemsMap[jobId].splice(idx, 1);
            renderLinkedItems(jobId);
        }

        // LIVE DATE AND TIME CLOCK SCRIPT
        function updateLiveClock() {
            const now = new Date();
            const options = { 
                day: '2-digit', 
                month: 'short', 
                year: 'numeric', 
                hour: '2-digit', 
                minute: '2-digit', 
                second: '2-digit',
                hour12: false 
            };
            document.getElementById('liveClockDisplay').innerHTML = "🕒 " + now.toLocaleString('en-GB', options);
        }
        setInterval(updateLiveClock, 1000);
        updateLiveClock();

        // SCANNER SCRIPT
        let html5QrCode;
        let currentFacingMode = "environment";

        function onScanSuccess(decodedText) {
            document.getElementById('search_box').value = decodedText;
            document.getElementById('scan-results').innerText = "🎯 Scanned Code: " + decodedText;
            handleSearchAndHighlight(decodedText);
        }

        function startScanner(facingMode) {
            if (html5QrCode && html5QrCode.isScanning) {
                html5QrCode.stop().then(() => { runInit(facingMode); }).catch(err => console.log(err));
            } else {
                runInit(facingMode);
            }
        }

        function runInit(facingMode) {
            html5QrCode = new Html5Qrcode("qr-reader");
            html5QrCode.start({ facingMode: facingMode }, { fps: 20, qrbox: { width: 250, height: 150 } }, onScanSuccess, () => {})
            .catch(() => {
                document.getElementById('scan-results').innerHTML = `<span class="text-warning">⚠️ Live video streaming locked over HTTP network link. Use file upload option below to take snapshots!</span>`;
            });
        }

        function switchCamera() {
            currentFacingMode = (currentFacingMode === "environment") ? "user" : "environment";
            startScanner(currentFacingMode);
        }

        document.getElementById('file-selector').addEventListener('change', e => {
            if (e.target.files.length == 0) return;
            const fileScanner = new Html5Qrcode("search_box"); 
            fileScanner.scanFile(e.target.files[0], true).then(decodedText => {
                document.getElementById('search_box').value = decodedText;
                handleSearchAndHighlight(decodedText);
            }).catch(() => { alert("❌ System failed to resolve clear barcode lines inside snapshot image."); });
        });

        function handleSearchAndHighlight(val) {
            if(!val.trim()) {
                document.getElementById('db-lookup-info').innerHTML = "";
                document.querySelectorAll('.item-row').forEach(row => row.style.backgroundColor = "");
                return;
            }

            let fd = new FormData(); fd.append('identifier', val);
            fetch('find_item_name.php', { method: 'POST', body: fd })
            .then(r => r.text()).then(html => {
                document.getElementById('db-lookup-info').innerHTML = html;
                appendAddToReportButton();
            });

            let matchedItem = null;
            document.querySelectorAll('.item-row').forEach(row => {
                if(row.getAttribute('data-barcode') === val || row.getAttribute('data-code') === val) {
                    row.style.backgroundColor = "#fffbeb"; 
                    row.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    if (!matchedItem) {
                        const descEl = row.querySelector('.item-desc');
                        matchedItem = {
                            item_code: row.getAttribute('data-code') || '',
                            item_name: descEl ? descEl.innerText.trim() : ''
                        };
                    }
                } else {
                    row.style.backgroundColor = "";
                }
            });

            window.lastMatchedItem = matchedItem;
        }

        function appendAddToReportButton() {
            const infoBox = document.getElementById('db-lookup-info');
            if (window.lastMatchedItem && window.lastMatchedItem.item_code) {
                infoBox.innerHTML += ` <button type="button" class="btn btn-sm btn-outline-success mt-1" onclick='addLinkedItemToActiveReport(${JSON.stringify(window.lastMatchedItem)})'>➕ Add to Report</button>`;
            }
        }

        document.getElementById('search_box').addEventListener('input', e => handleSearchAndHighlight(e.target.value));
        window.addEventListener('DOMContentLoaded', () => startScanner(currentFacingMode));
    </script>
</body>
</html>
