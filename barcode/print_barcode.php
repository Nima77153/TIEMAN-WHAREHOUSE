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

// Show error if no items selected
if (empty($item_codes)) {
    die("<div style='text-align:center; margin-top:50px; font-family:sans-serif;'>
            <h4 style='color:red;'>Error: Please select at least one item checkbox from the catalog list.</h4>
            <a href='../items/item_list.php'
               style='display:inline-block; padding:10px 20px; background:#4b5563; color:white; text-decoration:none; border-radius:6px; font-weight:bold;'>
               ⬅️ Back to Item List
            </a>
         </div>");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <title>Print Multiple Labels Asset Tracker</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            background: #525659;
            font-family: 'Segoe UI', Arial, sans-serif;
            margin: 0;
            padding: 20px;
        }

        /* ================================
           CONTROL PANEL
        ================================= */

        .print-control-panel {
            background: white;
            max-width: 900px;
            margin: 0 auto 25px auto;
            padding: 18px 20px;
            border-radius: 10px;
            box-shadow: 0 3px 12px rgba(0,0,0,0.25);
            text-align: center;
        }

        .control-title {
            font-size: 18px;
            font-weight: bold;
            color: #111827;
            margin-bottom: 15px;
        }

        .size-control {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin: 5px 15px 5px 0;
        }

        .size-control label {
            font-weight: bold;
            color: #374151;
        }

        .size-select {
            padding: 9px 12px;
            min-width: 190px;
            border: 1px solid #9ca3af;
            border-radius: 6px;
            background: white;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
        }

        .page-info {
            display: inline-block;
            background: #f3f4f6;
            color: #374151;
            padding: 9px 14px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            margin: 5px;
        }

        .btn-back {
            display: inline-block;
            background: #4b5563;
            color: white;
            text-decoration: none;
            padding: 10px 18px;
            font-size: 14px;
            font-weight: bold;
            border-radius: 6px;
            margin: 5px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }

        .btn-back:hover {
            background: #374151;
        }

        .btn-print {
            display: inline-block;
            background: #f97316;
            color: white;
            border: none;
            padding: 10px 18px;
            font-size: 14px;
            font-weight: bold;
            border-radius: 6px;
            cursor: pointer;
            margin: 5px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }

        .btn-print:hover {
            background: #ea580c;
        }


        /* ================================
           LABEL AREA
        ================================= */

        .labels-grid-container {

            display: flex;
            flex-wrap: wrap;

            gap: 5mm;

            justify-content: center;

            width: 100%;

            margin: 0 auto;

        }


        /* ================================
           LABEL
        ================================= */

        .print-label-card {

            background: white;

            width: 70mm;
            height: 30mm;

            padding: 3mm;

            border: 0.4mm solid #000;

            border-radius: 1.5mm;

            text-align: center;

            page-break-inside: avoid;

            break-inside: avoid;

            overflow: hidden;

            display: flex;

            flex-direction: column;

            justify-content: space-between;

        }


        /* ================================
           COMPANY HEADER
        ================================= */

        .company-header {

            font-size: 9pt;

            font-weight: 800;

            color: #111;

            letter-spacing: 1.2px;

            text-transform: uppercase;

            border-bottom: 0.3mm dashed #aaa;

            padding-bottom: 1.5mm;

            margin-bottom: 1mm;

        }


        /* ================================
           ITEM NAME
        ================================= */

        .item-title {

            font-size: 9pt;

            font-weight: 600;

            color: #333;

            line-height: 1.2;

            min-height: 5mm;

            max-height: 7mm;

            overflow: hidden;

            text-overflow: ellipsis;

        }


        /* ================================
           BARCODE
        ================================= */

        .barcode-display-wrapper {

            width: 100%;

            flex: 1;

            display: flex;

            justify-content: center;

            align-items: center;

            overflow: hidden;

            padding: 1mm 0;

        }

        .barcode-img {

            width: 90%;

            max-width: 100%;

            height: auto;

            max-height: 14mm;

            display: block;

            margin: 0 auto;

        }


        /* ================================
           FOOTER
        ================================= */

        .metadata-footer-grid {

            display: flex;

            justify-content: space-between;

            align-items: center;

            width: 100%;

            border-top: 0.25mm solid #ddd;

            padding-top: 1mm;

            font-size: 7pt;

            white-space: nowrap;

        }

        .bin-badge {

            background: #000;

            color: white;

            padding: 0.8mm 1.5mm;

            font-weight: bold;

            border-radius: 0.8mm;

        }


        /* =====================================================
           SMALL
           50 x 30 mm
        ===================================================== */

        body.size-small .print-label-card {

            width: 50mm;

            height: 30mm;

            padding: 2.5mm;

        }

        body.size-small .company-header {
            font-size: 7pt;
            letter-spacing: 0.8px;
        }

        body.size-small .item-title {
            font-size: 7pt;
        }

        body.size-small .barcode-img {
            max-height: 14mm;
        }

        body.size-small .metadata-footer-grid {
            font-size: 6pt;
        }


        /* =====================================================
           MEDIUM
           60 x 40 mm
        ===================================================== */

        body.size-medium .print-label-card {

            width: 60mm;

            height: 40mm;

            padding: 3mm;

        }

        body.size-medium .company-header {
            font-size: 8pt;
        }

        body.size-medium .item-title {
            font-size: 8pt;
        }

        body.size-medium .barcode-img {
            max-height: 20mm;
        }

        body.size-medium .metadata-footer-grid {
            font-size: 6.5pt;
        }


        /* =====================================================
           STANDARD
           70 x 30 mm
        ===================================================== */

        body.size-standard .print-label-card {

            width: 70mm;

            height: 30mm;

            padding: 3mm;

        }


        /* =====================================================
           LARGE
           80 x 50 mm
        ===================================================== */

        body.size-large .print-label-card {

            width: 80mm;

            height: 50mm;

            padding: 4mm;

        }

        body.size-large .company-header {
            font-size: 10pt;
        }

        body.size-large .item-title {
            font-size: 10pt;
        }

        body.size-large .barcode-img {
            max-height: 27mm;
        }

        body.size-large .metadata-footer-grid {
            font-size: 7.5pt;
        }


        /* =====================================================
           60 x 80 MM
           PORTRAIT
        ===================================================== */

        body.size-6080 .print-label-card {

            width: 60mm;

            height: 80mm;

            padding: 4mm;

        }

        body.size-6080 .company-header {

            font-size: 10pt;

            letter-spacing: 1.2px;

            padding-bottom: 2mm;

        }

        body.size-6080 .item-title {

            font-size: 10pt;

            min-height: 8mm;

            max-height: 12mm;

        }

        body.size-6080 .barcode-display-wrapper {

            padding: 3mm 0;

        }

        body.size-6080 .barcode-img {

            width: 95%;

            max-height: 42mm;

        }

        body.size-6080 .metadata-footer-grid {

            font-size: 8pt;

            padding-top: 2mm;

        }


        /* ================================
           PRINT
        ================================= */

        @media print {

            @page {

                size: A4 portrait;

                margin: 5mm;

            }

            html,
            body {

                background: white;

                margin: 0;

                padding: 0;

            }

            .print-control-panel {

                display: none !important;

            }

            .labels-grid-container {

                display: flex;

                flex-wrap: wrap;

                justify-content: flex-start;

                align-content: flex-start;

                gap: 5mm;

                width: 200mm;

                margin: 0;

            }

            .print-label-card {

                box-shadow: none;

                page-break-inside: avoid;

                break-inside: avoid;

                flex-shrink: 0;

            }

        }


        /* ================================
           MOBILE PREVIEW
        ================================= */

        @media screen and (max-width: 700px) {

            .print-control-panel {
                text-align: left;
            }

            .size-control {
                display: flex;
                flex-direction: column;
                align-items: flex-start;
            }

            .labels-grid-container {
                transform-origin: top center;
            }

        }

    </style>
</head>

<body class="size-standard">


    <!-- =====================================================
         CONTROL PANEL
    ====================================================== -->

    <div class="print-control-panel">

        <div class="control-title">
            🖨️ Barcode Label Printing
        </div>


        <div class="size-control">

            <label for="labelSize">
                Label Size:
            </label>

            <select id="labelSize" class="size-select">

                <option value="small">
                    Small - 50 × 30 mm
                </option>

                <option value="medium">
                    Medium - 60 × 40 mm
                </option>

                <option value="standard" selected>
                    Standard - 70 × 30 mm
                </option>

                <option value="large">
                    Large - 80 × 50 mm
                </option>

                <option value="6080">
                    60 × 80 mm
                </option>

            </select>

        </div>


        <div class="page-info">

            A4: <span id="pageCount">18</span> labels/page

        </div>


        <div class="page-info">

            Selected:
            <?= count($item_codes) ?>
            labels

        </div>


        <br>


        <a href="../items/item_list.php" class="btn-back">
            ⬅️ Back to Item List
        </a>


        <button class="btn-print" onclick="window.print()">
            🖨️ Print All Selected Barcodes
        </button>

    </div>



    <!-- =====================================================
         LABELS
    ====================================================== -->

    <div class="labels-grid-container">

        <?php

        foreach ($item_codes as $code) {

            $stmt = $conn->prepare("
                SELECT item_name, description, location
                FROM items
                WHERE item_code = ?
                LIMIT 1
            ");

            $stmt->bind_param("s", $code);

            $stmt->execute();

            $meta_result = $stmt->get_result();

            $item_info = $meta_result->fetch_assoc();


            $display_name = $item_info
                ? ($item_info['item_name'] ?? $item_info['description'] ?? 'Warehouse Asset')
                : 'Warehouse Asset';


            $bin_location = $item_info
                ? ($item_info['location'] ?? 'N/A')
                : 'N/A';


            /*
             * Barcode generated from item code
             */

            $barcode_url =
                "https://bwipjs-api.metafloor.com/?" .
                "bcid=code128" .
                "&text=" . urlencode($code) .
                "&scale=3" .
                "&rotate=N" .
                "&includetext";

        ?>

            <div class="print-label-card">


                <div class="company-header">

                    TIEMAN WAREHOUSE SYSTEM

                </div>


                <div class="item-title">

                    <?= htmlspecialchars($display_name) ?>

                </div>


                <div class="barcode-display-wrapper">

                    <img
                        class="barcode-img"
                        src="<?= htmlspecialchars($barcode_url) ?>"
                        alt="Barcode"
                    >

                </div>


                <div class="metadata-footer-grid">


                    <div>

                        PART NO:
                        <strong>
                            <?= htmlspecialchars($code) ?>
                        </strong>

                    </div>


                    <div>

                        LOC:
                        <span class="bin-badge">

                            <?= htmlspecialchars($bin_location) ?>

                        </span>

                    </div>


                </div>


            </div>

        <?php

        }

        ?>

    </div>



    <!-- =====================================================
         SIZE CONTROL JAVASCRIPT
    ====================================================== -->

    <script>

        const labelSize = document.getElementById("labelSize");

        const pageCount = document.getElementById("pageCount");


        /*
         * Approximate A4 calculation.
         *
         * A4 = 210 x 297 mm
         *
         * Printable area after 5mm margins:
         * 200 x 287 mm
         */

        const sizes = {

            small: {
                width: 50,
                height: 30,
                perPage: 36
            },

            medium: {
                width: 60,
                height: 40,
                perPage: 21
            },

            standard: {
                width: 70,
                height: 30,
                perPage: 18
            },

            large: {
                width: 80,
                height: 50,
                perPage: 12
            },

            6080: {
                width: 60,
                height: 80,
                perPage: 9
            }

        };


        function changeLabelSize() {

            const selected = labelSize.value;

            /*
             * Remove old size classes
             */

            document.body.classList.remove(
                "size-small",
                "size-medium",
                "size-standard",
                "size-large",
                "size-6080"
            );


            /*
             * Add selected size
             */

            document.body.classList.add(
                "size-" + selected
            );


            /*
             * Update A4 information
             */

            pageCount.textContent =
                sizes[selected].perPage;

        }


        labelSize.addEventListener(
            "change",
            changeLabelSize
        );


        /*
         * Set default size
         */

        changeLabelSize();

    </script>


</body>
</html>