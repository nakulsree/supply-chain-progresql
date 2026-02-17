<?php
session_start();

if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    $_SESSION = array();
    session_destroy();
}

$error_message = '';
if (isset($_SESSION['login_error'])) {
    $error_message = $_SESSION['login_error'];
    unset($_SESSION['login_error']);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IE332 Group 30 Login</title>
    <link rel="stylesheet" href="index.css">
</head>

<body>
    <h1>IE332 Group 30 Dashboard</h1>
    <h2>Group Members</h2>
    
    <div class="image-grid">
        <div class="grid-item">
            <img src="domenica.jpg" alt="Domenica Forno Headshot">
            <p class="image-title">Domenica Forno</p>
        </div>
        <div class="grid-item">
            <img src="mark.jpg" alt="Mark Rapp Headshot">
            <p class="image-title">Mark Rapp</p>
        </div>
        <div class="grid-item">
            <img src="laura.jpg" alt="Laura Ortiz Headshot">
            <p class="image-title">Laura Ortiz</p>
        </div>
        <div class="grid-item">
            <img src="jacob.jpg" alt="Jacob Lavra Headshot">
            <p class="image-title">Jacob Lavra</p>
        </div>
        <div class="grid-item">
            <img src="aditya.jpg" alt="Aditya Kumar Headshot">
            <p class="image-title">Aditya Kumar</p>
        </div>
        <div class="grid-item">
            <img src="nakul.jpg" alt="Nakul Sreekanth Headshot">
            <p class="image-title">Nakul Sreekanth</p>
        </div>
    </div>
    
    <h2>Login</h2>
    
    <div style="background: #e8f4f8; padding: 15px; margin: 10px 0; border-radius: 5px; border-left: 4px solid #2196F3;">
        <p><strong>Sample Credentials for Demo:</strong></p>
        <p><code style="background: #fff; padding: 5px 10px; border-radius: 3px;">Username: sm | Password: sm123</code> (Senior Manager)</p>
        <p><code style="background: #fff; padding: 5px 10px; border-radius: 3px;">Username: scm | Password: scm123</code> (Supply Chain Manager)</p>
    </div>
    
    <?php if (!empty($error_message)): ?>
        <p class="error-message"><?php echo htmlspecialchars($error_message); ?></p>
    <?php endif; ?>

    <form method="post" action="authenticate.php">
        <input type="text" name="username" placeholder="Username" required>
        <input type="password" name="password" placeholder="Password" required>
        <input type="submit" value="Login">
    </form>

    <div class="form-footer">
        <a href="register.php">Create a new account</a>
    </div>

</body>

</html>