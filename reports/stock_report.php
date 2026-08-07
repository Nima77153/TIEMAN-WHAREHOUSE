<?php
session_start();
// Config database inclusion
include('../config/db.php'); 

if (!$conn) {
    die("<div class='alert alert-danger m-3'><b>Database Connection Error:</b> Please verify your db.php configurations.</div>");
}

// EXACT MATCHING IMAGE RESOLVER FROM item_list.php
function getItemImage($imageName) {
    $cleanName = trim(strip_tags($imageName));
    
    // Check uploads/items/ folder (matching item_list.php)
    if (!empty($cleanName) && file_exists('../uploads/items/' . $cleanName)) {
        return '../uploads/items/' . $cleanName;
    }
    
    // Fallback if image stored directly in uploads/ folder
    if (!empty($cleanName) && file_exists('../uploads/' . $cleanName)) {
        return '../uploads/' . $cleanName;
    }
    
    // Fallback placeholder image if missing or broken
    return '../assets/images/no-image.png';
}

// --- 1. FETCH JOB ALLOCATIONS DATA ---
$job_query = mysqli_query($conn, "SELECT j.id AS job_id, j.job_no, j.customer_name, j.status AS job_status, j.created_at, i.item_code, i.barcode, i.stock_qty, i.image, i.item_name, i.remark FROM jobs j LEFT JOIN job_items ji ON j.id = ji.job_id LEFT JOIN items i ON ji.item_id = i.id ORDER BY j.id DESC");

$jobs = [];
if ($job_query) {
    while ($row = mysqli_fetch_assoc($job_query)) {
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
                'image' => $row['image'],
                'remark' => $row['remark']
            ];
        }
    }
}

