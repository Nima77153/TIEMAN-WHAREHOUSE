<?php
session_start();

// 1. Establish database connection cleanly
if (file_exists('config/db.php')) {
    include('config/db.php');
} elseif (file_exists('../config/db.php')) {
    include('../config/db.php');
} else {
    die("<div class='alert alert-danger' style='margin:20px;'><strong>Database Connection Error:</strong> Missing config/db.php configuration pathway file.</div>");
}

// 2. Validate routing parameters safely
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: jobs/job_list.php");
    exit;
}

$job_id = intval($_GET['id']);

// 3. Fetch Master Job Ticket Record
$job_query = "SELECT * FROM jobs WHERE id = $job_id";
$job_result = mysqli_query($conn, $job_query);
$job = mysqli_fetch_assoc($job_result);

if (!$job) {
    die("<div class='alert alert-danger' style='margin:30px;'><strong>Database Exception:</strong> The requested Job Sheet profile ID could not be located.</div>");
}

// 4. FIXED QUERY: Fetches structural rows link mappings along with raw non-zero quantity matrices
$items_query = "SELECT 
                    ji.quantity, 
                    ji.remark, 
                    i.item_code, 
                    i.item_name, 
                    i.description, 
                    i.image_url
                FROM job_items ji
                INNER JOIN items i ON ji.item_id = i.id
                WHERE ji.job_id = $job_id AND ji.quantity > 0
                ORDER BY i.item_code ASC";
