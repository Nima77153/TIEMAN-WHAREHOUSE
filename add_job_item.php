<?php

include('../config/db.php');

$job=$_GET['id'];

if(isset($_POST['save']))
{
$item=$_POST['item_id'];
$qty=$_POST['qty'];

mysqli_query($conn,"
INSERT INTO job_items
(job_id,item_id,qty)
VALUES
('$job','$item','$qty')
");

mysqli_query($conn,"
UPDATE items

SET stock_qty=
stock_qty-$qty

WHERE id='$item'
");

header("Location:job_details.php?id=$job");
}
?>