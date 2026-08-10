<?php
session_start();
include('../config/db.php'); 

if (!$conn) {
    die("<div class='alert alert-danger m-3'><b>Database Connection Error:</b> " . mysqli_connect_error() . "</div>");
}

// Fetch search and filter inputs
$search   = isset($_GET['search']) ? mysqli_real_escape_string($conn, trim($_GET['search'])) : '';
$category = isset($_GET['category']) ? mysqli_real_escape_string($conn, trim($_GET['category'])) : '';
$sort     = isset($_GET['sort']) ? $_GET['sort'] : 'newest';

// Build SQL Query
$where_clauses = [];

if (!empty($search)) {
    $where_clauses[] = "(item_code LIKE '%$search%' OR item_name LIKE '%$search%' OR description LIKE '%$search%' OR location LIKE '%$search%')";
}

if (!empty($category)) {
    $where_clauses[] = "category = '$category'";
}

$where_sql = "";
if (count($where_clauses) > 0) {
    $where_sql = " WHERE " . implode(' AND ', $where_clauses);
}

// Sorting logic
$order_by = "ORDER BY created_at DESC";
if ($sort == 'oldest') {
    $order_by = "ORDER BY created_at ASC";
} elseif ($sort == 'name_asc') {
    $order_by = "ORDER BY item_name ASC";
} elseif ($sort == 'qty_desc') {
    $order_by = "ORDER BY stock_qty DESC";
}

