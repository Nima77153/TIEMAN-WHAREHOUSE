<?php
session_start();
include('../config/db.php');
date_default_timezone_set('Asia/Kuala_Lumpur');

// ---- Accept item codes: POST selected_codes[] / POST-GET codes[] / GET code ----
$selected_items = [];
foreach (['selected_codes', 'codes', 'code'] as $key) {
    foreach ([$_POST, $_GET] as $src) {
        if (!empty($src[$key])) $selected_items = array_merge($selected_items, (array)$src[$key]);
    }
}
$selected_items = array_values(array_unique(array_filter(array_map('trim', $selected_items))));
if (empty($selected_items)) {
    echo "<script>alert('Please select at least one item first.'); window.close();</script>";
    exit;
}

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$base_url = $protocol . $_SERVER['HTTP_HOST'];
$printed_at = date('d M Y') . ' ' . date('H:i');

function clean($s) { return trim(str_replace('\\', '', (string)$s)); }
function h($s) { return htmlspecialchars(clean($s), ENT_QUOTES); }
function resolveLabelImage($image_file) {
    $clean = trim(strip_tags((string)$image_file));
    if ($clean === '') return '';
    if (filter_var($clean, FILTER_VALIDATE_URL) || stripos($clean, 'http') === 0) return $clean;
    $base = basename(str_replace('\\', '/', $clean));
    foreach (['../uploads/items/' . $base, '../uploads/' . $base, '../uploads/items/' . $clean] as $p) if (file_exists($p)) return $p;
    return '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Thermal Print Queue</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <style>
        * { box-sizing: border-box; }
        body { margin:0; padding:20px; background:#4b5563; font-family:'Segoe UI', Arial, sans-serif; }

        .label-block {
            width:80mm; height:55mm; padding:3mm 4mm; background:#fff; border-radius:4px;
            box-shadow:0 2px 8px rgba(0,0,0,.15); margin:6px; text-align:center;
            page-break-inside:avoid; vertical-align:top; display:inline-flex; flex-direction:column; overflow:hidden;
        }
        .label-brand { font-size:7px; color:#94a3b8; text-transform:uppercase; letter-spacing:1px; flex-shrink:0; }
        .item-title { font-weight:800; text-transform:uppercase; color:#1e293b; line-height:1.15; white-space:nowrap; overflow:hidden; margin:0.5mm 0 1mm; flex-shrink:0; }

        /* middle row: BIG image on the left, QR on the right */
        .mid-row { display:flex; align-items:center; justify-content:center; gap:5mm; flex-shrink:0; }
        .img-slot { width:23mm; height:23mm; border:1px solid #e2e8f0; border-radius:4px; display:flex; align-items:center; justify-content:center; overflow:hidden; background:#fff; }
        .img-slot img { max-width:100%; max-height:100%; object-fit:contain; }
        .qr-wrap { width:23mm; height:23mm; display:flex; align-items:center; justify-content:center; }
        .qr-wrap img, .qr-wrap canvas { max-width:100%; max-height:100%; }

        .part-no { font-weight:900; color:#0f172a; white-space:nowrap; overflow:hidden; margin:1.2mm 0 0.5mm; line-height:1.1; flex-shrink:0; }
        .label-divider { border:0; border-top:1px solid #e2e8f0; margin:0.8mm 0; flex-shrink:0; }

        .label-footer { display:flex; justify-content:space-between; gap:2mm; margin-top:auto; flex-shrink:0; text-align:center; }
        .f-qty { flex:0 0 11mm; } .f-loc { flex:1; min-width:0; } .f-date { flex:0 0 20mm; }
        .f-label { font-size:6px; color:#94a3b8; text-transform:uppercase; letter-spacing:.5px; }
        .f-value { font-weight:800; color:#1e293b; line-height:1.15; overflow:hidden; }
        .f-loc .f-value { max-height:7.5mm; word-break:break-word; }

        @media print {
            body { background:#fff; padding:0; }
            .label-block { box-shadow:none; margin:0; page-break-after:always; width:80mm; height:55mm; }
        }
    </style>
</head>
<body>
    <?php
    $label_index = 0;
    foreach ($selected_items as $code) {
        $safe_code = mysqli_real_escape_string($conn, $code);
        $item = null;
        try {
            $res = mysqli_query($conn, "SELECT * FROM items WHERE item_code='$safe_code' LIMIT 1");
            $item = $res ? mysqli_fetch_assoc($res) : null;
        } catch (Throwable $ex) { $item = null; }

        $title    = $item ? clean($item['item_name'] ?? '') : '';
        if ($title === '') $title = $item ? clean($item['description'] ?? '') : '';
        if ($title === '') $title = $code;
        $image    = $item ? resolveLabelImage($item['image'] ?? '') : '';
        $qty      = $item ? (int)($item['stock_qty'] ?? 0) : 0;
        $location = $item ? (clean($item['location'] ?? '') ?: '-') : '-';

        // Same QR for everyone: phone camera = view only; scanned while logged in = can update qty
        $qr_target_url = $base_url . '/barcode/public_item_view.php?code=' . urlencode($code);
        $qr_div_id = 'qr_' . $label_index;
        ?>
        <div class="label-block">
            <div class="label-brand">Tieman Warehouse</div>
            <div class="item-title autofit-nowrap" data-max="11" data-min="7"><?= h($title) ?></div>

            <div class="mid-row">
                <div class="img-slot">
                    <?php if ($image): ?><img src="<?= h($image) ?>" alt="Item" onerror="this.style.display='none'"><?php endif; ?>
                </div>
                <div class="qr-wrap" id="<?= $qr_div_id ?>"></div>
            </div>

            <div class="part-no autofit-nowrap" data-max="22" data-min="10"><?= h($code) ?></div>
            <hr class="label-divider">

            <div class="label-footer">
                <div class="f-qty"><div class="f-label">Qty</div><div class="f-value" style="font-size:12px"><?= $qty ?></div></div>
                <div class="f-loc"><div class="f-label">Location</div><div class="f-value autofit-wrap" data-max="9" data-min="5.5"><?= h($location) ?></div></div>
                <div class="f-date"><div class="f-label">Printed</div><div class="f-value" style="font-size:7.5px"><?= date('d M Y') ?><br><?= date('H:i') ?></div></div>
            </div>
        </div>
        <script>
            new QRCode(document.getElementById(<?= json_encode($qr_div_id) ?>), {
                text: <?= json_encode($qr_target_url) ?>, width: 84, height: 84, correctLevel: QRCode.CorrectLevel.M
            });
        </script>
        <?php $label_index++;
    }
    ?>
    <script>
        function fitW(el) { const mx = parseFloat(el.dataset.max), mn = parseFloat(el.dataset.min); let f = mx; el.style.fontSize = f + 'px';
            while (el.scrollWidth > el.clientWidth && f > mn) { f -= 0.5; el.style.fontSize = f + 'px'; } }
        function fitH(el) { const mx = parseFloat(el.dataset.max), mn = parseFloat(el.dataset.min); let f = mx; el.style.fontSize = f + 'px';
            while (el.scrollHeight > el.clientHeight + 1 && f > mn) { f -= 0.5; el.style.fontSize = f + 'px'; } }
        window.addEventListener('load', () => {
            setTimeout(() => {
                document.querySelectorAll('.autofit-nowrap').forEach(fitW);
                document.querySelectorAll('.autofit-wrap').forEach(fitH);
                setTimeout(() => window.print(), 150);
            }, 250);
        });
    </script>
</body>
</html>
