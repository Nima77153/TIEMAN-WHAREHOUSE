<?php
include('config/db.php');

if(isset($_POST['save']))
{
    $job_no = $_POST['job_no'];
    $customer = $_POST['customer'];

    $image = '';

    if(!empty($_FILES['job_image']['name']))
    {
        $image = time().'_'.$_FILES['job_image']['name'];

        move_uploaded_file(
            $_FILES['job_image']['tmp_name'],
            'uploads/jobs/'.$image
        );
    }

    mysqli_query($conn,"
    INSERT INTO jobs
    (
        job_no,
        customer_name,
        job_image
    )
    VALUES
    (
        '$job_no',
        '$customer',
        '$image'
    )
    ");

    header("Location: job_list.php");
}
?>