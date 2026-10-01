<?php
// Public, read-only item page opened by scanning the label QR code. No login needed.
// Save as:  barcode/public_item_view.php
include('../config/db.php');

function clean($s) { return trim(str_replace('\\', '', (string)$s)); }
function h($s) { return htmlspecialchars(clean($s), ENT_QUOTES); }
function itemImage($n) {
    $n = trim(strip_tags((string)$n));
    if ($n === '') return '';
    if (preg_match('~^https?://~i', $n)) return $n;
    $b = basename(str_replace('\\', '/', $n));
    foreach (["../uploads/items/$b", "../uploads/$b", "../uploads/items/$n"] as $p) if (is_file($p)) return $p;
    return '';
}

$code = trim($_GET['code'] ?? '');
$item = null;
if ($code !== '') {
    $stmt = mysqli_prepare($conn, "SELECT * FROM items WHERE item_code = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 's', $code);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $item = $res ? mysqli_fetch_assoc($res) : null;
}

$qty = $item ? (int)($item['stock_qty'] ?? 0) : 0;
$min = $item ? (int)($item['min_qty'] ?? 5) : 5;
$badge = $qty <= 0 ? ['Out of stock', '#ef4444'] : ($qty <= $min ? ['Low stock', '#eab308'] : ['In stock', '#22c55e']);
$img = $item ? itemImage($item['image'] ?? '') : '';
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $item ? h($item['item_code']) : 'Item not found' ?> - Tieman Warehouse</title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#eef2f7;font-family:'Segoe UI',Arial,sans-serif;color:#1e293b}
.top{background:#f97316;color:#fff;text-align:center;padding:14px;font-weight:700;letter-spacing:.5px}
.card{max-width:480px;margin:16px auto;background:#fff;border-radius:14px;box-shadow:0 2px 10px rgba(0,0,0,.08);overflow:hidden}
.img{background:#fff;padding:14px;text-align:center;border-bottom:1px solid #e2e8f0}
.img img{max-width:100%;max-height:260px;object-fit:contain}
.body{padding:16px 18px}
.code{color:#2563eb;font-weight:800;font-size:20px;word-break:break-all}
.name{font-weight:700;text-transform:uppercase;margin:6px 0 10px;font-size:15px}
.desc{color:#64748b;font-size:14px;margin-bottom:12px;white-space:pre-wrap}
.badge{display:inline-block;color:#fff;font-weight:700;padding:5px 14px;border-radius:20px;font-size:14px}
.grid{display:flex;gap:10px;margin-top:14px}
.box{flex:1;background:#f1f5f9;border-radius:10px;padding:10px 12px;min-width:0}
.box small{display:block;color:#94a3b8;font-size:11px;text-transform:uppercase;letter-spacing:.5px}
.box b{font-size:20px;word-break:break-word}
.nf{padding:40px 20px;text-align:center}
</style></head><body>
<div class="top">TIEMAN WAREHOUSE</div>
<div class="card">
<?php if ($item): ?>
  <?php if ($img): ?><div class="img"><img src="<?= h($img) ?>" alt="Item" onerror="this.parentNode.style.display='none'"></div><?php endif; ?>
  <div class="body">
    <div class="code"><?= h($item['item_code']) ?></div>
    <div class="name"><?= h($item['item_name'] ?? '') ?></div>
    <?php $d = clean($item['description'] ?? ($item['remark'] ?? '')); if ($d !== '' && $d !== clean($item['item_name'] ?? '')): ?>
      <div class="desc"><?= h($d) ?></div><?php endif; ?>
    <span class="badge" style="background:<?= $badge[1] ?>"><?= $badge[0] ?></span>
    <div class="grid">
      <div class="box"><small>Quantity</small><b><?= $qty ?></b></div>
      <div class="box"><small>Location</small><b><?= h(($item['location'] ?? '') ?: '-') ?></b></div>
    </div>
    <?php if (!empty($item['category'])): ?><div class="desc" style="margin:12px 0 0">Category: <?= h($item['category']) ?></div><?php endif; ?>
  </div>
<?php else: ?>
  <div class="nf"><h3>Item not found</h3><p class="desc">No item with code <b><?= h($code) ?></b>.</p></div>
<?php endif; ?>
</div>
</body></html>
