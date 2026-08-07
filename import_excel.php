<?php
session_start();
include('config/db.php');

// ==========================================
// ROBUST AUTOMATIC DATABASE REPAIR ENGINE
// ==========================================
$check_remark = mysqli_query($conn, "SHOW COLUMNS FROM items LIKE 'remark'");
if (mysqli_num_rows($check_remark) == 0) {
    mysqli_query($conn, "ALTER TABLE items ADD COLUMN remark TEXT NULL DEFAULT NULL AFTER location");
}

$check_qty_tanker = mysqli_query($conn, "SHOW COLUMNS FROM items LIKE 'qty_per_tanker'");
if (mysqli_num_rows($check_qty_tanker) == 0) {
    mysqli_query($conn, "ALTER TABLE items ADD COLUMN qty_per_tanker VARCHAR(100) NULL DEFAULT NULL AFTER stock_qty");
}

$check_stock_date = mysqli_query($conn, "SHOW COLUMNS FROM items LIKE 'stock_date'");
if (mysqli_num_rows($check_stock_date) == 0) {
    mysqli_query($conn, "ALTER TABLE items ADD COLUMN stock_date VARCHAR(100) NULL DEFAULT NULL AFTER qty_per_tanker");
}

$check_pdf = mysqli_query($conn, "SHOW COLUMNS FROM items LIKE 'pdf_document'");
if (mysqli_num_rows($check_pdf) == 0) {
    $target_after = (mysqli_num_rows(mysqli_query($conn, "SHOW COLUMNS FROM items LIKE 'remark'")) > 0) ? "AFTER remark" : "";
    mysqli_query($conn, "ALTER TABLE items ADD COLUMN pdf_document VARCHAR(255) NULL DEFAULT NULL $target_after");
}

