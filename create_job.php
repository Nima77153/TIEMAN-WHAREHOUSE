<?php
session_start();
include('config/db.php');

require 'vendor/autoload.php'; 
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\MemoryDrawing;

// FUNCTION 1: EXCEL MATRIX AUTO-PARSER (UNTOUCHED)
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
            $description = isset($row['C']) ? mysqli_real_escape_string($conn, trim($row['C'])) : '';
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

// FUNCTION 3: ATTACH MULTIPLE SELECTED ITEMS TO A JOB CARD (UNTOUCHED LOGIC)
if(isset($_POST['add_items_to_job'])) {
    $job_id = (int)$_POST['job_id'];
    if($job_id > 0 && isset($_POST['selected_items']) && is_array($_POST['selected_items'])) {
        $added_count = 0;
        foreach($_POST['selected_items'] as $item_id) {
            $item_id = (int)$item_id;
            $add_qty = isset($_POST['item_qty'][$item_id]) ? (int)$_POST['item_qty'][$item_id] : 1;
            
            if($add_qty > 0) {
                $item_query = mysqli_query($conn, "SELECT description, location FROM items WHERE id=$item_id");
                $item_data = mysqli_fetch_assoc($item_query);
                $desc = mysqli_real_escape_string($conn, $item_data['description'] ?? '');
                $loc = mysqli_real_escape_string($conn, $item_data['location'] ?? '');

                mysqli_query($conn, "INSERT INTO job_items (job_id, item_id, qty, description, remark) 
                                     VALUES ($job_id, $item_id, $add_qty, '$desc', '$loc') 
                                     ON DUPLICATE KEY UPDATE qty = qty + $add_qty");
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
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', sans-serif; }
        body { background: #f4f6f9; display: flex; }
        .sidebar { width: 260px; height: 100vh; background: #1a2232; position: fixed; color: white; }
        .logo { padding: 20px; font-size: 20px; font-weight: bold; background: #f97316; text-align: center; }
        .sidebar a { display: block; padding: 15px 20px; color: #cdbcbc; text-decoration: none; border-bottom: 1px solid #232f45; }
        .sidebar a:hover { background: #f97316; color: white; }
        .main-content { margin-left: 260px; padding: 30px; width: calc(100% - 260px); }
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
        .item-img { width: 50px; height: 50px; object-fit: contain; border: 1px solid #e2e8f0; border-radius: 6px; background: #fff; }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="logo">WAREHOUSE</div>
        <a href="dashboard.php">Dashboard</a>
        <a href="item_list.php">Items</a>
        <a href="create_job.php" style="background:#f97316; color:white;">Create Job Card</a>
        <a href="job_list.php">Job List</a>
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
                                $img_path = !empty($itm['image']) && file_exists('uploads/items/' . $itm['image']) 
                                            ? 'uploads/items/' . $itm['image'] 
                                            : 'uploads/items/placeholder.png';
                                ?>
                                <tr class="item-row">
                                    <td style="text-align: center;">
                                        <input type="checkbox" name="selected_items[]" value="<?= $itm['id'] ?>" style="width: 18px; height: 18px; cursor: pointer;">
                                    </td>
                                    <td>
                                        <img src="<?= $img_path ?>" class="item-img" alt="Item Image">
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