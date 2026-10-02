<?php
// stock/stock_in.php
// Self-contained page (no other file needed).
// Every job has its own "box". Stock Out puts items into a job's box; Stock In either receives
// new stock into the store, or returns items out of a job's box back to the store.
$MODE = 'in';
session_start();
include('../config/db.php');
date_default_timezone_set('Asia/Kuala_Lumpur');

$IS_OUT = ($MODE === 'out');
$SELF   = basename($_SERVER['PHP_SELF']);

function clean($s) { return trim(str_replace('\\', '', (string)$s)); }
function h($s) { return htmlspecialchars(clean($s), ENT_QUOTES); }
// Same image lookup idea as item_list.php (Cloudinary URL, or file named by part no / image column)
function itemImage($image_file, $item_code = '') {
    $image_file = trim((string)$image_file);
    if ($image_file !== '' && preg_match('~^https?://~i', $image_file)) return $image_file;
    $root = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
    $dirs = ['/uploads/items/', '/uploads/'];
    if ($item_code !== '') {
        $cc = preg_replace('/[^A-Za-z0-9_\-]/', '_', trim((string)$item_code));
        foreach ($dirs as $d) foreach (['jpg', 'jpeg', 'png', 'webp', 'gif'] as $x) if (is_file($root . $d . $cc . '.' . $x)) return $d . $cc . '.' . $x;
    }
    if ($image_file !== '') {
        $b = basename(str_replace('\\', '/', $image_file));
        foreach ($dirs as $d) if (is_file($root . $d . $b)) return $d . $b;
    }
    return '';
}