// Check Composer status
if (!file_exists('vendor/autoload.php')) {
    die("<div style='padding:20px; font-family:sans-serif; background:#fee2e2; color:#991b1b; border:1px solid #f87171; border-radius:6px; margin:20px;'>
            <strong>Composer Vendor File Missing!</strong><br>
            Please open your command prompt (CMD), run <code>cd C:\xampp\htdocs\TIEMAN WAREHOUSE</code> and then execute <code>composer require phpoffice/phpspreadsheet</code>.
         </div>");
}

require 'vendor/autoload.php'; 
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\MemoryDrawing;

if(isset($_POST['import']))
{
    if(empty($_FILES['excel']['tmp_name'])) {
        echo "<script>alert('Please select a valid Excel file.'); window.history.back();</script>";
        exit;
    }

    $excelRealName = $_FILES['excel']['name'];
    $excelFile     = $_FILES['excel']['tmp_name'];
    $fileExtension = strtolower(pathinfo($excelRealName, PATHINFO_EXTENSION));

    if (!in_array($fileExtension, ['xlsx', 'xls'])) {
        echo "<script>alert('Error: Slot 1 accepts ONLY true Excel files (.xlsx / .xls).'); window.history.back();</script>";
        exit;
    }

    $target_dir = 'uploads/items/';
    if(!is_dir($target_dir)) {
        mkdir($target_dir, 0777, true);
    }

    $extracted_zip_files = [];

    // Handle Secondary Media Inputs (ZIP/PDF package extract matches)
    if(!empty($_FILES['media_file']['tmp_name'])) {
        $fileName = $_FILES['media_file']['name'];
        $fileTmp  = $_FILES['media_file']['tmp_name'];
        $ext      = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if($ext === 'zip') {
            $zip = new ZipArchive;
            if ($zip->open($fileTmp) === TRUE) {
                for ($i = 0; $i < $zip->numRows; $i++) {
                    $zName = $zip->getNameIndex($i);
                    $zInfo = pathinfo($zName);
                    if (strpos($zName, '__MACOSX') !== false || empty($zInfo['basename'])) continue;

                    $clean_base_name = strtoupper($zInfo['filename']);
                    $zExt = strtolower($zInfo['extension']);
                    $file_contents = $zip->getFromIndex($i);

                    if (in_array($zExt, ['jpg', 'jpeg', 'png'])) {
                        $saved_name = $clean_base_name . "." . $zExt;
                        file_put_contents($target_dir . $saved_name, $file_contents);
                        $extracted_zip_files[$clean_base_name]['image'] = $saved_name;
                    } else if ($zExt === 'pdf') {
                        $saved_name = $clean_base_name . ".pdf";
                        file_put_contents($target_dir . $saved_name, $file_contents);
                        $extracted_zip_files[$clean_base_name]['pdf'] = $saved_name;
                    }
                }
                $zip->close();
            }
        } else if($ext === 'pdf') {
            move_uploaded_file($fileTmp, $target_dir . $fileName);
        }
    }

    try {
        $spreadsheet = IOFactory::load($excelFile);
        $sheetNames = $spreadsheet->getSheetNames();
        
        foreach($sheetNames as $sheetName) {
            $worksheet = $spreadsheet->getSheetByName($sheetName);
            $sheetData = $worksheet->toArray(null, true, true, true); 
            
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
                        // FIXED: Added @ error suppressor and file path check to prevent open stream failure warnings
                        $drawingPath = $drawing->getPath();
                        if (empty($drawingPath) || !@file_exists($drawingPath)) {
                            continue;
                        }
                        $zipReader = @fopen($drawingPath, 'r');
                        if(!$zipReader) continue;
                        $imageContents = '';
                        while (!feof($zipReader)) {
                            $imageContents .= fread($zipReader, 8192);
                        }
                        fclose($zipReader);
                        $extension = $drawing->getExtension();
                    }
                    $inlineImages[$rowNumber] = [
                        'content' => $imageContents,
                        'ext' => $extension
                    ];
                }
            }
            
            foreach($sheetData as $key => $row)
            {
                $part_no = isset($row['B']) ? trim($row['B']) : '';
                if(empty($part_no) || $part_no == 'PART NO' || $part_no == 'ITEM NAME') continue; 

                $item_code      = mysqli_real_escape_string($conn, $part_no);
                $description    = isset($row['C']) ? mysqli_real_escape_string($conn, trim($row['C'])) : '';
                $item_name      = mysqli_real_escape_string($conn, substr($description, 0, 50)); 
                $qty_per_tanker = isset($row['E']) ? mysqli_real_escape_string($conn, trim($row['E'])) : '';
                $stock_date     = isset($row['F']) ? mysqli_real_escape_string($conn, trim($row['F'])) : '';
                $stock_qty      = isset($row['G']) ? (int)$row['G'] : 0;
                $remark_loc     = isset($row['H']) ? mysqli_real_escape_string($conn, trim($row['H'])) : '';

                if($qty_per_tanker == '#NAME?') $qty_per_tanker = '-';
                if($stock_date == '#NAME?') $stock_date = '-';
                if($remark_loc == '#NAME?') $remark_loc = '';

                $final_image_name = "";
                $final_pdf_name   = "";
                $lookup_key       = strtoupper($part_no);

                // Extract or map images safely
                if (isset($inlineImages[$key])) {
                    $imgName = $item_code . '.' . $inlineImages[$key]['ext'];
                    file_put_contents($target_dir . $imgName, $inlineImages[$key]['content']);
                    $final_image_name = $imgName;
                } else if (isset($extracted_zip_files[$lookup_key]['image'])) {
                    $final_image_name = $extracted_zip_files[$lookup_key]['image'];
                } else {
                    if(file_exists($target_dir.$item_code.".jpg"))  $final_image_name = $item_code.".jpg";
                    if(file_exists($target_dir.$item_code.".png"))  $final_image_name = $item_code.".png";
                    if(file_exists($target_dir.$item_code.".jpeg")) $final_image_name = $item_code.".jpeg";
                }

                if (isset($extracted_zip_files[$lookup_key]['pdf'])) {
                    $final_pdf_name = $extracted_zip_files[$lookup_key]['pdf'];
                } else if(file_exists($target_dir.$item_code.".pdf")) {
                    $final_pdf_name = $item_code.".pdf";
                } else if (!empty($_FILES['media_file']['tmp_name']) && $ext === 'pdf' && strpos(strtoupper($fileName), $lookup_key) !== false) {
                    $final_pdf_name = $fileName;
                }

                // Check system for duplicates using part no
                $check = mysqli_query($conn, "SELECT id, image, pdf_document FROM items WHERE item_code='$item_code'");

                if(mysqli_num_rows($check) > 0)
                {
                    $existing = mysqli_fetch_assoc($check);
                    $img_to_save = !empty($final_image_name) ? $final_image_name : $existing['image'];
                    $pdf_to_save = !empty($final_pdf_name) ? $final_pdf_name : $existing['pdf_document'];

                    // FIXED: ONLY update image/pdf fields. Do not touch stock totals, quantities, names, or location records!
                    mysqli_query($conn, "
                        UPDATE items 
                        SET 
                        image='$img_to_save',
                        pdf_document='$pdf_to_save'
                        WHERE item_code='$item_code'
                    ");
                }
                else
                {
                    // Create new entries normally if item does not exist in database catalog yet
                    mysqli_query($conn, "
                        INSERT INTO items (item_code, barcode, item_name, description, qty_per_tanker, stock_date, stock_qty, location, remark, image, pdf_document, minimum_stock) 
                        VALUES ('$item_code', '$item_code', '$item_name', '$description', '$qty_per_tanker', '$stock_date', '$stock_qty', '$remark_loc', '$remark_loc', '$final_image_name', '$final_pdf_name', 5)
                    ");
                }
            }
        }

        echo "<script>
        alert('Success! Spreadsheet parsed and inventory items synchronized successfully.');
        window.location='items/item_list.php';
        </script>";
    } catch (Exception $e) {
        echo "<script>alert('Error reading Excel data: ".$e->getMessage()."'); window.history.back();</script>";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>AI Smart Importer - Warehouse</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', sans-serif; }
        body { background: #f4f6f9; color: #333; }
        .sidebar { width: 250px; height: 100vh; background: #111827; position: fixed; left: 0; top: 0; overflow: auto; z-index: 100; }
        .logo { padding: 20px; font-size: 22px; font-weight: bold; color: #fff; text-align: center; background: #f97316; }
        .sidebar a { display: block; padding: 15px; color: #fff; text-decoration: none; transition: 0.3s; }
        .sidebar a:hover { background: #f97316; }
        .main { margin-left: 250px; padding: 20px; min-height: calc(100vh - 60px); }
        .topbar { background: white; padding: 15px 30px; border-radius: 10px; margin-bottom: 20px; box-shadow: 0 2px 10px rgba(0,0,0,.05); display: flex; justify-content: space-between; align-items: center; }
        .card { background: white; padding: 30px; border-radius: 15px; box-shadow: 0 2px 10px rgba(0,0,0,.05); max-width: 650px; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 600; color: #374151; }
        .form-control { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 6px; background: #f9fafb; }
        .btn-success { padding: 12px 24px; border: none; border-radius: 6px; cursor: pointer; background: #10b981; color: white; font-weight: bold; width: 100%; }
        .btn-success:hover { background: #059669; }
        .footer { margin-left: 250px; background: #111827; color: #9ca3af; text-align: center; padding: 15px; position: fixed; bottom: 0; right: 0; left: 0; }
    </style>
</head>
<body>

    <div class="sidebar">
        <div class="logo">WAREHOUSE</div>
        <a href="dashboard.php">🏠 Dashboard</a>
        <a href="items/item_list.php">📦 Items</a>
        <a href="items/add_item.php">➕ Add Item</a>
        <a href="import_excel.php">📥 Import Excel</a>
        <a href="jobs/job_list.php">📝 Job List</a>
        <a href="logout.php">🚪 Logout</a>
    </div>

    <div class="main">
        <div class="topbar">
            <h3>AI Smart Parsing Hub</h3>
            <div style="font-size: 14px;">Database Status: <span style="color:#10b981; font-weight:bold;">Online & Managed</span></div>
        </div>

        <div class="card">
            <h3 style="margin-bottom: 20px; border-bottom: 2px solid #e5e7eb; padding-bottom: 10px;">Upload Inventory</h3>
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label>1. Select Master Excel File (.xlsx / .xls)</label>
                    <input type="file" name="excel" class="form-control" accept=".xlsx, .xls" required>
                    <small style="color:#f97316; display:block; margin-top:5px;">
                        ✨ <strong>AI Mode Active:</strong> Images inside your spreadsheet cells will be automatically extracted!
                    </small>
                </div>

                <div class="form-group">
                    <label>2. Associated Media Uploads (Accepts .pdf OR .zip) - Optional</label>
                    <input type="file" name="media_file" class="form-control" accept=".zip,.pdf">
                    <small style="color:#6b7280; display:block; margin-top:5px;">
                        You can directly select a single <code>.pdf</code> data drawing sheet or a bulk <code>.zip</code> container file.
                    </small>
                </div>

                <button type="submit" name="import" class="btn-success">Upload and Parse Records</button>
            </form>
        </div>
    </div>

    <div class="footer">
        &copy; <?= date('Y') ?> Warehouse Management System. All Rights Reserved.
    </div>

</body>
</html>