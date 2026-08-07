<?php
include('../config/db.php');

$id = $_GET['id'];

$item = mysqli_fetch_assoc(
mysqli_query($conn,"
SELECT * FROM items
WHERE id='$id'
"));

$total_in = mysqli_fetch_assoc(
mysqli_query($conn,"
SELECT IFNULL(SUM(qty),0) total
FROM stock_transactions
WHERE item_id='$id'
AND trans_type='STOCK IN'
"));

$total_out = mysqli_fetch_assoc(
mysqli_query($conn,"
SELECT IFNULL(SUM(qty),0) total
FROM stock_transactions
WHERE item_id='$id'
AND trans_type='STOCK OUT'
"));

$total_return = mysqli_fetch_assoc(
mysqli_query($conn,"
SELECT IFNULL(SUM(qty),0) total
FROM stock_transactions
WHERE item_id='$id'
AND trans_type='RETURN'
"));
?>

<?php include('../layout/header.php'); ?>
<?php include('../layout/sidebar.php'); ?>

<div class="container-fluid">

<div class="card shadow p-4">

<div class="row">

<div class="col-md-4 text-center">

<img
src="../uploads/items/<?=
$item['image']; ?>"
class="img-fluid rounded border">

</div>

<div class="col-md-8">

<h2><?= $item['item_name']; ?></h2>

<table class="table">

<tr>
<td><b>Item Code</b></td>
<td><?= $item['item_code']; ?></td>
</tr>

<tr>
<td><b>Barcode</b></td>
<td><?= $item['barcode']; ?></td>
</tr>

<tr>
<td><b>Category</b></td>
<td><?= $item['category']; ?></td>
</tr>

<tr>
<td><b>Location</b></td>
<td><?= $item['location']; ?></td>
</tr>

<tr>
<td><b>Unit</b></td>
<td><?= $item['unit']; ?></td>
</tr>

<tr>
<td><b>Current Stock</b></td>
<td>
<span class="badge bg-success">
<?= $item['stock_qty']; ?>
</span>
</td>
</tr>

</table>

</div>

</div>

<hr>

<h4>Stock Summary</h4>

<div class="row">

<div class="col-md-3">
<div class="card bg-primary text-white p-3">
<h3><?= $total_in['total']; ?></h3>
Stock In
</div>
</div>

<div class="col-md-3">
<div class="card bg-danger text-white p-3">
<h3><?= $total_out['total']; ?></h3>
Stock Out
</div>
</div>

<div class="col-md-3">
<div class="card bg-success text-white p-3">
<h3><?= $total_return['total']; ?></h3>
Returned
</div>
</div>

<div class="col-md-3">
<div class="card bg-dark text-white p-3">
<h3><?= $item['stock_qty']; ?></h3>
Current Stock
</div>
</div>

</div>

<hr>

<h4>Recent Transactions</h4>

<div class="table-responsive">

<table class="table table-bordered">

<tr>
<th>Date</th>
<th>Type</th>
<th>Qty</th>
<th>Reference</th>
</tr>

<?php

$trx = mysqli_query($conn,"
SELECT *
FROM stock_transactions
WHERE item_id='$id'
ORDER BY id DESC
LIMIT 20
");

while($row=mysqli_fetch_assoc($trx))
{
?>

<tr>
<td><?= $row['created_at']; ?></td>
<td><?= $row['trans_type']; ?></td>
<td><?= $row['qty']; ?></td>
<td><?= $row['reference_no']; ?></td>
</tr>

<?php } ?>

</table>

</div>

<a href="item_list.php"
class="btn btn-secondary mt-3">
Back
</a>

</div>

</div>