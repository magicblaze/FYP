<?php
// File: login.php (auto-detect role by email; disable animations on this page; supports redirect back)
require_once __DIR__ . '/config.php';
session_start();

$error = '';

// reCAPTCHA v3 keys can be provided via environment variables or constants.
$recaptchaSiteKey = getenv('RECAPTCHA_SITE_KEY');
if ($recaptchaSiteKey === false && defined('RECAPTCHA_SITE_KEY')) {
    $recaptchaSiteKey = RECAPTCHA_SITE_KEY;
}
$recaptchaSiteKey = is_string($recaptchaSiteKey) ? trim($recaptchaSiteKey) : '';

$recaptchaSecretKey = getenv('RECAPTCHA_SECRET_KEY');
if ($recaptchaSecretKey === false && defined('RECAPTCHA_SECRET_KEY')) {
    $recaptchaSecretKey = RECAPTCHA_SECRET_KEY;
}
$recaptchaSecretKey = is_string($recaptchaSecretKey) ? trim($recaptchaSecretKey) : '';

$recaptchaAction = 'login';
$recaptchaMinScore = 0.5;

// If already logged in, redirect by role (use same destinations as post-login switch)
if (isset($_SESSION['user'])) {
    $role = $_SESSION['user']['role'] ?? 'client';
    $redirect = $_GET['redirect'] ?? '';

    switch ($role) {
        case 'client':
            $dest = !empty($redirect) ? $redirect : 'design_dashboard.php'; //need adjust
            break;
        case 'supplier':
            $dest = 'supplier/dashboard.php';
            break;
        case 'designer':
            $dest = 'design_dashboard.php';
            break;
        case 'manager':
            $dest = 'Manager/Manager_dashboard.php';
            break;
        case 'contractor':
            $dest = 'design_dashboard.php';
            break;
        default:
            $dest = 'dashboard.php';
    }

    header('Location: ' . $dest);
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
        'role'      => 'contractor',
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
            // Include role metadata so caller can set role-specific session keys
            $user['role'] = $cfg['role'];
            $user['id_col'] = $cfg['id_col'];
            $user['table']  = $cfg['table'];
            return $user;
        }
    }
    return null;
}

