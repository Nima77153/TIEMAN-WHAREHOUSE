<?php
session_start();
// Relative path configuration to step back out into main config folder safely
include('../config/db.php');

// Retrieve item record parameters securely
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: item_list.php");
    exit;
}

$id = (int)$_GET['id'];
$stmt = $conn->prepare("SELECT * FROM items WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$item = $stmt->get_result()->fetch_assoc();

if (!$item) {
    echo "<script>alert('Target inventory item asset not found.'); window.location='item_list.php';</script>";
    exit;
}

// Handle Form Update Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_item'])) {
    $item_code = mysqli_real_escape_string($conn, trim($_POST['item_code']));
    $item_name = mysqli_real_escape_string($conn, trim($_POST['item_name']));
    $description = mysqli_real_escape_string($conn, trim($_POST['description']));
    $qty_per_tanker = mysqli_real_escape_string($conn, trim($_POST['qty_per_tanker']));
    $stock_date = mysqli_real_escape_string($conn, trim($_POST['stock_date']));
    $stock_qty = (int)$_POST['stock_qty'];
    $location = mysqli_real_escape_string($conn, trim($_POST['location']));
    $category = mysqli_real_escape_string($conn, trim($_POST['category'])); // Process Category field
    
    $image_filename = $item['image']; // Default to existing filename

    // Process image file uploads if available
    if (isset($_FILES['new_image']) && $_FILES['new_image']['error'] === UPLOAD_ERR_OK) {
        $file_tmp = $_FILES['new_image']['tmp_name'];
        $original_name = basename($_FILES['new_image']['name']);
        $file_ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
        
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        
        if (in_array($file_ext, $allowed_extensions)) {
            // Relative target folder setup
            $upload_dir = '../uploads/items/';
            
            // Ensure destination directory exists with permissions
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            // Clean & unique filename generation (handles WhatsApp & special filenames safely)
            $clean_code = preg_replace('/[^A-Za-z0-9_\-]/', '_', $item_code);
            $image_filename = 'item_' . time() . '_' . $clean_code . '.' . $file_ext;
            
            $upload_path = $upload_dir . $image_filename;
            
            move_uploaded_file($file_tmp, $upload_path);
        }
    }

    // Updated SQL Query to handle saving category column
    $update_stmt = $conn->prepare("UPDATE items SET item_code = ?, item_name = ?, description = ?, qty_per_tanker = ?, stock_date = ?, stock_qty = ?, location = ?, image = ?, category = ? WHERE id = ?");
    $update_stmt->bind_param("sssssisssi", $item_code, $item_name, $description, $qty_per_tanker, $stock_date, $stock_qty, $location, $image_filename, $category, $id);

    if ($update_stmt->execute()) {
        echo "<script>alert('✨ Inventory item layout updated successfully!'); window.location='item_list.php';</script>";
        exit;
    } else {
        $error_message = "Database execution error transaction trace: " . $conn->error;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Warehouse Item</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <style>
        body { background:#f4f6f9; font-family:'Segoe UI', sans-serif; }
        .sidebar { width:250px; height:100vh; background:#111827; position:fixed; left:0; top:0; overflow:auto; z-index: 100; }
        .logo { background:#f97316; padding:20px; text-align:center; font-size:22px; font-weight:bold; color:white; }
        .sidebar a { display:block; padding:15px; color:white; text-decoration:none; transition:.3s; }
        .sidebar a:hover { background:#f97316; }
        .main { margin-left:250px; padding:20px; }
        .topbar { background:white; padding:15px 20px; border-radius:10px; box-shadow:0 2px 10px rgba(0,0,0,.1); margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; }
        .card-box { background:white; padding:30px; border-radius:12px; box-shadow:0 2px 10px rgba(0,0,0,.08); }
        
        /* Modern Upload Image Card Form Preview Styles */
        .preview-card { border: 2px dashed #cbd5e1; border-radius: 8px; padding: 15px; text-align: center; background: #f8fafc; }
        .preview-img { max-width: 100%; max-height: 220px; object-fit: contain; border-radius: 6px; background: #ffffff; border: 1px solid #e2e8f0; }
    </style>
</head>
<body>

    <div class="sidebar">
        <div class="logo">WAREHOUSE</div>
        <a href="http://localhost/TIEMAN%20WAREHOUSE/dashboard.php">🏠 Dashboard</a>
        <a href="http://localhost/TIEMAN%20WAREHOUSE/items/item_list.php">📦 Items</a>
        <a href="http://localhost/TIEMAN%20WAREHOUSE/items/add_item.php">➕ Add Item</a>
        <a href="http://localhost/TIEMAN%20WAREHOUSE/items/import_excel.php">📥 Import Excel</a>
        <a href="http://localhost/TIEMAN%20WAREHOUSE/jobs/job_list.php">📝 Job List</a>
        <a href="http://localhost/TIEMAN%20WAREHOUSE/logout.php">🚪 Logout</a>
    </div>

    <div class="main">
        <div class="topbar">
            <h4>Management &amp; Update Utility</h4>
            <div>Logged in profile: <strong><?= isset($_SESSION['user']) ? htmlspecialchars($_SESSION['user']) : 'Admin'; ?></strong></div>
        </div>

        <div class="card-box">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h3><i class="bi bi-pencil-square text-warning me-2"></i>Edit Item Specifications</h3>
                <a href="item_list.php" class="btn btn-secondary btn-sm"><i class="bi bi-arrow-left"></i> Back to Catalog List</a>
            </div>

            <?php if (isset($error_message)): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error_message) ?></div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
                <div class="row g-4">
                    
                    <div class="col-md-4 text-center">
                        <label class="form-label fw-bold text-secondary mb-2 d-block">Current Uploaded Image</label>
                        <div class="preview-card mb-3">
                            <?php 
                            // Relative path image checking logic
                            $current_img = '../assets/images/no-image.png';
                            if (!empty($item['image'])) {
                                $raw_img = trim($item['image']);
                                if (preg_match('/^https?:\/\//i', $raw_img)) {
                                    $current_img = $raw_img;
                                } elseif (file_exists('../uploads/items/' . $raw_img)) {
                                    $current_img = '../uploads/items/' . $raw_img;
                                }
                            }
                            ?>
                            <img id="imageDisplayLink" 
                                 src="<?= htmlspecialchars($current_img) ?>" 
                                 class="preview-img mb-2" 
                                 alt="Current Item Asset"
                                 onerror="this.onerror=null; this.src='../assets/images/no-image.png';">
                            <div class="small text-muted mt-2">File Upload Preview Matrix Context</div>
                        </div>
                        <div class="text-start">
                            <label for="new_image" class="form-label fw-semibold small text-muted">Upload New Replacement File</label>
                            <input type="file" name="new_image" id="new_image" class="form-control" accept="image/*" onchange="previewSelectedFile(this)">
                        </div>
                    </div>

                    <div class="col-md-8">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Part No</label>
                                <input type="text" name="item_code" class="form-control bg-light fw-bold" value="<?= htmlspecialchars($item['item_code']) ?>" required>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Item Name</label>
                                <input type="text" name="item_name" class="form-control" value="<?= htmlspecialchars($item['item_name'] ?? $item['description'] ?? '') ?>" required>
                            </div>

                            <div class="col-md-12">
                                <label class="form-label fw-semibold text-dark">Category</label>
                                <select name="category" class="form-select" required>
                                    <option value="" <?= empty($item['category']) ? 'selected' : '' ?>>-- Select Category --</option>
                                    <option value="Store Tieman" <?= (($item['category'] ?? '') === 'Store Tieman') ? 'selected' : '' ?>>Store Tieman</option>
                                    <option value="Extrusion" <?= (($item['category'] ?? '') === 'Extrusion') ? 'selected' : '' ?>>Extrusion</option>
                                    <option value="General" <?= (($item['category'] ?? '') === 'General') ? 'selected' : '' ?>>General</option>
                                    <option value="Civacon" <?= (($item['category'] ?? '') === 'Civacon') ? 'selected' : '' ?>>Civacon</option>
                                    <option value="Pneumatic" <?= (($item['category'] ?? '') === 'Pneumatic') ? 'selected' : '' ?>>Pneumatic</option>
                                    <option value="Lower Chassis Parts" <?= (($item['category'] ?? '') === 'Lower Chassis Parts') ? 'selected' : '' ?>>Lower Chassis Parts</option>
                                    <option value="Air Brake Parts" <?= (($item['category'] ?? '') === 'Air Brake Parts') ? 'selected' : '' ?>>Air Brake Parts</option>
                                    <option value="Other items" <?= (($item['category'] ?? '') === 'Other items') ? 'selected' : '' ?>>Other items</option>
                                    <option value="Valve & Pipe Parts" <?= (($item['category'] ?? '') === 'Valve & Pipe Parts') ? 'selected' : '' ?>>Valve & Pipe Parts</option>
                                    <option value="Liquip Parts" <?= (($item['category'] ?? '') === 'Liquip Parts') ? 'selected' : '' ?>>Liquip Parts</option>
                                    <option value="Electrical Parts" <?= (($item['category'] ?? '') === 'Electrical Parts') ? 'selected' : '' ?>>Electrical Parts</option>
                                    <option value="Lamp and fitting parts" <?= (($item['category'] ?? '') === 'Lamp and fitting parts') ? 'selected' : '' ?>>Lamp and fitting parts</option>
                                    <option value="Malayisa items" <?= (($item['category'] ?? '') === 'Malayisa items') ? 'selected' : '' ?>>Malayisa items</option>
                                    <option value="China items" <?= (($item['category'] ?? '') === 'China items') ? 'selected' : '' ?>>China items</option>
                                </select>
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-semibold">Description Specifications</label>
                                <textarea name="description" class="form-control" rows="2"><?= htmlspecialchars($item['description'] ?? '') ?></textarea>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-semibold text-success">Qty Per Tanker (How Much Use Per Tank)</label>
                                <input type="text" name="qty_per_tanker" class="form-control" value="<?= htmlspecialchars($item['qty_per_tanker'] ?? '') ?>">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-semibold text-primary">Stock Date (Month Reference)</label>
                                <input type="text" name="stock_date" class="form-control" value="<?= htmlspecialchars($item['stock_date'] ?? '') ?>">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Current Stock Qty</label>
                                <input type="number" name="stock_qty" class="form-control fw-bold text-dark" value="<?= (int)$item['stock_qty'] ?>" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Remark / Storage Location</label>
                                <input type="text" name="location" class="form-control" value="<?= htmlspecialchars($item['location'] ?? '') ?>">
                            </div>
                        </div>

                        <div class="mt-5 pt-3 border-top d-flex gap-2 justify-content-end">
                            <a href="item_list.php" class="btn btn-outline-secondary px-4">Cancel</a>
                            <button type="submit" name="update_item" class="btn btn-warning px-4 fw-bold">Update Item Changes</button>
                        </div>
                    </div>

                </div>
            </form>
        </div>
    </div>

    <script>
        // Live client-side structural image path tracking preview engine
        function previewSelectedFile(inputElement) {
            const displayPreviewFrame = document.getElementById('imageDisplayLink');
            if (inputElement.files && inputElement.files[0]) {
                const layoutReader = new FileReader();
                layoutReader.onload = function (eventTarget) {
                    displayPreviewFrame.src = eventTarget.target.result;
                };
                layoutReader.readAsDataURL(inputElement.files[0]);
            }
        }
    </script>
</body>
</html>