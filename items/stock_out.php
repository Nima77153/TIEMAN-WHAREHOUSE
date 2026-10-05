<?php
// items/stock_out.php
// Self-contained page (no other file needed).
// Follows the job boxes made in job_list.php (job -> box -> items). Add several items to the book list,
// then book them all at once. Store quantity and the job report update automatically.
$MODE = 'out';
session_start();
include('../config/db.php');
date_default_timezone_set('Asia/Kuala_Lumpur');

$IS_OUT = ($MODE === 'out');
$SELF   = basename($_SERVER['PHP_SELF']);

function clean($s) { return trim(str_replace('\\', '', (string)$s)); }
function h($s) { return htmlspecialchars(clean($s), ENT_QUOTES); }
function itemImage($image_file, $item_code = '') {   // same lookup idea as item_list.php
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

// movement ledger shared with the report page
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS stock_moves (id INT AUTO_INCREMENT PRIMARY KEY, item_id INT NOT NULL, direction VARCHAR(3) NOT NULL,
    source VARCHAR(5) NOT NULL, job_id INT NULL, qty INT NOT NULL, note TEXT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
if (!mysqli_num_rows(mysqli_query($conn, "SHOW COLUMNS FROM stock_moves LIKE 'box_id'"))) mysqli_query($conn, "ALTER TABLE stock_moves ADD box_id INT NULL");
const SENT_SQL = "COALESCE(SUM(CASE WHEN m.direction='out' THEN m.qty ELSE -m.qty END),0)";   // net pieces sent to a job

// ---------- live search (every letter) ----------
if (($_GET['a'] ?? '') === 'search') {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? ''); $job = (int)($_GET['job'] ?? 0); $out = [];
    if ($q !== '') {
        $like = '%' . $q . '%';
        $st = mysqli_prepare($conn, "SELECT i.id,i.item_code,i.item_name,i.image,i.stock_qty,
              (SELECT " . SENT_SQL . " FROM stock_moves m WHERE m.item_id=i.id AND m.job_id=?) AS sent
            FROM items i WHERE i.item_code LIKE ? OR i.item_name LIKE ? OR i.barcode LIKE ?
            ORDER BY (i.item_code=? OR i.barcode=?) DESC, i.item_code LIMIT 10");
        mysqli_stmt_bind_param($st, 'isssss', $job, $like, $like, $like, $q, $q);
        mysqli_stmt_execute($st);
        $r = mysqli_stmt_get_result($st);
        while ($r && $x = mysqli_fetch_assoc($r)) {
            $out[] = ['id' => (int)$x['id'], 'item_code' => clean($x['item_code']), 'item_name' => clean($x['item_name']),
                      'stock_qty' => (int)$x['stock_qty'], 'sent' => (int)$x['sent'], 'img' => itemImage($x['image'], $x['item_code'])];
        }
    }
    exit(json_encode($out));
}

// ---------- scope: which job / box ----------
$job = max(0, (int)($_GET['job'] ?? 0));
$box = isset($_GET['box']) ? (int)$_GET['box'] : -1;      // -1 = all items, 0 = items without a box, >0 = that box
$jobRow = null;
if ($job > 0) { $r = mysqli_query($conn, "SELECT id,job_no,customer_name,status FROM jobs WHERE id=$job"); $jobRow = $r ? mysqli_fetch_assoc($r) : null; if (!$jobRow) { $job = 0; $box = -1; } }
$dirSql   = $IS_OUT ? 'out' : 'in';
$scopeSql = $job > 0 ? "m.job_id=$job" . ($box > 0 ? " AND m.box_id=$box" : '') : "m.job_id IS NULL";

// ---------- export the bookings as an Excel file ----------
if (isset($_GET['export'])) {
    $res = mysqli_query($conn, "SELECT m.created_at,m.qty,m.note,i.item_code,i.item_name,i.image,j.job_no,j.customer_name,b.box_name
        FROM stock_moves m JOIN items i ON i.id=m.item_id LEFT JOIN jobs j ON j.id=m.job_id LEFT JOIN job_boxes b ON b.id=m.box_id
        WHERE m.direction='$dirSql' AND $scopeSql ORDER BY m.id DESC");
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $domain = $proto . $_SERVER['HTTP_HOST'];
    $fname = ($IS_OUT ? 'Stock_Out_' : 'Stock_In_') . ($jobRow ? preg_replace('/[^A-Za-z0-9_\-]/', '_', $jobRow['job_no']) : 'Store') . '_' . date('Ymd_His') . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $fname);
    header('Pragma: public');
    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40"><head><meta http-equiv="content-type" content="text/html; charset=UTF-8"></head><body>';
    echo '<h3>' . ($IS_OUT ? 'Stock Out' : 'Stock In') . ' - ' . h($jobRow ? 'Job ' . $jobRow['job_no'] . ' ' . $jobRow['customer_name'] : ($IS_OUT ? 'Store use' : 'New stock')) . '</h3>';
    echo '<table border="1"><thead><tr style="background-color:#2e7d32;color:#ffffff;font-weight:bold;text-align:center;height:30px;">';
    foreach (['DATE / TIME', 'JOB', 'BOX', 'IMAGE', 'PART NO', 'DESCRIPTION', 'QTY', 'NOTE'] as $c) echo "<th>$c</th>";
    echo '</tr></thead><tbody>';
    while ($res && $r = mysqli_fetch_assoc($res)) {
        $im = itemImage($r['image'], $r['item_code']);
        if ($im !== '' && !preg_match('~^https?://~i', $im)) $im = $domain . $im;
        echo '<tr style="height:60px;vertical-align:middle;"><td>' . date('d M Y H:i', strtotime($r['created_at'])) . '</td><td>' . h($r['job_no'] ?? '-') . '</td><td>' . h($r['box_name'] ?? '-') . '</td>';
        echo $im !== '' ? '<td align="center" style="width:70px;"><img src="' . h($im) . '" width="55" height="55"></td>' : '<td align="center">NO IMAGE</td>';
        echo '<td style="font-weight:bold;vnd.ms-excel.numberformat:@;">' . h($r['item_code']) . '</td><td>' . h($r['item_name']) . '</td><td align="center" style="font-weight:bold;">' . (int)$r['qty'] . '</td><td>' . h($r['note']) . '</td></tr>';
    }
    echo '</tbody></table></body></html>';
    exit;
}

// ---------- book everything in the list ----------
if (isset($_POST['book_all'])) {
    $pjob = max(0, (int)($_POST['job_id'] ?? 0));
    $pbox = (int)($_POST['box_id'] ?? 0);
    $note = trim($_POST['note'] ?? '');
    $cart = json_decode($_POST['cart_json'] ?? '[]', true);
    $lines = [];
    if (is_array($cart)) foreach ($cart as $c) { $id = (int)($c['id'] ?? 0); $n = (int)($c['qty'] ?? 0); if ($id > 0 && $n > 0) $lines[$id] = ($lines[$id] ?? 0) + $n; }

    if (!$lines) {
        $flash = ['warning', 'The book list is empty. Add at least one item first.'];
    } else {
        try {
            mysqli_begin_transaction($conn);
            $total = 0;
            foreach ($lines as $id => $n) {
                $st = mysqli_prepare($conn, "SELECT id,item_code,stock_qty FROM items WHERE id=? FOR UPDATE");
                mysqli_stmt_bind_param($st, 'i', $id); mysqli_stmt_execute($st);
                $item = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
                if (!$item) throw new RuntimeException('An item in the list was not found.');
                $code = h($item['item_code']);

                // which box does this item belong to in the job?
                $mbox = 0; $inJob = false;
                if ($pjob > 0) {
                    $st = mysqli_prepare($conn, "SELECT box_id FROM job_items WHERE job_id=? AND item_id=? LIMIT 1");
                    mysqli_stmt_bind_param($st, 'ii', $pjob, $id); mysqli_stmt_execute($st);
                    $ji = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
                    if ($ji) { $inJob = true; $mbox = (int)$ji['box_id']; } else { $mbox = max(0, $pbox); }
                }

                if ($IS_OUT) {   // atomic: only subtracts when enough is left
                    $up = mysqli_prepare($conn, "UPDATE items SET stock_qty = stock_qty - ? WHERE id = ? AND stock_qty >= ?");
                    mysqli_stmt_bind_param($up, 'iii', $n, $id, $n); mysqli_stmt_execute($up);
                    if (mysqli_stmt_affected_rows($up) < 1) throw new RuntimeException('Not enough stock for <b>' . $code . '</b>: only ' . (int)$item['stock_qty'] . ' left. Nothing was booked.');
                } else {
                    if ($pjob > 0) {   // returning: cannot return more than was sent to the job
                        $st = mysqli_prepare($conn, "SELECT " . SENT_SQL . " AS s FROM stock_moves m WHERE m.item_id=? AND m.job_id=?");
                        mysqli_stmt_bind_param($st, 'ii', $id, $pjob); mysqli_stmt_execute($st);
                        $sent = (int)(mysqli_fetch_assoc(mysqli_stmt_get_result($st))['s'] ?? 0);
                        if ($n > $sent) throw new RuntimeException('The job only has <b>' . $sent . '</b> of <b>' . $code . '</b> to return. Nothing was booked.');
                    }
                    $up = mysqli_prepare($conn, "UPDATE items SET stock_qty = stock_qty + ? WHERE id = ?");
                    mysqli_stmt_bind_param($up, 'ii', $n, $id); mysqli_stmt_execute($up);
                }

                $src = $pjob > 0 ? 'job' : 'store'; $jp = $pjob > 0 ? $pjob : null; $bp = $mbox > 0 ? $mbox : null;
                $lg = mysqli_prepare($conn, "INSERT INTO stock_moves (item_id,direction,source,job_id,box_id,qty,note) VALUES (?,?,?,?,?,?,?)");
                $d = $IS_OUT ? 'out' : 'in';
                mysqli_stmt_bind_param($lg, 'issiiis', $id, $d, $src, $jp, $bp, $n, $note); mysqli_stmt_execute($lg);

                // item not on the job's list yet -> add it to the job list (and the chosen box)
                if ($IS_OUT && $pjob > 0 && !$inJob) {
                    try {
                        $ji = mysqli_prepare($conn, "INSERT INTO job_items (job_id,item_id,qty,qty_per_tanker,production_units,after_qty,remark,box_id) VALUES (?,?,?,'1',0,?,'',?)");
                        mysqli_stmt_bind_param($ji, 'iiiii', $pjob, $id, $n, $n, $mbox); mysqli_stmt_execute($ji);
                    } catch (Throwable $e) { /* booking still counts even if the job list could not be extended */ }
                }
                $total += $n;
            }
            mysqli_commit($conn);
            $flash = ['success', ($IS_OUT ? 'Stock out' : 'Stock in') . ' booked: <b>' . count($lines) . '</b> item(s), <b>' . $total . '</b> pcs. Store quantities and the job report are updated.'];
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            $flash = ['danger', $e instanceof RuntimeException ? $e->getMessage() : 'Database error: ' . h($e->getMessage())];
        }
    }
    $_SESSION['flash'] = $flash;
    header('Location: ' . $SELF . '?job=' . $pjob . ($box >= 0 ? '&box=' . $box : (isset($_POST['box_view']) ? '&box=' . (int)$_POST['box_view'] : ''))); exit;
}
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);

// ---------- data for the page ----------
$jobsList = []; $jr = mysqli_query($conn, "SELECT id,job_no,customer_name,status FROM jobs ORDER BY id DESC LIMIT 500");
while ($jr && $x = mysqli_fetch_assoc($jr)) $jobsList[] = ['id' => (int)$x['id'], 'job_no' => clean($x['job_no']), 'customer' => clean($x['customer_name']), 'status' => clean($x['status'])];

$boxes = []; $boxName = [0 => 'No box']; $agg = []; $rows = [];
if ($job > 0) {
    $br = mysqli_query($conn, "SELECT id,box_name FROM job_boxes WHERE job_id=$job ORDER BY box_name");
    while ($br && $x = mysqli_fetch_assoc($br)) { $boxes[] = $x; $boxName[(int)$x['id']] = clean($x['box_name']); }
    $ir = mysqli_query($conn, "SELECT ji.item_id,ji.qty AS need,ji.box_id,i.item_code,i.item_name,i.image,i.stock_qty,
        COALESCE((SELECT " . SENT_SQL . " FROM stock_moves m WHERE m.job_id=ji.job_id AND m.item_id=ji.item_id),0) AS sent
        FROM job_items ji JOIN items i ON i.id=ji.item_id WHERE ji.job_id=$job ORDER BY i.item_code");
    while ($ir && $x = mysqli_fetch_assoc($ir)) {
        $bid = (int)$x['box_id']; if (!isset($boxName[$bid])) $bid = 0;
        $x['bid'] = $bid; $rows[] = $x;
        foreach ([$bid, -1] as $k) { $agg[$k]['n'] = ($agg[$k]['n'] ?? 0) + 1; $agg[$k]['need'] = ($agg[$k]['need'] ?? 0) + (int)$x['need']; $agg[$k]['sent'] = ($agg[$k]['sent'] ?? 0) + (int)$x['sent']; }
    }
}
$shown = array_values(array_filter($rows, function ($x) use ($box, $IS_OUT) {
    if ($box >= 0 && $x['bid'] !== $box) return false;
    return $IS_OUT ? true : ((int)$x['sent'] > 0);
}));
$recent = mysqli_query($conn, "SELECT m.created_at,m.qty,m.note,i.item_code,i.item_name,b.box_name FROM stock_moves m JOIN items i ON i.id=m.item_id
    LEFT JOIN job_boxes b ON b.id=m.box_id WHERE m.direction='$dirSql' AND $scopeSql ORDER BY m.id DESC LIMIT 15");

$title = $IS_OUT ? 'Stock Out' : 'Stock In';
$color = $IS_OUT ? 'danger' : 'success';
$storeLabel = $IS_OUT ? 'Store use (no job)' : 'New stock into store';
$scopeName = $jobRow ? ('Job ' . clean($jobRow['job_no']) . ($box > 0 && isset($boxName[$box]) ? ' / ' . $boxName[$box] : '')) : $storeLabel;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Warehouse - <?= $title ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
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
        .box-tile { display:block; height:100%; border:1px solid #dee2e6; border-radius:8px; padding:9px 12px; text-decoration:none; color:#212529; background:#fff; }
        .box-tile:hover { border-color:#f97316; color:#212529; }
        .box-tile.active { border-color:#f97316; background:#fff7ed; box-shadow:0 0 0 2px rgba(249,115,22,.25); }
        .box-tile .nm { font-weight:700; font-size:14px; } .box-tile .cu { font-size:12px; color:#6c757d; }
        .ac { position:absolute; z-index:60; background:#fff; border:1px solid #cbd5e1; border-radius:8px; width:100%; max-height:340px; overflow:auto; display:none; box-shadow:0 8px 20px rgba(0,0,0,.15); top:100%; left:0; }
        .ac div { display:flex; gap:10px; align-items:center; padding:7px 10px; cursor:pointer; font-size:13px; }
        .ac div:hover { background:#fff7ed; } .ac img, .thumb { width:40px; height:40px; object-fit:contain; background:#fff; border:1px solid #e2e8f0; border-radius:6px; }
        .qty-in { width:80px; text-align:center; font-weight:700; }
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
            <a href="../items/stock_in.php"<?= !$IS_OUT ? ' class="active"' : '' ?>><i class="fa-solid fa-arrow-trend-up"></i> Stock In</a>
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
            <a class="btn btn-outline-success btn-sm" href="?export=1&job=<?= $job ?><?= $box > 0 ? '&box=' . $box : '' ?>"><i class="fa-solid fa-file-excel me-1"></i> Export <?= strtolower($title) ?> list (Excel)</a>
        </div></div>

        <?php if ($flash): ?>
            <div class="alert alert-<?= $flash[0] ?> alert-dismissible fade show"><?= $flash[1] ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>

        <!-- 1. job and box -->
        <div class="card mb-3">
            <div class="card-header"><i class="fa-solid fa-box me-1"></i> 1. Choose the job and its box</div>
            <div class="card-body">
                <div class="row g-2 align-items-start mb-2">
                    <div class="col-md-6"><div class="input-group position-relative">
                        <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
                        <input type="text" id="jobSearch" class="form-control" placeholder="Type job no / customer to find a job..." autocomplete="off">
                        <div class="ac" id="jobAc"></div></div></div>
                    <div class="col-md-6 d-flex flex-wrap gap-2 align-items-center">
                        <a class="btn btn-sm <?= $job === 0 ? 'btn-warning' : 'btn-outline-secondary' ?>" href="?job=0"><i class="fa-solid fa-warehouse me-1"></i><?= h($storeLabel) ?></a>
                        <?php if ($jobRow): ?><span class="fw-semibold">Selected: <span class="text-warning">Job <?= h($jobRow['job_no']) ?></span> <span class="text-muted small"><?= h($jobRow['customer_name']) ?></span></span><?php endif; ?>
                    </div>
                </div>
                <?php if ($job > 0): ?>
                <div class="row g-2 mt-1">
                    <div class="col-6 col-md-4 col-xl-3"><a class="box-tile<?= $box === -1 ? ' active' : '' ?>" href="?job=<?= $job ?>&box=-1">
                        <div class="nm"><i class="fa-solid fa-layer-group text-secondary me-1"></i>All items</div>
                        <div class="cu"><?= (int)($agg[-1]['n'] ?? 0) ?> items · sent <?= (int)($agg[-1]['sent'] ?? 0) ?> / <?= (int)($agg[-1]['need'] ?? 0) ?></div></a></div>
                    <?php foreach ($boxes as $b): $bid = (int)$b['id']; ?>
                    <div class="col-6 col-md-4 col-xl-3"><a class="box-tile<?= $box === $bid ? ' active' : '' ?>" href="?job=<?= $job ?>&box=<?= $bid ?>">
                        <div class="nm"><i class="fa-solid fa-box-open text-warning me-1"></i><?= h($b['box_name']) ?></div>
                        <div class="cu"><?= (int)($agg[$bid]['n'] ?? 0) ?> items · sent <?= (int)($agg[$bid]['sent'] ?? 0) ?> / <?= (int)($agg[$bid]['need'] ?? 0) ?></div></a></div>
                    <?php endforeach; if (!empty($agg[0])): ?>
                    <div class="col-6 col-md-4 col-xl-3"><a class="box-tile<?= $box === 0 ? ' active' : '' ?>" href="?job=<?= $job ?>&box=0">
                        <div class="nm"><i class="fa-regular fa-square text-secondary me-1"></i>No box</div>
                        <div class="cu"><?= (int)$agg[0]['n'] ?> items · sent <?= (int)$agg[0]['sent'] ?> / <?= (int)$agg[0]['need'] ?></div></a></div>
                    <?php endif; ?>
                </div>
                <?php if (!$boxes): ?><div class="small text-muted mt-2">This job has no boxes yet. Make them in Job List (View &amp; Manage Job Boxes).</div><?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- 2. items of the job / box -->
        <div class="card mb-3">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span><i class="fa-solid fa-list-check me-1"></i> 2. <?= $job > 0 ? ($IS_OUT ? 'Items on the job list — press Add' : 'Items sent to this job — press Add to return') : 'Find the items' ?></span>
            </div>
            <div class="card-body">
                <div class="row g-2 mb-3">
                    <div class="col-md-8"><div class="input-group position-relative">
                        <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
                        <input type="text" id="search" class="form-control" placeholder="Type part no / description / barcode to add any other item..." autocomplete="off">
                        <div class="ac" id="acb"></div></div></div>
                    <div class="col-md-4"><div class="input-group"><span class="input-group-text">Qty</span>
                        <input type="number" id="addq" class="form-control text-center fw-bold" min="1" value="1"></div></div>
                </div>
                <?php if ($job > 0): ?>
                <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                    <thead class="table-light"><tr><th style="width:56px">Image</th><th>Part No</th><th>Description</th><th>Box</th>
                        <th class="text-end"><?= $IS_OUT ? 'Job qty' : 'Sent' ?></th>
                        <?php if ($IS_OUT): ?><th class="text-end">Sent</th><th class="text-end">Left</th><?php endif; ?>
                        <th class="text-end">Store</th><th style="width:100px">Qty</th><th style="width:70px"></th></tr></thead><tbody>
                    <?php foreach ($shown as $r):
                        $img = itemImage($r['image'], $r['item_code']); $need = (int)$r['need']; $sent = (int)$r['sent']; $left = $need - $sent; $stock = (int)$r['stock_qty'];
                        $data = ['id' => (int)$r['item_id'], 'item_code' => clean($r['item_code']), 'item_name' => clean($r['item_name']), 'stock_qty' => $stock, 'sent' => $sent, 'img' => $img];
                        $def = $IS_OUT ? max(1, min($left, $stock)) : max(1, $sent); ?>
                    <tr data-item="<?= htmlspecialchars(json_encode($data), ENT_QUOTES) ?>">
                        <td><?php if ($img): ?><img class="thumb" loading="lazy" src="<?= h($img) ?>" onerror="this.style.visibility='hidden'"><?php endif; ?></td>
                        <td class="fw-semibold text-primary"><?= h($r['item_code']) ?></td><td class="small text-uppercase"><?= h($r['item_name']) ?></td>
                        <td class="small"><?= h($boxName[$r['bid']] ?? 'No box') ?></td>
                        <td class="text-end"><?= $IS_OUT ? $need : $sent ?></td>
                        <?php if ($IS_OUT): ?><td class="text-end"><?= $sent ?></td><td class="text-end fw-bold <?= $left <= 0 ? 'text-success' : 'text-danger' ?>"><?= $left ?></td><?php endif; ?>
                        <td class="text-end <?= $stock <= 0 ? 'text-danger fw-bold' : '' ?>"><?= $stock ?></td>
                        <td><input type="number" class="form-control form-control-sm qty-in row-qty" min="1" value="<?= $def ?>"></td>
                        <td><button type="button" class="btn btn-sm btn-<?= $color ?> row-add"><i class="fa-solid fa-plus"></i> Add</button></td></tr>
                    <?php endforeach; if (!$shown) echo "<tr><td colspan='10' class='text-center text-muted py-3'>" . ($IS_OUT ? 'No items here yet. Search above to add one.' : 'Nothing has been sent to this job (or box) yet.') . "</td></tr>"; ?>
                    </tbody></table></div>
                <?php else: ?>
                    <div class="text-muted small"><?= $IS_OUT ? 'Store use: search an item above and add it to the list.' : 'Search the items that arrived and add them to the list.' ?> To work with a job box, pick a job first.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- 3. the book list -->
        <div class="card mb-3">
            <div class="card-header"><i class="fa-solid fa-cart-flatbed me-1"></i> 3. Book list for <span class="text-<?= $color ?>"><?= h($scopeName) ?></span></div>
            <div class="card-body">
                <div id="cartMsg" class="small mb-2"></div>
                <div class="table-responsive"><table class="table table-sm align-middle">
                    <thead class="table-light"><tr><th style="width:56px">Image</th><th>Part No</th><th>Description</th><th class="text-end">Store</th><th style="width:100px">Qty</th><th style="width:50px"></th></tr></thead>
                    <tbody id="cartBody"></tbody></table></div>
                <form method="POST" id="bookForm" class="row g-2 align-items-end">
                    <input type="hidden" name="job_id" value="<?= $job ?>">
                    <input type="hidden" name="box_id" value="<?= max(0, $box) ?>">
                    <input type="hidden" name="box_view" value="<?= $box ?>">
                    <input type="hidden" name="cart_json" id="cart_json">
                    <div class="col-md-7"><label class="form-label small fw-semibold mb-1">Note (optional)</label><input type="text" name="note" class="form-control" placeholder="e.g. who took it"></div>
                    <div class="col-md-5"><button type="submit" name="book_all" id="btnBook" class="btn btn-<?= $color ?> w-100 fw-semibold" disabled>Book all</button></div>
                </form>
            </div>
        </div>

        <!-- recent -->
        <div class="card mb-4">
            <div class="card-header"><i class="fa-regular fa-clock me-1"></i> Recent <?= strtolower($title) ?> — <?= h($scopeName) ?></div>
            <div class="table-responsive"><table class="table table-sm mb-0">
                <thead class="table-light"><tr><th>Date / time</th><th>Box</th><th>Part No</th><th>Description</th><th class="text-end">Qty</th><th>Note</th></tr></thead><tbody>
                <?php $n = 0; while ($recent && $r = mysqli_fetch_assoc($recent)): $n++; ?>
                    <tr><td class="small"><?= date('d M Y H:i', strtotime($r['created_at'])) ?></td><td class="small"><?= h($r['box_name'] ?? '-') ?></td><td class="fw-semibold"><?= h($r['item_code']) ?></td>
                        <td class="small text-uppercase"><?= h($r['item_name']) ?></td><td class="text-end fw-bold"><?= (int)$r['qty'] ?></td><td class="small"><?= h($r['note']) ?></td></tr>
                <?php endwhile; if (!$n) echo "<tr><td colspan='6' class='text-center text-muted py-3'>Nothing booked here yet.</td></tr>"; ?>
                </tbody></table></div>
        </div>
    </div>

    <script>
        const SELF = location.pathname, IS_OUT = <?= json_encode($IS_OUT) ?>, JOB = <?= (int)$job ?>;
        const KEY = 'cart_' + (IS_OUT ? 'out' : 'in') + '_' + JOB;
        const JOBS = <?= json_encode($jobsList) ?>;
        const $ = id => document.getElementById(id);
        const PH = 'data:image/svg+xml,' + encodeURIComponent("<svg xmlns='http://www.w3.org/2000/svg' width='40' height='40'><rect width='40' height='40' fill='#f1f5f9'/></svg>");
        const esc = s => String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));

        if (<?= json_encode(!empty($flash) && $flash[0] === 'success') ?>) sessionStorage.removeItem(KEY);   // a successful booking empties the list
        let cart = []; try { cart = JSON.parse(sessionStorage.getItem(KEY) || '[]'); } catch (e) {}

        function maxFor(it) { return IS_OUT ? it.stock_qty : (JOB > 0 ? it.sent : Infinity); }
        function say(msg, cls) { $('cartMsg').innerHTML = msg ? '<span class="text-' + (cls || 'danger') + '">' + msg + '</span>' : ''; }
        function save() { sessionStorage.setItem(KEY, JSON.stringify(cart)); render(); }

        function addToCart(it, qty) {
            qty = parseInt(qty) || 1; const max = maxFor(it); const ex = cart.find(c => c.id === it.id);
            let n = (ex ? ex.qty : 0) + qty; say('');
            if (n > max) { n = max; say((IS_OUT ? 'Only ' + max + ' in store' : 'Only ' + max + ' sent to this job') + ' for ' + esc(it.item_code) + '.'); }
            if (n <= 0) { say(esc(it.item_code) + ': none available.'); return; }
            if (ex) { ex.qty = n; ex.stock_qty = it.stock_qty; ex.sent = it.sent; } else cart.push({ id: it.id, item_code: it.item_code, item_name: it.item_name, img: it.img, stock_qty: it.stock_qty, sent: it.sent, qty: n });
            save();
        }
        function render() {
            const body = $('cartBody');
            if (!cart.length) body.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3">The list is empty. Add items from the table above or search for an item.</td></tr>';
            else body.innerHTML = cart.map((c, i) => `<tr>
                <td><img class="thumb" src="${esc(c.img || PH)}" onerror="this.onerror=null;this.src=PH"></td>
                <td class="fw-semibold text-primary">${esc(c.item_code)}</td><td class="small text-uppercase">${esc(c.item_name)}</td>
                <td class="text-end">${c.stock_qty}</td>
                <td><input type="number" class="form-control form-control-sm qty-in" min="1" value="${c.qty}" onchange="setQty(${i}, this.value)"></td>
                <td><button type="button" class="btn btn-sm text-danger" onclick="removeLine(${i})"><i class="fa-solid fa-xmark"></i></button></td></tr>`).join('');
            const pcs = cart.reduce((s, c) => s + c.qty, 0);
            $('btnBook').disabled = !cart.length;
            $('btnBook').textContent = cart.length ? 'Book all (' + cart.length + ' item' + (cart.length > 1 ? 's' : '') + ', ' + pcs + ' pcs)' : 'Book all';
        }
        function setQty(i, v) {
            const c = cart[i], max = maxFor(c); let n = parseInt(v) || 1; say('');
            if (n > max) { n = max; say('Limited to ' + max + ' for ' + esc(c.item_code) + '.'); }
            c.qty = Math.max(1, n); save();
        }
        function removeLine(i) { cart.splice(i, 1); save(); }
        $('bookForm').addEventListener('submit', e => {
            if (!cart.length) { e.preventDefault(); return; }
            if (!confirm('Book ' + cart.length + ' item(s) for <?= addslashes(h($scopeName)) ?>?')) { e.preventDefault(); return; }
            $('cart_json').value = JSON.stringify(cart.map(c => ({ id: c.id, qty: c.qty })));
            sessionStorage.removeItem(KEY);
        });

        // "Add" buttons on the job / box items table
        document.querySelectorAll('tr[data-item]').forEach(tr => {
            const it = JSON.parse(tr.dataset.item), q = tr.querySelector('.row-qty');
            tr.querySelector('.row-add').addEventListener('click', () => addToCart(it, q.value));
        });

        // find any item: every letter shows matches
        let timer;
        function bindSearch(inp, box, fetchUrl, render, pick) {
            inp.addEventListener('input', () => {
                clearTimeout(timer);
                timer = setTimeout(() => {
                    const v = inp.value.trim(); if (!v) { box.style.display = 'none'; return; }
                    fetchUrl(v).then(list => {
                        box.innerHTML = list.length ? '' : '<div class="text-muted">No matches</div>';
                        list.forEach(x => { const d = document.createElement('div'); d.innerHTML = render(x); d.onclick = () => { box.style.display = 'none'; pick(x); }; box.appendChild(d); });
                        box.style.display = 'block';
                    });
                }, 150);
            });
            inp.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); const f = box.querySelector('div'); if (f && f.onclick) f.onclick(); } });
            document.addEventListener('click', e => { if (!box.contains(e.target) && e.target !== inp) box.style.display = 'none'; });
        }
        bindSearch($('search'), $('acb'),
            v => fetch(SELF + '?a=search&job=' + JOB + '&q=' + encodeURIComponent(v)).then(r => r.json()),
            it => `<img src="${esc(it.img || PH)}" onerror="this.onerror=null;this.src=PH"><span><b>${esc(it.item_code)}</b> — ${esc(it.item_name)} <small class="text-muted">(store ${it.stock_qty})</small></span>`,
            it => { addToCart(it, $('addq').value); $('search').value = ''; $('search').focus(); });
        bindSearch($('jobSearch'), $('jobAc'),
            v => Promise.resolve(JOBS.filter(j => (j.job_no + ' ' + j.customer).toLowerCase().includes(v.toLowerCase())).slice(0, 12)),
            j => `<span><b>${esc(j.job_no)}</b> <small class="text-muted">${esc(j.customer)} · ${esc(j.status)}</small></span>`,
            j => { location.href = SELF + '?job=' + j.id; });

        render();
    </script>
</body>
</html>
