<?php
session_start();
include('../config/db.php');

if(empty($_POST['selected_codes'])) {
    echo "<script>alert('Please check at least one checkbox on the left grid first.'); window.close();</script>";
    exit;
}

$selected_items = $_POST['selected_codes'];

// Build the base URL so the QR code links back to THIS server's public item page,
// e.g. https://tieman-wharehouse-production.up.railway.app/barcode/public_item_view.php?code=XXXX
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$base_url = $protocol . $_SERVER['HTTP_HOST'];

// Resolve each item's image the same way item_list.php does
function resolveLabelImage($image_file) {
    $clean = trim(strip_tags((string)$image_file));
    if (!empty($clean) && (filter_var($clean, FILTER_VALIDATE_URL) || strpos($clean, 'http') === 0)) {
        return $clean; // Cloudinary / full URL already
    }
    if (!empty($clean) && file_exists('../uploads/items/' . $clean)) {
        return '../uploads/items/' . $clean;
    }
    if (!empty($clean) && file_exists('../uploads/' . $clean)) {
        return '../uploads/' . $clean;
    }
    return '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Thermal Print Queue</title>
    <!-- Real QR code generator (client-side) -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <style>
        * { box-sizing: border-box; }
        body { margin:0; padding:20px; background:#4b5563; font-family: 'Segoe UI', Arial, sans-serif; }

        .label-block {
            display:inline-block;
            width:80mm;
            height:55mm;
            padding:4mm 5mm;
            background:#ffffff;
            border-radius: 4px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            margin:6px;
            text-align:center;
            page-break-inside:avoid;
            vertical-align:top;
            display:flex;
            flex-direction:column;
        }

        .label-brand { font-size:8px; color:#94a3b8; text-transform:uppercase; letter-spacing:1px; margin-bottom:1.5mm; flex-shrink:0; }

        .label-title-row { display:flex; align-items:center; justify-content:center; gap:2mm; margin-bottom:1mm; flex-shrink:0; }
        .label-img-slot {
            width:8mm;
            height:8mm;
            border:1px solid #e2e8f0;
            border-radius:3px;
            display:flex;
            align-items:center;
            justify-content:center;
            overflow:hidden;
            flex-shrink:0;
            background:#fff;
        }
        .label-img-slot img { max-width:100%; max-height:100%; object-fit:contain; }
        .desc-title {
            font-weight:800;
            text-transform:uppercase;
            letter-spacing:0.3px;
            color:#1e293b;
            line-height:1.15;
            white-space:nowrap;
            overflow:hidden;
            max-width: 100%;
        }

        /* Description line: wraps, auto-shrinks, capped to 2 lines of space */
        .label-description {
            color:#64748b;
            line-height:1.2;
            max-height: 7mm;
            overflow:hidden;
            margin-bottom:1mm;
            flex-shrink:0;
        }

        .qr-wrap { display:flex; justify-content:center; margin: 0.5mm 0; flex-shrink:0; }

        .label-divider { border:0; border-top:1px solid #e2e8f0; margin: 1.5mm 0; flex-shrink:0; }

        .label-footer { display:flex; justify-content:space-between; gap: 2mm; margin-top:auto; flex-shrink:0; }
        .footer-col { flex:1; min-width:0; }
        .footer-label { font-size:7px; color:#94a3b8; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:1px; }
        .footer-value {
            font-weight:800;
            color:#1e293b;
            white-space:nowrap;
            overflow:hidden;
        }

        @media print {
            body { background:#ffffff; padding:0; }
            .label-block { box-shadow:none; margin:0; page-break-after:always; width:80mm; height:55mm; }
        }
    </style>
</head>
<body>
    <?php
    $label_index = 0;
    foreach($selected_items as $code) {
        $safe_code = mysqli_real_escape_string($conn, $code);
        $res = mysqli_query($conn, "SELECT description, item_name, image, stock_qty, location FROM items WHERE item_code='$safe_code'");
        $item = mysqli_fetch_assoc($res);

        $title       = $item ? ($item['item_name'] ?: $item['description']) : $code;
        $description = $item ? trim((string)$item['description']) : '';
        $image       = $item ? resolveLabelImage($item['image']) : '';
        $qty         = $item ? (int)$item['stock_qty'] : 0;
        $location    = $item ? ($item['location'] ?: '-') : '-';

        // This is the link encoded into the QR code. Anyone scanning it with a
        // normal phone camera opens this page directly - no app/login needed.
        $qr_target_url = $base_url . '/barcode/public_item_view.php?code=' . urlencode($code);
        $qr_div_id = 'qr_' . $label_index;
        ?>
        <div class="label-block">
            <div class="label-brand">Tieman Warehouse</div>

            <div class="label-title-row">
                <?php if (!empty($image)): ?>
                    <div class="label-img-slot"><img src="<?= htmlspecialchars($image) ?>" alt="Item"></div>
                <?php endif; ?>
                <div class="desc-title autofit-nowrap" data-max="13" data-min="8"><?= htmlspecialchars($title) ?></div>
            </div>

            <?php if (!empty($description)): ?>
                <div class="label-description autofit-wrap" data-max="9" data-min="6"><?= htmlspecialchars($description) ?></div>
            <?php endif; ?>

            <div class="qr-wrap" id="<?= $qr_div_id ?>"></div>

            <hr class="label-divider">

            <div class="label-footer">
                <div class="footer-col">
                    <div class="footer-label">Part No</div>
                    <div class="footer-value autofit-nowrap" data-max="11" data-min="7"><?= htmlspecialchars($code) ?></div>
                </div>
                <div class="footer-col">
                    <div class="footer-label">Qty</div>
                    <div class="footer-value autofit-nowrap" data-max="11" data-min="7"><?= $qty ?></div>
                </div>
                <div class="footer-col">
                    <div class="footer-label">Location</div>
                    <div class="footer-value autofit-nowrap" data-max="11" data-min="7"><?= htmlspecialchars($location) ?></div>
                </div>
            </div>
        </div>
        <script>
            new QRCode(document.getElementById("<?= $qr_div_id ?>"), {
                text: "<?= addslashes($qr_target_url) ?>",
                width: 70,
                height: 70,
                correctLevel: QRCode.CorrectLevel.M
            });
        </script>
        <?php
        $label_index++;
    }
    ?>
    <script>
        // AUTO-FIT: shrink font size on long text so it fits its box instead of
        // overflowing or getting cut off (long Part Nos, Locations, Descriptions).
        function autoFitNowrap(el) {
            const maxSize = parseFloat(el.dataset.max);
            const minSize = parseFloat(el.dataset.min);
            let fontSize = maxSize;
            el.style.fontSize = fontSize + 'px';
            while (el.scrollWidth > el.clientWidth && fontSize > minSize) {
                fontSize -= 0.5;
                el.style.fontSize = fontSize + 'px';
            }
        }

        function autoFitWrap(el) {
            const maxSize = parseFloat(el.dataset.max);
            const minSize = parseFloat(el.dataset.min);
            let fontSize = maxSize;
            el.style.fontSize = fontSize + 'px';
            while (el.scrollHeight > el.clientHeight && fontSize > minSize) {
                fontSize -= 0.5;
                el.style.fontSize = fontSize + 'px';
            }
        }

        function runAutoFit() {
            document.querySelectorAll('.autofit-nowrap').forEach(autoFitNowrap);
            document.querySelectorAll('.autofit-wrap').forEach(autoFitWrap);
        }

        // Run after layout settles, then print
        window.addEventListener('load', () => {
            setTimeout(() => {
                runAutoFit();
                setTimeout(() => window.print(), 150);
            }, 250);
        });
    </script>
</body>
</html>
