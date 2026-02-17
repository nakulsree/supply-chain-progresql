<?php
session_start();
include 'db_connect.php';

$username = isset($_POST['username']) ? $_POST['username'] : '';
$password = isset($_POST['password']) ? $_POST['password'] : '';

if (empty($username) || empty($password)) {
    $_SESSION['login_error'] = 'Invalid username or password';
    header('Location: index.php');
    exit;
}

$sql = 'SELECT UserID, FullName, Username, Password, Role FROM "User" WHERE Username = ?';
$stmt = $conn->prepare($sql);
$stmt->execute([$username]);
$user = $stmt->fetch();

if (!$user) {
    $_SESSION['login_error'] = 'Invalid username or password';
    header('Location: index.php');
    exit;
}

if ($user['Password'] !== $password) {
    $_SESSION['login_error'] = 'Invalid username or password';
    header('Location: index.php');
    exit;
}

$_SESSION['loggedin'] = true;
$_SESSION['username'] = $username;
$_SESSION['user_id'] = $user['UserID'];
$_SESSION['full_name'] = $user['FullName'];
$_SESSION['role'] = $user['Role'];

if ($user['Role'] === 'SeniorManager') {
    header('Location: sm_overview.php');
} else {
    header('Location: overview.php');
}
exit;
?>