$query = "SELECT * FROM items $where_sql $order_by";
$result = mysqli_query($conn, $query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Warehouse - Item List</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background:#1e293b; color: white; font-family:'Segoe UI', sans-serif; }
        .sidebar { width:260px; height:100vh; background:#111827; position:fixed; left:0; top:0; overflow:auto; z-index: 100; }
        .logo { background:#f97316; padding:18px; text-align:center; font-size:20px; font-weight:bold; color:white; }
        .sidebar a { display:block; padding:12px 18px; color:white; text-decoration:none; transition:.3s; }
        .sidebar a:hover, .sidebar .active { background:#f97316; }
        .main { margin-left:260px; padding:20px; }
        .card-box { background:#1f2937; padding:25px; border-radius:15px; box-shadow: 0 5px 15px rgba(0,0,0,0.3); }
        .form-control, .form-select { background-color: #374151; border: 1px solid #4b5563; color: white; }
        .form-control:focus, .form-select:focus { background-color: #374151; color: white; border-color: #f97316; box-shadow: none; }
        .form-control::placeholder { color: #9ca3af; }
        .table-dark-custom { background-color: #1f2937; color: white; border-color: #374151; }
        .table-dark-custom th { background-color: #111827; color: #9ca3af; border-color: #374151; font-size: 13px; text-transform: uppercase; }
        .table-dark-custom td { border-color: #374151; vertical-align: middle; }
        @media(max-width: 768px) { .sidebar { display: none; } .main { margin-left: 0; padding: 10px; } }
    </style>
</head>
<body>

    <div class="sidebar">
        <div class="logo">WAREHOUSE SYSTEM</div>
        <a href="http://172.20.10.7/TIEMAN%20WAREHOUSE/dashboard.php">🏠 Dashboard</a>
        <a href="http://172.20.10.7/TIEMAN%20WAREHOUSE/items/item_list.php" class="active">📦 Items</a>
        <a href="http://172.20.10.7/TIEMAN%20WAREHOUSE/items/add_item.php">➕ Add Item</a>
        <a href="http://172.20.10.7/TIEMAN%20WAREHOUSE/import_excel.php">📥 Import Excel</a>
        <a href="http://172.20.10.7/TIEMAN%20WAREHOUSE/create_job.php">📋 Create Job</a>
        <a href="http://172.20.10.7/TIEMAN%20WAREHOUSE/job_list.php">📝 Job List</a>
        <a href="http://172.20.10.7/TIEMAN%20WAREHOUSE/items/stock_in.php">⬆ Stock In</a>
        <a href="http://172.20.10.7/TIEMAN%20WAREHOUSE/items/stock_out.php">⬇ Stock Out</a>
        <a href="http://172.20.10.7/TIEMAN%20WAREHOUSE/return_item.php">↩ Returns</a>
        <a href="http://172.20.10.7/TIEMAN%20WAREHOUSE/stock/missing_item.php">❌ Missing</a>
        <a href="http://172.20.10.7/TIEMAN%20WAREHOUSE/barcode/print_barcode.php">📷 Scanner</a>
        <a href="http://172.20.10.7/TIEMAN%20WAREHOUSE/reports/stock_report.php">📊 Reports</a>
        <a href="http://172.20.10.7/TIEMAN%20WAREHOUSE/logout.php">🚪 Logout</a>
    </div>

    <div class="main">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h3 class="fw-bold m-0" style="border-left: 5px solid #f97316; padding-left: 10px;">Item Log Catalog View</h3>
            <a href="add_item.php" class="btn fw-bold text-white shadow" style="background: #f97316;">+ Add Item</a>
        </div>

        <div class="card-box mb-4">
            <form method="GET" action="item_list.php" class="row g-3">
                <div class="col-md-4">
                    <input type="text" name="search" class="form-control" placeholder="Search Part No / Desc / Location..." value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="col-md-3">
                    <select name="category" class="form-select">
                        <option value="">-- All Categories --</option>
                        <option value="Store Tieman" <?= $category == 'Store Tieman' ? 'selected' : '' ?>>Store Tieman</option>
                        <option value="Extrusion" <?= $category == 'Extrusion' ? 'selected' : '' ?>>Extrusion</option>
                        <option value="General" <?= $category == 'General' ? 'selected' : '' ?>>General</option>
                        <option value="Pneumatic" <?= $category == 'Pneumatic' ? 'selected' : '' ?>>Pneumatic</option>
                        <option value="Lower Chassis Parts" <?= $category == 'Lower Chassis Parts' ? 'selected' : '' ?>>Lower Chassis Parts</option>
                        <option value="Air Brake Parts" <?= $category == 'Air Brake Parts' ? 'selected' : '' ?>>Air Brake Parts</option>
                        <option value="Other items" <?= $category == 'Other items' ? 'selected' : '' ?>>Other items</option>
                        <option value="Valve & Pipe Parts" <?= $category == 'Valve & Pipe Parts' ? 'selected' : '' ?>>Valve & Pipe Parts</option>
                        <option value="Liquip Parts" <?= $category == 'Liquip Parts' ? 'selected' : '' ?>>Liquip Parts</option>
                        <option value="Electrical Parts" <?= $category == 'Electrical Parts' ? 'selected' : '' ?>>Electrical Parts</option>
                        <option value="Lamp and fitting parts" <?= $category == 'Lamp and fitting parts' ? 'selected' : '' ?>>Lamp and fitting parts</option>
                        <option value="Malasyia items" <?= $category == 'Malasyia items' ? 'selected' : '' ?>>Malaysia</option>
                        <option value="China items" <?= $category == 'China items' ? 'selected' : '' ?>>China</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <select name="sort" class="form-select">
                        <option value="newest" <?= $sort == 'newest' ? 'selected' : '' ?>>Sort: Newest First</option>
                        <option value="oldest" <?= $sort == 'oldest' ? 'selected' : '' ?>>Sort: Oldest First</option>
                        <option value="name_asc" <?= $sort == 'name_asc' ? 'selected' : '' ?>>Sort: Name (A-Z)</option>
                        <option value="qty_desc" <?= $sort == 'qty_desc' ? 'selected' : '' ?>>Sort: Highest Stock Qty</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100 fw-bold">Search</button>
                </div>
            </form>
        </div>

        <div class="card-box p-0 overflow-hidden">
            <div class="table-responsive">
                <table class="table table-dark-custom align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="ps-3" style="width: 80px;">IMAGE</th>
                            <th>PART NO</th>
                            <th>DESCRIPTION</th>
                            <th>CATEGORY</th>
                            <th>CURRENT QTY</th>
                            <th>LOCATION</th>
                            <th class="text-end pe-3">ACTIONS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (mysqli_num_rows($result) > 0): ?>
                            <?php while ($row = mysqli_fetch_assoc($result)): ?>
                                <tr>
                                    <td class="ps-3">
                                        <?php 
                                        $img_src = $row['image'];
                                        if (!empty($img_src)): 
                                            // Handle Base64 string from direct DB storage
                                            if (strpos($img_src, 'data:image') === 0): ?>
                                                <img src="<?= $img_src ?>" alt="Item Image" style="width:50px; height:50px; object-fit:cover; border-radius:6px; border: 1px solid #4b5563;">
                                            <?php 
                                            // Handle relative paths or filenames
                                            else: 
                                                $file_path = (strpos($img_src, 'uploads/') === 0) ? '../' . $img_src : '../uploads/' . $img_src;
                                            ?>
                                                <img src="<?= htmlspecialchars($file_path) ?>" alt="Item Image" style="width:50px; height:50px; object-fit:cover; border-radius:6px; border: 1px solid #4b5563;" onerror="this.onerror=null; this.src='https://via.placeholder.com/50?text=No+Img';">
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <div class="d-flex align-items-center justify-content-center bg-dark text-muted rounded" style="width:50px; height:50px; font-size:10px; border:1px solid #374151;">No Img</div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="edit_item.php?id=<?= $row['id'] ?>" class="fw-bold text-decoration-none" style="color:#60a5fa;">
                                            <?= htmlspecialchars($row['item_code']) ?>
                                        </a>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?= htmlspecialchars($row['item_name']) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($row['description']) ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary text-uppercase"><?= htmlspecialchars($row['category']) ?></span>
                                    </td>
                                    <td>
                                        <span class="fw-bold <?= $row['stock_qty'] <= $row['minimum_stock'] ? 'text-danger' : 'text-success' ?>">
                                            <?= $row['stock_qty'] ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-dark border border-secondary"><?= !empty($row['location']) ? htmlspecialchars($row['location']) : 'N/A' ?></span>
                                    </td>
                                    <td class="text-end pe-3">
                                        <a href="edit_item.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-warning me-1">Edit</a>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="text-center py-4 text-muted">No items found in system inventory.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</body>
</html>