<?php
// 1. Database Connection via config folder
include(__DIR__ . '/config/db.php');

// CREATE OVERRIDE TABLE IF NOT EXISTS
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS dashboard_overrides (
    meta_key VARCHAR(50) PRIMARY KEY,
    meta_value VARCHAR(255) NULL
)");

// ==========================================
// HANDLE CLEAR / RESET OVERRIDES TO LIVE DB
// ==========================================
if (isset($_POST['reset_overrides'])) {
    mysqli_query($conn, "TRUNCATE TABLE dashboard_overrides");
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// ==========================================
// HANDLE SAVE FROM EDIT DASHBOARD FORM
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_dashboard_form'])) {
    if (isset($_POST['override_data']) && is_array($_POST['override_data'])) {
        foreach ($_POST['override_data'] as $key => $val) {
            $key_clean = mysqli_real_escape_string($conn, $key);
            $val_clean = trim(str_replace(["\r", "\n"], '', $val));
            
            // Delete override if blank
            if ($val_clean === "") {
                mysqli_query($conn, "DELETE FROM dashboard_overrides WHERE meta_key = '$key_clean'");
            } else {
                $val_clean = mysqli_real_escape_string($conn, $val_clean);
                mysqli_query($conn, "INSERT INTO dashboard_overrides (meta_key, meta_value) 
                                     VALUES ('$key_clean', '$val_clean') 
                                     ON DUPLICATE KEY UPDATE meta_value = '$val_clean'");
            }
        }
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// ==========================================
// DYNAMIC LIVE DATA CALCULATION ENGINE
// ==========================================
function getLiveDashboardData($conn) {
    $dbValues = [
        'd_total' => 0, 'd_instock' => 0, 'd_outstock' => 0,
        'd_jobs' => 0, 'd_missing' => 0, 'd_lowstock' => 0
    ];

    // 1. Total Items
    $q = mysqli_query($conn, "SELECT COUNT(*) as total FROM items");
    if ($q) { $dbValues['d_total'] = mysqli_fetch_assoc($q)['total']; }

    // 2. In Stock Items (stock_qty > 0)
    $qIn = mysqli_query($conn, "SELECT COUNT(*) as total FROM items WHERE COALESCE(stock_qty, 0) > 0");
    if ($qIn) { $dbValues['d_instock'] = mysqli_fetch_assoc($qIn)['total']; }

    // 3. Out of Stock Items (stock_qty <= 0)
    $qOut = mysqli_query($conn, "SELECT COUNT(*) as total FROM items WHERE COALESCE(stock_qty, 0) <= 0");
    if ($qOut) { $dbValues['d_outstock'] = mysqli_fetch_assoc($qOut)['total']; }

    // 4. Open Jobs
    $table_check_jobs = mysqli_query($conn, "SHOW TABLES LIKE 'jobs'");
    if ($table_check_jobs && mysqli_num_rows($table_check_jobs) > 0) {
        $qJobs = mysqli_query($conn, "SELECT COUNT(*) as total FROM jobs");
        if ($qJobs) { $dbValues['d_jobs'] = mysqli_fetch_assoc($qJobs)['total']; }
    } else {
        $table_check_jobs2 = mysqli_query($conn, "SHOW TABLES LIKE 'job_list'");
        if ($table_check_jobs2 && mysqli_num_rows($table_check_jobs2) > 0) {
            $qJobs = mysqli_query($conn, "SELECT COUNT(*) as total FROM job_list");
            if ($qJobs) { $dbValues['d_jobs'] = mysqli_fetch_assoc($qJobs)['total']; }
        }
    }

    // 5. Missing Items
    $table_check_missing = mysqli_query($conn, "SHOW TABLES LIKE 'missing_items'");
    if ($table_check_missing && mysqli_num_rows($table_check_missing) > 0) {
        $qMissing = mysqli_query($conn, "SELECT COUNT(*) as total FROM missing_items");
        if ($qMissing) { $dbValues['d_missing'] = mysqli_fetch_assoc($qMissing)['total']; }
    }

    // 6. Low Stock Items (1 to 5)
    $qLow = mysqli_query($conn, "SELECT COUNT(*) as total FROM items WHERE stock_qty > 0 AND stock_qty <= 5");
    if ($qLow) { $dbValues['d_lowstock'] = mysqli_fetch_assoc($qLow)['total']; }

    // ==========================================
    // COMBINED REAL-TIME RECENT ACTIVITY QUERY
    // ==========================================
    $activityRowsHtml = '';
    
    // Check if created_at exists on items
    $col_check = mysqli_query($conn, "SHOW COLUMNS FROM items LIKE 'created_at'");
    $itemDateCol = ($col_check && mysqli_num_rows($col_check) > 0) ? "i.created_at" : "NOW()";

    $activity_query = mysqli_query($conn, "
        (SELECT CONCAT('🆕 New Item Registered: ', i.item_name, ' (Part: ', COALESCE(i.item_code, 'N/A'), ')') AS action, $itemDateCol AS act_date FROM items i)
        UNION ALL
        (SELECT CONCAT('⬆ Stock Added: ', i.item_name, ' (Part: ', COALESCE(i.item_code, 'N/A'), ')') AS action, si.created_at AS act_date FROM stock_in si JOIN items i ON si.item_id = i.id)
        UNION ALL
        (SELECT CONCAT('⬇ Stock Dispatched: ', i.item_name, ' (Part: ', COALESCE(i.item_code, 'N/A'), ')') AS action, so.created_at AS act_date FROM stock_out so JOIN items i ON so.item_id = i.id)
        ORDER BY act_date DESC LIMIT 7
    ");

    if ($activity_query && mysqli_num_rows($activity_query) > 0) {
        while ($act = mysqli_fetch_assoc($activity_query)) {
            $activityRowsHtml .= "<tr>";
            $activityRowsHtml .= "<td>" . htmlspecialchars($act['action']) . "</td>";
            $activityRowsHtml .= "<td>" . date('d M Y, h:i A', strtotime($act['act_date'])) . "</td>";
            $activityRowsHtml .= "</tr>";
        }
    } else {
        $activityRowsHtml = "<tr><td colspan='2' class='text-muted text-center py-3'>No recent operational activities recorded across stock channels.</td></tr>";
    }

    return ['dbValues' => $dbValues, 'activityHtml' => $activityRowsHtml];
}

// ==========================================
// AJAX LIVE DATA JSON RESPONDER
// ==========================================
if (isset($_GET['ajax_fetch'])) {
    header('Content-Type: application/json');
    $liveData = getLiveDashboardData($conn);
    
    // Fetch Overrides
    $overrides = [];
    $overrides_query = mysqli_query($conn, "SELECT * FROM dashboard_overrides");
    if ($overrides_query) {
        while ($row = mysqli_fetch_assoc($overrides_query)) {
            if ($row['meta_value'] !== null && trim($row['meta_value']) !== "") {
                $overrides[$row['meta_key']] = $row['meta_value'];
            }
        }
    }
    
    echo json_encode([
        'dbValues' => $liveData['dbValues'],
        'overrides' => $overrides,
        'activityHtml' => $liveData['activityHtml']
    ]);
    exit;
}

// Default initial page render
$data = getLiveDashboardData($conn);
$dbValues = array_merge([
    't_total' => 'Total Items', 't_instock' => 'In Stock', 't_outstock' => 'Out Of Stock',
    't_jobs' => 'Open Jobs', 't_missing' => 'Missing Items', 't_lowstock' => 'Low Stock'
], $data['dbValues']);

$displayValues = $dbValues; 
$overrides_query = mysqli_query($conn, "SELECT * FROM dashboard_overrides");
$hasOverrides = false;
if ($overrides_query) {
    while ($row = mysqli_fetch_assoc($overrides_query)) {
        if ($row['meta_value'] !== null && trim($row['meta_value']) !== "") {
            $displayValues[$row['meta_key']] = $row['meta_value'];
            $hasOverrides = true;
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Warehouse System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome CDN added below -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <style>
    /* Sidebar Container */
.sidebar {
    width: 240px;
    background-color: #1a2232;
    min-height: 100vh;
}

/* Header Logo */
.sidebar .logo {
    background-color: #ff6801; /* Orange Header */
    color: #ffffff;
    font-weight: bold;
    font-size: 20px;
    text-align: center;
    padding: 18px 10px;
    letter-spacing: 1px;
}

/* Menu Container */
.sidebar-menu {
    display: flex;
    flex-direction: column;
}

/* Base Links */
.sidebar-menu a {
    display: flex;
    align-items: center;
    padding: 14px 20px;
    color: #c2c7d0;
    text-decoration: none;
    font-size: 15px;
    transition: all 0.2s ease;
    border-left: 4px solid transparent; /* Keeps layout smooth on hover */
}

/* Icons styling */
.sidebar-menu a i {
    width: 25px;
    margin-right: 12px;
    font-size: 16px;
    text-align: center;
}

/* Active Item (Orange Left Border + Lighter Background) */
.sidebar-menu a.active {
    background-color: #121824;
    color: #ffffff;
    border-left-color: #ff6801;
    font-weight: 600;
}

/* Hover State */
.sidebar-menu a:hover {
    background-color: #222d42;
    color: #ffffff;
}
</style>
<form id="dashForm" method="POST" action="" style="display:none;">
    <input type="hidden" name="save_dashboard_form" value="1">
    <div id="hiddenInputsContainer"></div>
</form>

<form id="resetForm" method="POST" action="" style="display:none;">
    <input type="hidden" name="reset_overrides" value="1">
</form>

<<div class="sidebar">
    <div class="logo">WAREHOUSE</div>
    <div class="sidebar-menu">
        <a href="dashboard.php">
            <i class="fa-solid fa-gauge-high"></i> Dashboard
        </a>
        <a href="items/item_list.php" class="active">
            <i class="fa-solid fa-box-archive"></i> Items
        </a>
        <a href="items/add_item.php">
            <i class="fa-solid fa-plus"></i> Add Item
        </a>
        <a href="import_excel.php">
            <i class="fa-solid fa-file-import"></i> Import Excel
        </a>
        <a href="create_job.php">
            <i class="fa-solid fa-file-circle-plus"></i> Create Job
        </a>
        <a href="job_list.php">
            <i class="fa-solid fa-file-lines"></i> Job List
        </a>
        <a href="stock/stock_in.php">
            <i class="fa-solid fa-arrow-trend-up"></i> Stock In
        </a>
        <a href="items/stock_out.php">
            <i class="fa-solid fa-arrow-trend-down"></i> Stock Out
        </a>
        <a href="return_item.php">
            <i class="fa-solid fa-rotate-left"></i> Returns
        </a>
        <a href="stock/missing_item.php">
            <i class="fa-solid fa-triangle-exclamation"></i> Missing
        </a>
        <a href="scaner.php">
            <i class="fa-solid fa-barcode"></i> Scanner
        </a>
        <a href="reports/stock_report.php">
            <i class="fa-solid fa-chart-pie"></i> Reports
        </a>
        <a href="logout.php">
            <i class="fa-solid fa-right-from-bracket"></i> Logout
        </a>
    </div>
</div>
<div class="topbar d-flex justify-content-between align-items-center">
    <h4>Warehouse Dashboard</h4>
    <div class="d-flex align-items-center gap-2">
        <?php if ($hasOverrides): ?>
            <button class="btn btn-sm btn-outline-warning fw-bold" onclick="document.getElementById('resetForm').submit();">🔄 Reset to Live DB</button>
        <?php endif; ?>
        <span class="text-white ms-2">Admin</span>
        <button id="editDashboardBtn" class="btn btn-sm fw-bold text-white" style="border-left: 1px solid #4b5563; padding-left: 12px; background: transparent;" onclick="toggleDashboardEditing()">⚙️ Edit Dashboard: OFF</button>
    </div>
</div>

<div class="main">
    <div class="row g-3" id="editableCardRow">
        <div class="col-md-2">
            <div class="card-box">
                <h2 id="d_total" data-dbval="<?= $dbValues['d_total'] ?>"><?= htmlspecialchars($displayValues['d_total']) ?></h2>
                <p id="t_total"><?= htmlspecialchars($displayValues['t_total']) ?></p>
            </div>
        </div>

        <div class="col-md-2">
            <div class="card-box">
                <h2 id="d_instock" class="text-success" data-dbval="<?= $dbValues['d_instock'] ?>"><?= htmlspecialchars($displayValues['d_instock']) ?></h2>
                <p id="t_instock"><?= htmlspecialchars($displayValues['t_instock']) ?></p>
            </div>
        </div>

        <div class="col-md-2">
            <div class="card-box">
                <h2 id="d_outstock" class="text-danger" data-dbval="<?= $dbValues['d_outstock'] ?>"><?= htmlspecialchars($displayValues['d_outstock']) ?></h2>
                <p id="t_outstock"><?= htmlspecialchars($displayValues['t_outstock']) ?></p>
            </div>
        </div>

        <div class="col-md-2">
            <div class="card-box">
                <h2 id="d_jobs" data-dbval="<?= $dbValues['d_jobs'] ?>"><?= htmlspecialchars($displayValues['d_jobs']) ?></h2>
                <p id="t_jobs"><?= htmlspecialchars($displayValues['t_jobs']) ?></p>
            </div>
        </div>

        <div class="col-md-2">
            <div class="card-box">
                <h2 id="d_missing" data-dbval="<?= $dbValues['d_missing'] ?>"><?= htmlspecialchars($displayValues['d_missing']) ?></h2>
                <p id="t_missing"><?= htmlspecialchars($displayValues['t_missing']) ?></p>
            </div>
        </div>

        <div class="col-md-2">
            <div class="card-box">
                <h2 id="d_lowstock" data-dbval="<?= $dbValues['d_lowstock'] ?>"><?= htmlspecialchars($displayValues['d_lowstock']) ?></h2>
                <p id="t_lowstock"><?= htmlspecialchars($displayValues['t_lowstock']) ?></p>
            </div>
        </div>
    </div>

    <br>

    <div class="card-box">
        <h5>Recent Activity</h5>
        <table class="table table-dark table-striped mt-2">
            <thead>
                <tr>
                    <th>Action / Process Mapped</th>
                    <th>Date / Timestamp Log</th>
                </tr>
            </thead>
            <tbody id="recentActivityBody">
                <?= $data['activityHtml'] ?>
            </tbody>
        </table>
    </div>
</div>

<style>
body { background: #0f172a; font-family: 'Segoe UI', sans-serif; }
.sidebar { width: 260px; height: 100vh; position: fixed; background: #111827; color: white; overflow-y: auto; }
.sidebar .logo { background: #f97316; padding: 18px; font-size: 20px; font-weight: bold; text-align: center; }
.sidebar a { display: block; padding: 12px 18px; color: white; text-decoration: none; transition: 0.3s; }
.sidebar a:hover { background: #f97316; }
.main { margin-left: 260px; padding: 20px; }
.topbar { background: #1f2937; padding: 15px; border-radius: 12px; color: white; margin-bottom: 20px; }
.card-box { background: #1f2937; padding: 20px; border-radius: 15px; color: white; box-shadow: 0 5px 15px rgba(0,0,0,0.3); transition: outline 0.2s; }
.card-box h2 { color: #f97316; font-size: 32px; }
.card-box h2.text-success { color: #10b981 !important; }
.card-box h2.text-danger { color: #ef4444 !important; }
.editing-active .card-box h2, .editing-active .card-box p { outline: 1px dashed #f97316; cursor: text; }
</style>

<script>
const targets = ['d_total', 't_total', 'd_instock', 't_instock', 'd_outstock', 't_outstock', 'd_jobs', 't_jobs', 'd_missing', 't_missing', 'd_lowstock', 't_lowstock'];
let isEditing = false;

// Dynamic Polling Engine (Every 2 seconds)
function pollLiveData() {
    if (isEditing) return;

    fetch('?ajax_fetch=1')
        .then(res => res.json())
        .then(data => {
            if (isEditing) return;

            const valueKeys = ['d_total', 'd_instock', 'd_outstock', 'd_jobs', 'd_missing', 'd_lowstock'];
            valueKeys.forEach(key => {
                const el = document.getElementById(key);
                if (el) {
                    const finalVal = (data.overrides && data.overrides[key] !== undefined) ? data.overrides[key] : data.dbValues[key];
                    el.innerText = finalVal;
                }
            });

            // Update Live Recent Activity Table
            const activityTable = document.getElementById('recentActivityBody');
            if (activityTable && data.activityHtml) {
                activityTable.innerHTML = data.activityHtml;
            }
        })
        .catch(err => console.error("Live fetch error:", err));
}

setInterval(pollLiveData, 2000);

function toggleDashboardEditing() {
    isEditing = !isEditing;
    const btn = document.getElementById('editDashboardBtn');
    const row = document.getElementById('editableCardRow');
    
    if (isEditing) {
        btn.innerText = "💾 Save Changes";
        btn.style.color = "#10b981";
        row.classList.add('editing-active');
        targets.forEach(id => { if(document.getElementById(id)) document.getElementById(id).contentEditable = "true"; });
    } else {
        btn.innerText = "⚙️ Edit Dashboard: OFF";
        btn.style.color = "#ffffff";
        row.classList.remove('editing-active');
        
        const container = document.getElementById('hiddenInputsContainer');
        container.innerHTML = ''; 
        
        targets.forEach(id => {
            const el = document.getElementById(id);
            if(el) {
                el.contentEditable = "false";
                let currentText = el.innerText.trim();
                
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = `override_data[${id}]`;
                input.value = currentText;
                container.appendChild(input);
            }
        });

        document.getElementById('dashForm').submit();
    }
}
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>