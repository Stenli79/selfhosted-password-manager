<?php
$isLocked  = Auth::isLocked('master');
$remaining = $isLocked ? Auth::getLockoutRemainingSeconds('master') : 0;
$timeout   = isset($_GET['timeout']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Manager — Login</title>
    <script>(function(){var t=localStorage.getItem('pm-theme')||'dark';document.documentElement.setAttribute('data-theme',t);})();</script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="ui/assets/style.css">
</head>
<body class="login-page">
<div class="login-container">

    <div class="login-header">
        <div class="glow-icon">
            <i class="fa-solid fa-shield-halved"></i>
        </div>
        <h1>Password Manager</h1>
        <p>Sign in to access your vault</p>
    </div>

    <?php if ($timeout): ?>
        <div class="alert alert-warning">Session expired due to inactivity.</div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($isLocked): ?>
        <div class="alert alert-error">
            Too many failed attempts. Try again in
            <strong><?= ceil($remaining / 60) ?> minute(s)</strong>.
        </div>
    <?php else: ?>
        <form method="POST" autocomplete="off">
            <input type="hidden" name="action" value="login">
            <div class="form-group">
                <label for="username">Username</label>
                <input
                    type="text"
                    id="username"
                    name="username"
                    autofocus
                    required
                    autocomplete="off"
                    placeholder="Enter username"
                >
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    required
                    placeholder="Enter master password"
                >
            </div>
            <button type="submit" class="btn btn-primary btn-full">Sign In</button>
        </form>
    <?php endif; ?>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
