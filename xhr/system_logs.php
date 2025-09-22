<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('Asia/Dhaka');
if ($f == 'system_logs') {
	if ($s == 'fetch_report') {
// 		if (Wo_IsAdmin() || Wo_IsModerator() || check_permission('system_logs')) {
			$get_uid = isset($_POST['user_id']) ? Wo_Secure($_POST['user_id']) : '';
// 		} else {
// 			$get_uid = $wo['user']['user_id'];
// 		}
		
		// Retrieve and sanitize date inputs from POST or set default values
		$date_start = isset($_POST['data_start']) ? Wo_Secure($_POST['data_start']) : date('Y-m-01');
		$date_end = isset($_POST['data_end']) ? Wo_Secure($_POST['data_end']) : '';
		
		setcookie("default_u_syslog", $get_uid, time() + (10 * 365 * 24 * 60 * 60), '/');
		setcookie("start_end_syslog", $date_start . ' to ' . $date_end, time() + (10 * 365 * 24 * 60 * 60), '/');
		// Adjust date format and set timestamps
		if (empty($date_end)) {
			// If $date_end is empty, set it to the end of the selected day
			$date_end = $date_start . ' 23:59:59';
			$date_start = $date_start . ' 00:00:00';
		} else {
			// If both dates are provided, set timestamps for the entire days
			$date_start = $date_start . ' 00:00:00';
			$date_end = $date_end . ' 23:59:59';
		}
		
		// Fetch and process data
		$page_num = isset($_POST['start']) ? $_POST['start'] / $_POST['length'] + 1 : 1;
		
		// Get the search value
		$searchValue = isset($_POST['search']['value']) ? $_POST['search']['value'] : '';

		// Initialize conditions array for filtering
		$searchConditions = array();

		$start_timestamp = strtotime($date_start);
		$end_timestamp = strtotime($date_end);
		
		$db2 = clone $db;
		
		if (!empty($start_timestamp) && !empty($end_timestamp)) {
			$db2->where('created_at', $start_timestamp, '>=')->where('created_at', $end_timestamp, '<=');
		}
		if (!empty($get_uid) && is_numeric($get_uid) && $get_uid != 999) {
			$db2->where('user_id', $get_uid);
		}
		
		if (!empty($searchValue) && is_numeric($searchValue)) {
			$db2->where('id', ltrim($searchValue, '#'));
		}
		
		// foreach ($searchConditions as $condition) {
			// $db2->where($condition[0], $condition[1], $condition[2]);
		// }

		// Order by the column specified by the user
		$orderColumn = isset($_POST['order'][0]['column']) ? $_POST['order'][0]['column'] : null;
		$orderDirection = isset($_POST['order'][0]['dir']) ? $_POST['order'][0]['dir'] : null;

		if ($orderColumn !== null) {
			if ($orderColumn == 0) {
				$db2->orderBy('id', $orderDirection == 'asc' ? 'ASC' : 'DESC');
			} else {
				$db2->orderBy('id', 'DESC');
			}
		} else {
			$db2->orderBy('id', 'DESC');
		}
		
		if ($orderColumn !== null) {
			if ($orderColumn == 0) {
				$db2->orderBy('id', $orderDirection == 'asc' ? 'ASC' : 'DESC');
			} else {
				$db2->orderBy('id', 'DESC');
			}
		} else {
			$db2->orderBy('id', 'DESC');
		}
		
		if (!empty($_POST['features']) && is_array($_POST['features'])) {
            if (in_array('999', $_POST['features'])) {
                // "All Features" selected — skip filtering
            } else {
                $db2->where('feature', $_POST['features'], 'IN');
            }
        }

		$countDb = clone $db2;
		$count = $countDb->where('created_at', $start_timestamp, '>=')->where('created_at', $end_timestamp, '<=')->getValue('activity_logs', 'count(*)') ?? 0;


		$db2->pageLimit = $_POST['length'];
		$link = '';

		$logs = $db2->objectbuilder()->paginate('activity_logs', $page_num);
		
		// Prepare data for DataTables
		$outputData = array();
		
		foreach ($logs as $log) {
			$user_data = Wo_UserData($log->user_id);
            $browser = GetDeviceName($log->device_info);
            $browser_icon = GetDeviceIcon($browser);
            if ($log->user_id == '999') {
                $name = '<i class="fa fa-microchip" style=" font-size: 17px; "></i> System';
            } else {
                $name = '<img src="' . $wo['site_url'] . '/' . $user_data['avatar_24'] . '" class="user-img" style=" width: 24px; height: 24px; border-radius: 35px; margin-right: 8px; ">' . $user_data['name'];
            }


			$outputData[] = array(
				'id' => $log->id,
				'name' => $name,
				'activity_type' => ucfirst($log->activity_type),
				'feature' => ucfirst(str_replace('_', ' ', $log->feature)),
				'details' => $log->details,
				'created_at' => date('Y-m-d h:i A', $log->created_at),
                'device_info' => $browser_icon . ' ' . $browser,
				'ip_address' => $log->ip_address,
			);

		}
		// Send JSON response
		$data = array(
			"draw" => intval($_POST['draw']),
			"recordsTotal" => $count ?? 0,
			"recordsFiltered" => $db->totalPages * $_POST['length'],
			"data" => $outputData
		);
	}
	
	if ($s == 'download_report') {
		// Retrieve and sanitize date inputs from POST or set default values
		$date_start = isset($_POST['data_start']) ? Wo_Secure($_POST['data_start']) : date('Y-m-01');
		$date_end = isset($_POST['data_end']) ? Wo_Secure($_POST['data_end']) : '';
		$user_id = isset($_POST['user_id']) ? Wo_Secure($_POST['user_id']) : '';
		
		
        if (!empty($date_start) && !empty($date_end)) {
			$hash_id = Wo_CreateMainSession();
			$data = array(
				'status' => 200,
				'result' => $site_url . '/download.php?dl_type=leave_report&user_id=' . $user_id . '&date_start=' . $date_start . '&date_end=' . $date_end . '&token=' . $hash_id
			);
        } else {
            $data = array(
                'status' => 500,
                'message' => 'Please select a date!'
            );
        }
	}
	header("Content-type: application/json");
	echo json_encode($data);
	exit();
}