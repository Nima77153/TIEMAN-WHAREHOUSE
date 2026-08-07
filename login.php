<?php
session_start();
include('config/db.php');

if(isset($_POST['login']))
{
$user=$_POST['username'];
$pass=md5($_POST['password']);

$sql=mysqli_query($conn,
"SELECT * FROM users
WHERE username='$user'
AND password='$pass'");

if(mysqli_num_rows($sql))
{
$row=mysqli_fetch_assoc($sql);

$_SESSION['user']=$row['username'];

header("Location:dashboard.php");
}
else
{
echo "<script>alert('Login Failed')</script>";
}
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Warehouse Login</title>
    <style>
        /* Incorporating your existing CSS variables */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', sans-serif;
        }

        body {
            background: #f4f6f9;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }

        .login-container {
            background: white;
            padding: 40px;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 400px;
        }

        .login-header {
            background: #f97316; /* Your brand orange */
            margin: -40px -40px 30px -40px;
            padding: 20px;
            border-radius: 15px 15px 0 0;
            color: white;
            text-align: center;
        }

        .login-header h2 {
            font-size: 24px;
            font-weight: bold;
        }

        .form-group {
            margin-bottom: 20px;
        }

        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 16px;
            transition: 0.3s;
        }

        input:focus {
            outline: none;
            border-color: #f97316;
            box-shadow: 0 0 5px rgba(249, 115, 22, 0.2);
        }

        .btn-login {
            width: 100%;
            padding: 12px;
            background: #111827; /* Matching your sidebar dark color */
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 18px;
            font-weight: bold;
            cursor: pointer;
            transition: 0.3s;
        }

        .btn-login:hover {
            background: #f97316; /* Turns orange on hover */
        }
    </style>
</head>
<body>

<div class="login-container">
    <div class="login-header">
        <h2>Warehouse System</h2>
    </div>

    <form method="POST">
        <div class="form-group">
            <input type="text" name="username" placeholder="Username" required>
        </div>
        
        <div class="form-group">
            <input type="password" name="password" placeholder="Password" required>
        </div>

        <button type="submit" name="login" class="btn-login">
            Login
        </button>
    </form>
</div>

</body>
</html>