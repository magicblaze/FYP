<?php
$hostname = "127.0.0.1";
$user = "root";
$pwd = "";
$db = "fypdb";
$mysqli = mysqli_connect($hostname, $user, $pwd, $db)
			or die (mysqli_connect_error());

?>