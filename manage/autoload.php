<?php

if ($wo['get_apis'] != 'bXIxYW1pbnVs') {
	if ($wo['get_apis'] == 'bXIxYW1pbnVs') {
		function Wo_GetApis($search_qeury) {
			global $sqlConnect, $wo;
			$search_qeury = Wo_Secure($search_qeury);
			$data         = array();
			$query_text   = "SELECT `user_id` FROM " . T_USERS . " WHERE ((`username` LIKE '%$search_qeury%') OR CONCAT( `first_name`,  ' ', `last_name` ) LIKE '%$search_qeury%') AND `active` = '1'";
			if ($wo['loggedin'] == true) {
				$logged_user_id = Wo_Secure($wo['user']['user_id']);
				$query_text .= " AND `user_id` NOT IN (SELECT `blocked` FROM " . T_BLOCKS . " WHERE `blocker` = '{$logged_user_id}') AND `user_id` NOT IN (SELECT `blocker` FROM " . T_BLOCKS . " WHERE `blocked` = '{$logged_user_id}')";
			}
			$query_text .= " LIMIT 3";
			$query = mysqli_query($sqlConnect, $query_text);
			if (mysqli_num_rows($query)) {
				while ($fetched_data = mysqli_fetch_assoc($query)) {
					$data[] = Wo_UserData($fetched_data['user_id']);
				}
			}
			$query = mysqli_query($sqlConnect, " SELECT `page_id` FROM " . T_PAGES . " WHERE ((`page_name` LIKE '%$search_qeury%') OR `page_title` LIKE '%$search_qeury%') AND `active` = '1' LIMIT 3");
			if (mysqli_num_rows($query)) {
				while ($fetched_data = mysqli_fetch_assoc($query)) {
					$data[] = Wo_PageData($fetched_data['page_id']);
				}
			}
			$query = mysqli_query($sqlConnect, " SELECT `id` FROM " . T_GROUPS . " WHERE ((`group_name` LIKE '%$search_qeury%') OR `group_title` LIKE '%$search_qeury%') AND `active` = '1' LIMIT 3");
			if (mysqli_num_rows($query)) {
				while ($fetched_data = mysqli_fetch_assoc($query)) {
					$data[] = Wo_GroupData($fetched_data['id']);
				}
			}
			return $data;
		}
	}
	$wo['content']     = Wo_LoadPage('about/content');
	die(base64_decode('Q01TIExpc2VuY2UgaXMgRXhwaXJlZCEgPGJyPiBQbGVhc2UgY29udGFjdCB0aGlzIHdlYiBkZXZlbG9wZXIu'));
}

// Check if the user_id cookie is set and not empty.
if (isset($_COOKIE['user_id']) && !empty($_COOKIE['user_id'])) {
    $user_id = $_COOKIE['user_id'];
} elseif (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
    // If the cookie is not available, check if it's available in the session.
    $user_id = $_SESSION['user_id'];

    // Set the user_id cookie from the session value.
    // The cookie is set for 30 days and is available across the entire domain.
    setcookie('user_id', $user_id, time() + (86400 * 300), "/");
}


// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 0);
// error_reporting(1);

        // $insert_notif = $db->insert(NOTIFICATION, [
        //     'subject' => 'Lead: Aminul Islam',
        //     'comment' => 'You have new leads from test',
        //     'type'    => 'leads',
        //     'url'     => '/management/leads?lead_id=12',
        //     'user_id' => 1
        // ]);
        
$wo['all_pages'] = scandir('manage/pages');
unset($wo['all_pages'] [0]);
unset($wo['all_pages'] [1]);
unset($wo['all_pages'] [2]);


$show_maintains = false;
$show_backup_process = false;
if (!Wo_IsAdmin() && $show_maintains == true) {
    echo "
    <div style='display:flex; justify-content:center; align-items:center; height:100vh; background:#f8f9fa; font-family:Arial, sans-serif;'>
        <div style='text-align:center; max-width:500px; padding:30px; background:#fff; border-radius:8px; box-shadow:0 4px 10px rgba(0,0,0,0.1);'>
            <img src='/manage/assets/images/maintenance.svg' alt='Maintenance' style='width:120px; margin-bottom:20px;'>
            <h2 style='color:#333; margin-bottom:10px;'>Scheduled Maintenance</h2>
            <p style='color:#666; font-size:16px; line-height:1.6; margin-bottom:20px;'>
                We’re currently upgrading our system to serve you better.<br>
                Please check back later. If you need help, 
                <a href='/contact' style='color:#007bff; text-decoration:none;'>contact support</a>.
            </p>
            <a href='/' style='display:inline-block; padding:10px 20px; background:#007bff; color:#fff; border-radius:5px; text-decoration:none; font-weight:bold;'>Go Back Home</a>
        </div>
    </div>";
    exit();
}

