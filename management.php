<?php
// You can access the admin panel by using the following url: http://yoursite.com/admincp 
require 'assets/init.php';
Global $wo, $domain_details;

ini_set('display_errors', 1);
ini_set('display_startup_errors', 0);
error_reporting(1);

$is_admin = Wo_IsAdmin();
$is_moderoter = Wo_IsModerator();

if ($wo['config']['maintenance_mode'] == 1) {
    if ($wo['loggedin'] == false) {
        header("Location: " . Wo_SeoLink('index.php') . $wo['marker'] . 'm=true' . $app_version_tag['&']);
        exit();
    } else {
        if ($is_admin === false) {
            header("Location: " . Wo_SeoLink('index.php') . $wo['marker'] . 'm=true' . $app_version_tag['&']);
            exit();
        }
    } 
}
if ($wo['loggedin'] == false) {
    header("Location: " . $wo['site_url'] . '/login/?last_url=' . urlencode($wo['site_url'] . '/management' . $app_version_tag['?']) . $app_version_tag['&']);
    exit();
}
/**
 * Recursively sanitize a value (string or array).
 *
 * @param mixed $val Value to sanitize
 * @param bool  $apply_preg whether to apply the on...= removal preg_replace (true for GET/REQUEST; POST can opt-out)
 * @return mixed sanitized value (array or string)
 */
function sanitize_recursive($val, $apply_preg = true) {
    // If it's an array, recurse on each child
    if (is_array($val)) {
        $out = [];
        foreach ($val as $k => $v) {
            // For nested arrays, keep the same apply_preg behavior
            $out[$k] = sanitize_recursive($v, $apply_preg);
        }
        return $out;
    }

    // If not string, leave as-is (but you could cast to string if desired)
    if (!is_string($val)) {
        return $val;
    }

    // Remove inline event handlers like: onxxx=...  (same as your original)
    if ($apply_preg) {
        $val = preg_replace('/on[^<>=]+=[^<>]*/m', '', $val);
    }

    // Strip HTML tags (safe because $val is guaranteed string here)
    $val = strip_tags($val);

    // Optionally trim
    return trim($val);
}

/* Sanitize GET */
if (!empty($_GET)) {
    foreach ($_GET as $key => $value) {
        // always apply preg_replace for GET (same behavior as original)
        $_GET[$key] = sanitize_recursive($value, true);
    }
}

/* Sanitize REQUEST */
if (!empty($_REQUEST)) {
    foreach ($_REQUEST as $key => $value) {
        // always apply preg_replace for REQUEST (same as original)
        $_REQUEST[$key] = sanitize_recursive($value, true);
    }
}

/* Sanitize POST */
if (!empty($_POST)) {
    foreach ($_POST as $key => $value) {
        // Your original code excluded 'avatar' and 'game' from preg_replace;
        // keep that behavior here: do NOT run preg_replace for these keys.
        $apply_preg = !in_array($key, ['avatar', 'game'], true);
        $_POST[$key] = sanitize_recursive($value, $apply_preg);
    }
}
$wo['script_root'] = dirname(__FILE__);
// autoload admin panel files
require 'manage/autoload.php';