<?php
session_start();

// 1. Establish database connection pathways
if (file_exists('config/db.php')) {
    include('config/db.php');
} elseif (file_exists('../config/db.php')) {
    include('../config/db.php');
} else {
    die("<div class='alert alert-danger' style='margin:20px;'><strong>Database Connection Path Error:</strong> Could not find config/db.php.</div>");
}

$message = "";
$job_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// 2. Fetch current profile values
$job_query = "SELECT * FROM jobs WHERE id = $job_id";
$job_result = mysqli_query($conn, $job_query);
$job = mysqli_fetch_assoc($job_result);

if (!$job) {
    die("<div class='alert alert-danger' style='margin:30px;'><strong>Error:</strong> Job profile not found.</div>");
}

// 3. Process data save requests (POST)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_job'])) {
    $client_name = mysqli_real_escape_string($conn, trim($_POST['client_name']));
    $tanker_no   = mysqli_real_escape_string($conn, trim($_POST['tanker_no']));
    $status      = mysqli_real_escape_string($conn, trim($_POST['status']));
    $description = mysqli_real_escape_string($conn, trim($_POST['description']));

    $update_query = "UPDATE jobs SET 
                        client_name = '$client_name', 
                        tanker_no = '$tanker_no', 
                        status = '$status', 
                        description = '$description' 
                     WHERE id = $job_id";

    if (mysqli_query($conn, $update_query)) {
        $message = "<div class='alert alert-success'>🎉 **Success!** Job specifications updated successfully.</div>";
        // Refresh values in memory
        $job['client_name'] = $client_name;
        $job['tanker_no'] = $tanker_no;
        $job['status'] = $status;
        $job['description'] = $description;
    } else {
        $message = "<div class='alert alert-danger'>Database Error: " . mysqli_error($conn) . "</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Job Sheet - <?= htmlspecialchars($job['job_no']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background:#f4f6f9; font-family:'Segoe UI', sans-serif; }
        .sidebar { width:250px; height:100vh; background:#111827; position:fixed; left:0; top:0; }
        .logo { background:#f97316; padding:20px; text-align:center; font-size:22px; font-weight:bold; color:white; }
        .sidebar a { display:block; padding:15px; color:#9ca3af; text-decoration:none; transition:.3s; }
        .sidebar a:hover { background:#f97316; color:white; }
        .main { margin-left:250px; padding:20px; }
        .card-box { background:white; padding:30px; border-radius:12px; box-shadow:0 2px 10px rgba(0,0,0,.08); max-width:750px; }
    </style>
</head>
<body>

    <div class="sidebar">
        <div class="logo">WAREHOUSE</div>
        <a href="dashboard.php">🏠 Dashboard</a>
        <a href="items/item_list.php">📦 Items</a>
        <a href="jobs/job_list.php">📝 Job List</a>
        <a href="create_job.php">➕ Create Job</a>
        <a href="logout.php">🚪 Logout</a>
    </div>

    <div class="main">
        <div class="card-box mx-auto mt-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h3 class="fw-bold m-0 text-dark">✏️ Edit Job Settings</h3>
                <a href="jobs/job_list.php" class="btn btn-sm btn-secondary px-3">Back to List</a>
            </div>

            <?= $message ?>

            <form method="POST">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold text-muted">Job Number Code (Read-Only)</label>
                        <input type="text" class="form-control bg-light p-2.5 fw-bold text-primary" value="<?= htmlspecialchars($job['job_no']) ?>" readonly>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold text-muted">Client Target Identity Name</label>
                        <input type="text" name="client_name" class="form-control p-2.5" value="<?= htmlspecialchars($job['client_name']) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold text-muted">Tanker Registration Reference</label>
                        <input type="text" name="tanker_no" class="form-control p-2.5" value="<?= htmlspecialchars($job['tanker_no'] ?? $job['job_no']) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold text-muted">Operational Status Code</label>
                        <select name="status" class="form-select p-2.5">
                            <option value="Pending" <?= $job['status'] == 'Pending' ? 'selected' : '' ?>>⏳ Pending</option>
                            <option value="In Progress" <?= $job['status'] == 'In Progress' ? 'selected' : '' ?>>⚙️ In Progress</option>
                            <option value="Completed" <?= $job['status'] == 'Completed' ? 'selected' : '' ?>>✅ Completed</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold text-muted">Internal Context Description Comments</label>
                        <textarea name="description" class="form-control" rows="4"><?= htmlspecialchars($job['description']) ?></textarea>
                    </div>
                </div>
                
                <div class="mt-4 pt-2 d-flex gap-2">
                    <button type="submit" name="update_job" class="btn text-white px-4 fw-bold shadow-sm" style="background:#f97316;">Save Dynamic Changes</button>
                    <a href="view_job.php?id=<?= $job_id ?>" class="btn btn-outline-dark px-4 fw-bold">View Allocated Parts</a>
                </div>
            </form>
        </div>
    </div>

</body>
</html>