// --- 2. FETCH STORE INVENTORY DATA ---
$store_query = mysqli_query($conn, "SELECT id, item_code, item_name, barcode, stock_qty, image, remark FROM items ORDER BY id DESC");
$stock_in_query = mysqli_query($conn, "SELECT id, item_code, item_name, barcode, stock_qty, image, remark FROM items WHERE stock_qty > 0 ORDER BY id DESC LIMIT 15");
$stock_out_query = mysqli_query($conn, "SELECT id, item_code, item_name, barcode, stock_qty, image, remark FROM items WHERE stock_qty = 0 ORDER BY id DESC LIMIT 15");
$job_trans_query = mysqli_query($conn, "SELECT ji.job_id, j.job_no, j.customer_name, j.status, j.created_at AS trans_date, i.item_name, i.item_code, i.barcode, i.image, i.remark FROM job_items ji JOIN jobs j ON ji.job_id = j.id JOIN items i ON ji.item_id = i.id ORDER BY j.id DESC LIMIT 15");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Warehouse - Stock Report Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js"></script>
    <style>
        body { background:#f8fafc; font-family:'Segoe UI', sans-serif; }
        .sidebar { width:250px; height:100vh; background:#111827; position:fixed; left:0; top:0; overflow:auto; z-index: 100; }
        .logo { background:#f97316; padding:20px; text-align:center; font-size:22px; font-weight:bold; color:white; }
        .sidebar a { display:block; padding:15px; color:white; text-decoration:none; transition:.3s; }
        .sidebar a:hover, .sidebar .active { background:#f97316; }
        .main { margin-left:250px; padding:20px; }
        .card-box { background:white; padding:20px; border-radius:12px; box-shadow:0 1px 3px rgba(0,0,0,.05); margin-bottom: 20px;}
        
        /* TABLE & IMAGE CONTAINER MATCHING YOUR SCREENSHOT EXACTLY */
        .table-item-list { border-collapse: separate; border-spacing: 0 8px; }
        .table-item-list tr { background: #ffffff; border-bottom: 1px solid #f1f5f9; }
        .img-card {
            width: 55px;
            height: 55px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 3px;
            background: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }
        .img-card img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
            border-radius: 4px;
        }
        
        .part-code {
            color: #2563eb;
            font-weight: 700;
            font-size: 14px;
            text-decoration: none;
        }
        
        .item-desc {
            font-weight: 600;
            color: #334155;
            text-transform: uppercase;
            font-size: 13px;
        }

        #qr-reader { width: 100%; max-width: 400px; margin: 0 auto; background: #111; border: 2px dashed #f97316!important; border-radius: 8px; overflow: hidden; }
        #qr-reader video { width: 100%!important; height: auto !important; }
        .nav-tabs .nav-link { font-weight: bold; color: #4b5563; border-radius: 8px 8px 0 0; padding: 12px 25px; }
        .nav-tabs .nav-link.active { background-color: white; color: #f97316; border-color: #dee2e6 #dee2e6 white; }
        .live-clock { background: #1e293b; color: #38bdf8; padding: 6px 14px; border-radius: 8px; font-weight: bold; font-family: monospace; display: inline-block; }
        @media(max-width: 768px) { .sidebar { display: none; } .main { margin-left: 0; padding: 10px; } }
        
        .color-theme-1 { background-color: #ffffff !important; border-left: 5px solid #ef4444 !important; }
        .color-theme-2 { background-color: #ffffff !important; border-left: 5px solid #22c55e !important; }
        .color-theme-3 { background-color: #ffffff !important; border-left: 5px solid #3b82f6 !important; }
        .color-theme-4 { background-color: #ffffff !important; border-left: 5px solid #eab308 !important; }
        .color-theme-5 { background-color: #ffffff !important; border-left: 5px solid #a855f7 !important; }
        .color-theme-6 { background-color: #ffffff !important; border-left: 5px solid #14b8a6 !important; }
        .color-theme-7 { background-color: #ffffff !important; border-left: 5px solid #f97316 !important; }
    </style>
</head>
<body>

    <div class="sidebar">
        <div class="logo">WAREHOUSE</div>
        <a href="../dashboard.php">🏠 Dashboard</a>
        <a href="../items/item_list.php">📦 Items</a>
        <a href="../items/stock_in.php">⬆️ Stock In</a>
        <a href="../items/stock_out.php">⬇️ Stock Out</a>
        <a href="../jobs/job_list.php">📝 Job List</a>
        <a href="stock_report.php" class="active">📊 Master Reports</a>
        <a href="../logout.php">🚪 Logout</a>
    </div>

    <div class="main">
        <div class="topbar card-box d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h4 class="m-0 text-dark fw-bold">📊 Unified Stock & Job Report</h4>
                <small class="text-muted">Live view of customer parts orders and overall system inventory status</small>
            </div>
            
            <div class="text-end">
                <div class="live-clock" id="liveClockDisplay">🕒 Loading Live Time...</div>
            </div>

            <div class="d-flex gap-2">
                <a href="export_combined_excel.php?type=job" class="btn btn-warning fw-bold text-white shadow-sm">📥 Export Jobs to Excel</a>
                <a href="export_combined_excel.php?type=store" class="btn btn-success fw-bold shadow-sm">📥 Export Store to Excel</a>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-lg-4">
                <div class="card-box text-center sticky-top" style="top: 20px; z-index: 10;">
                    <h5 class="mb-3 text-secondary fw-bold">🔍 Universal Search & Scanner</h5>
                    <div id="qr-reader"></div>
                    <div id="scan-results" class="mt-2 fw-bold text-success small"></div>
                    <button class="btn btn-warning btn-sm mt-2 w-100" onclick="switchCamera()">🔄 Switch Camera View</button>
                    
                    <div class="text-muted my-3 small">─ OR SNAP / CHOOSE FILE ─</div>
                    <input type="file" accept="image/*" capture="environment" id="file-selector" class="form-control border-2 mb-3">

                    <input type="text" id="search_box" class="form-control form-control-lg border-2 text-center" placeholder="Scan barcode or input part code..." autocomplete="off">
                    <div id="db-lookup-info" class="mt-2 fw-bold text-primary small"></div>
                </div>
            </div>

            <div class="col-lg-8">
                <ul class="nav nav-tabs border-0 mb-3" id="reportTabs" role="tablist">
                    <li class="nav-item">
                        <button class="nav-link active" id="job-tab" data-bs-toggle="tab" data-bs-target="#job-panel" type="button" role="tab">📋 Customer Job Sheets</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="store-tab" data-bs-toggle="tab" data-bs-target="#store-panel" type="button" role="tab">🏢 Core Store Inventory</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="stockin-tab" data-bs-toggle="tab" data-bs-target="#stockin-panel" type="button" role="tab">📈 Stock In</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="stockout-tab" data-bs-toggle="tab" data-bs-target="#stockout-panel" type="button" role="tab">📉 Stock Out</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="jobtrans-tab" data-bs-toggle="tab" data-bs-target="#jobtrans-panel" type="button" role="tab">🔄 Job Item Out/In</button>
                    </li>
                </ul>

                <div class="tab-content" id="reportTabsContent">
                    
                    <!-- JOB PANEL -->
                    <div class="tab-pane fade show active" id="job-panel" role="tabpanel">
                        <?php 
                        $color_counter = 0;
                        if(!empty($jobs)): foreach($jobs as $id => $job): 
                            $selected_theme = "color-theme-" . (($color_counter % 7) + 1);
                            $color_counter++;
                        ?>
                            <div class="card-box mb-4 <?= $selected_theme ?>">
                                <div class="d-flex flex-wrap justify-content-between align-items-center border-bottom pb-2 mb-3">
                                    <div>
                                        <h5 class="m-0 fw-bold text-dark">Job Assignment: <span class="text-primary"><?= htmlspecialchars($job['job_no']) ?></span></h5>
                                        <small class="text-muted">👥 Customer Profile: <b><?= htmlspecialchars($job['customer_name']) ?></b></small>
                                    </div>
                                    <div class="text-end">
                                        <span class="badge bg-dark px-3 py-1.5 text-uppercase"><?= htmlspecialchars($job['job_status']) ?></span>
                                        <div class="text-muted small mt-1"><?= date('d M Y H:i', strtotime($job['created_at'])) ?></div>
                                    </div>
                                </div>

                                <div class="table-responsive">
                                    <table class="table align-middle table-item-list m-0">
                                        <tbody>
                                            <?php if(!empty($job['items'])): foreach($job['items'] as $item): ?>
                                                <tr class="item-row" data-barcode="<?= htmlspecialchars($item['barcode']) ?>" data-code="<?= htmlspecialchars($item['item_code']) ?>">
                                                    <td style="width: 40px;"><input type="checkbox" class="form-check-input"></td>
                                                    <td style="width: 70px;">
                                                        <div class="img-card">
                                                            <img src="<?= getItemImage($item['image']) ?>" alt="Image" onerror="this.onerror=null; this.src='https://via.placeholder.com/50?text=No+Img';">
                                                        </div>
                                                    </td>
                                                    <td style="width: 180px;"><span class="part-code"><?= htmlspecialchars($item['item_code']) ?></span></td>
                                                    <td><span class="item-desc"><?= htmlspecialchars($item['item_name']) ?></span></td>
                                                    <td class="text-end" style="width: 80px;"><span class="badge bg-primary fs-6"><?= $item['stock_qty'] ?></span></td>
                                                </tr>
                                            <?php endforeach; else: ?>
                                                <tr><td colspan="5" class="text-center text-muted py-3">No physical lines assigned to this job tracking sheet.</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endforeach; else: ?>
                            <div class="card-box text-center py-5 text-muted"><h3>No active client tracking records found.</h3></div>
                        <?php endif; ?>
                    </div>

                    <!-- STORE PANEL -->
                    <div class="tab-pane fade" id="store-panel" role="tabpanel">
                        <div class="card-box border-start border-5 border-success">
                            <div class="table-responsive">
                                <table class="table align-middle table-item-list m-0">
                                    <tbody>
                                        <?php if($store_query && mysqli_num_rows($store_query) > 0): while($item = mysqli_fetch_assoc($store_query)): ?>
                                            <tr class="item-row" data-barcode="<?= htmlspecialchars($item['barcode']) ?>" data-code="<?= htmlspecialchars($item['item_code']) ?>">
                                                <td style="width: 40px;"><input type="checkbox" class="form-check-input"></td>
                                                <td style="width: 70px;">
                                                    <div class="img-card">
                                                        <img src="<?= getItemImage($item['image']) ?>" alt="Image" onerror="this.onerror=null; this.src='https://via.placeholder.com/50?text=No+Img';">
                                                    </div>
                                                </td>
                                                <td style="width: 180px;"><span class="part-code"><?= htmlspecialchars($item['item_code']) ?></span></td>
                                                <td><span class="item-desc"><?= htmlspecialchars($item['item_name']) ?></span></td>
                                                <td class="text-end" style="width: 100px;">
                                                    <?php $lbl = ($item['stock_qty'] > 0) ? 'bg-success' : 'bg-danger'; ?>
                                                    <span class="badge <?= $lbl ?> fs-6"><?= $item['stock_qty'] ?></span>
                                                </td>
                                            </tr>
                                        <?php endwhile; else: ?>
                                            <tr><td colspan="5" class="text-center text-muted py-4">No data metrics located inside database core tables.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- STOCK IN PANEL -->
                    <div class="tab-pane fade" id="stockin-panel" role="tabpanel">
                        <div class="card-box border-start border-5 border-info">
                            <h5 class="mb-3 text-info fw-bold">📈 Stock In Records</h5>
                            <div class="table-responsive">
                                <table class="table align-middle table-item-list m-0">
                                    <tbody>
                                        <?php if($stock_in_query && mysqli_num_rows($stock_in_query) > 0): mysqli_data_seek($stock_in_query, 0); while($in_row = mysqli_fetch_assoc($stock_in_query)): ?>
                                            <tr>
                                                <td style="width: 40px;"><input type="checkbox" class="form-check-input"></td>
                                                <td style="width: 70px;">
                                                    <div class="img-card">
                                                        <img src="<?= getItemImage($in_row['image']) ?>" alt="Image" onerror="this.onerror=null; this.src='https://via.placeholder.com/50?text=No+Img';">
                                                    </div>
                                                </td>
                                                <td style="width: 180px;"><span class="part-code"><?= htmlspecialchars($in_row['item_code']) ?></span></td>
                                                <td><span class="item-desc"><?= htmlspecialchars($in_row['item_name']) ?></span></td>
                                                <td class="text-end" style="width: 80px;"><span class="badge bg-info text-dark fs-6"><?= $in_row['stock_qty'] ?></span></td>
                                            </tr>
                                        <?php endwhile; else: ?>
                                            <tr><td colspan="5" class="text-center text-muted py-3">No entry items tracked.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- STOCK OUT PANEL -->
                    <div class="tab-pane fade" id="stockout-panel" role="tabpanel">
                        <div class="card-box border-start border-5 border-danger">
                            <h5 class="mb-3 text-danger fw-bold">📉 Stock Out Records</h5>
                            <div class="table-responsive">
                                <table class="table align-middle table-item-list m-0">
                                    <tbody>
                                        <?php if($stock_out_query && mysqli_num_rows($stock_out_query) > 0): mysqli_data_seek($stock_out_query, 0); while($out_row = mysqli_fetch_assoc($stock_out_query)): ?>
                                            <tr>
                                                <td style="width: 40px;"><input type="checkbox" class="form-check-input"></td>
                                                <td style="width: 70px;">
                                                    <div class="img-card">
                                                        <img src="<?= getItemImage($out_row['image']) ?>" alt="Image" onerror="this.onerror=null; this.src='https://via.placeholder.com/50?text=No+Img';">
                                                    </div>
                                                </td>
                                                <td style="width: 180px;"><span class="part-code"><?= htmlspecialchars($out_row['item_code']) ?></span></td>
                                                <td><span class="item-desc"><?= htmlspecialchars($out_row['item_name']) ?></span></td>
                                                <td class="text-end" style="width: 80px;"><span class="badge bg-danger fs-6"><?= $out_row['stock_qty'] ?></span></td>
                                            </tr>
                                        <?php endwhile; else: ?>
                                            <tr><td colspan="5" class="text-center text-muted py-3">No outbound items tracked.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- JOB TRANS PANEL -->
                    <div class="tab-pane fade" id="jobtrans-panel" role="tabpanel">
                        <div class="card-box border-start border-5 border-warning">
                            <h5 class="mb-3 text-warning fw-bold">🔄 Job Item Out/In Transactions</h5>
                            <div class="table-responsive">
                                <table class="table align-middle table-item-list m-0">
                                    <tbody>
                                        <?php if($job_trans_query && mysqli_num_rows($job_trans_query) > 0): mysqli_data_seek($job_trans_query, 0); while($j_trans = mysqli_fetch_assoc($job_trans_query)): ?>
                                            <tr>
                                                <td style="width: 40px;"><input type="checkbox" class="form-check-input"></td>
                                                <td style="width: 70px;">
                                                    <div class="img-card">
                                                        <img src="<?= getItemImage($j_trans['image']) ?>" alt="Image" onerror="this.onerror=null; this.src='https://via.placeholder.com/50?text=No+Img';">
                                                    </div>
                                                </td>
                                                <td style="width: 150px;"><span class="text-dark fw-bold"><?= htmlspecialchars($j_trans['job_no']) ?></span></td>
                                                <td style="width: 150px;"><span class="part-code"><?= htmlspecialchars($j_trans['item_code']) ?></span></td>
                                                <td><span class="item-desc"><?= htmlspecialchars($j_trans['item_name']) ?></span></td>
                                                <td class="text-end"><small class="text-muted"><?= date('d M Y H:i', strtotime($j_trans['trans_date'])) ?></small></td>
                                            </tr>
                                        <?php endwhile; else: ?>
                                            <tr><td colspan="6" class="text-center text-muted py-3">No client tracking lines updated.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <script>
        // LIVE DATE AND TIME CLOCK SCRIPT
        function updateLiveClock() {
            const now = new Date();
            const options = { 
                day: '2-digit', 
                month: 'short', 
                year: 'numeric', 
                hour: '2-digit', 
                minute: '2-digit', 
                second: '2-digit',
                hour12: false 
            };
            document.getElementById('liveClockDisplay').innerHTML = "🕒 " + now.toLocaleString('en-GB', options);
        }
        setInterval(updateLiveClock, 1000);
        updateLiveClock();

        // SCANNER SCRIPT
        let html5QrCode;
        let currentFacingMode = "environment";

        function onScanSuccess(decodedText) {
            document.getElementById('search_box').value = decodedText;
            document.getElementById('scan-results').innerText = "🎯 Scanned Code: " + decodedText;
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
                document.getElementById('scan-results').innerHTML = `<span class="text-warning">⚠️ Live video streaming locked over HTTP network link. Use file upload option below to take snapshots!</span>`;
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
            }).catch(() => { alert("❌ System failed to resolve clear barcode lines inside snapshot image."); });
        });

        function handleSearchAndHighlight(val) {
            if(!val.trim()) {
                document.getElementById('db-lookup-info').innerHTML = "";
                document.querySelectorAll('.item-row').forEach(row => row.style.backgroundColor = "");
                return;
            }

            let fd = new FormData(); fd.append('identifier', val);
            fetch('find_item_name.php', { method: 'POST', body: fd })
            .then(r => r.text()).then(html => document.getElementById('db-lookup-info').innerHTML = html);

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