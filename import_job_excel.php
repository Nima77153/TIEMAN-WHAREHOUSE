<?php
include('config/db.php');
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

if(isset($_POST['import']))
{
    $file=$_FILES['excel']['tmp_name'];

    $sheet=IOFactory::load($file);

    $rows=$sheet->getActiveSheet()->toArray();

    $job_no="";

    foreach($rows as $k=>$row)
    {
        if($k==0) continue;

        $part_no=trim($row[1]);
        $desc=trim($row[2]);
        $image=trim($row[3]);
        $qty=(int)$row[4];
        $job_no=trim($row[5]);

        if(empty($job_no)) continue;

        $check=mysqli_query($conn,
        "SELECT * FROM jobs
        WHERE job_no='$job_no'");

        if(mysqli_num_rows($check)==0)
        {
            mysqli_query($conn,"
            INSERT INTO jobs(job_no)
            VALUES('$job_no')
            ");
        }

        $job=mysqli_fetch_assoc(
        mysqli_query($conn,
        "SELECT * FROM jobs
        WHERE job_no='$job_no'")
        );

        $job_id=$job['id'];

        mysqli_query($conn,"
        INSERT INTO job_items
        (
            job_id,
            part_no,
            description,
            image,
            qty_required
        )
        VALUES
        (
            '$job_id',
            '$part_no',
            '$desc',
            '$image',
            '$qty'
        )
        ");
    }

    echo "Import Complete";
}
?>

<form method="POST" enctype="multipart/form-data">

<input type="file" name="excel">

<button name="import">
Import Excel
</button>

</form>