function verifyRecaptchaToken(
    string $secretKey,
    string $token,
    string $expectedAction,
    float $minScore,
    ?string $remoteIp = null
): bool {
    if ($secretKey === '' || $token === '') {
        return false;
    }

    $verifyUrl = 'https://www.google.com/recaptcha/api/siteverify';
    $postData = [
        'secret'   => $secretKey,
        'response' => $token,
    ];

    if (!empty($remoteIp)) {
        $postData['remoteip'] = $remoteIp;
    }

    $encodedPostData = http_build_query($postData);
    $responseBody = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($verifyUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $encodedPostData,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $curlResponse = curl_exec($ch);
        if ($curlResponse !== false) {
            $responseBody = $curlResponse;
        }
        curl_close($ch);
    } else {
        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'content' => $encodedPostData,
                'timeout' => 10,
            ],
        ]);
        $streamResponse = @file_get_contents($verifyUrl, false, $context);
        if ($streamResponse !== false) {
            $responseBody = $streamResponse;
        }
    }

    if ($responseBody === '') {
        return false;
    }

    $decoded = json_decode($responseBody, true);
    if (!is_array($decoded) || empty($decoded['success'])) {
        return false;
    }

    $responseAction = isset($decoded['action']) ? (string)$decoded['action'] : '';
    $responseScore = isset($decoded['score']) ? (float)$decoded['score'] : 0.0;

    if ($responseAction !== $expectedAction) {
        return false;
    }

    return $responseScore >= $minScore;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $redirect = $_POST['redirect'] ?? ($_GET['redirect'] ?? '');
    $recaptchaToken = $_POST['g-recaptcha-response'] ?? '';

    if ($recaptchaSiteKey === '' || $recaptchaSecretKey === '') {
        $error = 'reCAPTCHA is not configured. Please contact the administrator.';
    } elseif (empty($recaptchaToken)) {
        $error = 'Security verification failed. Please try again.';
    } elseif (!verifyRecaptchaToken($recaptchaSecretKey, $recaptchaToken, $recaptchaAction, $recaptchaMinScore, $_SERVER['REMOTE_ADDR'] ?? null)) {
        $error = 'reCAPTCHA verification failed. Please try again.';
    } elseif (empty($_POST['email']) || empty($_POST['password'])) {
        $error = 'Please enter your email and password.';
    } else {
        $email = trim($_POST['email']);
        $password = $_POST['password'];

        $user = findUserByEmailAcrossRoles($mysqli, $email, $roleConfigs);

        // Plain-text passwords in the current DB; compare directly
        if ($user && $password === $user['password']) {
            // Base session payload
            $_SESSION['user'] = [
                'id'    => (int)$user['id'],
                'name'  => $user['name'],
                'email' => $user['email'],
                'role'  => $user['role'],
            ];

            // Store the role-specific id column (e.g. clientid, designerid, managerid, etc.)
            if (!empty($user['id_col'])) {
                $_SESSION['user'][$user['id_col']] = (int)$user['id'];
            }

            // Role-aware redirect destinations (adjust paths as needed)
            switch ($user['role']) {
                case 'client':
                    $dest = !empty($redirect) ? $redirect : 'design_dashboard.php';
                    break;
                case 'supplier':
                    $dest = 'supplier/dashboard.php';
                    break;
                case 'designer':
                    $dest = 'designer/designer_dashboard.php';
                    break;
                case 'manager':
                    $dest = 'Manager/Manager_dashboard.php';
                    break;
                case 'contractor':
                    // Contractor default destination
                    $dest = !empty($redirect) ? $redirect : 'design_dashboard.php';
                    break;
                default:
                    $dest = 'login.php';
            }

            header('Location: ' . $dest);
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
                        <h1 class="h4 text-center mb-3 text-dark">HappyDesign - Interior Design Project Management App</h1>
                        <p class="text-center text-muted mb-4">Please sign in to continue</p>

                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                        <?php endif; ?>

                        <?php if (empty($recaptchaSiteKey)): ?>
                            <div class="alert alert-warning">Login is temporarily unavailable because reCAPTCHA keys are missing.</div>
                        <?php endif; ?>

                        <form method="POST" autocomplete="on" novalidate>
                            <input type="hidden" name="redirect" value="<?= htmlspecialchars($_GET['redirect'] ?? '') ?>">
                            <input type="hidden" name="g-recaptcha-response" id="g-recaptcha-response" value="">
                            <div class="mb-3">
                                <label for="email" class="form-label">Email address</label>
                                <input id="email" type="email" class="form-control" name="email" required>
                            </div>
                            <div class="mb-3">
                                <label for="password" class="form-label">Password</label>
                                <input id="password" type="password" class="form-control" name="password" required>
                            </div>
                            <button class="w-100 btn btn-primary" type="submit">Sign in</button>
                            <div class="mt-4 text-center">
                                <p class="mb-0 text-muted">Don't have an account? <a href="signup.php">Sign Up</a></p>
                            </div>
                            <p class="mt-4 mb-0 text-muted text-center">&copy; <?= date('Y') ?> HappyDesign</p>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </main>
        <?php if (!empty($recaptchaSiteKey)): ?>
                <script src="https://www.google.com/recaptcha/api.js?render=<?= urlencode($recaptchaSiteKey) ?>"></script>
                <script>
                    (function () {
                        const form = document.querySelector('form[method="POST"]');
                        const tokenInput = document.getElementById('g-recaptcha-response');
                        if (!form || !tokenInput || typeof grecaptcha === 'undefined') {
                            return;
                        }

                        form.addEventListener('submit', function (event) {
                            event.preventDefault();
                            grecaptcha.ready(function () {
                                grecaptcha.execute('<?= htmlspecialchars($recaptchaSiteKey, ENT_QUOTES) ?>', { action: '<?= htmlspecialchars($recaptchaAction, ENT_QUOTES) ?>' })
                                    .then(function (token) {
                                        tokenInput.value = token;
                                        form.submit();
                                    });
                            });
                        });
                    })();
                </script>
        <?php endif; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
