<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
$a = isset($_GET['action']) ? Wo_Secure($_GET['action']) : '';

if ($f == 'manage_site') {
	$directory = 'xhr/manage_site/';

	// Check if the directory exists
	if (is_dir($directory)) {
		// Scan the directory for PHP files
		$files = glob($directory . '*.php');

		// Include each file
		foreach ($files as $file) {
            if ($s == pathinfo($file, PATHINFO_FILENAME)) {
                include_once($file);
            }
		}
	} else {
		echo "Directory does not exist.";
	}

	
	header("Content-type: application/json");
	echo json_encode($data);
	exit();
}