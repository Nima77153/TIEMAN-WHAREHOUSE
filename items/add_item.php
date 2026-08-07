<?php
session_start();
include('../config/db.php'); 

if (!$conn) {
    die("<div class='alert alert-danger m-3'><b>Database Connection Error:</b> " . mysqli_connect_error() . "</div>");
}

$message = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_item'])) {
    $item_code  = mysqli_real_escape_string($conn, trim($_POST['item_code']));
    $item_name  = mysqli_real_escape_string($conn, trim($_POST['item_name']));
    $category   = mysqli_real_escape_string($conn, trim($_POST['category']));
    $stock_qty  = intval($_POST['stock_qty']);
    $location   = mysqli_real_escape_string($conn, trim($_POST['location']));
    $description = mysqli_real_escape_string($conn, trim($_POST['description']));
    $barcode    = !empty($_POST['barcode']) ? mysqli_real_escape_string($conn, trim($_POST['barcode'])) : $item_code;

    // Handle Image Upload Process
    $image_name = "";
    if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
        // Adjusted directory route to make sure it drops inside your root uploads folder
        $target_dir = "../uploads/";
        
        if (!file_exists($target_dir)) {
            mkdir($target_dir, 0777, true);
        }
        
        $file_ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
        $image_name = time() . "_" . bin2hex(random_bytes(4)) . "." . $file_ext;
        $target_file = $target_dir . $image_name;
        
        if (move_uploaded_file($_FILES['image']['tmp_name'], $target_file)) {
            // File saved successfully
        } else {
            $image_name = ""; // Fallback if server folder permissions block moving file
        }
    }

    $insert_query = "INSERT INTO items (item_code, item_name, barcode, category, stock_qty, location, description, image) 
                     VALUES ('$item_code', '$item_name', '$barcode', '$category', '$stock_qty', '$location', '$description', '$image_name')";

    if (mysqli_query($conn, $insert_query)) {
        header("Location: item_list.php?success=1");
        exit();
    } else {
        $message = "<div class='alert alert-danger m-0 border-0 rounded-0'>❌ <b>Error saving record:</b> " . mysqli_error($conn) . "</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Warehouse - Add New Item</title>
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
        <a href="http://172.20.10.7/TIEMAN%20WAREHOUSE/items/add_item.php" class="active">➕ Add Item</a>
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
        <?= $message ?>
        
        <div class="card-box mt-3">
            <h4 class="mb-4 text-white fw-bold" style="border-left: 5px solid #f97316; padding-left: 10px;">Add New Item</h4>
            
            <form action="add_item.php" method="POST" enctype="multipart/form-data">
                <div class="row g-4">
                    
                    <div class="col-md-6">
                        <label class="form-label fw-semibold text-light">Item Code / Part No</label>
                        <input type="text" name="item_code" class="form-control form-control-lg" placeholder="e.g. ITM-001" required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold text-light">Item Name</label>
                        <input type="text" name="item_name" class="form-control form-control-lg" placeholder="e.g. Wireless Mouse" required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold text-light">Category</label>
                        <select name="category" class="form-select form-select-lg" required>
                            <option value="" disabled selected>-- Select Material Category --</option>
                            <option value="Extrusion">Extrusion</option>
                            <option value="General">General</option>
                            <option value="Pneumatic">Pneumatic</option>
                            <option value="Lower Chassis Parts">Lower Chassis Parts</option>
                            <option value="Air Brake Parts">Air Brake Parts</option>
                            <option value="Other items">Other items</option>
                            <option value="Valve & Pipe Parts">Valve & Pipe Parts</option>
                            <option value="LIQUIQ Parts">LIQUIQ Parts</option>
                            <option value="Electrical Parts">Electrical Parts</option>
                            <option value="Lamp and fitting parts">Lamp and fitting parts</option>
                            <option value="Malasyia items">Malaysia</option>
                            <option value="China items">China</option>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold text-light">Stock Qty</label>
                        <input type="number" name="stock_qty" class="form-control form-control-lg" value="0" min="0" required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold text-light">Location / Bin Rack Space</label>
                        <input type="text" name="location" class="form-control form-control-lg" placeholder="e.g. Shelf A-12">
                    </div>

                    <input type="hidden" name="barcode" value="">

                    <div class="col-12">
                        <label class="form-label fw-semibold text-light">Description</label>
                        <textarea name="description" class="form-control" rows="4" placeholder="Enter optional item specs..."></textarea>
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-semibold text-light">Product Image</label>
                        <input type="file" name="image" accept="image/*" class="form-control">
                    </div>

                    <div class="col-12 mt-4">
                        <button type="submit" name="save_item" class="btn text-white fw-bold px-5 py-2.5 shadow" style="background: #f97316; font-size: 16px; border: none; border-radius: 6px;">Save Item</button>
                    </div>

                </div>
            </form>
        </div>
    </div>

</body>
</html>