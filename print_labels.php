<?php
session_start();
include('../config/db.php');

if(empty($_POST['selected_codes'])) {
    echo "<script>alert('Please check at least one checkbox on the left grid first.'); window.close();</script>";
    exit;
}

$selected_items = $_POST['selected_codes'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Thermal Print Queue</title>
    <link href="https://fonts.googleapis.com/css2?family=Libre+Barcode+39&display=swap" rel="stylesheet">
    <style>
        body { margin:0; padding:0; background:#white; }
        .label-block { 
            display:inline-block; 
            width:60mm; 
            height:40mm; 
            padding:4mm; 
            box-sizing:border-box; 
            border:1px dashed #ccc; 
            margin:5px; 
            text-align:center; 
            page-break-inside:avoid; 
            vertical-align:top;
        }
        .desc-title { font-size:10px; font-weight:bold; height:24px; overflow:hidden; text-transform:uppercase; font-family:sans-serif; }
        .barcode-render { font-family:'Libre Barcode 39', sans-serif; font-size:46px; margin:2px 0; display:block; line-height:1; }
        .code-string { font-size:12px; font-weight:bold; font-family:sans-serif; letter-spacing:1px; }
        @media print {
            .label-block { border:none; margin:0; page-break-after:always; width:60mm; height:40mm; }
        }
    </style>
</head>
<body onload="window.print();">
    <?php 
    foreach($selected_items as $code) {
        $safe_code = mysqli_real_escape_string($conn, $code);
        $res = mysqli_query($conn, "SELECT description FROM items WHERE item_code='$safe_code'");
        $item = mysqli_fetch_assoc($res);
        $desc = $item ? $item['description'] : $code;
        ?>
        <div class="label-block">
            <div class="desc-title"><?= htmlspecialchars(substr($desc, 0, 45)) ?></div>
            <span class="barcode-render">*<?= htmlspecialchars($code) ?>*</span>
            <div class="code-string"><?= htmlspecialchars($code) ?></div>
        </div>
    <?php } ?>
</body>
</html>