<?php
session_start();
include('../config/db.php');
$q = fn($s) => mysqli_query($conn, $s);
$e = fn($s) => mysqli_real_escape_string($conn, trim((string)$s));

// ---------- schema ----------
if (!mysqli_num_rows($q("SHOW COLUMNS FROM job_items LIKE 'qty'"))) $q("ALTER TABLE job_items ADD qty INT NOT NULL DEFAULT 1");
$q("CREATE TABLE IF NOT EXISTS stock_moves (id INT AUTO_INCREMENT PRIMARY KEY, item_id INT NOT NULL, direction VARCHAR(3) NOT NULL,
    source VARCHAR(5) NOT NULL, job_id INT NULL, qty INT NOT NULL, note TEXT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
$q("CREATE TABLE IF NOT EXISTS report_notes (id INT AUTO_INCREMENT PRIMARY KEY, scope VARCHAR(10) NOT NULL, ref_id INT NULL,
    notes TEXT NULL, file_name VARCHAR(255) NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");

function img($n) {
    $n = trim(strip_tags((string)$n));
    if ($n && file_exists("../uploads/items/$n")) return "../uploads/items/$n";
    if ($n && file_exists("../uploads/$n")) return "../uploads/$n";
    return '../assets/images/no-image.png';
}

// ---------- AJAX ----------
if (isset($_REQUEST['a'])) {
    header('Content-Type: application/json');
    $a = $_REQUEST['a']; $ok = ['ok' => true];
    if ($a === 'search') {
        $s = $e($_GET['q'] ?? ''); $out = [];
        $r = $q("SELECT id,item_code,item_name,image,stock_qty FROM items WHERE item_code LIKE '%$s%' OR item_name LIKE '%$s%' OR barcode LIKE '%$s%' ORDER BY item_code LIMIT 8");
        while ($r && $x = mysqli_fetch_assoc($r)) { $x['img'] = img($x['image']); $out[] = $x; }
        exit(json_encode($out));
    }
    if ($a === 'add_job_item') {
        $j = (int)$_POST['job_id']; $i = (int)$_POST['item_id']; $n = max(1, (int)$_POST['qty']);
        $x = $q("SELECT 1 FROM job_items WHERE job_id=$j AND item_id=$i");
        if (mysqli_num_rows($x)) $q("UPDATE job_items SET qty=qty+$n WHERE job_id=$j AND item_id=$i");
        else $q("INSERT INTO job_items (job_id,item_id,qty) VALUES ($j,$i,$n)");
    }
    if ($a === 'upd_job_item') $q("UPDATE job_items SET qty=" . max(0, (int)$_POST['qty']) . " WHERE job_id=" . (int)$_POST['job_id'] . " AND item_id=" . (int)$_POST['item_id']);
    if ($a === 'del_job_item') foreach (explode(',', $_POST['ids']) as $i) $q("DELETE FROM job_items WHERE job_id=" . (int)$_POST['job_id'] . " AND item_id=" . (int)$i);
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
    exit(json_encode($ok));
}

// ---------- page data ----------
$view = $_GET['view'] ?? 'job';
$job_id = (int)($_GET['job'] ?? 0);
$cat = $_GET['cat'] ?? '';
$jobs_r = $q("SELECT j.id, j.job_no, j.customer_name, j.status, (SELECT COUNT(*) FROM job_items ji WHERE ji.job_id=j.id) AS n FROM jobs j ORDER BY j.id DESC");
$jobs = []; while ($jobs_r && $r = mysqli_fetch_assoc($jobs_r)) $jobs[] = $r;
$cats_r = $q("SELECT DISTINCT category FROM items WHERE category IS NOT NULL AND category<>'' ORDER BY category");
$cats = []; while ($cats_r && $r = mysqli_fetch_assoc($cats_r)) $cats[] = $r['category'];
$tab = fn($v, $l) => "<a class='nav-link " . ($view === $v ? 'active' : '') . "' href='?view=$v'>$l</a>";
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Warehouse - Report Hub</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
body{background:#eef2f7;font-family:'Segoe UI',sans-serif}
.main{margin-left:260px;padding:20px}
@media(max-width:768px){.main{margin-left:0}}
.panel{background:#fff;border-radius:10px;padding:16px;box-shadow:0 1px 3px rgba(0,0,0,.06);margin-bottom:16px}
.folder{display:block;background:#fff;border-radius:12px;padding:18px;text-decoration:none;color:#1e293b;border:1px solid #e2e8f0;transition:.15s}
.folder:hover{border-color:#f97316;transform:translateY(-2px)}
.folder i{font-size:34px;color:#f59e0b}
/* excel-like grid */
.xl{border-collapse:collapse;width:100%;background:#fff;font-size:13px}
.xl th{background:#6ee7f9;border:1px solid #94a3b8;padding:8px;text-align:center;position:sticky;top:0}
.xl td{border:1px solid #cbd5e1;padding:4px 8px;vertical-align:middle;text-align:center}
.xl tbody tr:nth-child(odd){background:#dde7f3}.xl tbody tr:nth-child(even){background:#b9cde6}
.xl td.desc{text-align:left;text-transform:uppercase}
.xl input.cell{width:100%;border:0;background:transparent;text-align:inherit;padding:2px}
.xl input.cell:focus{background:#fff;outline:2px solid #f97316}
.thumb{width:64px;height:64px;object-fit:contain;background:#fff;border:1px solid #cbd5e1;border-radius:6px}
.ac{position:absolute;z-index:50;background:#fff;border:1px solid #cbd5e1;border-radius:8px;width:100%;max-height:300px;overflow:auto;display:none;box-shadow:0 8px 20px rgba(0,0,0,.12)}
.ac div{display:flex;gap:10px;align-items:center;padding:6px 10px;cursor:pointer}.ac div:hover{background:#fff7ed}
.ac img{width:36px;height:36px;object-fit:contain}
.nav-link.active{background:#f97316!important;color:#fff!important}
</style></head><body>

<?php include('sidebar.php'); /* move your existing sidebar HTML + CSS into sidebar.php, unchanged */ ?>

<div class="main">
<div class="panel d-flex flex-wrap gap-2 justify-content-between align-items-center">
  <h4 class="m-0 fw-bold">📊 Report Hub</h4>
  <nav class="nav nav-pills"><?= $tab('job', '📁 Job Folders') . $tab('store', '🏢 Tieman Store') . $tab('in', '📈 Stock In') . $tab('out', '📉 Stock Out') ?></nav>
</div>

<?php if ($view === 'job'): ?>
  <?php if (!$job_id): ?>
    <div class="panel"><div class="row g-3">
      <?php foreach ($jobs as $j): ?>
      <div class="col-6 col-md-4 col-xl-3"><a class="folder" href="?view=job&job=<?= $j['id'] ?>">
        <i class="fa-solid fa-folder-open"></i>
        <div class="fw-bold mt-2"><?= htmlspecialchars($j['job_no']) ?></div>
        <div class="small text-muted"><?= htmlspecialchars($j['customer_name']) ?></div>
        <span class="badge bg-dark mt-2"><?= $j['n'] ?> items</span> <span class="badge bg-secondary"><?= htmlspecialchars($j['status']) ?></span>
      </a></div>
      <?php endforeach; if (!$jobs) echo "<p class='text-muted m-0'>No jobs yet.</p>"; ?>
    </div></div>
  <?php else:
    $jr = mysqli_fetch_assoc($q("SELECT * FROM jobs WHERE id=$job_id"));
    $rows = $q("SELECT i.id,i.item_code,i.item_name,i.image,ji.qty FROM job_items ji JOIN items i ON i.id=ji.item_id WHERE ji.job_id=$job_id ORDER BY i.item_code"); ?>
    <div class="panel">
      <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
        <div><a href="?view=job" class="btn btn-sm btn-light">← Folders</a>
          <b class="ms-2">📁 <?= htmlspecialchars($jr['job_no'] ?? '') ?></b> <span class="text-muted"><?= htmlspecialchars($jr['customer_name'] ?? '') ?></span></div>
        <div class="d-flex gap-2 align-items-center">
          <select class="form-select form-select-sm" onchange="location='?view=job&job='+this.value">
            <?php foreach ($jobs as $j) echo "<option value='{$j['id']}'" . ($j['id'] == $job_id ? ' selected' : '') . ">" . htmlspecialchars($j['job_no']) . "</option>"; ?>
          </select>
          <a class="btn btn-sm btn-success" href="report_export.php?type=job&id=<?= $job_id ?>&fmt=xlsx">Excel</a>
          <a class="btn btn-sm btn-danger" href="report_export.php?type=job&id=<?= $job_id ?>&fmt=pdf">PDF</a>
        </div>
      </div>
      <div class="d-flex gap-2 mb-3">
        <div class="position-relative flex-grow-1"><input id="s" class="form-control" placeholder="Search part no / description / barcode to add..." autocomplete="off"><div class="ac" id="acb"></div></div>
        <input id="addq" type="number" min="1" value="1" class="form-control" style="width:80px">
        <button class="btn btn-danger" onclick="delSel('del_job_item',{job_id:<?= $job_id ?>})"><i class="fa-solid fa-trash"></i> Delete selected</button>
      </div>
      <div class="table-responsive"><table class="xl">
        <thead><tr><th style="width:50px">NO</th><th>PART NO.</th><th style="width:90px">IMAGE</th><th>DESCRIPTION</th><th style="width:90px">QTY</th><th style="width:70px">☑</th></tr></thead>
        <tbody>
        <?php $n = 0; while ($rows && $r = mysqli_fetch_assoc($rows)): $n++; ?>
          <tr><td><b><?= $n ?></b></td><td><?= htmlspecialchars($r['item_code']) ?></td>
          <td><img class="thumb" src="<?= img($r['image']) ?>"></td>
          <td class="desc"><?= htmlspecialchars($r['item_name']) ?></td>
          <td><input class="cell" type="number" value="<?= (int)$r['qty'] ?>" onchange="post('upd_job_item',{job_id:<?= $job_id ?>,item_id:<?= $r['id'] ?>,qty:this.value})"></td>
          <td><input type="checkbox" class="sel form-check-input" value="<?= $r['id'] ?>">
              <button class="btn btn-sm text-danger" onclick="delOne('del_job_item',{job_id:<?= $job_id ?>},<?= $r['id'] ?>)"><i class="fa-solid fa-trash"></i></button></td></tr>
        <?php endwhile; if (!$n) echo "<tr><td colspan='6' class='text-muted py-4'>Empty folder. Use the search bar to add items.</td></tr>"; ?>
        </tbody></table></div>
    </div>
    <?php $scope = 'job'; $ref = $job_id; endif; ?>

<?php elseif ($view === 'store'):
  $w = $cat ? "WHERE category='" . $e($cat) . "'" : '';
  $rows = $q("SELECT * FROM items $w ORDER BY item_code"); ?>
  <div class="panel">
    <div class="d-flex flex-wrap gap-2 justify-content-between mb-3">
      <div class="d-flex gap-2">
        <select class="form-select form-select-sm" onchange="location='?view=store&cat='+encodeURIComponent(this.value)">
          <option value="">All categories</option>
          <?php foreach ($cats as $c) echo "<option" . ($c === $cat ? ' selected' : '') . ">" . htmlspecialchars($c) . "</option>"; ?>
        </select>
        <input id="filter" class="form-control form-control-sm" placeholder="Type to filter list..." oninput="liveFilter(this.value)">
      </div>
      <div class="d-flex gap-2">
        <button class="btn btn-sm btn-danger" onclick="delSel('del_item',{})"><i class="fa-solid fa-trash"></i> Delete selected</button>
        <a class="btn btn-sm btn-success" href="report_export.php?type=store&cat=<?= urlencode($cat) ?>&fmt=xlsx">Excel</a>
        <a class="btn btn-sm btn-danger" href="report_export.php?type=store&cat=<?= urlencode($cat) ?>&fmt=pdf">PDF</a>
      </div>
    </div>
    <div class="table-responsive"><table class="xl" id="storeTbl">
      <thead><tr><th>NO</th><th>PART NO.</th><th>IMAGE</th><th>DESCRIPTION</th><th>QTY</th><th>☑</th></tr></thead><tbody>
      <?php $n = 0; while ($rows && $r = mysqli_fetch_assoc($rows)): $n++; $id = $r['id']; ?>
        <tr data-t="<?= htmlspecialchars(strtolower($r['item_code'] . ' ' . $r['item_name'])) ?>"><td><b><?= $n ?></b></td>
        <td><input class="cell" id="c<?= $id ?>" value="<?= htmlspecialchars($r['item_code']) ?>" onchange="saveItem(<?= $id ?>)"></td>
        <td><img class="thumb" src="<?= img($r['image']) ?>"></td>
        <td class="desc"><input class="cell" style="text-align:left" id="n<?= $id ?>" value="<?= htmlspecialchars($r['item_name']) ?>" onchange="saveItem(<?= $id ?>)"></td>
        <td><input class="cell" type="number" id="q<?= $id ?>" value="<?= (int)$r['stock_qty'] ?>" onchange="saveItem(<?= $id ?>)"></td>
        <td><input type="checkbox" class="sel form-check-input" value="<?= $id ?>">
            <button class="btn btn-sm text-danger" onclick="delOne('del_item',{},<?= $id ?>)"><i class="fa-solid fa-trash"></i></button></td></tr>
      <?php endwhile; ?></tbody></table></div>
  </div>
  <?php $scope = 'store'; $ref = 0; ?>

<?php else: $dir = $view === 'out' ? 'out' : 'in';
  $mv = $q("SELECT m.*, i.item_code, i.item_name, i.image, j.job_no FROM stock_moves m JOIN items i ON i.id=m.item_id LEFT JOIN jobs j ON j.id=m.job_id WHERE m.direction='$dir' ORDER BY m.id DESC LIMIT 100"); ?>
  <div class="panel">
    <h5 class="fw-bold"><?= $dir === 'in' ? '📈 Stock In' : '📉 Stock Out' ?></h5>
    <div class="row g-2 mb-3">
      <div class="col-md-5 position-relative"><input id="s" class="form-control" placeholder="Search item..." autocomplete="off"><div class="ac" id="acb"></div></div>
      <div class="col-6 col-md-1"><input id="mq" type="number" min="1" value="1" class="form-control"></div>
      <div class="col-6 col-md-2"><select id="msrc" class="form-select" onchange="document.getElementById('mjob').style.display=this.value=='job'?'block':'none'">
        <option value="store">From Store</option><option value="job">From Job</option></select></div>
      <div class="col-md-2"><select id="mjob" class="form-select" style="display:none"><?php foreach ($jobs as $j) echo "<option value='{$j['id']}'>" . htmlspecialchars($j['job_no']) . "</option>"; ?></select></div>
      <div class="col-md-2"><input id="mnote" class="form-control" placeholder="Note"></div>
    </div>
    <div id="picked" class="mb-3 small text-muted">Pick an item from the search list, then press the button.</div>
    <button class="btn btn-<?= $dir === 'in' ? 'success' : 'danger' ?>" onclick="doMove('<?= $dir ?>')">Record Stock <?= strtoupper($dir) ?></button>
    <table class="xl mt-3"><thead><tr><th>DATE</th><th>PART NO.</th><th>IMAGE</th><th>DESCRIPTION</th><th>QTY</th><th>SOURCE</th></tr></thead><tbody>
    <?php while ($mv && $r = mysqli_fetch_assoc($mv)): ?>
      <tr><td><?= date('d M Y H:i', strtotime($r['created_at'])) ?></td><td><?= htmlspecialchars($r['item_code']) ?></td>
      <td><img class="thumb" src="<?= img($r['image']) ?>"></td><td class="desc"><?= htmlspecialchars($r['item_name']) ?></td>
      <td><b><?= $r['qty'] ?></b></td><td><?= $r['source'] === 'job' ? 'Job ' . htmlspecialchars($r['job_no']) : 'Store' ?></td></tr>
    <?php endwhile; ?></tbody></table>
  </div>
  <?php $scope = $dir; $ref = 0; ?>
<?php endif; ?>

<?php if ($view !== 'job' || $job_id): // notes + file/camera box
  $nr = $q("SELECT * FROM report_notes WHERE scope='" . $e($scope) . "' AND ref_id=" . (int)$ref . " ORDER BY id DESC LIMIT 20"); ?>
  <div class="panel">
    <h6 class="fw-bold">📝 Notes &amp; Files</h6>
    <textarea id="nt" class="form-control mb-2" rows="3" placeholder="Write notes..."></textarea>
    <div class="d-flex flex-wrap gap-2 mb-2">
      <input type="file" id="nf" class="form-control" style="max-width:320px" accept="image/*,.pdf,.xls,.xlsx,.doc,.docx">
      <label class="btn btn-outline-dark mb-0"><i class="fa-solid fa-camera"></i> Camera
        <input type="file" id="nc" accept="image/*" capture="environment" hidden onchange="document.getElementById('nf').files=this.files"></label>
      <button class="btn btn-success" onclick="saveNote('<?= $scope ?>',<?= (int)$ref ?>)">💾 Save</button>
    </div>
    <?php while ($nr && $r = mysqli_fetch_assoc($nr)): ?>
      <div class="border rounded p-2 mt-2 small"><span class="text-muted"><?= date('d M Y H:i', strtotime($r['created_at'])) ?></span>
      <div style="white-space:pre-wrap"><?= htmlspecialchars($r['notes']) ?></div>
      <?php if ($r['file_name']): ?><a target="_blank" href="../uploads/notes/<?= rawurlencode($r['file_name']) ?>">📎 <?= htmlspecialchars($r['file_name']) ?></a><?php endif; ?></div>
    <?php endwhile; ?>
  </div>
<?php endif; ?>
</div>

<script>
const VIEW = <?= json_encode($view) ?>, JOB = <?= (int)$job_id ?>;
let picked = null;
function post(a, d, files) {
  const fd = new FormData(); fd.append('a', a);
  for (const k in d) fd.append(k, d[k]);
  if (files) for (const k in files) fd.append(k, files[k]);
  return fetch('report_hub.php', {method: 'POST', body: fd}).then(r => r.json());
}
function saveItem(id) { post('upd_item', {id, item_code: c(id).value, item_name: n(id).value, stock_qty: q(id).value}); }
const c = id => document.getElementById('c' + id), n = id => document.getElementById('n' + id), q = id => document.getElementById('q' + id);
function delOne(a, extra, id) { if (confirm('Delete this row?')) post(a, {...extra, ids: id}).then(() => location.reload()); }
function delSel(a, extra) {
  const ids = [...document.querySelectorAll('.sel:checked')].map(x => x.value);
  if (!ids.length) return alert('Tick at least one row first.');
  if (confirm('Delete ' + ids.length + ' row(s)?')) post(a, {...extra, ids: ids.join(',')}).then(() => location.reload());
}
function liveFilter(v) { v = v.toLowerCase(); document.querySelectorAll('#storeTbl tbody tr').forEach(r => r.style.display = r.dataset.t.includes(v) ? '' : 'none'); }
function saveNote(scope, ref) {
  const f = document.getElementById('nf').files[0];
  post('save_note', {scope, ref_id: ref, notes: document.getElementById('nt').value}, f ? {file: f} : null).then(() => location.reload());
}
function doMove(dir) {
  if (!picked) return alert('Pick an item first.');
  post('move', {item_id: picked.id, direction: dir, source: msrc.value, job_id: mjob.value, qty: mq.value, note: mnote.value}).then(() => location.reload());
}
// autocomplete: shows matches while typing
const s = document.getElementById('s'), box = document.getElementById('acb');
if (s) {
  let t;
  s.addEventListener('input', () => {
    clearTimeout(t);
    t = setTimeout(() => {
      if (!s.value.trim()) { box.style.display = 'none'; return; }
      fetch('report_hub.php?a=search&q=' + encodeURIComponent(s.value)).then(r => r.json()).then(list => {
        box.innerHTML = '';
        list.forEach(it => {
          const d = document.createElement('div');
          d.innerHTML = `<img src="${it.img}"><span><b>${it.item_code}</b> — ${it.item_name} <small class="text-muted">(stock ${it.stock_qty})</small></span>`;
          d.onclick = () => pick(it);
          box.appendChild(d);
        });
        box.style.display = list.length ? 'block' : 'none';
      });
    }, 200);
  });
}
function pick(it) {
  box.style.display = 'none';
  if (VIEW === 'job') {
    post('add_job_item', {job_id: JOB, item_id: it.id, qty: document.getElementById('addq').value}).then(() => location.reload());
  } else {
    picked = it; s.value = it.item_code;
    document.getElementById('picked').innerHTML = 'Selected: <b>' + it.item_code + '</b> — ' + it.item_name;
  }
}
</script></body></html>
