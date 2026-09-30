<?php
session_start();
include('config/db.php');

require 'vendor/autoload.php'; 
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\MemoryDrawing;

// Same image-resolving helper used in items/item_list.php, so images
// display identically here as they do on the Items list page.
function getDynamicImagePath($image_file, $item_code = '') {
    if (!empty($image_file) && (filter_var($image_file, FILTER_VALIDATE_URL) || strpos($image_file, 'http') === 0)) {
        return $image_file . '?v=' . time();
    }

    $search_directories = [
        $_SERVER['DOCUMENT_ROOT'] . "/uploads/items/",
        $_SERVER['DOCUMENT_ROOT'] . "/uploads/"
    ];

    $allowed_exts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    if (!empty($item_code)) {
        $clean_code = preg_replace('/[^A-Za-z0-9_\-]/', '_', trim($item_code));
        foreach ($search_directories as $dir) {
            foreach ($allowed_exts as $ext) {
                if (file_exists($dir . $clean_code . '.' . $ext)) {
                    $rel_path = str_replace($_SERVER['DOCUMENT_ROOT'], '', $dir . $clean_code . '.' . $ext);
                    return $rel_path . '?v=' . time();
                }
            }
        }
    }

    if (!empty($image_file)) {
        foreach ($search_directories as $dir) {
            if (file_exists($dir . $image_file)) {
                return str_replace($_SERVER['DOCUMENT_ROOT'], '', $dir . $image_file);
            }
        }
    }

    return '/assets/images/no-image.png';
}

