<?php
session_start();
include('../config/db.php'); 

if (!$conn) {
    die("<div class='alert alert-danger m-3'><b>Database Connection Error:</b> " . mysqli_connect_error() . "</div>");
}

$message = "";
$item_id = "";
$row = [];

// Step 1: Fetch existing item data
if (isset($_GET['id'])) {
    $item_id = mysqli_real_escape_string($conn, $_GET['id']);
    $fetch_query = "SELECT * FROM items WHERE id = '$item_id'";
    $result = mysqli_query($conn, $fetch_query);

    if (mysqli_num_rows($result) > 0) {
        $row = mysqli_fetch_assoc($result);
    } else {
        header("Location: item_list.php?error=notfound");
        exit();
    }
} else {
    header("Location: item_list.php");
    exit();
}

// Step 2: Handle form submission for editing
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_item'])) {
    $item_code   = mysqli_real_escape_string($conn, trim($_POST['item_code']));
    $item_name   = mysqli_real_escape_string($conn, trim($_POST['item_name']));
    $category    = mysqli_real_escape_string($conn, trim($_POST['category']));
    $stock_qty   = intval($_POST['stock_qty']);
    $location    = mysqli_real_escape_string($conn, trim($_POST['location']));
    $description = mysqli_real_escape_string($conn, trim($_POST['description']));
    $barcode     = !empty($_POST['barcode']) ? mysqli_real_escape_string($conn, trim($_POST['barcode'])) : $item_code;

    // --- HANDLE IMAGE UPDATE PROCESS ---
    $image_data = $row['image']; // Default to keeping the OLD image

    // Check if a NEW image file was selected
    if (isset($_FILES['image']) && $_FILES['image']['error'] == UPLOAD_ERR_OK) {
        $file_tmp  = $_FILES['image']['tmp_name'];
        $file_type = $_FILES['image']['type'];

        // Read NEW image file contents and convert to Base64 format
        $binary_content = file_get_contents($file_tmp);
        if ($binary_content !== false) {
            $image_data = 'data:' . $file_type . ';base64,' . base64_encode($binary_content);
        }
    }
    // --- END IMAGE UPDATE PROCESS ---

    $image_data_escaped = mysqli_real_escape_string($conn, $image_data);

    $update_query = "UPDATE items SET 
                     item_code = '$item_code', 
                     item_name = '$item_name', 
                     barcode = '$barcode', 
                     category = '$category', 
                     stock_qty = '$stock_qty', 
                     location = '$location', 
                     description = '$description', 
                     image = '$image_data_escaped'
                     WHERE id = '$item_id'";

    if (mysqli_query($conn, $update_query)) {
        header("Location: item_list.php?updated=1");
        exit();
    } else {
        $message = "<div class='alert alert-danger m-0 border-0 rounded-0'>❌ <b>Error updating record:</b> " . mysqli_error($conn) . "</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Warehouse - Edit Item</title>
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
        @media(max-width: 768px) { .sidebar { display: none; } .main { margin-left: 0; padding: 10px; } }
    </style>
</head>
<body>

    <div class="sidebar">
        <div class="logo">WAREHOUSE SYSTEM</div>
        <a href="http://172.20.10.7/TIEMAN%20WAREHOUSE/dashboard.php">🏠 Dashboard</a>
        <a href="http://172.20.10.7/TIEMAN%20WAREHOUSE/items/item_list.php">📦 Items</a>
        <a href="http://172.20.10.7/TIEMAN%20WAREHOUSE/items/add_item.php">➕ Add Item</a>
        <!-- Add other menu links as needed -->
        <a href="http://172.20.10.7/TIEMAN%20WAREHOUSE/logout.php">🚪 Logout</a>
    </div>

    <div class="main">
        <?= $message ?>
        
        <div class="card-box mt-3">
            <h4 class="mb-4 text-white fw-bold" style="border-left: 5px solid #f97316; padding-left: 10px;">Edit Item: <?= htmlspecialchars($row['item_name']) ?></h4>
            
            <form action="edit_item.php?id=<?= $item_id ?>" method="POST" enctype="multipart/form-data">
                <div class="row g-4">
                    
                    <div class="col-md-6">
                        <label class="form-label fw-semibold text-light">Item Code / Part No</label>
                        <input type="text" name="item_code" class="form-control form-control-lg" value="<?= htmlspecialchars($row['item_code']) ?>" required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold text-light">Item Name</label>
                        <input type="text" name="item_name" class="form-control form-control-lg" value="<?= htmlspecialchars($row['item_name']) ?>" required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold text-light">Category</label>
                        <select name="category" class="form-select form-select-lg" required>
                            <option value="<?= htmlspecialchars($row['category']) ?>" selected><?= htmlspecialchars($row['category']) ?></option>
                            <option value="Store Tieman">Store Tieman</option>
                            <option value="Extrusion">Extrusion</option>
                            <option value="General">General</option>
                            <!-- Add other category options here -->
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold text-light">Stock Qty</label>
                        <input type="number" name="stock_qty" class="form-control form-control-lg" value="<?= htmlspecialchars($row['stock_qty']) ?>" min="0" required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold text-light">Location</label>
                        <input type="text" name="location" class="form-control form-control-lg" value="<?= htmlspecialchars($row['location']) ?>">
                    </div>

                    <input type="hidden" name="barcode" value="<?= htmlspecialchars($row['barcode']) ?>">

                    <div class="col-12">
                        <label class="form-label fw-semibold text-light">Description</label>
                        <textarea name="description" class="form-control" rows="4"><?= htmlspecialchars($row['description']) ?></textarea>
                    </div>

                    <div class="col-md-12 mt-4">
                        <label class="form-label fw-semibold text-light">Current Image</label><br>
                        <?php if (!empty($row['image'])): ?>
                            <img src="<?= $row['image'] ?>" alt="Current Item Image" class="img-thumbnail shadow-sm mb-3" style="width:150px; height:150px; object-fit:cover; border-radius:10px; border: 2px solid #4b5563;">
                        <?php else: ?>
                            <div class="p-3 mb-3 text-muted bg-dark rounded text-center" style="width:150px; height:150px; border: 2px dashed #4b5563;">No Image Stored</div>
                        <?php endif; ?>
                    </div>

                    <div class="col-md-12">
                        <label class="form-label fw-semibold text-light">Upload New Image (Leave blank to keep current)</label>
                        <input type="file" name="image" accept="image/*" class="form-control form-control-lg">
                    </div>

                    <div class="col-12 mt-4">
                        <button type="submit" name="update_item" class="btn text-white fw-bold px-5 py-2.5 shadow" style="background: #f97316; font-size: 16px; border: none; border-radius: 6px;">Update Item</button>
                        <a href="item_list.php" class="btn btn-outline-light px-4 py-2.5 ms-2" style="border-radius: 6px;">Cancel</a>
                    </div>

                </div>
            </form>
        </div>
    </div>

</body>
</html>