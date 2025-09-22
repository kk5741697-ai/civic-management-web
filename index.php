<?php
// +------------------------------------------------------------------------+
// | @author Aminul Islam
// | @author_url 1: http://www.vrbel.com
// | @author_email: admin@vrbel.com
// +------------------------------------------------------------------------+
// | Project Management
// | Copyright (c) 2022 Vrbel. All rights reserved.
// +------------------------------------------------------------------------+

require_once('assets/init.php');
if ($domain_details) {
$wo['cache_pages'] = array(
);
$checkHTTPS = checkHTTPS();
$isURLSSL = strpos($domain_details['domain'], 'https');
if ($isURLSSL !== false) {
    if (empty($checkHTTPS)) {
        header("Location: https://" . full_url($_SERVER));
        exit();
    }
} else if ($checkHTTPS) {
    header("Location: http://" . full_url($_SERVER));
    exit();
}
// if (strpos($site_url, 'www') !== false) {
//     if (!preg_match('/www/', $_SERVER['HTTP_HOST'])) {
//         $protocol = ($isURLSSL !== false) ? "https://" : "http://";
//         header("Location: $protocol" . full_url($_SERVER));
//         exit();
//     }
// }
// if (preg_match('/www/', $_SERVER['HTTP_HOST'])) {
//     if (strpos($site_url, 'www') === false) {
//         $protocol = ($isURLSSL !== false) ? "https://" : "http://";
//         header("Location: $protocol" . str_replace("www.", "", full_url($_SERVER)));
//         exit();
//     }
// }
if ($wo['loggedin'] == true) {
    $update_last_seen = Wo_LastSeen($wo['user']['user_id']);
} else if (!empty($_SERVER['HTTP_HOST'])) {
}
if (!empty($_GET)) {
    foreach ($_GET as $key => $value) {
        if (!is_array($value)) {
            $value      = ($key != 'last_url') ? preg_replace('/on[^<>=]+=[^<>]*/m', '', $value) : $value;
            $value      = preg_replace('/\((.*?)\)/m', '', $value);
            $_GET[$key] = strip_tags($value);
        }
    }
}
if (!empty($_REQUEST)) {
    foreach ($_REQUEST as $key => $value) {
        if (!is_array($value)) {
            $value          = preg_replace('/on[^<>=]+=[^<>]*/m', '', $value);
            $_REQUEST[$key] = strip_tags($value);
        }
    }
}
if (!empty($_POST)) {
    foreach ($_POST as $key => $value) {
        if (!is_array($value)) {
            $value       = preg_replace('/on[^<>=]+=[^<>]*/m', '', $value);
            $_POST[$key] = strip_tags($value);
        }
    }
}
if (!empty($_GET['ref']) && $wo['loggedin'] == false) {
    $_GET['ref'] = Wo_Secure($_GET['ref']);
    $ref_user_id = Wo_UserIdFromUsername($_GET['ref']);
    $user_date   = Wo_UserData($ref_user_id);
    if (!empty($user_date)) {
        $_SESSION['ref'] = $user_date['username'];
    }
}
if (!isset($_COOKIE['src'])) {
    @setcookie('src', '1', time() + 31556926, '/');
}
$page = '';
if (!isset($_GET['link1'])) {
    $page = 'home';
} elseif (isset($_GET['link1'])) {
    $page = $_GET['link1'];
}
if ((!isset($_GET['link1']) && $wo['loggedin'] == false) || (isset($_GET['link1']) && $wo['loggedin'] == false && $page == 'home')) {
    if ($wo['loggedin'] == true && !isset($_GET['link1'])) {
		$page = 'home';
	} elseif (isset($_GET['link1'])) {
		$page = $_GET['link1'];
	}
}
if ($wo['config']['maintenance_mode'] == 1) {
    if ($wo['loggedin'] == false) {
        if ($page == 'admincp' || $page == 'admin-cp') {
            $page = 'welcome';
        } else {
            if (empty($_COOKIE['maintenance_access']) || (!empty($_COOKIE['maintenance_access']) && $_COOKIE['maintenance_access'] != 1)) {
                $page = 'maintenance';
            }
        }
    } else {
        if (Wo_IsAdmin() === false) {
            $page = 'maintenance';
        }
    }
}
if (!empty($_GET['m'])) {
    $page = 'welcome';
    setcookie('maintenance_access','1', time() + 31556926, '/');
}
if ($page != 'admincp' && $page != 'admin-cp') {
    if ($wo["loggedin"] && !empty($wo['user']) && $wo['user']['is_pro'] && !empty($wo["pro_packages"][$wo['user']['pro_type']]) && !empty($wo["pro_packages"][$wo['user']['pro_type']]['max_upload'])) {
        $wo['config']['maxUpload'] = $wo["pro_packages"][$wo['user']['pro_type']]['max_upload'];
    }
}
$wo['lang_attr'] = 'en';
$wo['lang_dir'] = 'ltr';
$wo['lang_og_meta'] = '';

if (!empty($wo["language"]) && !empty($wo['iso']) && in_array($wo["language"], array_keys($wo['iso'])) && !empty($wo['iso'][$wo["language"]])) {
    $wo['lang_attr'] = $wo['iso'][$wo["language"]]->iso;
    $wo['lang_dir'] = $wo['iso'][$wo["language"]]->direction;
    $wo['language_type'] = $wo['iso'][$wo["language"]]->direction;
}
if (!$wo['loggedin'] || ($wo['loggedin'] && $wo['user']['banned'] != 1)) {
    $includes = [
        'request_review' => 'sources/request_review.php',
        'redirect' => 'sources/redirect.php',
        'home' => 'sources/home.php',
        'projects' => 'sources/projects.php',
        'login' => 'sources/welcome.php',
        'civic-moonhill' => 'sources/civic-moonhill.php',
        'apartments' => 'sources/apartments.php',
        'gallery' => 'sources/gallery.php',
        'about-us' => 'sources/about-us.php',
        'contact-us' => 'sources/contact.php',
        'register' => 'sources/register.php',
        'forgot-password' => 'sources/forgot_password.php',
        'logout' => 'sources/logout.php',
        '404' => 'sources/404.php',
    ];

    // if ($wo['loggedin']) {
        // $pageToInclude = $includes[$page] ?? 'sources/redirect.php';
    // } else {
    // }
	$pageToInclude = $includes[$page] ?? 'sources/404.php';

    include($pageToInclude);
} else {
    include('sources/banned.php');
}

if (empty($wo['content'])) {
	include('sources/404.php');
}

echo Wo_Loadpage('container');


mysqli_close($sqlConnect);
unset($wo);

} else {
    die("Current domain is not valid.");
}
?>
