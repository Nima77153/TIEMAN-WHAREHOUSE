<?php
ob_start();
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');
session_start();
include('../config/db.php');

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use Dompdf\Dompdf;

$q = fn($s) => mysqli_query($conn, $s);
$e = fn($s) => mysqli_real_escape_string($conn, trim((string)$s));
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
function ph() { return 'data:image/svg+xml,' . rawurlencode("<svg xmlns='http://www.w3.org/2000/svg' width='64' height='64'><rect width='64' height='64' fill='#f1f5f9'/><text x='32' y='36' font-size='10' text-anchor='middle' fill='#94a3b8'>No Img</text></svg>"); }
function imgFs($n) { // real file path on disk, or null
    $n = trim(strip_tags((string)$n)); if ($n === '' || preg_match('~^https?://~i', $n)) return null;
    $b = basename(str_replace('\\', '/', $n));
    foreach (["../uploads/items/$b", "../uploads/$b", "../$n", "../uploads/items/$n"] as $p) if (is_file($p)) return $p;
    return null;
}
function img($n) { $n = trim((string)$n); if (preg_match('~^https?://~i', $n)) return $n; $p = imgFs($n); return $p ?: ph(); }

// ---------- schema ----------
if (!mysqli_num_rows($q("SHOW COLUMNS FROM job_items LIKE 'qty'"))) $q("ALTER TABLE job_items ADD qty INT NOT NULL DEFAULT 1");
$q("CREATE TABLE IF NOT EXISTS stock_moves (id INT AUTO_INCREMENT PRIMARY KEY, item_id INT NOT NULL, direction VARCHAR(3) NOT NULL,
    source VARCHAR(5) NOT NULL, job_id INT NULL, qty INT NOT NULL, note TEXT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
$q("CREATE TABLE IF NOT EXISTS report_notes (id INT AUTO_INCREMENT PRIMARY KEY, scope VARCHAR(10) NOT NULL, ref_id INT NULL,
    notes TEXT NULL, file_name VARCHAR(255) NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");

// ---------- shared filters ----------
$view = $_GET['view'] ?? 'job';
$job_id = (int)($_GET['job'] ?? 0);
$cats = []; $cr = $q("SELECT DISTINCT category FROM items WHERE category IS NOT NULL AND category<>'' ORDER BY category");
while ($cr && $r = mysqli_fetch_assoc($cr)) $cats[] = $r['category'];
$tiemanCat = ''; foreach ($cats as $c) if (stripos($c, 'tieman') !== false) { $tiemanCat = $c; break; }
$cat = $_GET['cat'] ?? $tiemanCat;          // default = the Tieman category
if ($cat === '*') $cat = '';                // '*' = all categories
$sq = trim($_GET['sq'] ?? '');
function storeWhere($cat, $sq, $e) {
    $w = [];
    if ($cat !== '') $w[] = "i.category='" . $e($cat) . "'";
    if ($sq !== '') { $s = $e($sq); $w[] = "(i.item_code LIKE '%$s%' OR i.item_name LIKE '%$s%' OR i.barcode LIKE '%$s%')"; }
    return $w ? 'WHERE ' . implode(' AND ', $w) : '';
}
$INOUT = "COALESCE((SELECT SUM(qty) FROM stock_moves m WHERE m.item_id=i.id AND m.direction='in'),0) AS tin,
          COALESCE((SELECT SUM(qty) FROM stock_moves m WHERE m.item_id=i.id AND m.direction='out'),0) AS tout";

// ---------- AJAX ----------
if (isset($_REQUEST['a'])) {
    ob_clean(); header('Content-Type: application/json');
    $a = $_REQUEST['a'];
    if ($a === 'search') {
        $s = $e($_GET['q'] ?? ''); $out = [];
        $r = $q("SELECT id,item_code,item_name,image,stock_qty FROM items WHERE item_code LIKE '%$s%' OR item_name LIKE '%$s%' OR barcode LIKE '%$s%' ORDER BY item_code LIMIT 10");
        while ($r && $x = mysqli_fetch_assoc($r)) { $x['img'] = img($x['image']); $x['item_name'] = (string)$x['item_name']; $out[] = $x; }
        exit(json_encode($out));
    }
    if ($a === 'add_job_item') {
        $j = (int)$_POST['job_id']; $i = (int)$_POST['item_id']; $n = max(1, (int)$_POST['qty']);
        if (mysqli_num_rows($q("SELECT 1 FROM job_items WHERE job_id=$j AND item_id=$i"))) $q("UPDATE job_items SET qty=qty+$n WHERE job_id=$j AND item_id=$i");
        else $q("INSERT INTO job_items (job_id,item_id,qty) VALUES ($j,$i,$n)");
    }
    if ($a === 'upd_job_item') $q("UPDATE job_items SET qty=" . max(0, (int)$_POST['qty']) . " WHERE job_id=" . (int)$_POST['job_id'] . " AND item_id=" . (int)$_POST['item_id']);
    if ($a === 'del_job_item') foreach (explode(',', $_POST['ids']) as $i) $q("DELETE FROM job_items WHERE job_id=" . (int)$_POST['job_id'] . " AND item_id=" . (int)$i);
    if ($a === 'job_status') $q("UPDATE jobs SET status='" . $e($_POST['status']) . "' WHERE id=" . (int)$_POST['job_id']);
    if ($a === 'upd_item') $q("UPDATE items SET item_code='" . $e($_POST['item_code']) . "', item_name='" . $e($_POST['item_name']) . "', stock_qty=" . (int)$_POST['stock_qty'] . " WHERE id=" . (int)$_POST['id']);
    if ($a === 'del_item') foreach (explode(',', $_POST['ids']) as $i) $q("DELETE FROM items WHERE id=" . (int)$i);
    if ($a === 'move') {
        $i = (int)$_POST['item_id']; $n = max(1, (int)$_POST['qty']); $d = $_POST['direction'] === 'out' ? 'out' : 'in';
        $src = $_POST['source'] === 'job' ? 'job' : 'store'; $j = $src === 'job' ? (int)$_POST['job_id'] : 'NULL';
        $q("INSERT INTO stock_moves (item_id,direction,source,job_id,qty,note) VALUES ($i,'$d','$src',$j,$n,'" . $e($_POST['note'] ?? '') . "')");
        $q("UPDATE items SET stock_qty = GREATEST(0, stock_qty " . ($d === 'in' ? '+' : '-') . " $n) WHERE id=$i");
    }
    if ($a === 'save_note') {
        $f = '';
        if (!empty($_FILES['file']['name']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
            $dir = '../uploads/notes/'; if (!is_dir($dir)) mkdir($dir, 0777, true);
            $f = 'note_' . time() . '_' . rand(100, 999) . '.' . strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
            move_uploaded_file($_FILES['file']['tmp_name'], $dir . $f);
        }
        $q("INSERT INTO report_notes (scope,ref_id,notes,file_name) VALUES ('" . $e($_POST['scope']) . "'," . (int)$_POST['ref_id'] . ",'" . $e($_POST['notes']) . "','" . $e($f) . "')");
    }
    exit(json_encode(['ok' => true]));
}

// ---------- EXPORT (Excel / PDF) ----------
if (isset($_GET['export'])) {
    $vend = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($vend)) { ob_end_clean(); exit('Export needs the libraries. Run: composer require phpoffice/phpspreadsheet dompdf/dompdf  (then redeploy).'); }
    require $vend; ob_end_clean();
    $fmt = $_GET['export'] === 'pdf' ? 'pdf' : 'xlsx'; $rows = [];
    if (($_GET['type'] ?? '') === 'job') {
        $jr = mysqli_fetch_assoc($q("SELECT * FROM jobs WHERE id=$job_id"));
        $title = 'Job ' . $jr['job_no'] . ' - ' . $jr['customer_name']; $extra = ['QTY'];
        $res = $q("SELECT i.item_code, i.item_name, i.image, ji.qty FROM job_items ji JOIN items i ON i.id=ji.item_id WHERE ji.job_id=$job_id ORDER BY i.item_code");
        while ($res && $r = mysqli_fetch_assoc($res)) $rows[] = [$r['item_code'], $r['item_name'], $r['image'], [(int)$r['qty']]];
    } else {
        $title = 'Store' . ($cat ? " - $cat" : ' - All'); $extra = ['IN', 'OUT', 'BALANCE'];
        $res = $q("SELECT i.item_code, i.item_name, i.image, i.stock_qty, $INOUT FROM items i " . storeWhere($cat, $sq, $e) . " ORDER BY i.item_code");
        while ($res && $r = mysqli_fetch_assoc($res)) $rows[] = [$r['item_code'], $r['item_name'], $r['image'], [(int)$r['tin'], (int)$r['tout'], (int)$r['stock_qty']]];
    }
    $file = preg_replace('/[^A-Za-z0-9_-]+/', '_', $title);
    if ($fmt === 'pdf') {
        $hd = "<style>body{font-family:sans-serif;font-size:11px}table{width:100%;border-collapse:collapse}th{background:#6ee7f9;border:1px solid #666;padding:5px}
               td{border:1px solid #999;padding:4px;text-align:center}td.d{text-align:left}img{width:50px;height:50px}</style><h3>" . h($title) . "</h3>
               <table><tr><th>NO</th><th>PART NO.</th><th>IMAGE</th><th>DESCRIPTION</th><th>" . implode('</th><th>', $extra) . "</th></tr>";
        foreach ($rows as $i => $r) {
            $p = imgFs($r[2]); $src = $p ? 'data:image/' . pathinfo($p, PATHINFO_EXTENSION) . ';base64,' . base64_encode(file_get_contents($p)) : '';
            $hd .= '<tr><td>' . ($i + 1) . '</td><td>' . h($r[0]) . '</td><td>' . ($src ? "<img src='$src'>" : '') . '</td><td class="d">' . h($r[1]) . '</td><td>' . implode('</td><td>', $r[3]) . '</td></tr>';
        }
        $pdf = new Dompdf(); $pdf->loadHtml($hd . '</table>'); $pdf->setPaper('A4'); $pdf->render(); $pdf->stream("$file.pdf"); exit;
    }
    $ss = new Spreadsheet(); $sh = $ss->getActiveSheet();
    $heads = array_merge(['NO', 'PART NO.', 'IMAGE', 'DESCRIPTION'], $extra); $lastCol = chr(64 + count($heads));
    foreach ($heads as $i => $t) $sh->setCellValue(chr(65 + $i) . '1', $t);
    $sh->getStyle("A1:{$lastCol}1")->getFont()->setBold(true);
    $sh->getStyle("A1:{$lastCol}1")->getFill()->setFillType('solid')->getStartColor()->setRGB('6EE7F9');
    foreach (['A' => 6, 'B' => 24, 'C' => 16, 'D' => 55] as $c => $w) $sh->getColumnDimension($c)->setWidth($w);
    foreach ($rows as $i => $r) {
        $row = $i + 2; $sh->setCellValue("A$row", $i + 1); $sh->setCellValue("B$row", $r[0]); $sh->setCellValue("D$row", $r[1]);
        foreach ($r[3] as $k => $v) $sh->setCellValue(chr(69 + $k) . $row, $v);
        $sh->getRowDimension($row)->setRowHeight(60);
        if ($p = imgFs($r[2])) { $d = new Drawing(); $d->setPath($p); $d->setCoordinates("C$row"); $d->setHeight(75); $d->setOffsetX(5); $d->setOffsetY(3); $d->setWorksheet($sh); }
    }
    $last = count($rows) + 1;
    $sh->getStyle("A1:$lastCol$last")->getAlignment()->setVertical('center')->setHorizontal('center')->setWrapText(true);
    $sh->getStyle("D2:D$last")->getAlignment()->setHorizontal('left');
    $sh->getStyle("A1:$lastCol$last")->getBorders()->getAllBorders()->setBorderStyle('thin');
    $sh->setAutoFilter("A1:$lastCol$last");
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header("Content-Disposition: attachment; filename=\"$file.xlsx\""); (new Xlsx($ss))->save('php://output'); exit;
}

// ---------- page data ----------
function pager($p, $pages, $base) {
    if ($pages <= 1) return '';
    $o = "<nav class='mt-3'><ul class='pagination pagination-sm justify-content-center mb-0'>";
    for ($i = 1; $i <= $pages; $i++) $o .= "<li class='page-item" . ($i == $p ? ' active' : '') . "'><a class='page-link' href='$base&p=$i'>$i</a></li>";
    return $o . '</ul></nav>';
}
$p = max(1, (int)($_GET['p'] ?? 1));
$jobs_all = []; $jr = $q("SELECT id, job_no FROM jobs ORDER BY id DESC");
while ($jr && $r = mysqli_fetch_assoc($jr)) $jobs_all[] = $r;
$st = mysqli_fetch_assoc($q("SELECT COUNT(*) t, SUM(stock_qty=0) o, SUM(stock_qty>0 AND stock_qty<=5) l FROM items"));
$jt = mysqli_fetch_assoc($q("SELECT COUNT(*) t FROM jobs"));
$tab = fn($v, $l) => "<a class='nav-link " . ($view === $v ? 'active' : '') . "' href='?view=$v'>$l</a>";
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Warehouse - Report Hub</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
body{background:#eef2f7;font-family:'Segoe UI',sans-serif}
/* ===== SIDEBAR (unchanged) ===== */
.sidebar{width:260px;height:100vh;background:#1a2232;position:fixed;left:0;top:0;overflow-y:auto;z-index:100}
.logo{background:#f97316;padding:18px 20px;text-align:center;font-size:20px;font-weight:bold;color:#fff;letter-spacing:.5px}
.sidebar-menu{list-style:none;padding:0;margin:0}
.sidebar a{display:flex;align-items:center;padding:13px 20px;color:#d1d5db;text-decoration:none;font-size:15px;font-weight:500;transition:background .2s,color .2s;border-left:4px solid transparent}
.sidebar a i{font-size:18px;width:30px;text-align:center;margin-right:12px;color:#9ca3af;transition:color .2s}
.sidebar a:hover{background:#131924;color:#fff}.sidebar a:hover i{color:#fff}
.sidebar a.active{background:#131924;color:#fff;border-left:4px solid #f97316}.sidebar a.active i{color:#fff}
.main{margin-left:260px;padding:20px}
@media(max-width:768px){.sidebar{display:none}.main{margin-left:0;padding:10px}}
/* ===== HUB ===== */
.panel{background:#fff;border-radius:12px;padding:16px;box-shadow:0 1px 3px rgba(0,0,0,.06);margin-bottom:16px}
.stat{border-radius:12px;padding:14px 18px;color:#fff;font-weight:600}.stat b{display:block;font-size:26px}
.folder{display:block;background:#fff;border-radius:12px;padding:18px;text-decoration:none;color:#1e293b;border:1px solid #e2e8f0;transition:.15s;height:100%}
.folder:hover{border-color:#f97316;transform:translateY(-2px)}.folder i{font-size:34px;color:#f59e0b}
.xl{border-collapse:collapse;width:100%;background:#fff;font-size:13px}
.xl th{background:#6ee7f9;border:1px solid #94a3b8;padding:8px;text-align:center;position:sticky;top:0}
.xl td{border:1px solid #cbd5e1;padding:4px 8px;vertical-align:middle;text-align:center}
.xl tbody tr:nth-child(odd){background:#dde7f3}.xl tbody tr:nth-child(even){background:#b9cde6}
.xl td.desc{text-align:left;text-transform:uppercase}
.xl input.cell{width:100%;border:0;background:transparent;text-align:inherit;padding:2px}
.xl input.cell:focus{background:#fff;outline:2px solid #f97316}
.low{color:#dc2626;font-weight:700}
.thumb{width:64px;height:64px;object-fit:contain;background:#fff;border:1px solid #cbd5e1;border-radius:6px}
.ac{position:absolute;z-index:50;background:#fff;border:1px solid #cbd5e1;border-radius:8px;width:100%;max-height:320px;overflow:auto;display:none;box-shadow:0 8px 20px rgba(0,0,0,.15);top:100%;left:0}
.ac div{display:flex;gap:10px;align-items:center;padding:6px 10px;cursor:pointer;font-size:13px}.ac div:hover{background:#fff7ed}
.ac img{width:38px;height:38px;object-fit:contain}
.nav-pills .nav-link{color:#334155;font-weight:600}.nav-link.active{background:#f97316!important;color:#fff!important}
</style></head><body>

<div class="sidebar">
  <div class="logo">WAREHOUSE</div>
  <div class="sidebar-menu">
    <a href="../dashboard.php"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
    <a href="../items/item_list.php"><i class="fa-solid fa-box-archive"></i> Items</a>
    <a href="../items/add_item.php"><i class="fa-solid fa-plus"></i> Add Item</a>
    <a href="../import_excel.php"><i class="fa-solid fa-file-import"></i> Import Excel</a>
    <a href="../create_job.php"><i class="fa-solid fa-file-circle-plus"></i> Create Job</a>
    <a href="../job_list.php"><i class="fa-solid fa-file-lines"></i> Job List</a>
    <a href="../stock/stock_in.php"><i class="fa-solid fa-arrow-trend-up"></i> Stock In</a>
    <a href="../items/stock_out.php"><i class="fa-solid fa-arrow-trend-down"></i> Stock Out</a>
    <a href="../return_item.php"><i class="fa-solid fa-rotate-left"></i> Returns</a>
    <a href="../stock/missing_item.php"><i class="fa-solid fa-triangle-exclamation"></i> Missing</a>
    <a href="../scaner.php"><i class="fa-solid fa-barcode"></i> Scanner</a>
    <a href="stock_report.php" class="active"><i class="fa-solid fa-chart-pie"></i> Reports</a>
    <a href="../logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
  </div>
</div>

<div class="main">
<div class="panel d-flex flex-wrap gap-2 justify-content-between align-items-center">
  <h4 class="m-0 fw-bold">📊 Report Hub</h4>
  <nav class="nav nav-pills"><?= $tab('job', '📁 Job Folders') . $tab('store', '🏢 Tieman Store') . $tab('in', '📈 Stock In') . $tab('out', '📉 Stock Out') ?></nav>
</div>
<div class="row g-3 mb-3">
  <div class="col-6 col-md-3"><div class="stat" style="background:#3b82f6"><b><?= (int)$st['t'] ?></b>Total items</div></div>
  <div class="col-6 col-md-3"><div class="stat" style="background:#f97316"><b><?= (int)$jt['t'] ?></b>Job folders</div></div>
  <div class="col-6 col-md-3"><div class="stat" style="background:#eab308"><b><?= (int)$st['l'] ?></b>Low stock (≤5)</div></div>
  <div class="col-6 col-md-3"><div class="stat" style="background:#ef4444"><b><?= (int)$st['o'] ?></b>Out of stock</div></div>
</div>

<?php if ($view === 'job'): ?>
  <?php if (!$job_id):
    $jq = trim($_GET['jq'] ?? ''); $w = $jq !== '' ? "WHERE j.job_no LIKE '%" . $e($jq) . "%' OR j.customer_name LIKE '%" . $e($jq) . "%'" : '';
    $per = 10; $total = (int)mysqli_fetch_assoc($q("SELECT COUNT(*) c FROM jobs j $w"))['c']; $pages = max(1, ceil($total / $per)); $p = min($p, $pages);
    $res = $q("SELECT j.id,j.job_no,j.customer_name,j.status,(SELECT COUNT(*) FROM job_items ji WHERE ji.job_id=j.id) n FROM jobs j $w ORDER BY j.id DESC LIMIT $per OFFSET " . (($p - 1) * $per)); ?>
    <div class="panel">
      <form class="input-group mb-3" style="max-width:420px"><input type="hidden" name="view" value="job">
        <input name="jq" value="<?= h($jq) ?>" class="form-control" placeholder="Search job no / customer...">
        <button class="btn btn-warning"><i class="fa-solid fa-magnifying-glass"></i></button></form>
      <div class="row g-3">
      <?php while ($res && $j = mysqli_fetch_assoc($res)): ?>
        <div class="col-6 col-md-4 col-xl"><a class="folder" href="?view=job&job=<?= $j['id'] ?>"><i class="fa-solid fa-folder-open"></i>
          <div class="fw-bold mt-2"><?= h($j['job_no']) ?></div><div class="small text-muted"><?= h($j['customer_name']) ?: '—' ?></div>
          <span class="badge bg-dark mt-2"><?= (int)$j['n'] ?> items</span> <span class="badge bg-secondary"><?= h($j['status']) ?></span></a></div>
      <?php endwhile; if (!$total) echo "<p class='text-muted m-0'>No jobs found.</p>"; ?>
      </div>
      <?= pager($p, $pages, '?view=job&jq=' . urlencode($jq)) ?>
      <div class="text-center small text-muted mt-2">Showing latest 10 per page · <?= $total ?> jobs total</div>
    </div>
  <?php else:
    $jrow = mysqli_fetch_assoc($q("SELECT * FROM jobs WHERE id=$job_id"));
    $rows = $q("SELECT i.id,i.item_code,i.item_name,i.image,ji.qty FROM job_items ji JOIN items i ON i.id=ji.item_id WHERE ji.job_id=$job_id ORDER BY i.item_code"); ?>
    <div class="panel">
      <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
        <div><a href="?view=job" class="btn btn-sm btn-light">← Folders</a>
          <b class="ms-2">📁 <?= h($jrow['job_no']) ?></b> <span class="text-muted"><?= h($jrow['customer_name']) ?></span></div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
          <select class="form-select form-select-sm w-auto" onchange="post('job_status',{job_id:<?= $job_id ?>,status:this.value})">
            <?php foreach (['In Progress', 'Done', 'On Hold'] as $s) echo "<option" . ($jrow['status'] === $s ? ' selected' : '') . ">$s</option>"; ?></select>
          <select class="form-select form-select-sm w-auto" onchange="location='?view=job&job='+this.value">
            <?php foreach ($jobs_all as $j) echo "<option value='{$j['id']}'" . ($j['id'] == $job_id ? ' selected' : '') . ">" . h($j['job_no']) . "</option>"; ?></select>
          <a class="btn btn-sm btn-success" href="?export=xlsx&type=job&job=<?= $job_id ?>"><i class="fa-solid fa-file-excel"></i> Excel</a>
          <a class="btn btn-sm btn-danger" href="?export=pdf&type=job&job=<?= $job_id ?>"><i class="fa-solid fa-file-pdf"></i> PDF</a>
        </div>
      </div>
      <div class="d-flex gap-2 mb-3">
        <div class="input-group position-relative">
          <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
          <input id="s" class="form-control" placeholder="Type part no / description / barcode to add item..." autocomplete="off">
          <div class="ac" id="acb"></div></div>
        <input id="addq" type="number" min="1" value="1" class="form-control" style="width:80px" title="Qty">
        <button class="btn btn-danger text-nowrap" onclick="delSel('del_job_item',{job_id:<?= $job_id ?>})"><i class="fa-solid fa-trash"></i> Delete selected</button>
      </div>
      <div class="table-responsive"><table class="xl">
        <thead><tr><th style="width:50px">NO</th><th>PART NO.</th><th style="width:90px">IMAGE</th><th>DESCRIPTION</th><th style="width:90px">QTY</th>
          <th style="width:80px"><input type="checkbox" class="form-check-input" onclick="document.querySelectorAll('.sel').forEach(c=>c.checked=this.checked)"></th></tr></thead><tbody>
        <?php $n = 0; while ($rows && $r = mysqli_fetch_assoc($rows)): $n++; ?>
          <tr><td><b><?= $n ?></b></td><td><?= h($r['item_code']) ?></td>
          <td><img class="thumb" loading="lazy" src="<?= h(img($r['image'])) ?>" onerror="this.onerror=null;this.src=PH"></td>
          <td class="desc"><?= h($r['item_name']) ?></td>
          <td><input class="cell" type="number" value="<?= (int)$r['qty'] ?>" onchange="post('upd_job_item',{job_id:<?= $job_id ?>,item_id:<?= $r['id'] ?>,qty:this.value})"></td>
          <td><input type="checkbox" class="sel form-check-input" value="<?= $r['id'] ?>">
              <button class="btn btn-sm text-danger" onclick="delOne('del_job_item',{job_id:<?= $job_id ?>},<?= $r['id'] ?>)"><i class="fa-solid fa-trash"></i></button></td></tr>
        <?php endwhile; if (!$n) echo "<tr><td colspan='6' class='text-muted py-4'>Empty folder. Use the search bar to add items.</td></tr>"; ?>
        </tbody></table></div>
    </div>
    <?php $scope = 'job'; $ref = $job_id; endif; ?>

<?php elseif ($view === 'store'):
    $where = storeWhere($cat, $sq, $e); $per = 30;
    $total = (int)mysqli_fetch_assoc($q("SELECT COUNT(*) c FROM items i $where"))['c']; $pages = max(1, ceil($total / $per)); $p = min($p, $pages);
    $rows = $q("SELECT i.*, $INOUT FROM items i $where ORDER BY i.item_code LIMIT $per OFFSET " . (($p - 1) * $per)); ?>
  <div class="panel">
    <div class="d-flex flex-wrap gap-2 justify-content-between mb-3">
      <form class="d-flex gap-2"><input type="hidden" name="view" value="store">
        <select name="cat" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
          <option value="*"<?= $cat === '' ? ' selected' : '' ?>>All categories</option>
          <?php foreach ($cats as $c) echo "<option value='" . h($c) . "'" . ($c === $cat ? ' selected' : '') . ">" . h($c) . "</option>"; ?></select>
        <div class="input-group input-group-sm"><input name="sq" value="<?= h($sq) ?>" class="form-control" placeholder="Search part / description..."><button class="btn btn-warning"><i class="fa-solid fa-magnifying-glass"></i></button></div>
      </form>
      <div class="d-flex gap-2">
        <button class="btn btn-sm btn-danger" onclick="delSel('del_item',{})"><i class="fa-solid fa-trash"></i> Delete selected</button>
        <a class="btn btn-sm btn-success" href="?export=xlsx&type=store&cat=<?= urlencode($cat === '' ? '*' : $cat) ?>&sq=<?= urlencode($sq) ?>"><i class="fa-solid fa-file-excel"></i> Excel</a>
        <a class="btn btn-sm btn-danger" href="?export=pdf&type=store&cat=<?= urlencode($cat === '' ? '*' : $cat) ?>&sq=<?= urlencode($sq) ?>"><i class="fa-solid fa-file-pdf"></i> PDF</a>
      </div>
    </div>
    <div class="table-responsive"><table class="xl">
      <thead><tr><th>NO</th><th>PART NO.</th><th>IMAGE</th><th>DESCRIPTION</th><th>IN</th><th>OUT</th><th>BALANCE</th>
        <th><input type="checkbox" class="form-check-input" onclick="document.querySelectorAll('.sel').forEach(c=>c.checked=this.checked)"></th></tr></thead><tbody>
      <?php $n = ($p - 1) * $per; while ($rows && $r = mysqli_fetch_assoc($rows)): $n++; $id = $r['id']; ?>
        <tr><td><b><?= $n ?></b></td>
        <td><input class="cell" id="c<?= $id ?>" value="<?= h($r['item_code']) ?>" onchange="saveItem(<?= $id ?>)"></td>
        <td><img class="thumb" loading="lazy" src="<?= h(img($r['image'])) ?>" onerror="this.onerror=null;this.src=PH"></td>
        <td class="desc"><input class="cell" style="text-align:left" id="n<?= $id ?>" value="<?= h($r['item_name']) ?>" onchange="saveItem(<?= $id ?>)"></td>
        <td class="text-success fw-bold">+<?= (int)$r['tin'] ?></td><td class="text-danger fw-bold">−<?= (int)$r['tout'] ?></td>
        <td><input class="cell fw-bold <?= $r['stock_qty'] <= 5 ? 'low' : '' ?>" type="number" id="q<?= $id ?>" value="<?= (int)$r['stock_qty'] ?>" onchange="saveItem(<?= $id ?>)"></td>
        <td><input type="checkbox" class="sel form-check-input" value="<?= $id ?>">
            <button class="btn btn-sm text-danger" onclick="delOne('del_item',{},<?= $id ?>)"><i class="fa-solid fa-trash"></i></button></td></tr>
      <?php endwhile; if (!$total) echo "<tr><td colspan='8' class='text-muted py-4'>No items found.</td></tr>"; ?></tbody></table></div>
    <?= pager($p, $pages, '?view=store&cat=' . urlencode($cat === '' ? '*' : $cat) . '&sq=' . urlencode($sq)) ?>
  </div>
  <?php $scope = 'store'; $ref = 0; ?>

<?php else: $dir = $view === 'out' ? 'out' : 'in';
  $mv = $q("SELECT m.*, i.item_code, i.item_name, i.image, j.job_no FROM stock_moves m JOIN items i ON i.id=m.item_id LEFT JOIN jobs j ON j.id=m.job_id WHERE m.direction='$dir' ORDER BY m.id DESC LIMIT 100"); ?>
  <div class="panel">
    <h5 class="fw-bold"><?= $dir === 'in' ? '📈 Stock In' : '📉 Stock Out' ?></h5>
    <div class="row g-2 mb-2">
      <div class="col-md-5"><div class="input-group position-relative"><span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
        <input id="s" class="form-control" placeholder="Type to search item..." autocomplete="off"><div class="ac" id="acb"></div></div></div>
      <div class="col-6 col-md-1"><input id="mq" type="number" min="1" value="1" class="form-control" title="Qty"></div>
      <div class="col-6 col-md-2"><select id="msrc" class="form-select" onchange="mjob.style.display=this.value=='job'?'block':'none'"><option value="store">From Store</option><option value="job">From Job</option></select></div>
      <div class="col-md-2"><select id="mjob" class="form-select" style="display:none"><?php foreach ($jobs_all as $j) echo "<option value='{$j['id']}'>" . h($j['job_no']) . "</option>"; ?></select></div>
      <div class="col-md-2"><input id="mnote" class="form-control" placeholder="Note"></div>
    </div>
    <div id="picked" class="mb-2 small text-muted">Search and pick an item, then press the button. Stock balance updates automatically.</div>
    <button class="btn btn-<?= $dir === 'in' ? 'success' : 'danger' ?>" onclick="doMove('<?= $dir ?>')">Record Stock <?= strtoupper($dir) ?></button>
    <div class="table-responsive mt-3"><table class="xl"><thead><tr><th>DATE</th><th>PART NO.</th><th>IMAGE</th><th>DESCRIPTION</th><th>QTY</th><th>SOURCE</th></tr></thead><tbody>
    <?php while ($mv && $r = mysqli_fetch_assoc($mv)): ?>
      <tr><td><?= date('d M Y H:i', strtotime($r['created_at'])) ?></td><td><?= h($r['item_code']) ?></td>
      <td><img class="thumb" loading="lazy" src="<?= h(img($r['image'])) ?>" onerror="this.onerror=null;this.src=PH"></td><td class="desc"><?= h($r['item_name']) ?></td>
      <td><b><?= (int)$r['qty'] ?></b></td><td><?= $r['source'] === 'job' ? 'Job ' . h($r['job_no']) : 'Store' ?></td></tr>
    <?php endwhile; ?></tbody></table></div>
  </div>
  <?php $scope = $dir; $ref = 0; ?>
<?php endif; ?>

<?php if ($view !== 'job' || $job_id):
  $nr = $q("SELECT * FROM report_notes WHERE scope='" . $e($scope) . "' AND ref_id=" . (int)$ref . " ORDER BY id DESC LIMIT 20"); ?>
  <div class="panel">
    <h6 class="fw-bold">📝 Notes &amp; Files</h6>
    <textarea id="nt" class="form-control mb-2" rows="3" placeholder="Write notes..."></textarea>
    <div class="d-flex flex-wrap gap-2 mb-2">
      <input type="file" id="nf" class="form-control" style="max-width:320px" accept="image/*,.pdf,.xls,.xlsx,.doc,.docx">
      <label class="btn btn-outline-dark mb-0"><i class="fa-solid fa-camera"></i> Camera
        <input type="file" accept="image/*" capture="environment" hidden onchange="document.getElementById('nf').files=this.files"></label>
      <button class="btn btn-success" onclick="saveNote('<?= $scope ?>',<?= (int)$ref ?>)">💾 Save</button>
    </div>
    <?php while ($nr && $r = mysqli_fetch_assoc($nr)): ?>
      <div class="border rounded p-2 mt-2 small"><span class="text-muted"><?= date('d M Y H:i', strtotime($r['created_at'])) ?></span>
      <div style="white-space:pre-wrap"><?= h($r['notes']) ?></div>
      <?php if ($r['file_name']): ?><a target="_blank" href="../uploads/notes/<?= rawurlencode($r['file_name']) ?>">📎 <?= h($r['file_name']) ?></a><?php endif; ?></div>
    <?php endwhile; ?>
  </div>
<?php endif; ?>
</div>

<script>
const PH = <?= json_encode(ph()) ?>, VIEW = <?= json_encode($view) ?>, JOB = <?= (int)$job_id ?>, SELF = location.pathname;
let picked = null;
function post(a, d, files) {
  const fd = new FormData(); fd.append('a', a);
  for (const k in d) fd.append(k, d[k]);
  if (files) for (const k in files) fd.append(k, files[k]);
  return fetch(SELF, {method: 'POST', body: fd}).then(r => r.json());
}
const gc = id => document.getElementById('c' + id), gn = id => document.getElementById('n' + id), gq = id => document.getElementById('q' + id);
function saveItem(id) { post('upd_item', {id, item_code: gc(id).value, item_name: gn(id).value, stock_qty: gq(id).value}); }
function delOne(a, extra, id) { if (confirm('Delete this row?')) post(a, {...extra, ids: id}).then(() => location.reload()); }
function delSel(a, extra) {
  const ids = [...document.querySelectorAll('.sel:checked')].map(x => x.value);
  if (!ids.length) return alert('Tick at least one row first.');
  if (confirm('Delete ' + ids.length + ' row(s)?')) post(a, {...extra, ids: ids.join(',')}).then(() => location.reload());
}
function saveNote(scope, ref) {
  const f = document.getElementById('nf').files[0];
  post('save_note', {scope, ref_id: ref, notes: document.getElementById('nt').value}, f ? {file: f} : null).then(() => location.reload());
}
function doMove(dir) {
  if (!picked) return alert('Pick an item from the search list first.');
  post('move', {item_id: picked.id, direction: dir, source: msrc.value, job_id: mjob.value, qty: mq.value, note: mnote.value}).then(() => location.reload());
}
const s = document.getElementById('s'), box = document.getElementById('acb');
let results = [], timer;
if (s) {
  s.addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
      const v = s.value.trim();
      if (!v) { box.style.display = 'none'; return; }
      fetch(SELF + '?a=search&q=' + encodeURIComponent(v)).then(r => r.json()).then(list => {
        results = list; box.innerHTML = '';
        if (!list.length) box.innerHTML = '<div class="text-muted">No matching items</div>';
        list.forEach(it => {
          const d = document.createElement('div');
          d.innerHTML = `<img src="${it.img}" onerror="this.onerror=null;this.src=PH"><span><b>${it.item_code}</b> — ${it.item_name} <small class="text-muted">(stock ${it.stock_qty})</small></span>`;
          d.onclick = () => pick(it); box.appendChild(d);
        });
        box.style.display = 'block';
      }).catch(() => { box.innerHTML = '<div class="text-danger">Search failed</div>'; box.style.display = 'block'; });
    }, 200);
  });
  s.addEventListener('keydown', ev => { if (ev.key === 'Enter' && results.length) { ev.preventDefault(); pick(results[0]); } });
  document.addEventListener('click', ev => { if (!box.contains(ev.target) && ev.target !== s) box.style.display = 'none'; });
}
function pick(it) {
  box.style.display = 'none';
  if (VIEW === 'job') post('add_job_item', {job_id: JOB, item_id: it.id, qty: document.getElementById('addq').value}).then(() => location.reload());
  else { picked = it; s.value = it.item_code; document.getElementById('picked').innerHTML = 'Selected: <b>' + it.item_code + '</b> — ' + it.item_name + ' (current stock ' + it.stock_qty + ')'; }
}
</script></body></html>