$items_result = mysqli_query($conn, $items_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Job Sheet Breakdown - <?= htmlspecialchars($job['job_no']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background:#f4f6f9; font-family:'Segoe UI', sans-serif; }
        .sidebar { width:250px; height:100vh; background:#111827; position:fixed; left:0; top:0; overflow:auto; z-index: 100; }
        .logo { background:#f97316; padding:20px; text-align:center; font-size:22px; font-weight:bold; color:white; }
        .sidebar a { display:block; padding:15px; color:#9ca3af; text-decoration:none; transition:.2s; }
        .sidebar a:hover, .sidebar a.active { background:#f97316; color:white; }
        .main { margin-left:250px; padding:20px; }
        .topbar { background:white; padding:15px 20px; border-radius:10px; box-shadow:0 2px 10px rgba(0,0,0,.05); margin-bottom:20px; }
        
        .card-box { background:white; padding:30px; border-radius:12px; box-shadow:0 2px 10px rgba(0,0,0,.06); }
        .meta-grid-table { width: 100%; margin-bottom: 25px; border-collapse: collapse; }
        .meta-grid-table td { padding: 10px 14px; border: 1px solid #e2e8f0; font-size: 14px; }
        .meta-grid-table .lbl-heading { background-color: #f8fafc; font-weight: 600; color: #475569; width: 18%; }
        .meta-grid-table .val-text { color: #1e293b; }
        
        .search-wrapper { position: relative; max-width: 380px; margin-bottom: 20px; }
        .search-box { width: 100%; padding: 9px 12px 9px 38px; border: 2px solid #f97316; border-radius: 6px; outline: none; font-size: 14px; }
        .search-icon { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: #f97316; font-weight: bold; }
        
        .parts-table thead { background-color: #f1f5f9; }
        .parts-table th { font-weight: 600; color: #334155; border-bottom: 2px solid #cbd5e1; font-size: 14px; padding: 12px; }
        .parts-table tbody tr:hover { background-color: #f8fafc; }
        
        /* Thumbnail frame fix layout */
        .thumb-img-frame { width: 50px; height: 50px; background: #f1f5f9; display: flex; align-items: center; justify-content: center; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 11px; color: #94a3b8; font-weight: bold; overflow: hidden; }
        .thumb-img-frame img { width: 100%; height: 100%; object-fit: cover; }
        
        /* Highlighted orange metrics layout indicator badge */
        .qty-badge { background-color: #fff7ed; color: #ea580c; border: 1px solid #ffedd5; padding: 6px 12px; border-radius: 6px; font-weight: 700; font-size: 15px; display: inline-block; min-width: 45px; text-center: center; }
    </style>
</head>
<body>

    <div class="sidebar">
        <div class="logo">WAREHOUSE</div>
        <a href="dashboard.php">🏠 Dashboard</a>
        <a href="items/item_list.php">📦 Items</a>
        <a href="items/add_item.php">➕ Add Item</a>
        <a href="jobs/job_list.php" class="active">📝 Job List</a>
        <a href="create_job.php">➕ Create Job</a>
        <a href="logout.php">🚪 Logout</a>
    </div>

    <div class="main">
        <div class="topbar">
            <h5 class="m-0 fw-bold text-secondary">Tieman Warehouse Allocation Tracking System</h5>
        </div>

        <div class="card-box">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h3 class="fw-bold m-0 text-dark">Job Components Allocations</h3>
                    <p class="text-muted small m-0">Detailed breakdown lists of parts mapped matching your imported workbook manifest.</p>
                </div>
                <a href="jobs/job_list.php" class="btn btn-dark px-4 fw-bold shadow-sm">← Back to Job List</a>
            </div>

            <table class="meta-grid-table shadow-sm">
                <tr>
                    <td class="lbl-heading">Job Allocation No:</td>
                    <td class="val-text fw-bold text-primary fs-5"><?= htmlspecialchars($job['job_no']) ?></td>
                    <td class="lbl-heading">Client Assignment:</td>
                    <td class="val-text fw-semibold"><?= htmlspecialchars($job['client_name']) ?></td>
                </tr>
                <tr>
                    <td class="lbl-heading">Tanker Registration:</td>
                    <td class="val-text"><?= htmlspecialchars($job['tanker_no'] ?? $job['job_no']) ?></td>
                    <td class="lbl-heading">Operational Status:</td>
                    <td class="val-text"><span class="badge bg-warning text-dark px-3 py-2 fw-bold"><?= htmlspecialchars($job['status']) ?></span></td>
                </tr>
                <tr>
                    <td class="lbl-heading">Internal Scope Notes:</td>
                    <td class="val-text text-muted small" colspan="3"><?= !empty($job['description']) ? nl2br(htmlspecialchars($job['description'])) : 'No additional production comments attached to this job card layout profiles.' ?></td>
                </tr>
            </table>

            <div class="search-wrapper">
                <span class="search-icon">🔍</span>
                <input type="text" id="partsSearch" class="search-box" placeholder="Filter item codes, names, or remarks..." onkeyup="filterPartsTable()">
            </div>

            <div class="table-responsive">
                <table class="table table-bordered align-middle text-start" id="partsTable">
                    <thead>
                        <tr>
                            <th class="text-center" style="width: 50px;">#</th>
                            <th class="text-center" style="width: 80px;">Item Image</th>
                            <th style="width: 180px;">Tieman Part No.</th>
                            <th>Description Notes Context</th>
                            <th class="text-center" style="width: 110px;">Quantity</th>
                            <th>Manifest Row Remark</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $counter = 1;
                        if (mysqli_num_rows($items_result) > 0) {
                            while ($item = mysqli_fetch_assoc($items_result)) {
                                
                                // Clean up the image lookup check logic loop routines
                                $image_src = ""; 
                                if (!empty($item['image_url']) && file_exists($item['image_url'])) {
                                    $image_src = $item['image_url'];
                                } elseif (file_exists("uploads/items/" . $item['item_code'] . ".jpg")) {
                                    $image_src = "uploads/items/" . $item['item_code'] . ".jpg";
                                } elseif (file_exists("uploads/items/" . $item['item_code'] . ".png")) {
                                    $image_src = "uploads/items/" . $item['item_code'] . ".png";
                                }
                        ?>
                            <tr>
                                <td class="text-center text-muted fw-bold font-monospace small"><?= $counter++ ?></td>
                                <td class="text-center">
                                    <div class="thumb-img-frame mx-auto">
                                        <?php if (!empty($image_src)): ?>
                                            <img src="<?= $image_src ?>" alt="Part Media Thumb">
                                        <?php else: ?>
                                            NO IMG
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="fw-bold text-danger fs-6"><?= htmlspecialchars($item['item_code']) ?></td>
                                <td>
                                    <div class="fw-semibold text-dark mb-0" style="font-size:14px;"><?= htmlspecialchars($item['item_name']) ?></div>
                                    <div class="text-secondary small mt-0.5" style="font-size:12px; max-width:450px;"><?= htmlspecialchars($item['description'] ?? '-') ?></div>
                                </td>
                                <td class="text-center">
                                    <span class="qty-badge shadow-sm"><?= $item['quantity'] ?></span>
                                </td>
                                <td class="small text-muted italic"><?= !empty($item['remark']) ? htmlspecialchars($item['remark']) : '-' ?></td>
                            </tr>
                        <?php 
                            }
                        } else {
                            echo "<tr><td colspan='6' class='text-center p-5 text-muted style='font-style: italic;''>⚠️ No active material components allocations are matching or mapped against this Job Profile yet. Please verify your file upload steps inside create_job.php</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
    function filterPartsTable() {
        var input = document.getElementById("partsSearch").value.toUpperCase();
        var rows = document.getElementById("partsTable").getElementsByTagName("tr");

        for (var i = 1; i < rows.length; i++) {
            var tdCode = rows[i].getElementsByTagName("td")[2];
            var tdDesc = rows[i].getElementsByTagName("td")[3];
            var tdRemk = rows[i].getElementsByTagName("td")[5];
            
            if (tdCode || tdDesc || tdRemk) {
                var txtCode = tdCode.textContent || tdCode.innerText;
                var txtDesc = tdDesc.textContent || tdDesc.innerText;
                var txtRemk = tdRemk ? (tdRemk.textContent || tdRemk.innerText) : "";
                
                if (txtCode.toUpperCase().indexOf(input) > -1 || 
                    txtDesc.toUpperCase().indexOf(input) > -1 ||
                    txtRemk.toUpperCase().indexOf(input) > -1) {
                    rows[i].style.display = "";
                } else {
                    rows[i].style.display = "none";
                }
            }       
        }
    }
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>