<?php

// ini_set('display_errors', '1');
// ini_set('display_startup_errors', '1');
// error_reporting(E_ALL);

if (!defined('ROOT_DIR')) {
    define('ROOT_DIR', __DIR__ . '/');
}

require_once(ROOT_DIR . 'assets/includes/tabels.php');
$domain_details['domain'] = 'civicgroupbd.com';
require_once ROOT_DIR . 'config.php';
require_once ROOT_DIR . "assets/libraries/DB/vendor/autoload.php";

// Connect to SQL Server
$sqlConnect = mysqli_connect($sql_db_host, $sql_db_user, $sql_db_pass, $sql_db_name, 3306);
$sqlConnect->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10000);


$db        = new MysqliDb($sqlConnect);


function Wo_Secure($string, $censored_words = 0, $br = true, $strip = 0,$cleanString = true) {
    global $sqlConnect;
    $string = trim($string);
    if ($cleanString) {
        $string = cleanString($string);
    }
    $string = mysqli_real_escape_string($sqlConnect, $string);
    $string = htmlspecialchars($string, ENT_QUOTES);
    if ($br == true) {
        $string = str_replace('\r\n', " <br>", $string);
        $string = str_replace('\n\r', " <br>", $string);
        $string = str_replace('\r', " <br>", $string);
        $string = str_replace('\n', " <br>", $string);
    } else {
        $string = str_replace('\r\n', "", $string);
        $string = str_replace('\n\r', "", $string);
        $string = str_replace('\r', "", $string);
        $string = str_replace('\n', "", $string);
    }
    if ($strip == 1) {
        $string = stripslashes($string);
    }
    $string = str_replace('&amp;#', '&#', $string);
    if ($censored_words == 1) {
        global $config;
        $censored_words = @explode(",", $config['censored_words']);
        foreach ($censored_words as $censored_word) {
            $censored_word = trim($censored_word);
            $string        = str_replace($censored_word, '****', $string);
        }
    }
    return $string;
}

function cleanString($string) {
    return $string = preg_replace("/&#?[a-z0-9]+;/i", "", $string);
}

function Wo_GetConfig() {
    global $sqlConnect;
    $data  = array();
    $query = mysqli_query($sqlConnect, "SELECT * FROM " . T_CONFIG);
    if (mysqli_num_rows($query)) {
        while ($fetched_data = mysqli_fetch_assoc($query)) {
            $data[$fetched_data['name']] = $fetched_data['value'];
        }
    }
    return $data;
}

$config    = Wo_GetConfig();

function Wo_SaveConfig($update_name, $value) {
    global $sqlConnect;
    
    $update_name = Wo_Secure($update_name);
    $value       = mysqli_real_escape_string($sqlConnect, $value);
    $query_one   = " UPDATE " . T_CONFIG . " SET `value` = '{$value}' WHERE `name` = '{$update_name}'";
    $query       = mysqli_query($sqlConnect, $query_one);
    if ($query) {
        return true;
    } else {
        return false;
    }
}



if (!function_exists("zk_Update_dbAttendance_from_machine")) {
    require_once(ROOT_DIR . 'assets/includes/zk_functions.php');
}

include(ROOT_DIR . 'assets/includes/leads_system.php');

// Ensure the function exists before calling it
if (function_exists('zk_Update_dbAttendance_from_machine')) {
    zk_Update_dbAttendance_from_machine();
} else {
    die("Error: Function zk_Update_dbAttendance_from_machine() not found.");
}



mysqli_close($sqlConnect);
exit();