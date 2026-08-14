<?php
session_start();
include('../config/db.php');

// =====================================================
// GET SELECTED ITEM CODES
// =====================================================

$item_codes = [];

if (isset($_GET['codes']) && is_array($_GET['codes'])) {

    $item_codes = array_map('trim', $_GET['codes']);

} elseif (isset($_GET['code']) && !empty($_GET['code'])) {

    $item_codes[] = trim($_GET['code']);

}


// Remove empty values and duplicates
$item_codes = array_values(
    array_unique(
        array_filter($item_codes, function ($value) {
            return $value !== '';
        })
    )
);


// =====================================================
// ERROR IF NOTHING SELECTED
// =====================================================

if (empty($item_codes)) {

    die("
        <div style='
            text-align:center;
            margin-top:50px;
            font-family:sans-serif;
        '>

            <h4 style='color:red;'>
                Error: Please select at least one item checkbox from the catalog list.
            </h4>

            <a href='../items/item_list.php'
               style='
                    display:inline-block;
                    padding:10px 20px;
                    background:#4b5563;
                    color:white;
                    text-decoration:none;
                    border-radius:6px;
                    font-weight:bold;
               '>
                ⬅️ Back to Item List
            </a>

        </div>
    ");

}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Print Multiple Labels Asset Tracker</title>


    <style>

        /* =====================================================
           GENERAL
        ===================================================== */

        * {
            box-sizing: border-box;
        }

        body {

            background: #525659;

            font-family: 'Segoe UI', Arial, sans-serif;

            margin: 0;

            padding: 20px;

        }


        /* =====================================================
           CONTROL PANEL
        ===================================================== */

        .print-control-panel {

            background: white;

            max-width: 950px;

            margin: 0 auto 25px auto;

            padding: 18px 20px;

            border-radius: 10px;

            box-shadow: 0 3px 12px rgba(0,0,0,0.25);

            text-align: center;

        }


        .control-title {

            font-size: 20px;

            font-weight: bold;

            color: #111827;

            margin-bottom: 15px;

        }


        .size-control {

            display: inline-flex;

            align-items: center;

            gap: 10px;

            margin: 5px 12px 5px 0;

        }


        .size-control label {

            font-weight: bold;

            color: #374151;

        }


        .size-select {

            padding: 9px 12px;

            min-width: 200px;

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


        /* =====================================================
           LABEL CONTAINER
        ===================================================== */

        .labels-grid-container {

            display: flex;

            flex-wrap: wrap;

            gap: 5mm;

            justify-content: center;

            width: 100%;

            margin: 0 auto;

        }


        /* =====================================================
           DEFAULT LABEL
           STANDARD = 70 x 30 MM
        ===================================================== */

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


        /* =====================================================
           ITEM NAME
        ===================================================== */

        .item-title {

            font-size: 12pt;

            font-weight: 700;

            color: #111;

            line-height: 1.15;

            min-height: 7mm;

            max-height: 11mm;

            overflow: hidden;

            text-overflow: ellipsis;

            text-align: center;

            display: flex;

            align-items: center;

            justify-content: center;

        }


        /* =====================================================
           BARCODE
        ===================================================== */

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

            width: 92%;

            max-width: 100%;

            height: auto;

            max-height: 15mm;

            display: block;

            margin: 0 auto;

        }


        /* =====================================================
           FOOTER
        ===================================================== */

        .metadata-footer-grid {

            display: flex;

            justify-content: space-between;

            align-items: center;

            width: 100%;

            border-top: 0.25mm solid #999;

            padding-top: 1.5mm;

            font-size: 8pt;

            font-weight: 600;

            white-space: nowrap;

        }


        .bin-badge {

            background: #000;

            color: white;

            padding: 0.8mm 1.5mm;

            font-size: 8pt;

            font-weight: bold;

            border-radius: 0.8mm;

        }


        /* =====================================================
           SMALL
           50 x 30 MM
        ===================================================== */

        body.size-small .print-label-card {

            width: 50mm;

            height: 30mm;

            padding: 2.5mm;

        }


        body.size-small .item-title {

            font-size: 9pt;

            min-height: 6mm;

            max-height: 9mm;

        }


        body.size-small .barcode-img {

            max-height: 14mm;

            width: 90%;

        }


        body.size-small .metadata-footer-grid {

            font-size: 6.5pt;

        }


        body.size-small .bin-badge {

            font-size: 6.5pt;

        }


        /* =====================================================
           MEDIUM
           60 x 40 MM
        ===================================================== */

        body.size-medium .print-label-card {

            width: 60mm;

            height: 40mm;

            padding: 3mm;

        }


        body.size-medium .item-title {

            font-size: 11pt;

            min-height: 7mm;

            max-height: 11mm;

        }


        body.size-medium .barcode-img {

            max-height: 20mm;

            width: 92%;

        }


        body.size-medium .metadata-footer-grid {

            font-size: 7.5pt;

        }


        body.size-medium .bin-badge {

            font-size: 7.5pt;

        }


        /* =====================================================
           STANDARD
           70 x 30 MM
        ===================================================== */

        body.size-standard .print-label-card {

            width: 70mm;

            height: 30mm;

            padding: 3mm;

        }


        /* =====================================================
           LARGE
           80 x 50 MM
        ===================================================== */

        body.size-large .print-label-card {

            width: 80mm;

            height: 50mm;

            padding: 4mm;

        }


        body.size-large .item-title {

            font-size: 13pt;

            min-height: 8mm;

            max-height: 12mm;

        }


        body.size-large .barcode-img {

            max-height: 27mm;

            width: 94%;

        }


        body.size-large .metadata-footer-grid {

            font-size: 9pt;

            padding-top: 2mm;

        }


        body.size-large .bin-badge {

            font-size: 9pt;

        }


        /* =====================================================
           80 x 60 MM
           LANDSCAPE
        ===================================================== */

        body.size-8060 .print-label-card {

            width: 80mm;

            height: 60mm;

            padding: 4mm;

        }


        body.size-8060 .item-title {

            font-size: 14pt;

            font-weight: 700;

            min-height: 9mm;

            max-height: 14mm;

        }


        body.size-8060 .barcode-display-wrapper {

            flex: 1;

            display: flex;

            align-items: center;

            justify-content: center;

            padding: 2mm 0;

            overflow: hidden;

        }


        body.size-8060 .barcode-img {

            width: 94%;

            max-width: 100%;

            max-height: 32mm;

        }


        body.size-8060 .metadata-footer-grid {

            font-size: 9pt;

            padding-top: 2mm;

        }


        body.size-8060 .bin-badge {

            font-size: 9pt;

        }


        /* =====================================================
           PRINT SETTINGS
        ===================================================== */

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


        /* =====================================================
           MOBILE
        ===================================================== */

        @media screen and (max-width: 700px) {

            .print-control-panel {

                text-align: left;

            }


            .size-control {

                display: flex;

                flex-direction: column;

                align-items: flex-start;

            }

        }

    </style>

</head>


<body class="size-standard">


    <!-- =====================================================
         PRINT CONTROL PANEL
    ===================================================== -->

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


                <option value="8060">

                    80 × 60 mm

                </option>

            </select>

        </div>


        <div class="page-info">

            A4:
            <span id="pageCount">18</span>
            labels/page

        </div>


        <div class="page-info">

            Selected:
            <?= count($item_codes) ?>
            labels

        </div>


        <br>


        <a href="../items/item_list.php"
           class="btn-back">

            ⬅️ Back to Item List

        </a>


        <button
            class="btn-print"
            onclick="window.print()">

            🖨️ Print All Selected Barcodes

        </button>


    </div>



    <!-- =====================================================
         LABELS
    ===================================================== -->

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


            // =================================================
            // BARCODE URL
            // =================================================

            $barcode_url =
                "https://bwipjs-api.metafloor.com/?" .
                "bcid=code128" .
                "&text=" . urlencode($code) .
                "&scale=3" .
                "&rotate=N" .
                "&includetext";

        ?>


            <div class="print-label-card">


                <!-- ITEM NAME ONLY -->
                <div class="item-title">

                    <?= htmlspecialchars($display_name) ?>

                </div>


                <!-- BARCODE -->
                <div class="barcode-display-wrapper">

                    <img
                        class="barcode-img"
                        src="<?= htmlspecialchars($barcode_url) ?>"
                        alt="Barcode"
                    >

                </div>


                <!-- PART NO + LOCATION -->
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
         SIZE SELECTION JAVASCRIPT
    ===================================================== -->

    <script>


        const labelSize =
            document.getElementById('labelSize');


        const pageCount =
            document.getElementById('pageCount');


        /*
         * A4 page:
         *
         * 210 x 297 mm
         *
         * Approximately 200 x 287 mm
         * printable area with 5mm margins.
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


            8060: {

                width: 80,

                height: 60,

                perPage: 8

            }

        };


        function changeLabelSize() {


            const selected =
                labelSize.value;


            document.body.classList.remove(

                'size-small',

                'size-medium',

                'size-standard',

                'size-large',

                'size-8060'

            );


            document.body.classList.add(

                'size-' + selected

            );


            pageCount.textContent =
                sizes[selected].perPage;

        }


        labelSize.addEventListener(
            'change',
            changeLabelSize
        );


        changeLabelSize();


    </script>


</body>

</html>