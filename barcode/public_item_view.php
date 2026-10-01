<?php
// Opened by the label QR code.
//  - Anyone (phone camera, not logged in): view only.
//  - Logged-in staff (same browser/session as the system): can also update the quantity.
session_start();
include('../config/db.php');
date_default_timezone_set('Asia/Kuala_Lumpur');

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
function loadItem($conn, $code) {
    if ($code === '') return null;
    $st = mysqli_prepare($conn, "SELECT * FROM items WHERE item_code = ? LIMIT 1");
    mysqli_stmt_bind_param($st, 's', $code);
    mysqli_stmt_execute($st);
    $r = mysqli_stmt_get_result($st);
    return $r ? mysqli_fetch_assoc($r) : null;
}

// Staff = anyone with a logged-in session (public visitors have an empty session)
$staff = !empty($_SESSION);
$code = trim($_GET['code'] ?? '');
$item = loadItem($conn, $code);

// ---- staff-only quantity update ----
if ($staff && $item && $_SERVER['REQUEST_METHOD'] === 'POST') {
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS stock_moves (id INT AUTO_INCREMENT PRIMARY KEY, item_id INT NOT NULL, direction VARCHAR(3) NOT NULL,
        source VARCHAR(5) NOT NULL, job_id INT NULL, qty INT NOT NULL, note TEXT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $id = (int)$item['id']; $cur = (int)$item['stock_qty']; $n = max(0, (int)($_POST['qty'] ?? 0));
    $mode = $_POST['mode'] ?? '';
    if ($mode === 'set') { $dir = $n >= $cur ? 'in' : 'out'; $delta = abs($n - $cur); $new = $n; $note = 'Set via QR scan'; }
    else { $dir = $mode === 'out' ? 'out' : 'in'; $delta = max(1, $n); $new = max(0, $cur + ($dir === 'in' ? $delta : -$delta)); $note = 'QR scan'; }
    if ($delta > 0) {
        $st = mysqli_prepare($conn, "INSERT INTO stock_moves (item_id,direction,source,qty,note) VALUES (?,?,'store',?,?)");
        mysqli_stmt_bind_param($st, 'isis', $id, $dir, $delta, $note); mysqli_stmt_execute($st);
        $st = mysqli_prepare($conn, "UPDATE items SET stock_qty=? WHERE id=?");
        mysqli_stmt_bind_param($st, 'ii', $new, $id); mysqli_stmt_execute($st);
    }
    header('Location: public_item_view.php?code=' . urlencode($code) . '&done=1'); exit;
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
.img{padding:14px;text-align:center;border-bottom:1px solid #e2e8f0}.img img{max-width:100%;max-height:260px;object-fit:contain}
.body{padding:16px 18px}
.code{color:#2563eb;font-weight:800;font-size:22px;word-break:break-all}
.name{font-weight:700;text-transform:uppercase;margin:6px 0 10px;font-size:15px}
.desc{color:#64748b;font-size:14px;white-space:pre-wrap}
.badge{display:inline-block;color:#fff;font-weight:700;padding:5px 14px;border-radius:20px;font-size:14px}
.grid{display:flex;gap:10px;margin-top:14px}
.box{flex:1;background:#f1f5f9;border-radius:10px;padding:10px 12px;min-width:0}
.box small{display:block;color:#94a3b8;font-size:11px;text-transform:uppercase;letter-spacing:.5px}.box b{font-size:20px;word-break:break-word}
.staff{margin-top:16px;border-top:2px dashed #fdba74;padding-top:14px}
.staff h4{margin:0 0 8px;font-size:14px;color:#c2410c}
.staff input{width:100%;font-size:22px;padding:10px;border:2px solid #cbd5e1;border-radius:10px;text-align:center}
.btns{display:flex;gap:8px;margin-top:10px}
.btns button{flex:1;border:0;border-radius:10px;padding:14px 4px;font-size:15px;font-weight:700;color:#fff}
.ok{background:#dcfce7;color:#166534;padding:10px;border-radius:10px;margin-bottom:12px;font-weight:600;font-size:14px}
.nf{padding:40px 20px;text-align:center}
</style></head><body>
<div class="top">TIEMAN WAREHOUSE</div>
<div class="card">
<?php if ($item): ?>
  <?php if ($img): ?><div class="img"><img src="<?= h($img) ?>" alt="Item" onerror="this.parentNode.style.display='none'"></div><?php endif; ?>
  <div class="body">
    <?php if (isset($_GET['done'])): ?><div class="ok">✅ Quantity updated.</div><?php endif; ?>
    <div class="code"><?= h($item['item_code']) ?></div>
    <div class="name"><?= h($item['item_name'] ?? '') ?></div>
    <span class="badge" style="background:<?= $badge[1] ?>"><?= $badge[0] ?></span>
    <div class="grid">
      <div class="box"><small>Quantity</small><b><?= $qty ?></b></div>
      <div class="box"><small>Location</small><b><?= h(($item['location'] ?? '') ?: '-') ?></b></div>
    </div>
    <?php if (!empty($item['category'])): ?><div class="desc" style="margin-top:12px">Category: <?= h($item['category']) ?></div><?php endif; ?>

    <?php if ($staff): ?>
    <form method="POST" class="staff">
      <h4>🔧 Staff: update quantity</h4>
      <input type="number" name="qty" min="0" value="1" inputmode="numeric">
      <div class="btns">
        <button name="mode" value="in" style="background:#16a34a">＋ Stock In</button>
        <button name="mode" value="out" style="background:#dc2626">－ Stock Out</button>
        <button name="mode" value="set" style="background:#2563eb" onclick="return confirm('Set quantity to exactly this number?')">＝ Set</button>
      </div>
    </form>
    <?php endif; ?>
  </div>
<?php else: ?>
  <div class="nf"><h3>Item not found</h3><p class="desc">No item with code <b><?= h($code) ?></b>.</p></div>
<?php endif; ?>
</div>
</body></html>
