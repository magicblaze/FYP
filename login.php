<?php
// File: login.php (auto-detect role by email; disable animations on this page; supports redirect back)
require_once __DIR__ . '/config.php';
session_start();

$error = '';

// If already logged in, redirect by role
if (isset($_SESSION['user'])) {
    $role = $_SESSION['user']['role'] ?? 'client';
    $redirect = $_GET['redirect'] ?? '';
    
    if ($role === 'client') {
        header('Location: ' . (!empty($redirect) ? $redirect : 'design_dashboard.php'));
    } elseif ($role === 'supplier') {
        header('Location: supplier/dashboard.php');
    } else {
        // For other roles, adjust as needed (e.g., designer_dashboard.php)
        header('Location: login.php');
    }
    exit;
}

// Role configuration to search user by email across tables
$roleConfigs = [
    [
        'role'      => 'client',
        'table'     => 'Client',
        'email_col' => 'cemail',
        'pass_col'  => 'cpassword',
        'id_col'    => 'clientid',
        'name_col'  => 'cname',
    ],
    [
        'role'      => 'designer',
        'table'     => 'Designer',
        'email_col' => 'demail',
        'pass_col'  => 'dpassword',
        'id_col'    => 'designerid',
        'name_col'  => 'dname',
    ],
    [
        'role'      => 'manager',
        'table'     => 'Manager',
        'email_col' => 'memail',
        'pass_col'  => 'mpassword',
        'id_col'    => 'managerid',
        'name_col'  => 'mname',
    ],
    [
        'role'      => 'contractors',
        'table'     => 'Contractors',
        'email_col' => 'cemail',
        'pass_col'  => 'cpassword',
        'id_col'    => 'contractorid',
        'name_col'  => 'cname',
    ],
    [
        'role'      => 'supplier',
        'table'     => 'Supplier',
        'email_col' => 'semail',
        'pass_col'  => 'spassword',
        'id_col'    => 'supplierid',
        'name_col'  => 'sname',
    ],
];

// Find user by email across roles
function findUserByEmailAcrossRoles(mysqli $mysqli, string $email, array $roleConfigs): ?array {
    foreach ($roleConfigs as $cfg) {
        $sql = sprintf(
            "SELECT %s AS id, %s AS name, %s AS email, %s AS password FROM %s WHERE %s = ? LIMIT 1",
            $cfg['id_col'], $cfg['name_col'], $cfg['email_col'], $cfg['pass_col'],
            $cfg['table'], $cfg['email_col']
        );
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            continue;
        }
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $res = $stmt->get_result();
        $user = $res->fetch_assoc();
        if ($user) {
            $user['role'] = $cfg['role'];
            return $user;
        }
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $redirect = $_POST['redirect'] ?? ($_GET['redirect'] ?? '');

    if (empty($_POST['email']) || empty($_POST['password'])) {
        $error = 'Please enter your email and password.';
    } else {
        $email = trim($_POST['email']);
        $password = $_POST['password'];

        $user = findUserByEmailAcrossRoles($mysqli, $email, $roleConfigs);

        // Plain-text passwords in the current DB; compare directly
        if ($user && $password === $user['password']) {
            $_SESSION['user'] = [
                'id'    => (int)$user['id'],
                'name'  => $user['name'],
                'email' => $user['email'],
                'role'  => $user['role'],
            ];
            
            // Important: client pages need clientid for comments/orders
            if ($user['role'] === 'client') {
                $_SESSION['user']['clientid'] = (int)$user['id'];
            }
            // Add supplierid to session if user is supplier
            if ($user['role'] === 'supplier') {
                $_SESSION['user']['supplierid'] = (int)$user['id'];
            }

            // Redirect logic
            if ($user['role'] === 'client') {
                header('Location: ' . (!empty($redirect) ? $redirect : 'design_dashboard.php'));
            } elseif ($user['role'] === 'supplier') {
                header('Location: supplier/dashboard.php'); 
            } else {
                // For other roles, adjust to your dashboards when available
                header('Location: login.php');
            }
            exit;
        } else {
            $error = 'Invalid email or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - HappyDesign</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/styles.css">
    <!-- Disable animations/transitions on this page -->
    <style>
      .card, .card *, .btn, .btn *, .form-control, .form-control *, body, body * {
        transition: none !important; animation: none !important;
      }
      .card:hover { transform: none !important; box-shadow: none !important; }
      .btn:hover, .btn:focus, .btn:active { transform: none !important; box-shadow: none !important; }
      .form-control:focus { box-shadow: none !important; }
    </style>
</head>
<body>
    <main class="container py-5">
        <div class="row justify-content-center">
            <div class="col-12" style="max-width: 520px;">
                <div class="card">
                    <div class="card-body">
                        <h1 class="h4 text-center mb-3">HappyDesign</h1>
                        <p class="text-center text-muted mb-4">Please sign in to continue</p>

                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                        <?php endif; ?>

                        <form method="POST" autocomplete="on" novalidate>
                            <input type="hidden" name="redirect" value="<?= htmlspecialchars($_GET['redirect'] ?? '') ?>">
                            <div class="mb-3">
                                <label for="email" class="form-label">Email address</label>
                                <input id="email" type="email" class="form-control" name="email" required>
                            </div>
                            <div class="mb-3">
                                <label for="password" class="form-label">Password</label>
                                <input id="password" type="password" class="form-control" name="password" required>
                            </div>
                            <button class="w-100 btn btn-primary" type="submit">Sign in</button>
                            <p class="mt-4 mb-0 text-muted text-center">&copy; <?= date('Y') ?> HappyDesign</p>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>