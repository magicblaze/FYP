<?php
// logout.php
session_start();

// Clear session data
$_SESSION = [];
session_unset();
session_destroy();

// Also clear the session cookie (optional but recommended)
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}

// Redirect to dashboard
header('Location: design_dashboard.php');
exit;
