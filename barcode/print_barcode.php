<?php
session_start();
include('../config/db.php');

// Collect either a single item code string or an array of checked item codes
$item_codes = [];
if (isset($_GET['codes']) && is_array($_GET['codes'])) {
    $item_codes = array_map('trim', $_GET['codes']);
} elseif (isset($_GET['code']) && !empty($_GET['code'])) {
    $item_codes[] = trim($_GET['code']);
}

// Show error if someone lands on the page with absolutely no items selected
if (empty($item_codes)) {
    die("<div style='text-align:center; margin-top:50px; font-family:sans-serif;'>
            <h4 style='color:red;'>Error: Please select at least one item checkbox from the catalog list.</h4>
            <a href='../items/item_list.php' style='display:inline-block; padding:10px 20px; background:#4b5563; color:white; text-decoration:none; border-radius:6px; font-weight:bold;'>⬅️ Back to Item List</a>
         </div>");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Print Multiple Labels Asset Tracker</title>
    <style>
        body { background: #525659; font-family: 'Segoe UI', Arial, sans-serif; margin: 0; padding: 20px; }
        
        /* Control Panel for Dashboard Navigation and Print Trigger Actions */
        .print-control-panel {
            text-align: center;
            margin-bottom: 30px;
        }

        .btn-back {
            display: inline-block;
            background: #4b5563;
            color: white;
            text-decoration: none;
            padding: 12px 24px;
            font-size: 15px;
            font-weight: bold;
            border-radius: 6px;
            margin-right: 12px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
            transition: background 0.2s;
            vertical-align: middle;
        }

        .btn-back:hover { background: #374151; }
        
        .btn-print {
            display: inline-block;
            background: #f97316; /* Theme Orange */
            color: white;
            border: none;
            padding: 12px 24px;
            font-size: 15px;
            font-weight: bold;
            border-radius: 6px;
            cursor: pointer;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
            transition: background 0.2s;
            vertical-align: middle;
        }

        .btn-print:hover { background: #ea580c; }

        /* Container designed to wrap multiple stickers together neatly on screen */
        .labels-grid-container {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            justify-content: center;
            max-width: 1200px;
            margin: 0 auto;
        }

        /* Industrial Grade Barcode Sticker Sheet Matrix Styling */
        .print-label-card {
            background: white;
            width: 360px;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.3);
            text-align: center;
            border: 2px solid #000;
            page-break-inside: avoid;
        }

        .company-header {
            font-size: 13px;
            font-weight: 800;
            color: #111;
            letter-spacing: 2px;
            text-transform: uppercase;
            border-bottom: 2px dashed #ccc;
            padding-bottom: 6px;
            margin-bottom: 12px;
        }

        .item-title {
            font-size: 14px;
            font-weight: 600;
            color: #333;
            margin-bottom: 15px;
            height: 36px;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .barcode-display-wrapper {
            margin: 15px 0;
            padding: 5px;
        }

        .barcode-img {
            max-width: 100%;
            height: auto;
            display: block;
            margin: 0 auto;
        }

        .metadata-footer-grid {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 15px;
            padding-top: 8px;
            border-top: 1px solid #eee;
            font-size: 12px;
        }

        .bin-badge {
            background: #000;
            color: white;
            padding: 3px 8px;
            font-weight: bold;
            border-radius: 3px;
            text-transform: uppercase;
        }

        /* Native Browser Print Engine Layout Rules Optimization */
        @media print {
            body { background: white; padding: 0; margin: 0; }
            .print-control-panel { display: none !important; }
            .labels-grid-container { display: block; width: 100%; }
            .print-label-card { 
                margin: 15px auto; 
                box-shadow: none; 
                border: 2px solid #000;
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body>

    <div class="print-control-panel">
        <a href="../items/item_list.php" class="btn-back">⬅️ Back to Item List</a>
        <button class="btn-print" onclick="window.print()">🖨️ Print All Selected Barcodes</button>
    </div>

    <div class="labels-grid-container">
        <?php 
        // Run a query iteration for each item code passed inside the selection array
        foreach ($item_codes as $code) {
            $stmt = $conn->prepare("SELECT item_name, description, location FROM items WHERE item_code = ? LIMIT 1");
            $stmt->bind_param("s", $code);
            $stmt->execute();
            $meta_result = $stmt->get_result();
            $item_info = $meta_result->fetch_assoc();

            $display_name = $item_info ? ($item_info['item_name'] ?? $item_info['description'] ?? 'Warehouse Asset') : 'Warehouse Asset';
            $bin_location = $item_info ? ($item_info['location'] ?? 'N/A') : 'N/A';
            
            // Build the distinct image tracking path URL strings
            $barcode_url = "https://bwipjs-api.metafloor.com/?bcid=code128&text=" . urlencode($code) . "&scale=3&rotate=N&includetext";
        ?>
            <div class="print-label-card">
                <div class="company-header">TIEMAN WAREHOUSE SYSTEM</div>
                <div class="item-title"><?= htmlspecialchars($display_name) ?></div>
                
                <div class="barcode-display-wrapper">
                    <img class="barcode-img" src="<?= $barcode_url ?>" alt="Barcode Graphic Layout Element">
                </div>
                
                <div class="metadata-footer-grid">
                    <div>PART NO: <strong><?= htmlspecialchars($code) ?></strong></div>
                    <div>LOC: <span class="bin-badge"><?= htmlspecialchars($bin_location) ?></span></div>
                </div>
            </div>
        <?php 
        } 
        ?>
    </div>

</body>
</html>