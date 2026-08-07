<?php

include('../config/db.php');

$id=(int)$_GET['id'];

$job=mysqli_fetch_assoc(
mysqli_query($conn,"
SELECT *
FROM jobs
WHERE id='$id'
")
);

$items=mysqli_query($conn,"
SELECT *
FROM job_items
WHERE job_id='$id'
");

?>

<!DOCTYPE html>

<html>

<head>

<link
href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
rel="stylesheet">

</head>

<body>

<?php include('../layout/sidebar.php'); ?>

<div class="container-fluid">

<div class="row">

<div class="col-md-10 offset-md-2 p-4">

<h2>
<?= $job['job_no']; ?>
</h2>

<?php if($job['job_image']){ ?>

<img
src="../uploads/jobs/<?= $job['job_image']; ?>"
class="img-fluid rounded mb-4"
style="max-height:300px;">

<?php } ?>

<input
type="text"
class="form-control mb-4"
placeholder="Scan Barcode / Search Item">

<div class="row">

<?php while($item=mysqli_fetch_assoc($items)){ ?>

<div class="col-md-4">

<div class="card shadow mb-4">

<img
src="../uploads/items/<?= $item['image']; ?>"
height="200">

<div class="card-body">

<h6><?= $item['part_no']; ?></h6>

<p><?= $item['description']; ?></p>

<p>
Required:
<?= $item['qty_required']; ?>
</p>

<p>
Added:
<?= $item['qty_added']; ?>
</p>

<form action="scan_item.php" method="POST">

<input
type="hidden"
name="item_id"
value="<?= $item['id']; ?>">

<button
class="btn btn-success">

Add Item

</button>

</form>

</div>

</div>

</div>

<?php } ?>

</div>

</div>

</div>

</div>

</body>

</html>