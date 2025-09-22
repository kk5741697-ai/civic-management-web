<?php
@ini_set('session.cookie_httponly',1);
@ini_set('session.use_only_cookies',1);
if (!version_compare(PHP_VERSION, '7.1.0', '>=')) {
    exit("Required PHP_VERSION >= 7.1.0 , Your PHP_VERSION is : " . PHP_VERSION . "\n");
}
if (!function_exists("mysqli_connect")) {
    exit("MySQLi is required to run the application, please contact your hosting to enable php mysqli.");
}
$reasons_array = array('Project Visit', 'Client Visit', 'Campaign', 'Bank Visit', 'Informed', 'Collection', 'Punch Mistake');

$wo['payment_banks'] = array('Islami Bank Ltd.', 'Dutch Bangla Bank Ltd.', 'City Bank Ltd.');
$wo['bank_branches'] = array('Gulshan', 'Banani', 'Banasree', 'Dhanmondi', 'Rangpur Terminal');

$bx_icons = [
    'bx bx-box',           // Boxed goods
    'bx bx-package',       // Packaged item
    'bx bx-basket',        // Shopping basket
    'bx bx-cart',          // Cart item
    'bx bx-briefcase',     // Business supplies
    'bx bx-book',          // Book or paper stock
    'bx bx-bulb',          // Light bulbs, electronics
    'bx bx-cube',          // General cube/boxed items
    'bx bx-desktop',       // Electronics
    'bx bx-dumbbell',      // Gym equipment
    'bx bx-food-menu',     // Groceries, packaged food
    'bx bx-football',      // Sports goods
    'bx bx-game',          // Entertainment stock
    'bx bx-gift',          // Gift items
    'bx bx-headphone',     // Electronics / audio
    'bx bx-home',          // Home-related stock
    'bx bx-laptop',        // Electronics
    'bx bx-mouse',         // Computer accessories
    'bx bx-notepad',       // Stationery
    'bx bx-paperclip',     // Office items
    'bx bx-paint',         // Hardware stock
    'bx bx-pen',           // Pens
    'bx bx-phone',         // Mobiles
    'bx bx-printer',       // Office supplies
    'bx bx-ruler',         // Measuring tools
    'bx bx-shape-circle',  // General parts or components
    'bx bx-shape-square',  // General parts
    'bx bx-shopping-bag',  // Groceries or retail
    'bx bx-speaker',       // Audio equipment
    'bx bx-store',         // General store goods
    'bx bx-tv',            // Electronics
    'bx bx-water',         // Bottled water or drinks
    'bx bx-wine',          // Wine/Alcohol
    'bx bx-bolt-circle',   // Hardware/tools
    'bx bx-cookie',        // Snacks
    'bx bx-capsule',       // Medical stock
    'bx bx-cake',          // Baked goods
    'bx bx-package',       // Generic packaging
    'bx bx-spray-can',     // Cleaning/paint supplies
];


$project_mapping = [
    'moon-hill' => 1,
    'hill-town' => 2,
    'civic-abedin' => 3,
    'civic-ashridge' => 4,
    'civic-mittika' => 5,
    'civic-autos' => 6,
];


// inistalize android varrable
$android = array();

$android['is_app'] = false;
$android['version'] = '';
$app_version_tag = ['?' => '', '&' => ''];

// Build query string parameters
$params = [];

if (!empty($_GET['app_version'])) {
    $version = strtok($_GET['app_version'], '?');
    $params[] = 'app_version=' . urlencode($version);
}

if (!empty($_GET['app_version2'])) {
    $version2 = strtok($_GET['app_version2'], '?');
    $params[] = 'app_version2=' . urlencode($version2);
}



// If any version parameters are found
if (!empty($params)) {
    $app_version_tag['?'] = '?' . implode('&', $params);
    $app_version_tag['&'] = '&' . implode('&', $params);
    $android['is_app'] = true;

    // Prioritize app_version2 if exists, otherwise fallback to app_version
    $android['version'] = $version ?? ($version2 ?? '');
}

date_default_timezone_set('Asia/Dhaka');
session_start();
@ini_set('gd.jpeg_ignore_warning', 1);
Global $domain_details;

require_once('assets/libraries/DB/vendor/joshcam/mysqli-database-class/MySQL-Maria.php');
require_once('includes/cache.php');
require_once('includes/functions_general.php');
require_once('includes/tabels.php');
require_once('includes/functions_one.php');
require_once('includes/functions_two.php');
require_once('includes/functions_three.php');