// FUNCTION 1: EXCEL MATRIX AUTO-PARSER
// Sheet layout confirmed as: B = Part No, C = Image, D = Description,
// E = Quantity (unused here), then job columns detected dynamically (TA####).
if(isset($_POST['import_matrix'])) {
    if(empty($_FILES['excel']['tmp_name'])) {
        echo "<script>alert('Please select a valid Excel file.'); window.history.back();</script>";
        exit;
    }

    $excelFile = $_FILES['excel']['tmp_name'];
    $target_dir = 'uploads/items/';
    if(!is_dir($target_dir)) mkdir($target_dir, 0777, true);

    try {
        $spreadsheet = IOFactory::load($excelFile);
        $worksheet = $spreadsheet->getActiveSheet();
        $sheetData = $worksheet->toArray(null, true, true, true); 

        // Safe extraction of cell image components
        $drawings = $worksheet->getDrawingCollection();
        $inlineImages = [];
        foreach ($drawings as $drawing) {
            $coordinates = $drawing->getCoordinates(); 
            preg_match('/^[A-Z]+(\d+)$/', $coordinates, $matches);
            $rowNumber = isset($matches[1]) ? (int)$matches[1] : -1; 
            if ($rowNumber >= 0) {
                if ($drawing instanceof MemoryDrawing) {
                    ob_start();
                    call_user_func($drawing->getRenderingFunction(), $drawing->getImageResource());
                    $imageContents = ob_get_contents();
                    ob_end_clean();
                    $extension = $drawing->getMimeType() == MemoryDrawing::MIMETYPE_PNG ? 'png' : 'jpeg';
                } else {
                    $zipReader = @fopen($drawing->getPath(), 'r');
                    if(!$zipReader) continue;
                    $imageContents = '';
                    while (!feof($zipReader)) { $imageContents .= fread($zipReader, 8192); }
                    fclose($zipReader);
                    $extension = $drawing->getExtension();
                }
                $inlineImages[$rowNumber] = ['content' => $imageContents, 'ext' => $extension];
            }
        }

        // Identify structural header columns containing job names (e.g. TA6674)
        $job_columns = [];
        for ($r = 1; $r <= 6; $r++) {
            if (!isset($sheetData[$r])) continue;
            foreach ($sheetData[$r] as $col => $val) {
                $clean_val = strtoupper(str_replace(' ', '', (string)$val));
                if (preg_match('/TA\d+/', $clean_val, $matches)) {
                    $job_no = $matches[0];
                    $job_columns[$col] = $job_no;

                    mysqli_query($conn, "INSERT IGNORE INTO jobs (job_no, status) VALUES ('$job_no', 'Open')");
                }
            }
            if(!empty($job_columns)) break;
        }

        // Map component items and their quantities
        foreach($sheetData as $key => $row) {
            $part_no = isset($row['B']) ? trim($row['B']) : '';
            if(empty($part_no) || in_array(strtoupper($part_no), ['PART NO', 'ITEM NAME', 'TIEMAN PART NO.'])) continue;

            $item_code   = mysqli_real_escape_string($conn, $part_no);
            // Description lives in column D (C is the embedded Image cell)
            $description = isset($row['D']) ? mysqli_real_escape_string($conn, trim($row['D'])) : '';
            $item_name   = substr($description, 0, 50);
            $remark_loc  = isset($row['I']) ? mysqli_real_escape_string($conn, trim($row['I'])) : '';

            $img_name = "";
            if (isset($inlineImages[$key])) {
                $img_name = $item_code . '.' . $inlineImages[$key]['ext'];
                file_put_contents($target_dir . $img_name, $inlineImages[$key]['content']);
            }

            $check_item = mysqli_query($conn, "SELECT id FROM items WHERE item_code='$item_code'");
            if(mysqli_num_rows($check_item) > 0) {
                $item_id = mysqli_fetch_assoc($check_item)['id'];
                if(!empty($img_name)) mysqli_query($conn, "UPDATE items SET image='$img_name' WHERE id=$item_id");
            } else {
                mysqli_query($conn, "INSERT INTO items (item_code, item_name, description, image, location) 
                                     VALUES ('$item_code', '$item_name', '$description', '$img_name', '$remark_loc')");
                $item_id = mysqli_insert_id($conn);
            }

            // Bind individual quantities using your verified database columns
            foreach ($job_columns as $col => $job_no) {
                $qty = intval(isset($row[$col]) ? trim($row[$col]) : 0);
                if ($qty > 0) {
                    $job_res = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM jobs WHERE job_no='$job_no'"));
                    if ($job_res) {
                        $job_id = $job_res['id'];
                        mysqli_query($conn, "INSERT INTO job_items (job_id, item_id, qty, description, remark) 
                                             VALUES ($job_id, $item_id, $qty, '$description', '$remark_loc')
                                             ON DUPLICATE KEY UPDATE qty=$qty");
                    }
                }
            }
        }
        echo "<script>alert('Matrix Parsed Successfully!'); window.location='job_list.php';</script>";
    } catch (Exception $e) {
        echo "<script>alert('Parser Error: ".$e->getMessage()."');</script>";
    }
}

// FUNCTION 2: CREATE MANUALLY (UNTOUCHED)
if(isset($_POST['create_manual_job'])) {
    $job_no        = mysqli_real_escape_string($conn, strtoupper(trim($_POST['job_no'])));
    $customer_name = mysqli_real_escape_string($conn, trim($_POST['customer_name']));
    $due_date      = mysqli_real_escape_string($conn, $_POST['due_date']);
    $remarks       = mysqli_real_escape_string($conn, trim($_POST['remarks']));

    $insert = mysqli_query($conn, "INSERT INTO jobs (job_no, customer_name, due_date, status) 
                                   VALUES ('$job_no', '$customer_name', '$due_date', 'Open')");
    if($insert) {
        echo "<script>alert('Manual job entry registered.'); window.location='job_list.php';</script>";
    }
}

// FUNCTION 3: ATTACH MULTIPLE SELECTED ITEMS TO A JOB CARD (FIXED: missing part_no on insert)
if(isset($_POST['add_items_to_job'])) {
    $job_id = (int)$_POST['job_id'];
    if($job_id > 0 && isset($_POST['selected_items']) && is_array($_POST['selected_items'])) {
        $added_count = 0;
        foreach($_POST['selected_items'] as $item_id) {
            $item_id = (int)$item_id;
            $add_qty = isset($_POST['item_qty'][$item_id]) ? (int)$_POST['item_qty'][$item_id] : 1;

            if($add_qty > 0) {
                $item_query = mysqli_query($conn, "SELECT item_code, description, location, image FROM items WHERE id=$item_id");
                $item_data = mysqli_fetch_assoc($item_query);
                $part_no = mysqli_real_escape_string($conn, $item_data['item_code'] ?? '');
                $desc = mysqli_real_escape_string($conn, $item_data['description'] ?? '');
                $loc = mysqli_real_escape_string($conn, $item_data['location'] ?? '');
                $img = mysqli_real_escape_string($conn, $item_data['image'] ?? '');

                mysqli_query($conn, "INSERT INTO job_items (job_id, item_id, part_no, qty, description, remark, image) 
                                     VALUES ($job_id, $item_id, '$part_no', $add_qty, '$desc', '$loc', '$img') 
                                     ON DUPLICATE KEY UPDATE qty = qty + $add_qty, part_no = '$part_no', image = '$img'");
                $added_count++;
            }
        }
        echo "<script>alert('$added_count items added to Job successfully!'); window.location='job_list.php?view_bom=$job_id';</script>";
    } else {
        echo "<script>alert('Please select a Target Job and check at least one item.');</script>";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Create Job - Warehouse Management</title>
    <!-- Font Awesome CDN for sidebar icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', sans-serif; }
        body { background: #f4f6f9; }

        /* SIDEBAR WITH MODERN ICON STYLING (same as dashboard) */
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

        .main-content { margin-left: 260px; padding: 30px; }
        .card { background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); margin-bottom: 25px; }
        .row-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 25px; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-weight: 600; margin-bottom: 5px; color: #4b5563; }
        .form-control { width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 6px; }
        .btn { padding: 12px; border: none; border-radius: 6px; color: white; font-weight: bold; cursor: pointer; }
        .btn-orange { background: #f97316; width: 100%; } .btn-blue { background: #2563eb; width: 100%; } .btn-green { background: #10b981; }
        
        /* Header Top Row Layout */
        .top-action-bar { display: flex; gap: 15px; align-items: flex-end; justify-content: space-between; margin-bottom: 20px; }
        .job-select-box { flex: 1; max-width: 320px; }
        .search-box { flex: 1; max-width: 350px; }

        /* Quantity Plus/Minus Controls */
        .qty-control-wrapper { display: inline-flex; align-items: center; border: 1px solid #cbd5e1; border-radius: 6px; overflow: hidden; background: #fff; }
        .qty-btn { background: #f1f5f9; border: none; width: 32px; height: 36px; font-weight: bold; font-size: 16px; color: #334155; cursor: pointer; transition: background 0.2s; }
        .qty-btn:hover { background: #e2e8f0; }
        .qty-input { width: 45px; height: 36px; border: none; border-left: 1px solid #cbd5e1; border-right: 1px solid #cbd5e1; text-align: center; font-weight: bold; color: #0f172a; outline: none; }
        
        /* Items Table Styling */
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { padding: 12px; border-bottom: 1px solid #e5e7eb; text-align: left; vertical-align: middle; }
        th { background: #f8fafc; color: #4b5563; font-weight: bold; text-transform: uppercase; font-size: 12px; }

        /* Zoom-on-hover thumbnails, same behaviour as items/item_list.php */
        .img-zoom-container { position: relative; width: 55px; height: 55px; margin: 0 auto; }
        .zoomable-thumbnail { width: 55px; height: 55px; border-radius: 6px; border: 1px solid #cbd5e1; object-fit: contain; background: #ffffff; cursor: pointer; }
        .zoom-popup-view { display: none; position: absolute; top: 50%; left: calc(100% + 20px); transform: translateY(-50%); width: 260px; height: 260px; background: #ffffff; border: 2px solid #111827; border-radius: 12px; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3); z-index: 9999; padding: 5px; }
        .zoom-popup-view img { width: 100%; height: 100%; object-fit: contain; background: #ffffff; border-radius: 8px; }
        .img-zoom-container:hover .zoom-popup-view { display: block; }
    </style>
</head>
<body>
    <!-- SIDEBAR -->
    <div class="sidebar">
        <div class="logo">WAREHOUSE</div>
        <div class="sidebar-menu">
            <a href="dashboard.php">
                <i class="fa-solid fa-gauge-high"></i> Dashboard
            </a>
            <a href="items/item_list.php">
                <i class="fa-solid fa-box-archive"></i> Items
            </a>
            <a href="items/add_item.php">
                <i class="fa-solid fa-plus"></i> Add Item
            </a>
            <a href="import_excel.php">
                <i class="fa-solid fa-file-import"></i> Import Excel
            </a>
            <a href="create_job.php" class="active">
                <i class="fa-solid fa-file-circle-plus"></i> Create Job
            </a>
            <a href="job_list.php">
                <i class="fa-solid fa-file-lines"></i> Job List
            </a>
            <a href="stock/stock_in.php">
                <i class="fa-solid fa-arrow-trend-up"></i> Stock In
            </a>
            <a href="items/stock_out.php">
                <i class="fa-solid fa-arrow-trend-down"></i> Stock Out
            </a>
            <a href="return_item.php">
                <i class="fa-solid fa-rotate-left"></i> Returns
            </a>
            <a href="stock/missing_item.php">
                <i class="fa-solid fa-triangle-exclamation"></i> Missing
            </a>
            <a href="scaner.php">
                <i class="fa-solid fa-barcode"></i> Scanner
            </a>
            <a href="reports/stock_report.php">
                <i class="fa-solid fa-chart-pie"></i> Reports
            </a>
            <a href="logout.php">
                <i class="fa-solid fa-right-from-bracket"></i> Logout
            </a>
        </div>
    </div>

    <div class="main-content">
        <!-- Top Row: Matrix Import & Manual Creation Forms -->
        <div class="row-grid">
            <div class="card">
                <h3 style="color:#f97316; margin-bottom:15px;">Auto Create via Excel Matrix</h3>
                <form method="POST" enctype="multipart/form-data">
                    <div class="form-group">
                        <label>Upload Spreadsheet Matrix (.xlsx)</label>
                        <input type="file" name="excel" class="form-control" accept=".xlsx" required>
                    </div>
                    <button type="submit" name="import_matrix" class="btn btn-orange">Parse Document Layout</button>
                </form>
            </div>

            <div class="card">
                <h3 style="color:#2563eb; margin-bottom:15px;">Create Custom Job Card (Manual)</h3>
                <form method="POST">
                    <div class="form-group"><label>Job Number Code</label><input type="text" name="job_no" class="form-control" required></div>
                    <div class="form-group"><label>Client Target Identity</label><input type="text" name="customer_name" class="form-control"></div>
                    <div class="form-group"><label>Target Due Date</label><input type="date" name="due_date" class="form-control"></div>
                    <button type="submit" name="create_manual_job" class="btn btn-blue">Save Profile Card</button>
                </form>
            </div>
        </div>

        <!-- SECTION: SELECT & ADD ITEMS TO JOB CARD WITH TOP BAR CONTROLS -->
        <div class="card">
            <h3 style="color:#10b981; margin-bottom:15px;">📦 Add Inventory Items to a Job Card</h3>
            
            <form method="POST">
                <!-- TOP CONTROL BAR: Job Select, Search, & Submit Button -->
                <div class="top-action-bar">
                    <div class="job-select-box">
                        <label style="font-size: 13px; font-weight: bold; color: #4b5563; display: block; margin-bottom: 5px;">Select Target Job Card:</label>
                        <select name="job_id" class="form-control" required>
                            <option value="">-- Select Job --</option>
                            <?php
                            $job_list_q = mysqli_query($conn, "SELECT id, job_no, customer_name FROM jobs ORDER BY id DESC");
                            while($j_row = mysqli_fetch_assoc($job_list_q)) {
                                $c_label = !empty($j_row['customer_name']) ? " (" . htmlspecialchars($j_row['customer_name']) . ")" : "";
                                echo "<option value='".$j_row['id']."'>".htmlspecialchars($j_row['job_no']).$c_label."</option>";
                            }
                            ?>
                        </select>
                    </div>

                    <div class="search-box">
                        <label style="font-size: 13px; font-weight: bold; color: #4b5563; display: block; margin-bottom: 5px;">🔍 Live Search Items:</label>
                        <input type="text" id="itemSearch" onkeyup="filterItems()" placeholder="Type Part No or Description..." class="form-control">
                    </div>

                    <div>
                        <button type="submit" name="add_items_to_job" class="btn btn-green" style="height: 42px; padding: 0 20px;">+ Add Selected Items to Job Card</button>
                    </div>
                </div>

                <table id="itemsTable">
                    <thead>
                        <tr>
                            <th width="5%" style="text-align: center;">Select</th>
                            <th width="10%">Image</th>
                            <th width="20%">Part No</th>
                            <th width="50%">Description</th>
                            <th width="15%" style="text-align: center;">Quantity</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $all_items = mysqli_query($conn, "SELECT id, item_code AS part_no, item_name, description, image FROM items ORDER BY id DESC");
                        if(mysqli_num_rows($all_items) > 0) {
                            while($itm = mysqli_fetch_assoc($all_items)) {
                                $img_path = getDynamicImagePath($itm['image'] ?? '', $itm['part_no'] ?? '');
                                ?>
                                <tr class="item-row">
                                    <td style="text-align: center;">
                                        <input type="checkbox" name="selected_items[]" value="<?= $itm['id'] ?>" style="width: 18px; height: 18px; cursor: pointer;">
                                    </td>
                                    <td>
                                        <div class="img-zoom-container">
                                            <img src="<?= htmlspecialchars($img_path) ?>" class="zoomable-thumbnail" alt="Item Image" onerror="this.onerror=null; this.src='/assets/images/no-image.png';">
                                            <div class="zoom-popup-view">
                                                <img src="<?= htmlspecialchars($img_path) ?>" alt="Full Item View" onerror="this.onerror=null; this.src='/assets/images/no-image.png';">
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <strong class="search-part" style="color: #2563eb; font-family: monospace; font-size: 14px;"><?= htmlspecialchars($itm['part_no']) ?></strong>
                                    </td>
                                    <td>
                                        <span class="search-desc" style="font-size: 13px; color: #334155; font-weight: 500;"><?= htmlspecialchars($itm['description']) ?></span>
                                    </td>
                                    <td style="text-align: center;">
                                        <!-- Interactive Plus / Minus Quantity Buttons -->
                                        <div class="qty-control-wrapper">
                                            <button type="button" class="qty-btn" onclick="decreaseQty(<?= $itm['id'] ?>)">-</button>
                                            <input type="number" id="qty_<?= $itm['id'] ?>" name="item_qty[<?= $itm['id'] ?>]" value="1" min="1" class="qty-input" readonly>
                                            <button type="button" class="qty-btn" onclick="increaseQty(<?= $itm['id'] ?>)">+</button>
                                        </div>
                                    </td>
                                </tr>
                                <?php
                            }
                        } else {
                            echo "<tr><td colspan='5' style='text-align:center; padding: 20px; color: #94a3b8;'>No items found in master inventory.</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </form>
        </div>
    </div>

    <!-- JavaScript for Live Search & Plus/Minus Controls -->
    <script>
        function increaseQty(id) {
            let input = document.getElementById('qty_' + id);
            input.value = parseInt(input.value) + 1;
        }

        function decreaseQty(id) {
            let input = document.getElementById('qty_' + id);
            if (parseInt(input.value) > 1) {
                input.value = parseInt(input.value) - 1;
            }
        }

        function filterItems() {
            let filter = document.getElementById('itemSearch').value.toLowerCase();
            let rows = document.querySelectorAll('#itemsTable .item-row');
            
            rows.forEach(row => {
                let part = row.querySelector('.search-part').innerText.toLowerCase();
                let desc = row.querySelector('.search-desc').innerText.toLowerCase();
                
                if (part.includes(filter) || desc.includes(filter)) {
                    row.style.display = "";
                } else {
                    row.style.display = "none";
                }
            });
        }
    </script>
</body>
</html>