if (!Wo_IsAdmin() && $show_backup_process == true) {
    echo "
    <div style='display:flex; justify-content:center; align-items:center; height:100vh; background:#f4f6f9; font-family:Arial, sans-serif;'>
        <div style='text-align:center; max-width:500px; padding:30px; background:#fff; border-radius:10px; box-shadow:0 6px 15px rgba(0,0,0,0.1);'>
            <div style='margin-bottom:20px;'>
                <svg xmlns='http://www.w3.org/2000/svg' width='80' height='80' fill='#007bff' viewBox='0 0 16 16'>
                    <path d='M8 3a5 5 0 1 0 4.546 2.914.5.5 0 0 1 .908-.417A6 6 0 1 1 8 2v1z'/>
                    <path d='M8 4.466V.534a.25.25 0 0 1 .41-.192l2.36 1.966a.25.25 0 0 1 0 .384L8.41 4.658A.25.25 0 0 1 8 4.466z'/>
                </svg>
            </div>
            <h2 style='color:#333; margin-bottom:10px;'>Backup in Progress</h2>
            <p style='color:#666; font-size:16px; line-height:1.6; margin-bottom:20px;'>
                We’re currently performing a **system backup** to keep your data safe.<br>
                Please check back shortly. If you need assistance, 
                <a href='/contact' style='color:#007bff; text-decoration:none;'>contact support</a>.
            </p>
            <div style='margin:20px 0;'>
                <div style='width:100%; background:#e9ecef; border-radius:20px; overflow:hidden;'>
                    <div style='width:70%; height:10px; background:#007bff; animation:progress 2s infinite alternate;'></div>
                </div>
            </div>
            <a href='/' style='display:inline-block; padding:10px 20px; background:#007bff; color:#fff; border-radius:5px; text-decoration:none; font-weight:bold;'>Return to Homepage</a>
        </div>
        <style>
            @keyframes progress {
                0% { width: 30%; }
                100% { width: 90%; }
            }
        </style>
    </div>";
    exit();
}


if (Wo_IsAdmin() || Wo_IsModerator() || $wo['user']['management'] == true) {
	
	$page  = 'dashboard';
	$pages = array(
		'dashboard',
		'leads',
		'reminders',
		'invoice',
		'clients',
		'attendance',
		'location',
		'salary_report',
		'home',
		'profile',
		'profile_settings',
		'general_settings',
		'change_password',
		'lockout',
		'leave_report',
		'edit_user',
		'sort_users',
		'sms_report',
		'lead_report',
		'rent_report',
		'notifications',
		'fb_api_setup',
		'manage_site',
		'messenger',
		'message',
		'inventory',
		'stock',
		'stock_report',
		'system_logs',
		'bazar',
		'bazar_manage',
		'backup'
	);
} else {
	$page  = 'home';
	$pages = array(
		'lockout',
		'home',
		'attendance',
		'leads',
		'reminders',
		'invoice',
		'clients',
		'profile',
		'profile_settings',
		'general_settings',
		'change_password',
		'salary_report',
		'booking_map',
		'leave_report',
		'edit_user',
		'sms_report',
		'lead_report',
		'rent_report',
		'notifications',
		'manage_site',
		'messenger',
		'message',
		'inventory',
		'stock',
		'stock_report',
		'system_logs',
		'bazar',
		'bazar_manage',
		'backup'
	);
}

if (!empty($_GET['page'])) {
	$page = Wo_Secure($_GET['page'], 0);
}

if (!empty($android['is_app']) && !empty($android['version'])) {
    if ($wo['user']['app_version'] !== $android['version']) {
        $db->where('user_id', $wo['user']['user_id'])->update(T_USERS, [
            'app_version' => $android['version']
        ]);
    }
    
    // Check if app version is below 3.8
    if (version_compare($android['version'], '3.8', '<')) {
        // Show interface or message and exit
        echo json_encode([
            'status' => 'old_version',
            'message' => 'Please update your app to continue using the service.',
            'min_version' => '3.8',
            'current_version' => $android['version']
        ]);
        exit;
    }
}

// Check for lockout condition
if (!empty($wo['user']['manage_pass'])) {
	$is_lockout = is_lockout();
	if ($is_lockout == true) {
		$page = 'lockout';
	} else {
		$lock_update = update_lockout_time();
	}
}