// movement ledger (shared with the report page); a job's box = its movements
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS stock_moves (id INT AUTO_INCREMENT PRIMARY KEY, item_id INT NOT NULL, direction VARCHAR(3) NOT NULL,
    source VARCHAR(5) NOT NULL, job_id INT NULL, qty INT NOT NULL, note TEXT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
const BOX_SUM = "COALESCE(SUM(CASE WHEN m.direction='out' THEN m.qty ELSE -m.qty END),0)";

// ---------- live search (every letter) ----------
if (($_GET['a'] ?? '') === 'search') {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? ''); $job = (int)($_GET['job'] ?? 0); $out = [];
    if ($q !== '') {
        $like = '%' . $q . '%';
        $st = mysqli_prepare($conn, "SELECT i.id,i.item_code,i.item_name,i.barcode,i.stock_qty,i.image,
              (SELECT " . BOX_SUM . " FROM stock_moves m WHERE m.item_id=i.id AND m.job_id=?) AS box_qty
            FROM items i WHERE i.item_code LIKE ? OR i.item_name LIKE ? OR i.barcode LIKE ?
            ORDER BY (i.item_code=? OR i.barcode=?) DESC, i.item_code LIMIT 10");
        mysqli_stmt_bind_param($st, 'isssss', $job, $like, $like, $like, $q, $q);
        mysqli_stmt_execute($st);
        $r = mysqli_stmt_get_result($st);
        while ($r && $x = mysqli_fetch_assoc($r)) {
            $out[] = ['id' => (int)$x['id'], 'item_code' => clean($x['item_code']), 'item_name' => clean($x['item_name']), 'barcode' => clean($x['barcode']),
                      'stock_qty' => (int)$x['stock_qty'], 'box_qty' => (int)$x['box_qty'], 'img' => itemImage($x['image'], $x['item_code'])];
        }
    }
    exit(json_encode($out));
}

// ---------- book the movement ----------
if (isset($_POST['book'])) {
    $code = trim($_POST['item_identifier'] ?? '');
    $n    = (int)($_POST['qty'] ?? 0);
    $job  = max(0, (int)($_POST['job_id'] ?? 0));
    $note = trim($_POST['note'] ?? '');
    $flash = ['warning', 'Pick an item and enter a quantity first.'];

    if ($code !== '' && $n > 0) {
        $st = mysqli_prepare($conn, "SELECT id,item_code,stock_qty FROM items WHERE item_code=? OR barcode=? LIMIT 1");
        mysqli_stmt_bind_param($st, 'ss', $code, $code);
        mysqli_stmt_execute($st);
        $res = mysqli_stmt_get_result($st);
        $item = $res ? mysqli_fetch_assoc($res) : null;

        if (!$item) {
            $flash = ['danger', 'Item <b>' . h($code) . '</b> not found.'];
        } else {
            $id = (int)$item['id']; $ok = false;
            if ($IS_OUT) {
                // atomic: only subtracts when enough stock is left, so it can never go negative
                $up = mysqli_prepare($conn, "UPDATE items SET stock_qty = stock_qty - ? WHERE id = ? AND stock_qty >= ?");
                mysqli_stmt_bind_param($up, 'iii', $n, $id, $n);
                mysqli_stmt_execute($up);
                $ok = mysqli_stmt_affected_rows($up) > 0;
                if (!$ok) $flash = ['danger', 'Not enough stock. Only <b>' . (int)$item['stock_qty'] . '</b> left for ' . h($item['item_code']) . '.'];
            } else {
                if ($job > 0) {   // returning from a box: cannot return more than the box holds
                    $bq = mysqli_prepare($conn, "SELECT " . BOX_SUM . " AS b FROM stock_moves m WHERE m.item_id=? AND m.job_id=?");
                    mysqli_stmt_bind_param($bq, 'ii', $id, $job); mysqli_stmt_execute($bq);
                    $inbox = (int)(mysqli_fetch_assoc(mysqli_stmt_get_result($bq))['b'] ?? 0);
                    if ($n > $inbox) $flash = ['danger', 'That box only holds <b>' . $inbox . '</b> of ' . h($item['item_code']) . '.'];
                    else $ok = true;
                } else $ok = true;
                if ($ok) {
                    $up = mysqli_prepare($conn, "UPDATE items SET stock_qty = stock_qty + ? WHERE id = ?");
                    mysqli_stmt_bind_param($up, 'ii', $n, $id); mysqli_stmt_execute($up);
                }
            }
            if ($ok) {
                $dir = $IS_OUT ? 'out' : 'in'; $src = $job > 0 ? 'job' : 'store'; $jp = $job > 0 ? $job : null;
                $lg = mysqli_prepare($conn, "INSERT INTO stock_moves (item_id,direction,source,job_id,qty,note) VALUES (?,?,?,?,?,?)");
                mysqli_stmt_bind_param($lg, 'issiis', $id, $dir, $src, $jp, $n, $note);
                mysqli_stmt_execute($lg);
                $left = (int)$item['stock_qty'] + ($IS_OUT ? -$n : $n);
                $flash = ['success', ($IS_OUT ? 'Stock out' : 'Stock in') . ': <b>' . $n . '</b> × ' . h($item['item_code']) . ' — store now has <b>' . $left . '</b>.'];
            }
        }
    }
    $_SESSION['flash'] = $flash;
    header('Location: ' . $SELF . '?job=' . $job); exit;
}
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);

// ---------- data for the page ----------
$job = max(0, (int)($_GET['job'] ?? 0));
$jobRow = null;
if ($job > 0) { $r = mysqli_query($conn, "SELECT id,job_no,customer_name FROM jobs WHERE id=$job"); $jobRow = $r ? mysqli_fetch_assoc($r) : null; if (!$jobRow) $job = 0; }

