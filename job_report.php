<?php
session_start();
include('../config/db.php');

$query = mysqli_query($conn, "SELECT j.id AS job_id, j.job_no, j.customer_name, j.status AS job_status, j.created_at, i.item_code, i.barcode, i.stock_qty, i.image, i.item_name FROM jobs j LEFT JOIN job_items ji ON j.id = ji.job_id LEFT JOIN items i ON ji.item_id = i.id ORDER BY j.id DESC");

$jobs = [];
while ($row = mysqli_fetch_assoc($query)) {
    $job_id = $row['job_id'];
    if (!isset($jobs[$job_id])) {
        $jobs[$job_id] = [
            'job_no' => $row['job_no'],
            'customer_name' => $row['customer_name'],
            'job_status' => $row['job_status'],
            'created_at' => $row['created_at'],
            'items' => []
        ];
    }
    if (!empty($row['item_code'])) {
        $jobs[$job_id]['items'][] = [
            'item_name' => $row['item_name'],
            'item_code' => $row['item_code'],
            'barcode' => $row['barcode'],
            'stock_qty' => $row['stock_qty'],
            'image' => $row['image']
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Warehouse - Job Allocations Report</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js"></script>
    <style>
        body { background:#f4f6f9; font-family:'Segoe UI', sans-serif; }
        .sidebar { width:250px; height:100vh; background:#111827; position:fixed; left:0; top:0; overflow:auto; z-index: 100; }
        .logo { background:#f97316; padding:20px; text-align:center; font-size:22px; font-weight:bold; color:white; }
        .sidebar a { display:block; padding:15px; color:white; text-decoration:none; transition:.3s; }
        .sidebar a:hover, .sidebar .active { background:#f97316; }
        .main { margin-left:250px; padding:20px; }
        .card-box { background:white; padding:20px; border-radius:12px; box-shadow:0 2px 10px rgba(0,0,0,.08); margin-bottom: 20px;}
        .item-thumbnail { width: 65px; height: 65px; object-fit: cover; border-radius: 8px; border: 1px solid #ddd; }
        #qr-reader { width: 100%; max-width: 400px; margin: 0 auto; background: #111; border: 2px dashed #f97316!important; border-radius: 8px; overflow: hidden; }
        #qr-reader video { width: 100%!important; height: auto!important; }
        @media(max-width: 768px) { .sidebar { display: none; } .main { margin-left: 0; padding: 10px; } }
    </style>
</head>
<body>

    <div class="sidebar">
        <div class="logo">WAREHOUSE</div>
        <a href="../dashboard.php">🏠 Dashboard</a>
        <a href="job_report.php" class="active">📋 Job Reports</a>
        <a href="store_report.php">🏢 Store Reports</a>
        <a href="../logout.php">🚪 Logout</a>
    </div>

    <div class="main">
        <div class="topbar card-box d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h4 class="m-0 text-dark fw-bold">📋 Job Allocations Master Table</h4>
                <small class="text-muted">Shows all tracked items grouped together by their specific Job Orders</small>
            </div>
            <a href="export_job_excel.php" class="btn btn-success fw-bold px-4 py-2 shadow-sm">📥 Export Jobs to Excel</a>
        </div>

        <div class="row g-3">
            <div class="col-lg-4">
                <div class="card-box text-center">
                    <h5 class="mb-3 text-secondary fw-bold">🔍 Live Search / Scan Panel</h5>
                    <div id="qr-reader"></div>
                    <div id="scan-results" class="mt-2 fw-bold text-success small"></div>
                    <button class="btn btn-warning btn-sm mt-2 w-100" onclick="switchCamera()">🔄 Switch Camera View</button>
                    
                    <div class="text-muted my-3 small">─ OR SNAP / CHOOSE FILE ─</div>
                    <input type="file" accept="image/*" capture="environment" id="file-selector" class="form-control border-2 mb-3">

                    <input type="text" id="search_box" class="form-control form-control-lg border-2 text-center" placeholder="Scanned value displays here..." autocomplete="off">
                    <div id="db-lookup-info" class="mt-2 fw-bold text-primary small"></div>
                </div>
            </div>

            <div class="col-lg-8">
                <?php if(!empty($jobs)): foreach($jobs as $id => $job): ?>
                    <div class="card-box border-start border-5 border-primary">
                        <div class="d-flex flex-wrap justify-content-between align-items-center border-bottom pb-2 mb-3">
                            <div>
                                <h5 class="m-0 fw-bold text-dark">Job No: <span class="text-primary"><?= htmlspecialchars($job['job_no']) ?></span></h5>
                                <small class="text-muted">👥 Client: <b><?= htmlspecialchars($job['customer_name']) ?></b></small>
                            </div>
                            <div class="text-end">
                                <span class="badge bg-dark px-3 py-1.5 text-uppercase"><?= htmlspecialchars($job['job_status']) ?></span>
                                <div class="text-muted small mt-1"><?= date('d M Y', strtotime($job['created_at'])) ?></div>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover align-middle m-0">
                                <thead class="table-light">
                                    <tr>
                                        <th class="text-center" style="width: 80px;">Image</th>
                                        <th>Item Name</th>
                                        <th>Part Number</th>
                                        <th>Barcode ID</th>
                                        <th class="text-center">Current Store Stock</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(!empty($job['items'])): foreach($job['items'] as $item): ?>
                                        <tr class="item-row" data-barcode="<?= htmlspecialchars($item['barcode']) ?>" data-code="<?= htmlspecialchars($item['item_code']) ?>">
                                            <td class="text-center">
                                                <?php if(!empty($item['image']) && file_exists("../uploads/" . $item['image'])): ?>
                                                    <img src="../uploads/<?= htmlspecialchars($item['image']) ?>" class="item-thumbnail">
                                                <?php else: ?>
                                                    <div class="item-thumbnail d-flex align-items-center justify-content-center bg-light text-muted small">No Img</div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="fw-bold text-dark"><?= htmlspecialchars($item['item_name']) ?></td>
                                            <td><code><?= htmlspecialchars($item['item_code']) ?></code></td>
                                            <td><?= htmlspecialchars($item['barcode']) ?></td>
                                            <td class="text-center"><span class="badge bg-success fs-6"><?= $item['stock_qty'] ?></span></td>
                                        </tr>
                                    <?php endforeach; else: ?>
                                        <tr><td colspan="5" class="text-center text-muted py-3">No linked inventory items assigned to this job record.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; else: ?>
                    <div class="card-box text-center py-5 text-muted"><h3>No active Job data records found.</h3></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        let html5QrCode;
        let currentFacingMode = "environment";

        function onScanSuccess(decodedText) {
            document.getElementById('search_box').value = decodedText;
            document.getElementById('scan-results').innerText = "🎯 Scanned: " + decodedText;
            handleSearchAndHighlight(decodedText);
        }

        function startScanner(facingMode) {
            if (html5QrCode && html5QrCode.isScanning) {
                html5QrCode.stop().then(() => { runInit(facingMode); }).catch(err => console.log(err));
            } else {
                runInit(facingMode);
            }
        }

        function runInit(facingMode) {
            html5QrCode = new Html5Qrcode("qr-reader");
            html5QrCode.start({ facingMode: facingMode }, { fps: 20, qrbox: { width: 250, height: 150 } }, onScanSuccess, () => {})
            .catch(() => {
                document.getElementById('scan-results').innerHTML = `<span class="text-warning">⚠️ Live video camera locked. Use the photo file input below to snap barcode picture instead!</span>`;
            });
        }

        function switchCamera() {
            currentFacingMode = (currentFacingMode === "environment") ? "user" : "environment";
            startScanner(currentFacingMode);
        }

        document.getElementById('file-selector').addEventListener('change', e => {
            if (e.target.files.length == 0) return;
            const fileScanner = new Html5Qrcode("search_box"); 
            fileScanner.scanFile(e.target.files[0], true).then(decodedText => {
                document.getElementById('search_box').value = decodedText;
                handleSearchAndHighlight(decodedText);
            }).catch(() => { alert("❌ Barcode image unreadable. Please ensure clear lighting."); });
        });

        function handleSearchAndHighlight(val) {
            if(!val.trim()) return;
            // Background database details lookup
            let fd = new FormData(); fd.append('identifier', val);
            fetch('find_item_name.php', { method: 'POST', body: fd })
            .then(r => r.text()).then(html => document.getElementById('db-lookup-info').innerHTML = html);

            // Highlight row on screen if matched
            document.querySelectorAll('.item-row').forEach(row => {
                if(row.getAttribute('data-barcode') === val || row.getAttribute('data-code') === val) {
                    row.style.backgroundColor = "#fffbeb";
                    row.scrollIntoView({ behavior: 'smooth', block: 'center' });
                } else {
                    row.style.backgroundColor = "";
                }
            });
        }

        document.getElementById('search_box').addEventListener('input', e => handleSearchAndHighlight(e.target.value));
        window.addEventListener('DOMContentLoaded', () => startScanner(currentFacingMode));
    </script>
</body>
</html>