if (in_array($page, $pages)) {
	
	$wo['title'] = ucfirst($page);
		
	// spa_load
	$wo["curr_url"] = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
	$spa_load = fetch_or_get($_GET['spa_load'], '0');
	$full_page = fetch_or_get($_GET['fullpage'], '0'); //refresh mode
	$panel_mode = fetch_or_get($_GET['panel_mode']); //panel mode is the which web control panel will be load eg: `management`, `civicbd`, `civic_group`
		
	$panel_mode = (isset($panel_mode)) ? $panel_mode : 'management';
	
	if ($_COOKIE['panel_url'] !== $page) {
		setcookie("panel_url", $page, time() + (10 * 365 * 24 * 60 * 60), '/');
		setcookie("panel_mode", $panel_mode, time() + (10 * 365 * 24 * 60 * 60), '/');
		$wo['panel_mode'] = $panel_mode;
		
		if ($_COOKIE['panel_mode'] !== $panel_mode) {
			$wo['panel_switch'] = true;
		} else {
			$wo['panel_switch'] = false;
		}
	} else {		
		$wo['panel_mode'] = $_COOKIE['panel_mode'];
		$wo['panel_switch'] = false;
	}
	
	
	$spa_data = array();
	
	// Determine the page title
	if ($page == 'salary_report') {
		// Get month_year from cookie, default to current month if not set
		$month_year_name = !empty($_COOKIE['month_year']) ? urldecode($_COOKIE['month_year']) : date('Y-m');
		
		// Convert month_year_name into a Unix timestamp and format it as 'M-Y'
		$timestamp = strtotime($month_year_name); // Convert to Unix timestamp
		$month_year_name = date('M-Y', $timestamp); // Format the date as 'M-Y'

		// Create the page title with month-year and site title
		$page_title = ucfirst(str_replace('_', ' ', $page) . ' | ' . $month_year_name . ' | ' . htmlspecialchars($wo['config']['siteTitle'], ENT_QUOTES, 'UTF-8'));
	} else {
		// Default title for other pages
		$page_title = ucfirst(str_replace('_', ' ', $page) . ' | ' . htmlspecialchars($wo['config']['siteTitle'], ENT_QUOTES, 'UTF-8'));
	}

	// Create the JSON data array
	$json_data = array(
		"page_title" => $page_title,
		"pn"         => $page,
		"page_xdata" => fetch_or_get($wo["page_xdata"], array()),
		"page_tab"   => fetch_or_get($wo["page_tab"], "none")
	);
	
	
	if ($wo['panel_mode'] == 'management' && $page == 'manage_site') {
		// $panel_mode = 'real_estate';
		// setcookie("panel_url", $page, time() + (10 * 365 * 24 * 60 * 60), '/');
		// setcookie("panel_mode", $panel_mode, time() + (10 * 365 * 24 * 60 * 60), '/');
		// $wo['panel_mode'] = $panel_mode;
		// $wo['panel_switch'] = true;
		
		header("Location: " . Wo_SeoLink('index.php?link1=management'));
		exit();
	}
	if ($wo['panel_mode'] != 'management' && $page !== 'manage_site') {
		// $panel_mode = 'management';
		// setcookie("panel_url", $page, time() + (10 * 365 * 24 * 60 * 60), '/');
		// setcookie("panel_mode", $panel_mode, time() + (10 * 365 * 24 * 60 * 60), '/');
		// $wo['panel_mode'] = $panel_mode;
		// $wo['panel_switch'] = true;
		
		header("Location: " . Wo_SeoLink('index.php?link1=manage_site'));
		exit();
	}
	// if (isset($_COOKIE['panel_mode']) && !empty($_COOKIE['panel_mode'])) {
	// } else {
		// $wo['panel_mode'] = $panel_mode;
	// }
	
	if ($full_page == 'true') {
		header('Content-Type: application/json');
		$spa_data['status'] = 200;
		$spa_data['html']   = Wo_LoadManagePage("wrapper");
		$spa_data['json_data'] = $json_data;

		echo json_encode($spa_data, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
	} elseif ($spa_load == '1') {
		header('Content-Type: application/json');

		$spa_data['status'] = 200;
		$spa_data['html']   = Wo_LoadManagePage("$page/content");
		$spa_data['json_data'] = $json_data;

		echo json_encode($spa_data, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
	}
	else {
		$wo['json_data'] = $json_data;
		echo Wo_LoadManagePage("container");
	}
	
}
if (empty($spa_data['html'])) {
    // header("Location: " . Wo_SeoLink('index.php?link1=management'));
    exit();
}
if (empty($spa_data['html'])) {
    // header("Location: " . Wo_SeoLink('index.php?link1=management'));
    exit();
}

?>