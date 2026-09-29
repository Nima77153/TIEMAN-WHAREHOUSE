<?php

session_start();

require_once '../vendor/autoload.php';
include('../config/db.php');

use Cloudinary\Configuration\Configuration;
use Cloudinary\Api\Upload\UploadApi;

// ==========================================
// CLOUDINARY CONFIGURATION
// ==========================================

$cloudinary_url = trim((string) getenv('CLOUDINARY_URL'));
$cloudinary_configured = !empty($cloudinary_url);

$message = "";

if ($cloudinary_configured) {
    Configuration::instance($cloudinary_url);
} else {
    // Don't kill the whole page anymore - just warn, so the form still works
    // for adding items (they just won't have an image until this is fixed).
    $message = "
        <div class='alert alert-warning m-0 border-0 rounded-0'>
            ⚠️ <b>Cloudinary is not configured on this server.</b>
            Image uploads will be skipped until <code>CLOUDINARY_URL</code> is set
            in your Render Environment Variables. You can still add items without an image.
        </div>
    ";
}

// ==========================================
// DATABASE CHECK
// ==========================================

if (!$conn) {
    die(
        "<div class='alert alert-danger m-3'>
            <b>Database Connection Error:</b> " .
            mysqli_connect_error() .
        "</div>"
    );
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_item'])) {

    $item_code = mysqli_real_escape_string(
        $conn,
        trim($_POST['item_code'])
    );

    $item_name = mysqli_real_escape_string(
        $conn,
        trim($_POST['item_name'])
    );

    $category = mysqli_real_escape_string(
        $conn,
        trim($_POST['category'])
    );

    $stock_qty = intval($_POST['stock_qty']);

    $location = mysqli_real_escape_string(
        $conn,
        trim($_POST['location'])
    );

    $description = mysqli_real_escape_string(
        $conn,
        trim($_POST['description'])
    );

    $barcode = !empty($_POST['barcode'])
        ? mysqli_real_escape_string(
            $conn,
            trim($_POST['barcode'])
        )
        : $item_code;


    // ==========================================
    // UPLOAD IMAGE TO CLOUDINARY
    // ==========================================

    $image_name = "";

    if (
        $cloudinary_configured &&
        isset($_FILES['image']) &&
        $_FILES['image']['error'] === UPLOAD_ERR_OK
    ) {

        try {

            $upload = new UploadApi();

            $upload_result = $upload->upload(
                $_FILES['image']['tmp_name'],
                [
                    'folder' => 'tieman_warehouse/items',
                    'resource_type' => 'image'
                ]
            );

            // Save Cloudinary secure URL
            $image_name = $upload_result['secure_url'];

        } catch (Exception $e) {

            $message = "
                <div class='alert alert-danger m-0 border-0 rounded-0'>
                    ❌ <b>Image upload failed:</b> " .
                    htmlspecialchars($e->getMessage()) .
                "</div>
            ";

            $image_name = "";
        }
    }


    // ==========================================
    // SAVE ITEM TO DATABASE
    // ==========================================

    $insert_query = "INSERT INTO items
        (
            item_code,
            item_name,
            barcode,
            category,
            stock_qty,
            location,
            description,
            image
        )
        VALUES
        (
            '$item_code',
            '$item_name',
            '$barcode',
            '$category',
            '$stock_qty',
            '$location',
            '$description',
            '$image_name'
        )";


    if (mysqli_query($conn, $insert_query)) {

        header("Location: item_list.php?success=1");
        exit();

    } else {

        $message = "
            <div class='alert alert-danger m-0 border-0 rounded-0'>
                ❌ <b>Error saving record:</b> " .
                mysqli_error($conn) .
            "</div>
        ";
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
    <!-- Font Awesome CDN for sidebar icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body { background:#1e293b; color: white; font-family:'Segoe UI', sans-serif; }

        /* SIDEBAR WITH MODERN ICON STYLING (same as dashboard) */
        .sidebar {
            width: 260px;
            height: 100vh;
            background: #1a2232;
            position: fixed;
            left: 0;
            top: 0;
            overflow-y: auto;
            z-index: 100;
        }
        .logo {
            background: #f97316;
            padding: 18px 20px;
            text-align: center;
            font-size: 20px;
            font-weight: bold;
            color: white;
            letter-spacing: 0.5px;
        }
        .sidebar-menu {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .sidebar a {
            display: flex;
            align-items: center;
            padding: 13px 20px;
            color: #d1d5db;
            text-decoration: none;
            font-size: 15px;
            font-weight: 500;
            transition: background 0.2s, color 0.2s;
            border-left: 4px solid transparent;
        }
        .sidebar a i {
            font-size: 18px;
            width: 30px;
            text-align: center;
            margin-right: 12px;
            color: #9ca3af;
            transition: color 0.2s;
        }
        .sidebar a:hover {
            background: #131924;
            color: #ffffff;
        }
        .sidebar a:hover i {
            color: #ffffff;
        }
        .sidebar a.active {
            background: #131924;
            color: #ffffff;
            border-left: 4px solid #f97316;
        }
        .sidebar a.active i {
            color: #ffffff;
        }

        /* PAGE LAYOUT */
        .main { margin-left:260px; padding:20px; }
        .card-box { background:#1f2937; padding:25px; border-radius:15px; box-shadow: 0 5px 15px rgba(0,0,0,0.3); }
        .form-control, .form-select { background-color: #374151; border: 1px solid #4b5563; color: white; }
        .form-control:focus, .form-select:focus { background-color: #374151; color: white; border-color: #f97316; box-shadow: none; }
        .form-control::placeholder { color: #9ca3af; }
        @media(max-width: 768px) { .sidebar { display: none; } .main { margin-left: 0; padding: 10px; } }
    </style>
</head>
<body>

    <!-- SIDEBAR (icons + original hyperlinks) -->
    <div class="sidebar">
        <div class="logo">WAREHOUSE SYSTEM</div>
          <div class="sidebar-menu">
            <a href="dashboard.php" class="active">
                <i class="fa-solid fa-gauge-high"></i> Dashboard
            </a>
            <a href="items/item_list.php">
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
                             <option value="Store Tieman">Store Tieman</option>
                            <option value="Extrusion">Extrusion</option>
                            <option value="General">General</option>
                            <option value="Pneumatic">Pneumatic</option>
                            <option value="Lower Chassis Parts">Lower Chassis Parts</option>
                            <option value="Air Brake Parts">Air Brake Parts</option>
                            <option value="Other items">Other items</option>
                            <option value="Valve & Pipe Parts">Valve & Pipe Parts</option>
                            <option value="Liquip Parts">Liquip Parts</option>
                            <option value="Electrical Parts">Electrical Parts</option>
                            <option value="Lamp and fitting parts">Lamp and fitting parts</option>
                            <option value="Malayisa items">Malaysia</option>
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
                        <input type="file" name="image" accept="image/*" class="form-control" <?= $cloudinary_configured ? '' : 'disabled' ?>>
                        <?php if (!$cloudinary_configured): ?>
                            <div class="form-text text-warning">Image upload disabled until Cloudinary is configured on the server.</div>
                        <?php endif; ?>
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
