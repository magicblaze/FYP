<?php
$hostname = "127.0.0.1";
$user = "root";
$pwd = "";
$db = "fypdb";

// Google reCAPTCHA v3 keys.
// RECAPTCHA_SITE_KEY and RECAPTCHA_SECRET_KEY.
if (!defined('RECAPTCHA_SITE_KEY')) {
	define('RECAPTCHA_SITE_KEY', '6Ld2sLosAAAAAIOHEW5OY6rPv9a7Nb7oyPV1XCL_');
}
if (!defined('RECAPTCHA_SECRET_KEY')) {
	define('RECAPTCHA_SECRET_KEY', '6Ld2sLosAAAAANsRmwC7Vrk4teugoVeAa_U093EU');
}

$mysqli = mysqli_connect($hostname, $user, $pwd, $db)
			or die (mysqli_connect_error());

?>