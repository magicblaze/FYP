<?php
// File: signup.php
require_once __DIR__ . '/config.php';
session_start();

// If already logged in, redirect to dashboard
if (isset($_SESSION['user'])) {
    header('Location: design_dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up - HappyDesign</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/styles.css">
    <style>
      .card, .card *, .btn, .btn *, .form-control, .form-control *, body, body * {
        transition: none !important; animation: none !important;
      }
      .card:hover { transform: none !important; box-shadow: none !important; }
      .btn:hover, .btn:focus, .btn:active { transform: none !important; box-shadow: none !important; }
      .form-control:focus { box-shadow: none !important; }
      .role-fields { display: none; }
    </style>
</head>
<body>
    <main class="container py-5">
        <div class="row justify-content-center">
            <div class="col-12" style="max-width: 600px;">
                <div class="card">
                    <div class="card-body">
                        <h1 class="h4 text-center mb-3 text-dark">HappyDesign</h1>
                        <p class="text-center text-muted mb-4">Create a new account</p>

                        <form action="signup_process.php" method="POST" id="signupForm">
                            <div class="mb-3">
                                <label for="role" class="form-label">I am a...</label>
                                <select id="role" name="role" class="form-select" required onchange="toggleFields()">
                                    <option value="" disabled selected>Select your role</option>
                                    <option value="client">Client</option>
                                    <option value="designer">Designer</option>
                                    <option value="supplier">Contractor</option>
                                    <option value="manager">Manager</option>
                                </select>
                            </div>

                            <!-- Common Fields -->
                            <div id="common-fields" style="display:none;">
                                <div class="mb-3">
                                    <label for="name" class="form-label">Full Name / Company Name</label>
                                    <input id="name" type="text" class="form-control" name="name" required>
                                </div>
                                <div class="mb-3">
                                    <label for="email" class="form-label">Email address</label>
                                    <input id="email" type="email" class="form-control" name="email" required>
                                </div>
                                <div class="mb-3">
                                    <label for="tel" class="form-label">Telephone</label>
                                    <input id="tel" type="tel" class="form-control" name="tel" required>
                                </div>
                                <div class="mb-3">
                                    <label for="password" class="form-label">Password</label>
                                    <input id="password" type="password" class="form-control" name="password" required>
                                </div>
                            </div>

                            <!-- Client Specific Fields -->
                            <div id="client-fields" class="role-fields">
                                <div class="mb-3">
                                    <label for="address" class="form-label">Address</label>
                                    <input id="address" type="text" class="form-control" name="address">
                                </div>

                            </div>

                            <button id="submitBtn" class="w-100 btn btn-primary mt-3" type="submit" style="display:none;">Sign Up</button>
                            
                            <div class="mt-4 text-center">
                                <p class="mb-0 text-muted">Already have an account? <a href="login.php">Sign In</a></p>
                            </div>
                            <p class="mt-4 mb-0 text-muted text-center">&copy; <?= date('Y') ?> HappyDesign</p>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
        function toggleFields() {
            const role = document.getElementById('role').value;
            const commonFields = document.getElementById('common-fields');
            const clientFields = document.getElementById('client-fields');
            const submitBtn = document.getElementById('submitBtn');

            if (role) {
                commonFields.style.display = 'block';
                submitBtn.style.display = 'block';
                
                if (role === 'client') {
                    clientFields.style.display = 'block';
                    document.getElementById('address').required = true;

                } else {
                    clientFields.style.display = 'none';
                    document.getElementById('address').required = false;

                }
            }
        }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
