<?php
session_start();
include('../config/db.php');


/* =========================================================
   GET SELECTED ITEM CODES
   ========================================================= */

$item_codes = [];


/* Multiple selected items */
if (isset($_GET['codes'])) {

    if (is_array($_GET['codes'])) {

        foreach ($_GET['codes'] as $code) {

            $code = trim($code);

            if ($code !== '') {
                $item_codes[] = $code;
            }
        }

    } else {

        $code = trim($_GET['codes']);

        if ($code !== '') {
            $item_codes[] = $code;
        }
    }
}


/* Single item */
if (
    empty($item_codes) &&
    isset($_GET['code']) &&
    trim($_GET['code']) !== ''
) {

    $item_codes[] = trim($_GET['code']);
}


/* Remove duplicate item codes */
$item_codes = array_values(array_unique($item_codes));


/* =========================================================
   ERROR IF NOTHING SELECTED
   ========================================================= */

if (empty($item_codes)) {

    die("
        <div style='
            text-align:center;
            margin-top:50px;
            font-family:Arial,sans-serif;
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


/* =========================================================
   CLEAN DISPLAY TEXT
   ---------------------------------------------------------
   Removes unwanted backslashes before quotation marks.

   Example:

   1/4\\\\\\\" BLACK

   becomes:

   1/4" BLACK

   This ONLY changes what is displayed on the barcode.
   It does NOT change the database.
   ========================================================= */

function cleanBarcodeText($text)
{
    if ($text === null) {
        return '';
    }

    $text = (string)$text;

    /*
     * Remove backslashes immediately before "
     *
     * Example:
     * \"
     * \\"
     * \\\"
     *
     * all become:
     * "
     */
    $text = preg_replace('/\\\\+(?=")/', '', $text);

    /*
     * Remove backslashes immediately before '
     * if your data contains them.
     */
    $text = preg_replace("/\\\\+(?=')/", "'", $text);

    /*
     * Remove repeated escaping around quotation marks.
     */
    $text = str_replace('\\"', '"', $text);

    return trim($text);
}


/* =========================================================
   LABEL SIZE
   DEFAULT = 80 × 60 MM HORIZONTAL
   ========================================================= */

$label_size = '80x60';


/* =========================================================
   FONT OPTION
   ========================================================= */

$font_option = isset($_GET['font'])
    ? $_GET['font']
    : 'auto';


$allowed_fonts = [
    'auto',
    'small',
    'medium',
    'large'
];


if (!in_array($font_option, $allowed_fonts, true)) {
    $font_option = 'auto';
}


/* =========================================================
   A4 INFORMATION
   ========================================================= */

$a4_width = 210;
$a4_height = 297;

$label_width = 80;
$label_height = 60;


/*
 * A4 portrait:
 *
 * 210 / 80 = 2 labels across
 * 297 / 60 = 4 labels down
 *
 * Approximately 8 labels per A4.
 */

$horizontal_fit = floor($a4_width / $label_width);
$vertical_fit = floor($a4_height / $label_height);

$labels_per_a4 = $horizontal_fit * $vertical_fit;


/* =========================================================
   FONT CLASS
   ========================================================= */

$font_class = 'font-auto';

if ($font_option === 'small') {
    $font_class = 'font-small';
}

if ($font_option === 'medium') {
    $font_class = 'font-medium';
}

if ($font_option === 'large') {
    $font_class = 'font-large';
}

?>
<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>Print Barcode Labels</title>


<style>

/* =========================================================
   GENERAL
   ========================================================= */

* {
    box-sizing: border-box;
}


html,
body {
    margin: 0;
    padding: 0;
}


body {

    background: #525659;

    font-family:
        Arial,
        "Segoe UI",
        sans-serif;

    padding: 20px;
}


/* =========================================================
   CONTROL PANEL
   ========================================================= */

.print-control-panel {

    max-width: 1200px;

    margin: 0 auto 20px auto;

    background: white;

    padding: 15px;

    border-radius: 10px;

    box-shadow:
        0 3px 15px rgba(0,0,0,0.25);

    display: flex;

    justify-content: center;

    align-items: center;

    gap: 10px;

    flex-wrap: wrap;
}


.control-group {

    display: flex;

    align-items: center;

    gap: 7px;
}


.control-group label {

    font-size: 13px;

    font-weight: bold;

    color: #333;
}


.control-group select {

    height: 40px;

    padding: 0 12px;

    border: 1px solid #cbd5e1;

    border-radius: 6px;

    background: white;

    font-size: 14px;

    font-weight: 600;

    cursor: pointer;
}


.btn {

    display: inline-flex;

    align-items: center;

    justify-content: center;

    height: 40px;

    padding: 0 16px;

    border-radius: 6px;

    text-decoration: none;

    border: none;

    cursor: pointer;

    font-size: 14px;

    font-weight: bold;
}


.btn-back {

    background: #4b5563;

    color: white;
}


.btn-back:hover {

    background: #374151;
}


.btn-print {

    background: #f97316;

    color: white;
}


.btn-print:hover {

    background: #ea580c;
}


.info-box {

    width: 100%;

    text-align: center;

    color: #475569;

    font-size: 13px;

    padding-top: 3px;
}


/* =========================================================
   LABEL AREA
   ========================================================= */

.labels-grid-container {

    width: 100%;

    display: flex;

    flex-wrap: wrap;

    justify-content: center;

    align-items: flex-start;

    gap: 15px;

    margin: 0 auto;
}


/* =========================================================
   80 × 60 MM HORIZONTAL LABEL
   ========================================================= */

.print-label-card {

    width: 80mm;

    height: 60mm;

    min-width: 80mm;

    min-height: 60mm;

    max-width: 80mm;

    max-height: 60mm;

    background: white;

    border: 1px solid #000;

    border-radius: 2mm;

    padding: 4mm;

    display: flex;

    flex-direction: column;

    justify-content: space-between;

    text-align: center;

    overflow: hidden;

    page-break-inside: avoid;

    break-inside: avoid;

    box-shadow:
        0 3px 10px rgba(0,0,0,0.25);
}


/* =========================================================
   ITEM NAME
   ========================================================= */

.item-title {

    width: 100%;

    color: #111827;

    font-weight: 700;

    line-height: 1.08;

    text-align: center;

    overflow-wrap: anywhere;

    word-break: break-word;

    white-space: normal;

    display: flex;

    align-items: center;

    justify-content: center;

    min-height: 9mm;

    max-height: 17mm;

    overflow: hidden;

    flex-shrink: 0;

    margin-bottom: 1mm;
}


/* =========================================================
   FONT OPTIONS
   ========================================================= */

.font-auto .item-title {

    font-size: 13px;
}


.font-small .item-title {

    font-size: 9px;
}


.font-medium .item-title {

    font-size: 12px;
}


.font-large .item-title {

    font-size: 15px;
}


/* =========================================================
   BARCODE
   ========================================================= */

.barcode-display-wrapper {

    width: 100%;

    flex: 1;

    min-height: 0;

    display: flex;

    justify-content: center;

    align-items: center;

    overflow: hidden;

    padding: 1mm 0;
}


.barcode-img {

    width: 100%;

    max-width: 100%;

    max-height: 100%;

    height: auto;

    object-fit: contain;

    display: block;
}


/* =========================================================
   FOOTER
   ========================================================= */

.metadata-footer-grid {

    width: 100%;

    display: grid;

    grid-template-columns: 1.35fr 0.65fr;

    gap: 3mm;

    align-items: center;

    margin-top: 1mm;

    padding-top: 2mm;

    border-top: 1px solid #d1d5db;

    flex-shrink: 0;
}


.metadata-box {

    min-width: 0;

    overflow: hidden;

    text-align: center;
}


.metadata-label {

    display: block;

    font-size: 7px;

    font-weight: 700;

    color: #4b5563;

    margin-bottom: 1px;

    text-transform: uppercase;
}


.part-number {

    display: block;

    font-size: 10px;

    font-weight: 800;

    color: #111827;

    white-space: normal;

    overflow-wrap: anywhere;

    word-break: break-word;

    line-height: 1.05;

    max-height: 8mm;

    overflow: hidden;
}


.location-value {

    display: block;

    font-size: 9px;

    font-weight: 800;

    color: #111827;

    white-space: normal;

    overflow-wrap: anywhere;

    word-break: break-word;

    line-height: 1.05;

    max-height: 8mm;

    overflow: hidden;
}


/* =========================================================
   SCREEN RESPONSIVE
   ========================================================= */

@media screen and (max-width: 600px) {

    body {
        padding: 10px;
    }

    .print-control-panel {
        padding: 10px;
    }

    .labels-grid-container {
        gap: 10px;
    }

}


/* =========================================================
   PRINT
   ========================================================= */

@page {

    size: A4 portrait;

    margin: 0;
}


@media print {

    html,
    body {

        width: 210mm;

        min-height: 297mm;

        background: white;

        margin: 0;

        padding: 0;
    }


    .print-control-panel {

        display: none !important;
    }


    .labels-grid-container {

        width: 210mm;

        min-height: 297mm;

        display: flex;

        flex-wrap: wrap;

        justify-content: flex-start;

        align-content: flex-start;

        gap: 0;

        padding: 0;

        margin: 0;
    }


    .print-label-card {

        width: 80mm;

        height: 60mm;

        min-width: 80mm;

        min-height: 60mm;

        max-width: 80mm;

        max-height: 60mm;

        margin: 0;

        padding: 4mm;

        border: 0.3mm solid #000;

        border-radius: 1mm;

        box-shadow: none;

        page-break-inside: avoid;

        break-inside: avoid;
    }

}

</style>

</head>


<body>


<!-- =========================================================
     CONTROL PANEL
     ========================================================= -->

<div class="print-control-panel">


    <a
        href="../items/item_list.php"
        class="btn btn-back"
    >
        ⬅️ Back to Item List
    </a>


    <div class="control-group">

        <label for="fontSize">
            Text:
        </label>

        <select id="fontSize">

            <option
                value="auto"
                <?= ($font_option === 'auto')
                    ? 'selected'
                    : '' ?>
            >
                Auto Fit
            </option>


            <option
                value="small"
                <?= ($font_option === 'small')
                    ? 'selected'
                    : '' ?>
            >
                Small
            </option>


            <option
                value="medium"
                <?= ($font_option === 'medium')
                    ? 'selected'
                    : '' ?>
            >
                Medium
            </option>


            <option
                value="large"
                <?= ($font_option === 'large')
                    ? 'selected'
                    : '' ?>
            >
                Large
            </option>

        </select>

    </div>


    <button
        type="button"
        class="btn btn-print"
        onclick="window.print()"
    >
        🖨️ Print All Selected
    </button>


    <div class="info-box">

        <strong>
            <?= count($item_codes) ?>
        </strong>

        label(s) selected

        &nbsp; | &nbsp;

        Label:

        <strong>
            80 × 60 mm
        </strong>

        &nbsp; | &nbsp;

        <strong>
            HORIZONTAL
        </strong>

        &nbsp; | &nbsp;

        A4:

        <strong>
            <?= $labels_per_a4 ?>
        </strong>

        labels approximately

    </div>

</div>


<!-- =========================================================
     BARCODE LABELS
     ========================================================= -->

<div class="labels-grid-container">


<?php

foreach ($item_codes as $code) {


    /* =====================================================
       GET ITEM
       ===================================================== */

    $stmt = $conn->prepare(
        "SELECT item_name, description, location
         FROM items
         WHERE item_code = ?
         LIMIT 1"
    );


    $stmt->bind_param(
        "s",
        $code
    );


    $stmt->execute();


    $meta_result =
        $stmt->get_result();


    $item_info =
        $meta_result->fetch_assoc();


    $stmt->close();


    /* =====================================================
       ITEM NAME
       ===================================================== */

    if ($item_info) {

        if (!empty($item_info['item_name'])) {

            $display_name =
                $item_info['item_name'];

        } elseif (!empty($item_info['description'])) {

            $display_name =
                $item_info['description'];

        } else {

            $display_name =
                'Warehouse Item';
        }

    } else {

        $display_name =
            'Warehouse Item';
    }


    /*
     * CLEAN ITEM NAME
     */
    $display_name =
        cleanBarcodeText($display_name);


    /* =====================================================
       CLEAN PART NUMBER
       ===================================================== */

    $display_code =
        cleanBarcodeText($code);


    /* =====================================================
       LOCATION
       ===================================================== */

    $bin_location = 'N/A';


    if (
        $item_info &&
        !empty($item_info['location'])
    ) {

        $bin_location =
            cleanBarcodeText(
                $item_info['location']
            );
    }


    /* =====================================================
       BARCODE IMAGE
       ===================================================== */

    $barcode_url =
        "https://bwipjs-api.metafloor.com/" .
        "?bcid=code128" .
        "&text=" . urlencode($display_code) .
        "&scale=2" .
        "&height=12" .
        "&includetext" .
        "&textsize=9" .
        "&paddingwidth=4" .
        "&paddingheight=2" .
        "&backgroundcolor=FFFFFF";

?>


    <!-- =================================================
         SINGLE 80 × 60 MM HORIZONTAL LABEL
         ================================================= -->

    <div class="print-label-card <?= htmlspecialchars($font_class) ?>">


        <!-- ITEM NAME -->

        <div
            class="item-title"
            title="<?= htmlspecialchars($display_name, ENT_QUOTES, 'UTF-8') ?>"
        >

            <?= htmlspecialchars(
                $display_name,
                ENT_QUOTES,
                'UTF-8'
            ) ?>

        </div>


        <!-- BARCODE -->

        <div class="barcode-display-wrapper">

            <img
                class="barcode-img"
                src="<?= htmlspecialchars(
                    $barcode_url,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>"
                alt="Barcode"
            >

        </div>


        <!-- PART NO + LOCATION -->

        <div class="metadata-footer-grid">


            <!-- PART NUMBER -->

            <div class="metadata-box">

                <span class="metadata-label">
                    PART NO
                </span>


                <span class="part-number">

                    <?= htmlspecialchars(
                        $display_code,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>

                </span>

            </div>


            <!-- LOCATION -->

            <div class="metadata-box">

                <span class="metadata-label">
                    LOC
                </span>


                <span class="location-value">

                    <?= htmlspecialchars(
                        $bin_location,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>

                </span>

            </div>


        </div>


    </div>


<?php

}

?>

</div>


<script>

/* =========================================================
   TEXT SIZE CHANGE
   ========================================================= */

document.getElementById('fontSize')
    .addEventListener(
        'change',
        function () {

            const url =
                new URL(window.location.href);


            url.searchParams.set(
                'font',
                this.value
            );


            window.location.href =
                url.toString();

        }
    );


/* =========================================================
   AUTOMATIC TEXT FIT
   ========================================================= */

document.addEventListener(
    'DOMContentLoaded',
    function () {

        const titles =
            document.querySelectorAll(
                '.item-title'
            );


        titles.forEach(
            function (title) {

                let currentSize =
                    parseFloat(
                        window.getComputedStyle(
                            title
                        ).fontSize
                    );


                /*
                 * Keep reducing the font
                 * until the complete name
                 * fits inside the label.
                 */

                while (
                    (
                        title.scrollHeight >
                        title.clientHeight
                    ) &&
                    currentSize > 7
                ) {

                    currentSize -= 0.5;

                    title.style.fontSize =
                        currentSize + 'px';
                }

            }
        );

    }
);

</script>


</body>

</html>