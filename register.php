<?php
session_start();
include 'db_connect.php';

$error_message = '';
$success_message = '';

if (isset($_POST['fullname'])) {
    $fullname = isset($_POST['fullname']) ? $_POST['fullname'] : '';
    $username = isset($_POST['username']) ? $_POST['username'] : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $role = isset($_POST['role']) ? $_POST['role'] : '';

    if (empty($fullname) || empty($username) || empty($password) || empty($role)) {
        $error_message = 'All fields are required';
    } else {
        $sql = 'SELECT Username FROM "User" WHERE Username = ?';
        $stmt = $conn->prepare($sql);
        $stmt->execute([$username]);
        $existing_user = $stmt->fetch();
        
        if ($existing_user) {
            $error_message = 'Username already exists';
        } else {
            $insert_sql = 'INSERT INTO "User" (FullName, Username, Password, Role) VALUES (?, ?, ?, ?)';
            $insert_stmt = $conn->prepare($insert_sql);
            
            try {
                $insert_stmt->execute([$fullname, $username, $password, $role]);
                $success_message = 'Account created successfully';
                $_POST = array();
            } catch (PDOException $e) {
                $error_message = 'Registration failed';
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account - IE332 Group 30</title>
    <link rel="stylesheet" href="index.css">
</head>

<body>
    <h1>IE332 Group 30 Dashboard</h1>
    <h2>Create New Account</h2>
    
    <?php if (!empty($error_message)): ?>
        <p class="error-message"><?php echo htmlspecialchars($error_message); ?></p>
    <?php endif; ?>

    <?php if (!empty($success_message)): ?>
        <p class="success-message"><?php echo htmlspecialchars($success_message); ?></p>
    <?php endif; ?>

    <form method="post" action="register.php">
        <input type="text" name="fullname" placeholder="Full Name" required value="<?php echo isset($_POST['fullname']) ? htmlspecialchars($_POST['fullname']) : ''; ?>">
        <input type="text" name="username" placeholder="Username" required value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
        <input type="password" name="password" placeholder="Password" required>
        
        <select name="role" required>
            <option value="">Select Role</option>
            <option value="SupplyChainManager">Supply Chain Manager</option>
            <option value="SeniorManager">Senior Manager</option>
        </select>
        
        <input type="submit" value="Create Account">
    </form>

    <div class="form-footer">
        <a href="index.php">Back to Login</a>
    </div>

</body>

</html>