// one box per job, with how many pieces it holds now
$having = $IS_OUT ? '' : 'HAVING pcs > 0';
$boxes = []; $br = mysqli_query($conn, "SELECT j.id, j.job_no, j.customer_name, " . BOX_SUM . " AS pcs
    FROM jobs j LEFT JOIN stock_moves m ON m.job_id = j.id GROUP BY j.id, j.job_no, j.customer_name $having ORDER BY j.id DESC LIMIT 300");
while ($br && $x = mysqli_fetch_assoc($br)) $boxes[] = $x;

// what is inside the selected box
$contents = [];
if ($job > 0) {
    $cr = mysqli_query($conn, "SELECT i.id,i.item_code,i.item_name,i.image,i.stock_qty, " . BOX_SUM . " AS box_qty
        FROM stock_moves m JOIN items i ON i.id=m.item_id WHERE m.job_id=$job GROUP BY i.id,i.item_code,i.item_name,i.image,i.stock_qty
        HAVING box_qty <> 0 ORDER BY i.item_code");
    while ($cr && $x = mysqli_fetch_assoc($cr)) $contents[] = $x;
}
$dirSql = $IS_OUT ? 'out' : 'in';
$recent = mysqli_query($conn, "SELECT m.created_at,m.qty,m.note,i.item_code,i.item_name FROM stock_moves m JOIN items i ON i.id=m.item_id
    WHERE m.direction='$dirSql' AND " . ($job > 0 ? "m.job_id=$job" : "m.job_id IS NULL") . " ORDER BY m.id DESC LIMIT 10");

$title   = $IS_OUT ? 'Stock Out' : 'Stock In';
$color   = $IS_OUT ? 'danger' : 'success';
$storeBox = $IS_OUT ? 'Store use (no job)' : 'New stock into store';
$boxName  = $jobRow ? ('Job ' . clean($jobRow['job_no'])) : $storeBox;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Warehouse - <?= $title ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body { background:#f4f6f9; font-family:'Segoe UI', sans-serif; }
        .sidebar { width:260px; height:100vh; background:#1a2232; position:fixed; left:0; top:0; overflow-y:auto; z-index:100; }
        .logo { background:#f97316; padding:18px 20px; text-align:center; font-size:20px; font-weight:bold; color:white; letter-spacing:.5px; }
        .sidebar-menu { list-style:none; padding:0; margin:0; }
        .sidebar a { display:flex; align-items:center; padding:13px 20px; color:#d1d5db; text-decoration:none; font-size:15px; font-weight:500; transition:background .2s,color .2s; border-left:4px solid transparent; }
        .sidebar a i { font-size:18px; width:30px; text-align:center; margin-right:12px; color:#9ca3af; transition:color .2s; }
        .sidebar a:hover { background:#131924; color:#fff; } .sidebar a:hover i { color:#fff; }
        .sidebar a.active { background:#131924; color:#fff; border-left:4px solid #f97316; } .sidebar a.active i { color:#fff; }
        .main { margin-left:260px; padding:20px; }
        .card { border:0; box-shadow:0 1px 3px rgba(0,0,0,.08); border-radius:10px; }
        .card-header { background:#fff; font-weight:600; border-bottom:1px solid #eef0f3; }
        .box-grid { max-height:230px; overflow-y:auto; padding:2px; }
        .box-tile { display:block; height:100%; border:1px solid #dee2e6; border-radius:8px; padding:9px 12px; text-decoration:none; color:#212529; background:#fff; }
        .box-tile:hover { border-color:#f97316; color:#212529; }
        .box-tile.active { border-color:#f97316; background:#fff7ed; box-shadow:0 0 0 2px rgba(249,115,22,.25); }
        .box-tile .nm { font-weight:700; font-size:14px; } .box-tile .cu { font-size:12px; color:#6c757d; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        #qr-reader { width:100%; max-width:380px; margin:0 auto; background:#111; border-radius:8px; overflow:hidden; }
        #qr-reader video { width:100% !important; height:auto !important; }
        .ac { position:absolute; z-index:60; background:#fff; border:1px solid #cbd5e1; border-radius:8px; width:100%; max-height:340px; overflow:auto; display:none; box-shadow:0 8px 20px rgba(0,0,0,.15); top:100%; left:0; }
        .ac div { display:flex; gap:10px; align-items:center; padding:7px 10px; cursor:pointer; font-size:13px; }
        .ac div:hover { background:#fff7ed; } .ac img, .thumb { width:40px; height:40px; object-fit:contain; background:#fff; border:1px solid #e2e8f0; border-radius:6px; }
        .picked { display:none; align-items:center; gap:14px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px 12px; margin-bottom:14px; }
        .picked img { width:64px; height:64px; object-fit:contain; background:#fff; border:1px solid #e2e8f0; border-radius:8px; }
        tr.pick-row { cursor:pointer; } tr.pick-row:hover { background:#fff7ed; }
        @media(max-width:768px){ .sidebar{display:none} .main{margin-left:0;padding:10px} }
    </style>
</head>
<body>

    <div class="sidebar">
        <div class="logo">WAREHOUSE</div>
        <div class="sidebar-menu">
            <a href="../dashboard.php"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
            <a href="../items/item_list.php"><i class="fa-solid fa-box-archive"></i> Items</a>
            <a href="../items/add_item.php"><i class="fa-solid fa-plus"></i> Add Item</a>
            <a href="../import_excel.php"><i class="fa-solid fa-file-import"></i> Import Excel</a>
            <a href="../create_job.php"><i class="fa-solid fa-file-circle-plus"></i> Create Job</a>
            <a href="../job_list.php"><i class="fa-solid fa-file-lines"></i> Job List</a>
            <a href="../stock/stock_in.php"<?= !$IS_OUT ? ' class="active"' : '' ?>><i class="fa-solid fa-arrow-trend-up"></i> Stock In</a>
            <a href="../items/stock_out.php"<?= $IS_OUT ? ' class="active"' : '' ?>><i class="fa-solid fa-arrow-trend-down"></i> Stock Out</a>
            <a href="../return_item.php"><i class="fa-solid fa-rotate-left"></i> Returns</a>
            <a href="../stock/missing_item.php"><i class="fa-solid fa-triangle-exclamation"></i> Missing</a>
            <a href="../scaner.php"><i class="fa-solid fa-barcode"></i> Scanner</a>
            <a href="../reports/stock_report.php"><i class="fa-solid fa-chart-pie"></i> Reports</a>
            <a href="../logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
        </div>
    </div>

    <div class="main">
        <div class="card mb-3"><div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h4 class="m-0 fw-bold text-<?= $color ?>"><i class="fa-solid fa-arrow-trend-<?= $IS_OUT ? 'down' : 'up' ?> me-2"></i><?= $title ?></h4>
            <span class="text-muted small"><?= $IS_OUT ? 'Put items into a job box, or take them out for store use.' : 'Receive new stock, or return items from a job box back to the store.' ?></span>
        </div></div>

        <?php if ($flash): ?>
            <div class="alert alert-<?= $flash[0] ?> alert-dismissible fade show"><?= $flash[1] ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>

        <!-- 1. choose the box -->
        <div class="card mb-3">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span><i class="fa-solid fa-box me-1"></i> 1. Choose the box</span>
                <input type="text" id="boxFilter" class="form-control form-control-sm" style="max-width:240px" placeholder="Find job no / customer...">
            </div>
            <div class="card-body box-grid"><div class="row g-2" id="boxRow">
                <div class="col-6 col-md-4 col-xl-3"><a class="box-tile<?= $job === 0 ? ' active' : '' ?>" href="?job=0">
                    <div class="nm"><i class="fa-solid fa-warehouse text-secondary me-1"></i><?= h($storeBox) ?></div><div class="cu"><?= $IS_OUT ? 'Not for any job' : 'Purchases / new arrivals' ?></div></a></div>
                <?php foreach ($boxes as $b): ?>
                <div class="col-6 col-md-4 col-xl-3 box-item" data-t="<?= h(strtolower($b['job_no'] . ' ' . $b['customer_name'])) ?>">
                    <a class="box-tile<?= $job === (int)$b['id'] ? ' active' : '' ?>" href="?job=<?= (int)$b['id'] ?>">
                        <div class="nm"><i class="fa-solid fa-box-open text-warning me-1"></i><?= h($b['job_no']) ?> <span class="badge bg-dark float-end"><?= (int)$b['pcs'] ?> pcs</span></div>
                        <div class="cu"><?= h($b['customer_name']) ?: '—' ?></div></a></div>
                <?php endforeach; if (!$IS_OUT && !$boxes) echo "<div class='col-12 text-muted small'>No job box holds any items yet, so there is nothing to return.</div>"; ?>
            </div></div>
        </div>

        <div class="row g-3">
            <div class="col-lg-5">
                <div class="card h-100">
                    <div class="card-header"><i class="fa-solid fa-camera me-1"></i> Scan label</div>
                    <div class="card-body text-center">
                        <div id="qr-reader"></div>
                        <div id="scan-msg" class="mt-2 small fw-semibold"></div>
                        <button class="btn btn-outline-secondary btn-sm mt-2 w-100" onclick="switchCamera()"><i class="fa-solid fa-camera-rotate"></i> Switch camera</button>
                        <div class="text-muted my-2 small">or take / choose a photo of the label</div>
                        <input type="file" accept="image/*" capture="environment" id="file-selector" class="form-control form-control-sm">
                        <div id="file-tmp" style="display:none"></div>
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="card h-100">
                    <div class="card-header"><i class="fa-solid fa-pen-to-square me-1"></i> 2. Find item and book it into: <span class="text-<?= $color ?>"><?= h($boxName) ?></span></div>
                    <div class="card-body">
                        <div class="input-group position-relative mb-3">
                            <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
                            <input type="text" id="search" class="form-control" placeholder="Type part no / description / barcode..." autocomplete="off">
                            <div class="ac" id="acb"></div>
                        </div>

                        <div class="picked" id="picked">
                            <img id="p-img" src="" alt="">
                            <div class="flex-grow-1">
                                <div class="fw-bold text-primary" id="p-code"></div>
                                <div class="small text-uppercase" id="p-name"></div>
                                <div class="small mt-1">Store stock: <b id="p-stock">0</b> → <b id="p-after">0</b>
                                    <?php if ($job > 0): ?> &nbsp;|&nbsp; In this box: <b id="p-box">0</b><?php endif; ?></div>
                            </div>
                        </div>

                        <form method="POST">
                            <input type="hidden" name="item_identifier" id="item_identifier">
                            <input type="hidden" name="job_id" value="<?= $job ?>">
                            <div class="row g-2 mb-3">
                                <div class="col-sm-4">
                                    <label class="form-label small fw-semibold">Quantity</label>
                                    <input type="number" name="qty" id="qty" class="form-control text-center fw-bold" min="1" value="1" required>
                                </div>
                                <div class="col-sm-8">
                                    <label class="form-label small fw-semibold">Note (optional)</label>
                                    <input type="text" name="note" class="form-control" placeholder="e.g. who took it">
                                </div>
                            </div>
                            <button type="submit" name="book" id="btnBook" class="btn btn-<?= $color ?> w-100 py-2 fw-semibold" disabled>
                                <?= $IS_OUT ? 'Book Stock Out' : ($job > 0 ? 'Return to Store' : 'Book Stock In') ?></button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- what is in the box -->
        <?php if ($job > 0): ?>
        <div class="card mt-3">
            <div class="card-header"><i class="fa-solid fa-boxes-stacked me-1"></i> Inside <?= h($boxName) ?> <span class="text-muted fw-normal small">(click a row to pick that item)</span></div>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                <thead class="table-light"><tr><th style="width:60px">Image</th><th>Part No</th><th>Description</th><th class="text-end">In box</th></tr></thead><tbody>
                <?php foreach ($contents as $c): $img = itemImage($c['image'], $c['item_code']);
                    $data = ['id' => (int)$c['id'], 'item_code' => clean($c['item_code']), 'item_name' => clean($c['item_name']), 'stock_qty' => (int)$c['stock_qty'], 'box_qty' => (int)$c['box_qty'], 'img' => $img]; ?>
                    <tr class="pick-row" data-item="<?= htmlspecialchars(json_encode($data), ENT_QUOTES) ?>">
                        <td><?php if ($img): ?><img class="thumb" loading="lazy" src="<?= h($img) ?>" onerror="this.style.visibility='hidden'"><?php endif; ?></td>
                        <td class="fw-semibold text-primary"><?= h($c['item_code']) ?></td><td class="text-uppercase small"><?= h($c['item_name']) ?></td>
                        <td class="text-end fw-bold"><?= (int)$c['box_qty'] ?></td></tr>
                <?php endforeach; if (!$contents) echo "<tr><td colspan='4' class='text-center text-muted py-3'>This box is empty.</td></tr>"; ?>
                </tbody></table></div>
        </div>
        <?php endif; ?>

        <!-- recent -->
        <div class="card mt-3 mb-4">
            <div class="card-header"><i class="fa-regular fa-clock me-1"></i> Recent <?= strtolower($title) ?> — <?= h($boxName) ?></div>
            <div class="table-responsive"><table class="table table-sm mb-0">
                <thead class="table-light"><tr><th>Date / time</th><th>Part No</th><th>Description</th><th class="text-end">Qty</th><th>Note</th></tr></thead><tbody>
                <?php $n = 0; while ($recent && $r = mysqli_fetch_assoc($recent)): $n++; ?>
                    <tr><td class="small"><?= date('d M Y H:i', strtotime($r['created_at'])) ?></td><td class="fw-semibold"><?= h($r['item_code']) ?></td>
                        <td class="small text-uppercase"><?= h($r['item_name']) ?></td><td class="text-end fw-bold"><?= (int)$r['qty'] ?></td><td class="small"><?= h($r['note']) ?></td></tr>
                <?php endwhile; if (!$n) echo "<tr><td colspan='5' class='text-center text-muted py-3'>Nothing booked here yet.</td></tr>"; ?>
                </tbody></table></div>
        </div>
    </div>

    <script>
        const SELF = location.pathname, IS_OUT = <?= json_encode($IS_OUT) ?>, JOB = <?= (int)$job ?>;
        let selected = null, lastScan = 0, timer;
        const $ = id => document.getElementById(id);
        const PH = 'data:image/svg+xml,' + encodeURIComponent("<svg xmlns='http://www.w3.org/2000/svg' width='64' height='64'><rect width='64' height='64' fill='#f1f5f9'/></svg>");

        // filter the job boxes
        $('boxFilter').addEventListener('input', e => {
            const v = e.target.value.toLowerCase();
            document.querySelectorAll('.box-item').forEach(b => b.style.display = b.dataset.t.includes(v) ? '' : 'none');
        });

        // The label QR holds a link (...public_item_view.php?code=XXXX). Take the code out of it;
        // plain barcodes and typed codes are used as they are.
        function codeFrom(text) {
            text = (text || '').trim();
            try { const u = new URL(text); const c = u.searchParams.get('code') || u.searchParams.get('codes[]'); if (c) return c; } catch (e) {}
            return text;
        }

        function maxQty() { return IS_OUT ? selected.stock_qty : (JOB > 0 ? selected.box_qty : Infinity); }
        function selectItem(it) {
            selected = it;
            $('item_identifier').value = it.item_code; $('search').value = it.item_code; $('acb').style.display = 'none';
            $('p-img').src = it.img || PH; $('p-code').textContent = it.item_code; $('p-name').textContent = it.item_name;
            $('p-stock').textContent = it.stock_qty; if ($('p-box')) $('p-box').textContent = it.box_qty;
            $('picked').style.display = 'flex';
            const m = maxQty(); if (m !== Infinity) $('qty').max = m; else $('qty').removeAttribute('max');
            if (m <= 0) $('scan-msg').innerHTML = '<span class="text-danger">' + (IS_OUT ? 'No stock left for this item.' : 'This box holds none of this item.') + '</span>';
            update(); $('qty').focus(); $('qty').select();
        }
        function update() {
            if (!selected) return;
            const n = parseInt($('qty').value) || 0, after = selected.stock_qty + (IS_OUT ? -n : n);
            $('p-after').textContent = after; $('p-after').className = (IS_OUT && after < 0) ? 'text-danger' : 'text-success';
            $('btnBook').disabled = !(n > 0 && n <= maxQty());
        }
        $('qty').addEventListener('input', update);

        // click an item inside the box table
        document.querySelectorAll('.pick-row').forEach(r => r.addEventListener('click', () => { selectItem(JSON.parse(r.dataset.item)); window.scrollTo({ top: 0, behavior: 'smooth' }); }));

        function searchItems(q) { return fetch(SELF + '?a=search&job=' + JOB + '&q=' + encodeURIComponent(q)).then(r => r.json()); }

        // every letter typed -> matching items appear
        $('search').addEventListener('input', () => {
            clearTimeout(timer);
            selected = null; $('btnBook').disabled = true; $('picked').style.display = 'none';
            timer = setTimeout(() => {
                const v = $('search').value.trim(), box = $('acb');
                if (!v) { box.style.display = 'none'; return; }
                searchItems(v).then(list => {
                    box.innerHTML = '';
                    if (!list.length) box.innerHTML = '<div class="text-muted">No matching items</div>';
                    list.forEach(it => {
                        const d = document.createElement('div');
                        d.innerHTML = `<img src="${it.img || PH}" onerror="this.onerror=null;this.src=PH"><span><b>${it.item_code}</b> — ${it.item_name} <small class="text-muted">(stock ${it.stock_qty})</small></span>`;
                        d.onclick = () => selectItem(it); box.appendChild(d);
                    });
                    box.style.display = 'block';
                });
            }, 150);
        });
        $('search').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); const f = $('acb').querySelector('div'); if (f && f.onclick) f.onclick(); } });
        document.addEventListener('click', e => { if (!$('acb').contains(e.target) && e.target !== $('search')) $('acb').style.display = 'none'; });

        // ---- scanner ----
        function handleScanned(text) {
            const code = codeFrom(text);
            $('scan-msg').innerHTML = '<span class="text-success">Scanned: ' + code.replace(/</g, '&lt;') + '</span>';
            searchItems(code).then(list => {
                const lc = code.toLowerCase();
                const hit = list.find(i => i.item_code.toLowerCase() === lc || (i.barcode || '').toLowerCase() === lc);
                if (hit) selectItem(hit); else $('scan-msg').innerHTML = '<span class="text-danger">No item found for "' + code.replace(/</g, '&lt;') + '"</span>';
            });
        }
        function onScanSuccess(text) { const now = Date.now(); if (now - lastScan < 2000) return; lastScan = now; if (navigator.vibrate) navigator.vibrate(100); handleScanned(text); }
        let qr, facing = 'environment';
        function startScanner() {
            const go = () => {
                qr = new Html5Qrcode('qr-reader');
                qr.start({ facingMode: facing }, { fps: 15, qrbox: { width: 260, height: 200 } }, onScanSuccess, () => {})
                  .catch(() => { $('scan-msg').innerHTML = '<span class="text-warning">Camera not available here (needs HTTPS). Use the photo option or type the code.</span>'; });
            };
            if (qr && qr.isScanning) qr.stop().then(go).catch(go); else go();
        }
        function switchCamera() { facing = facing === 'environment' ? 'user' : 'environment'; startScanner(); }
        $('file-selector').addEventListener('change', e => {
            if (!e.target.files.length) return;
            new Html5Qrcode('file-tmp').scanFile(e.target.files[0], true).then(handleScanned).catch(() => alert('Could not read a code from that photo. Try a clearer, closer photo.'));
        });
        window.addEventListener('DOMContentLoaded', startScanner);
    </script>
</body>
</html>
