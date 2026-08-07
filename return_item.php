<?php
session_start();
include('config/db.php');

$message = "";
$message_type = "";

// --- ENGINE 1: EXPORT SYSTEM ENGINE TO EXCEL (.CSV FORMAT) ---
if (isset($_GET['export_excel'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=Warehouse_Return_Records_v2_' . date('Ymd_His') . '.csv');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, array('ID', 'Return Date', 'Return Time', 'Job No Reference', 'Part Number', 'Item Description', 'Quantity Returned'));
    
    $export_query = "SELECT id, return_date, return_time, job_no, item_code, description, qty_returned FROM job_returns_2 ORDER BY id DESC";
    $export_result = mysqli_query($conn, $export_query);
    
    while ($row = mysqli_fetch_assoc($export_result)) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit();
}

// --- ENGINE 2: DELETE OPERATION ---
if (isset($_GET['delete_id'])) {
    $delete_id = (int)$_GET['delete_id'];
    
    // Reverse the inventory count adjustment before deleting the log entry
    $lookup = mysqli_query($conn, "SELECT item_code, qty_returned FROM job_returns_2 WHERE id = $delete_id LIMIT 1");
    if (mysqli_num_rows($lookup) > 0) {
        $old_data = mysqli_fetch_assoc($lookup);
        $old_qty = $old_data['qty_returned'];
        $item_code = $old_data['item_code'];
        
        // Deduct back from items stock inventory balance
        mysqli_query($conn, "UPDATE items SET stock_qty = stock_qty - $old_qty WHERE item_code = '$item_code'");
        // Deduct from job_items tracking history layout
        mysqli_query($conn, "UPDATE job_items SET qty_returned = qty_returned - $old_qty WHERE part_no = '$item_code'");
    }

    $delete_query = mysqli_query($conn, "DELETE FROM job_returns_2 WHERE id = $delete_id");
    if ($delete_query) {
        $message = "🗑️ Return record log entry permanently removed from audit trail.";
        $message_type = "success";
    } else {
        $message = "❌ Error removing entry from log matrix database.";
        $message_type = "danger";
    }
}

// --- ENGINE 3: UPDATE/EDIT OPERATION ---
if (isset($_POST['update_return_entry'])) {
    $record_id = (int)$_POST['record_id'];
    $new_qty = (int)$_POST['edit_qty_returned'];
    
    // Fetch original entry details to accurately recalculate stock parameters
    $lookup = mysqli_query($conn, "SELECT item_code, qty_returned, job_id FROM job_returns_2 WHERE id = $record_id LIMIT 1");
    if (mysqli_num_rows($lookup) > 0) {
        $old_data = mysqli_fetch_assoc($lookup);
        $old_qty = $old_data['qty_returned'];
        $item_code = $old_data['item_code'];
        $job_id = $old_data['job_id'];
        
        $qty_difference = $new_qty - $old_qty;

        // Sync and adjust core system inventory dynamically based on the update variance
        mysqli_query($conn, "UPDATE items SET stock_qty = stock_qty + $qty_difference WHERE item_code = '$item_code'");
        mysqli_query($conn, "UPDATE job_items SET qty_returned = qty_returned + $qty_difference WHERE job_id = $job_id AND part_no = '$item_code'");
        
        // Update the row entry inside job_returns_2
        $update_query = mysqli_query($conn, "UPDATE job_returns_2 SET qty_returned = $new_qty WHERE id = $record_id");
        if ($update_query) {
            $message = "✏️ Return record entry parameters successfully recalculated and updated!";
            $message_type = "success";
        } else {
            $message = "❌ Error updating quantities inside database registry table.";
            $message_type = "danger";
        }
    }
}

// --- ENGINE 4: PROCESS THE NEW RETURN SUBMISSION ---
if (isset($_POST['submit_return'])) {
    $job_id_raw = mysqli_real_escape_string($conn, $_POST['job_id']);
    $item_code = mysqli_real_escape_string($conn, trim($_POST['item_code']));
    $qty_returned = (int)$_POST['qty_returned'];
    $return_date = mysqli_real_escape_string($conn, $_POST['return_date']);
    $return_time = mysqli_real_escape_string($conn, $_POST['return_time']);

    $job_parts = explode('|', $job_id_raw);
    $job_id = (int)$job_parts[0];
    $job_no = isset($job_parts[1]) ? $job_parts[1] : 'Unknown';

    if (!empty($item_code) && $qty_returned > 0 && $job_id > 0) {
        
        // Find item description matching text/code parameters
        $item_check = mysqli_query($conn, "SELECT description FROM items WHERE item_code = '$item_code' LIMIT 1");
        $item_desc = "";
        if (mysqli_num_rows($item_check) > 0) {
            $item_data = mysqli_fetch_assoc($item_check);
            $item_desc = $item_data['description'];
        }

        $insert_log = mysqli_query($conn, "INSERT INTO job_returns_2 (job_id, job_no, item_code, description, qty_returned, return_date, return_time) 
                                           VALUES ($job_id, '$job_no', '$item_code', '$item_desc', $qty_returned, '$return_date', '$return_time')");
        
        if ($insert_log) {
            mysqli_query($conn, "UPDATE items SET stock_qty = stock_qty + $qty_returned WHERE item_code = '$item_code'");
            mysqli_query($conn, "UPDATE job_items SET qty_returned = qty_returned + $qty_returned WHERE job_id = $job_id AND part_no = '$item_code'");

            $message = "🎉 Return Recorded Successfully inside Log v2! Inventory restocked dynamically.";
            $message_type = "success";
        } else {
            $message = "❌ Error writing log details to table: " . mysqli_error($conn);
            $message_type = "danger";
        }
    } else {
        $message = "⚠️ Please verify all inputs are populated correctly.";
        $message_type = "warning";
    }
}

// Fetch lists for fields
$items_list = mysqli_query($conn, "SELECT item_code, description, location FROM items ORDER BY item_code ASC");
$jobs_list = mysqli_query($conn, "SELECT id, job_no FROM jobs ORDER BY id DESC");
$history_result = mysqli_query($conn, "SELECT * FROM job_returns_2 ORDER BY id DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Material Return Registry v2</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
    <script src="https://unpkg.com/html5-qrcode"></script>
    <style>
        body { background:#f4f6f9; font-family:'Segoe UI', sans-serif; }
        .sidebar { width:250px; height:100vh; background:#111827; position:fixed; left:0; top:0; overflow:auto; z-index: 100; }
        .logo { background:#f97316; padding:20px; text-align:center; font-size:22px; font-weight:bold; color:white; }
        .sidebar a { display:block; padding:15px 20px; color:#9ca3af; text-decoration:none; font-weight:500; }
        .sidebar a:hover { background:#1f2937; color:white; }
        .sidebar a.active { background:#1f2937; color:white; border-left: 4px solid #f97316; }
        .main { margin-left:250px; padding:25px; }
        .card-box { background:white; padding:24px; border-radius:12px; box-shadow:0 2px 12px rgba(0,0,0,.06); border: 1px solid #e5e7eb; }
        #reader { width: 100%; max-width: 400px; margin: 0 auto; border: 2px solid #f97316; border-radius: 8px; overflow: hidden; }
        .table th { background: #f8fafc; color: #1e293b; font-weight: 700; text-transform: uppercase; font-size: 11px; padding: 12px; }
        @media(max-width: 992px) { .sidebar { display:none; } .main { margin-left:0; } }
    </style>
</head>
<body>

    <div class="sidebar">
        <div class="logo">WAREHOUSE</div>
        <a href="dashboard.php"><i class="bi bi-speedometer2 me-2"></i> Dashboard</a>
        <a href="items/item_list.php"><i class="bi bi-box-seam me-2"></i> Items</a>
        <a href="jobs/job_list.php"><i class="bi bi-file-earmark-text me-2"></i> Job List</a>
        <a href="return_record.php" class="active"><i class="bi bi-arrow-counterclockwise me-2"></i> Return Records</a>
        <a href="logout.php" class="text-danger mt-5"><i class="bi bi-box-arrow-left me-2"></i> Logout</a>
    </div>

    <div class="main">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h3 class="fw-bold text-dark m-0">Material Return Processing Registry</h3>
            <a href="return_record.php?export_excel=1" class="btn btn-success fw-bold">
                <i class="bi bi-file-earmark-excel me-2"></i> Export Records to Excel
            </a>
        </div>

        <?php if(!empty($message)): ?>
            <div class="alert alert-<?= $message_type ?> alert-dismissible fade show" role="alert">
                <?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row g-4 mb-5">
            <div class="col-lg-4">
                <div class="card-box text-center h-100">
                    <h6 class="fw-bold text-secondary mb-3"><i class="bi bi-camera me-1"></i> Smart Item & Text Code Lens Scanner</h6>
                    <div id="reader" class="mb-3"></div>
                    <small class="text-muted d-block">Supports standard tracking barcodes, alphanumeric part text shapes, or plain stock match descriptions.</small>
                </div>
            </div>

            <div class="col-lg-8">
                <div class="card-box h-100">
                    <h5 class="fw-bold text-dark mb-4">Log New Inward Component Return</h5>
                    <form method="POST">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">1. Target Reference Project Job No:</label>
                                <select name="job_id" class="form-select form-control-lg" required>
                                    <option value="">-- Select Active Job Ref --</option>
                                    <?php while($j = mysqli_fetch_assoc($jobs_list)): ?>
                                        <option value="<?= $j['id'] ?>|<?= htmlspecialchars($j['job_no']) ?>">[<?= htmlspecialchars($j['job_no']) ?>]</option>
                                    <?php endwhile; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold small">2. Search Item (By Part Code / Description):</label>
                                <select id="itemSelectDropdown" class="form-select" onchange="syncSelectedPartCode(this.value)" required>
                                    <option value="">-- Type Description or Part No --</option>
                                    <?php 
                                    mysqli_data_seek($items_list, 0);
                                    while($i = mysqli_fetch_assoc($items_list)): 
                                    ?>
                                        <option value="<?= htmlspecialchars($i['item_code']) ?>">
                                            <?= htmlspecialchars($i['item_code']) ?> - <?= htmlspecialchars($i['description']) ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold small">3. Selected Part Code ID (Scanned/Text Matched Field):</label>
                                <input type="text" name="item_code" id="item_code_input" class="form-control form-control-lg border-warning fw-bold text-danger" placeholder="Part number displays here" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold small">4. Return Quantity Count:</label>
                                <input type="number" name="qty_returned" class="form-control form-control-lg text-center font-monospace fw-bold" min="1" value="1" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold small">5. Date of Return Log:</label>
                                <input type="date" name="return_date" class="form-control form-control-lg" value="<?= date('Y-m-d') ?>" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold small">6. Time of Return Log:</label>
                                <input type="time" name="return_time" class="form-control form-control-lg" value="<?= date('H:i') ?>" required>
                            </div>

                            <div class="col-12 mt-4">
                                <button type="submit" name="submit_return" class="btn btn-warning text-dark btn-lg w-100 fw-bold">
                                    <i class="bi bi-check-circle-fill me-2"></i> Save Return Record Log & Restock Inventory
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="card-box">
            <h5 class="fw-bold text-dark mb-3">Historical Return Logs Audit Trail</h5>
            <div class="table-responsive">
                <table class="table table-hover align-middle m-0">
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th style="text-align: center;">Job Ref</th>
                            <th>Part Number</th>
                            <th>Item Description</th>
                            <th style="text-align: center;">Returned Quantity</th>
                            <th style="text-align: center;">Control Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(mysqli_num_rows($history_result) > 0): ?>
                            <?php while($h = mysqli_fetch_assoc($history_result)): ?>
                            <tr>
                                <td>
                                    <small class="fw-semibold text-dark"><?= htmlspecialchars($h['return_date']) ?></small>
                                    <div class="text-muted small" style="font-size: 11px;"><?= htmlspecialchars($h['return_time']) ?></div>
                                </td>
                                <td style="text-align: center;"><span class="badge bg-danger text-white font-monospace">[<?= htmlspecialchars($h['job_no']) ?>]</span></td>
                                <td class="fw-bold text-primary"><?= htmlspecialchars($h['item_code']) ?></td>
                                <td><div class="text-secondary text-truncate" style="max-width: 300px;" title="<?= htmlspecialchars($h['description']) ?>"><?= htmlspecialchars($h['description']) ?></div></td>
                                <td style="text-align: center;" class="text-success fw-bold">+ <?= (int)$h['qty_returned'] ?> units</td>
                                <td style="text-align: center;">
                                    <button type="button" class="btn btn-sm btn-outline-primary me-1 fw-bold" 
                                            onclick="triggerInlineEdit(<?= $h['id'] ?>, <?= $h['qty_returned'] ?>, '<?= htmlspecialchars($h['item_code']) ?>')">
                                        <i class="bi bi-pencil-square"></i> Edit
                                    </button>
                                    <a href="return_record.php?delete_id=<?= $h['id'] ?>" class="btn btn-sm btn-outline-danger fw-bold" onclick="return confirm('Are you sure you want to permanently delete this log entry?');">
                                        <i class="bi bi-trash3-fill"></i> Delete
                                    </a>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">No component return metrics saved in database history yet.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editLogModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content">
          <div class="modal-header bg-dark text-white">
            <h5 class="modal-title fw-bold" id="editModalLabel">✏️ Edit Return Quantity Log</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <input type="hidden" name="record_id" id="edit_record_id">
            <div class="mb-3">
                <label class="form-label fw-bold text-secondary">Target Part Number:</label>
                <input type="text" id="edit_item_label" class="form-control bg-light font-monospace" readonly>
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold">Corrected Return Quantity:</label>
                <input type="number" name="edit_qty_returned" id="edit_qty_input" class="form-control text-center font-monospace fw-bold fs-4 border-warning" min="1" required>
                <small class="text-muted mt-1 d-block">Updating recalculates stock quantities automatically.</small>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary fw-bold" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" name="update_return_entry" class="btn btn-warning text-dark fw-bold">Save Changes</button>
          </div>
        </form>
      </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Init multi-search engine filter match dropdown
        var selectElement = new TomSelect("#itemSelectDropdown", {
            create: false,
            sortField: { field: "text", direction: "asc" },
            placeholder: "Search item code or descriptions..."
        });

        function syncSelectedPartCode(val) {
            document.getElementById('item_code_input').value = val;
        }

        // TRIGGER INLINE INTERACTIVE MODAL FOR EDITING LOG ENTRIES
        var editModalElement = new bootstrap.Modal(document.getElementById('editLogModal'));
        function triggerInlineEdit(id, currentQty, itemCode) {
            document.getElementById('edit_record_id').value = id;
            document.getElementById('edit_item_label').value = itemCode;
            document.getElementById('edit_qty_input').value = currentQty;
            editModalElement.show();
        }

        // ADVANCED CAMERA RECOGNITION MATCHING (Barcodes, Items text structural shapes, or Part Numbers)
        function onScanSuccess(decodedText) {
            let processedText = decodedText.trim();
            document.getElementById('item_code_input').value = processedText;
            
            if (selectElement) {
                // Scenario A: Check direct value match
                if (selectElement.options[processedText]) {
                    selectElement.setValue(processedText);
                } else {
                    // Scenario B: Perform an iterative loose structural matching loop scan over descriptions and text words
                    let optionsKeys = Object.keys(selectElement.options);
                    for (let i = 0; i < optionsKeys.length; i++) {
                        let optionText = selectElement.options[optionsKeys[i]].text.toLowerCase();
                        if (optionText.includes(processedText.toLowerCase())) {
                            selectElement.setValue(optionsKeys[i]);
                            document.getElementById('item_code_input').value = optionsKeys[i];
                            break;
                        }
                    }
                }
            }
            if(navigator.vibrate) navigator.vibrate(100);
        }

        let html5QrcodeScanner = new Html5QrcodeScanner("reader", { fps: 15, qrbox: {width: 260, height: 160} });
        html5QrcodeScanner.render(onScanSuccess);
    </script>
</body>
</html>