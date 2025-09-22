<?php
// error_reporting(E_ALL);
// ini_set('display_errors', 1);

if ($f == "attandence") {
	$lock_update = update_lockout_time();
	date_default_timezone_set('Asia/Dhaka');
	
	$errors = array();
	$result = array();  // Initialize as an array for consistency

	if (Wo_IsAdmin() || Wo_IsModerator() || check_permission('check-attandance') || check_permission('manage-attandance') || $wo['user']['is_team_leader'] == true) {
		$get_uid = isset($_POST['user_id']) ? Wo_Secure($_POST['user_id']) : '';
	} else {
		$get_uid = $wo['user']['user_id'];
	}

	$date_start = isset($_POST['data_start']) ? Wo_Secure($_POST['data_start']) : '';
	$date_end = isset($_POST['data_end']) ? Wo_Secure($_POST['data_end']) : '';

	if ($s == "submit_attendance_form") {
		$attendances = $_POST['attendance'];
		$date_value = $_POST['date_value'];
		
		foreach ($attendances as $attendance) {
			$user_id = $attendance['user_id'];
			$date_time_start = $date_value . ' 00:00:00';
			$date_time_end = $date_value . ' 23:59:59';
			
			if (isset($attendance['in_time']) && !empty($attendance['in_time'])) {
				$date_time = $date_value . ' ' . $attendance['in_time'] . ':00';

				$if_exist_i = $db->where('USERID', $user_id)->where('CHECKTYPE', 'i')->where('CHECKTIME', $date_time_start, '>=')->where('CHECKTIME', $date_time_end, '<=')->orderBy('CHECKTIME', 'DESC')->getOne('atten_in_out');
								
				if ($if_exist_i) {
					$update = $db->where('id', $if_exist_i->id)
								 ->update('atten_in_out', array(
									 'USERID'     => $user_id,
									 'CHECKTYPE'  => 'i',
									 'CHECKTIME'  => $date_time,
									 'entry_time' => date('Y-m-d H:i:s'),
									 'active'     => '1'
								 ));
				} else {
					$insert = $db->insert('atten_in_out', array(
						'USERID'     => $user_id,
						'CHECKTYPE'  => 'i',
						'CHECKTIME'  => $date_time,
						'entry_time' => date('Y-m-d H:i:s'),
						'active'     => '1'
					));
				}
			}
			if (isset($attendance['out_time']) && !empty($attendance['out_time'])) {
				$date_time = $date_value . ' ' . $attendance['out_time'] . ':00';
				
				$if_exist_o = $db->where('USERID', $user_id)->where('CHECKTYPE', 'o')->where('CHECKTIME', $date_time_start, '>=')->where('CHECKTIME', $date_time_end, '<=')->orderBy('CHECKTIME', 'DESC')->getOne('atten_in_out');

				if ($if_exist_o) {
					$update = $db->where('id', $if_exist_o->id)
								 ->update('atten_in_out', array(
									 'USERID'     => $user_id,
									 'CHECKTYPE'  => 'o',
									 'CHECKTIME'  => $date_time,
									 'entry_time' => date('Y-m-d H:i:s'),
									 'active'     => '1'
								 ));
				} else {
					$insert = $db->insert('atten_in_out', array(
						'USERID'     => $user_id,
						'CHECKTYPE'  => 'o',
						'CHECKTIME'  => $date_time,
						'entry_time' => date('Y-m-d H:i:s'),
						'active'     => '1'
					));
				}
			}
			
		}

		$response = array(
			'status' => 200
		);

		header("Content-type: application/json");
		echo json_encode($response);
		exit();
	}
	if ($s == "register_attendance") {
		setcookie("default_u_atten", $get_uid, time() + (10 * 365 * 24 * 60 * 60), '/');
		
		$form_start = '<form class="row g-3" id="submit_attendance_form">';
		$form_end = '<div class="col-12 col-lg-12 mt-0" style=" position: sticky; bottom: 15px; "> <div class="d-grid"> <button type="submit" class="btn btn-primary">Submit</button> </div> </div></form>';
		$date = isset($_POST['date']) ? Wo_Secure($_POST['date']) : '';

		if (empty($get_uid) || empty($date)) {
			$errors[] = 'User ID or Date is missing!';
		} else {
			// Define function outside of the conditional block

		function register_attendance_form($user, $date) {
			global $wo, $db;
			
			// Retrieve In and Out time
			$in_time = $db->where('USERID', $user->user_id)
						   ->where('CHECKTYPE', "i")
						   ->where('CHECKTIME', "$date 00:00:00", '>=')
						   ->where('CHECKTIME', "$date 23:59:59", '<=')
						   ->orderBy('CHECKTIME', "ASC")
						   ->getOne('atten_in_out');
			
			$out_time = $db->where('USERID', $user->user_id)
							->where('CHECKTYPE', "o")
							->where('CHECKTIME', "$date 00:00:00", '>=')
							->where('CHECKTIME', "$date 23:59:59", '<=')
							->orderBy('CHECKTIME', "ASC")
							->getOne('atten_in_out');
			
			// Convert times to 24-hour format if necessary
			$in_time_formatted = isset($in_time->CHECKTIME) ? date('H:i', strtotime($in_time->CHECKTIME)) : '';
			$out_time_formatted = isset($out_time->CHECKTIME) ? date('H:i', strtotime($out_time->CHECKTIME)) : '';

			// Generate HTML
			$result = '<input type="hidden" name="attendance[' . $user->user_id . '][user_id]" class="form-control" value="' . $user->user_id . '">';
			$result .= '<div class="col-12 col-sm-12 col-md-12 col-xl-6 col-xxl-6 d-flex align-items-center mt-0 image_margin">
							<td><img src="' . $wo['site_url'] . '/' . $user->avatar . '" class="user-img" style="width: 24px; height: 24px; border-radius: 35px; margin-right: 8px;">' 
							. $user->first_name . ' ' . $user->last_name . '</td>
					    </div>';
			// $result .= '<div class="col-12 col-sm-6 col-md-6 col-xl-3 col-xxl-3 mt-0 intime_margin"> 
							// <label class="form-label">In time</label> 
							// <input type="time" name="attendance[' . $user->user_id . '][in_time]" value="' . $in_time_formatted . '" class="form-control time-input-in" ' . (isset($in_time->CHECKTIME) && !empty($in_time->CHECKTIME) ? 'disabled' : '') . '>
						// </div>';
			// $result .= '<div class="col-12 col-sm-6 col-md-6 col-xl-3 col-xxl-3 mt-0"> 
							// <label class="form-label">Out time</label> 
							// <input type="time" name="attendance[' . $user->user_id . '][out_time]" value="' . $out_time_formatted . '" class="form-control time-input-out" ' . (isset($out_time->CHECKTIME) && !empty($out_time->CHECKTIME) ? 'disabled' : '') . '>
						// </div>';
			$result .= '<div class="col-12 col-sm-6 col-md-6 col-xl-3 col-xxl-3 mt-0 intime_margin"> 
							<label class="form-label">In time</label> 
							<input type="time" name="attendance[' . $user->user_id . '][in_time]" value="' . $in_time_formatted . '" class="form-control time-input-in">
						</div>';
			$result .= '<div class="col-12 col-sm-6 col-md-6 col-xl-3 col-xxl-3 mt-0"> 
							<label class="form-label">Out time</label> 
							<input type="time" name="attendance[' . $user->user_id . '][out_time]" value="' . $out_time_formatted . '" class="form-control time-input-out">
						</div>';
			$result .= '<hr/>';
			
			return $result;
		}

			
			
			
			// Check if user_id is 999, fetch all users' data
			if ($get_uid == 999) {
				// Fetch all users
				$get_users = $db->where('active', '1')->orderBy('serial', 'ASC')->get(T_USERS);
				
				// Initialize the result array
				$result[] = $form_start;
				foreach ($get_users as $userlist) {
					// Fetch and process attendance data for each user
					$result[] = register_attendance_form($userlist, $date);
				}
				$result[] = $form_end;
				$result[] = Wo_LoadManagePage('attendance/script');
				$result = implode('', $result);
			} else {
				// Fetch the specified user's data
				$atten_user = $db->where('active', '1')->where('user_id', $get_uid)->getOne(T_USERS);
				
				if ($atten_user) {
					// Process attendance data for the specified user
					
					$result = $form_start;
					$result .= register_attendance_form($atten_user, $date);
					$result .= $form_end;
					$result .= Wo_LoadManagePage('attendance/script');
				} else {
					$errors[] = 'User not found!';
				}
			}

			$response = array(
				'status' => (empty($errors)) ? 200 : 400,
				'result' => $result,
			);

			header("Content-type: application/json");
			echo json_encode($response);
			exit();
		}
	}
	if ($s == "result") {
		setcookie("default_u_atten", $get_uid, time() + (10 * 365 * 24 * 60 * 60), '/');
		setcookie("start_end", $date_start . ' to ' . $date_end, time() + (10 * 365 * 24 * 60 * 60), '/');

		if (empty($get_uid) || empty($date_start) || empty($date_end)) {
			$errors[] = 'Something Went Wrong!';
		} else {
			// Check if user_id is 0, fetch all users' data
			if ($get_uid == 999) {
				// Fetch all users
                if ($wo['user']['is_team_leader'] == true) {
					$get_users = $db->where('active', '1')->where('leader_id', $wo['user']['user_id'])->orWhere('user_id', $wo['user']['user_id'])->orderBy('serial', 'ASC')->get(T_USERS);
                } else {
					$get_users = $db->where('active', '1')->orderBy('serial', 'ASC')->get(T_USERS);
                }
                
				// Initialize the result array
				$result = [];

				// Loop through all users
				foreach ($get_users as $userlist) {
					// Fetch and process attendance data for each user
					$result = array_merge($result, fetchAndProcessAttendance($userlist, $date_start, $date_end));
				}
			} else {
				// Fetch the specified user's data
				$atten_user = $db->where('active', '1')->where('user_id', $get_uid)->getOne(T_USERS);

				// Process attendance data for the specified user
				$result = fetchAndProcessAttendance($atten_user, $date_start, $date_end);
			}

			$response = array(
				'status' => (empty($errors)) ? 200 : 400,
				'result' => $result,
			);

			header("Content-type: application/json");
			echo json_encode($response);
			exit();
		}
	}

	if ($s == "add_reason") {
	    ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL);

		$get_reason = isset($_POST['reason']) ? Wo_Secure($_POST['reason']) : '';
		$get_text   = isset($_POST['text']) ? $_POST['text'] : '';
		$get_date   = isset($_POST['date']) ? Wo_Secure($_POST['date']) : '';
		$get_uid    = isset($_POST['user']) ? Wo_Secure($_POST['user']) : '';
		$insert     = false;

		if (empty($get_uid) || empty($get_date) || empty($get_reason)) {
			$errors[] = 'Something Went Wrong!';
		} else {
			$if_exist = $db->where('Badgenumber', $get_uid)->where('date', $get_date)->getOne('atten_reason');
			if ($if_exist) {
				if ($get_reason == 'remove') {
					$insert = $db->where('id', $if_exist->id)->delete('atten_reason');
					$get_reason = ' ';
					$message = 'Reason removed!';
				} else {
					$update_data = array(
						'Badgenumber' => $get_uid,
						'reason'      => $get_reason,
						'text'        => $get_text,
						'date'        => $get_date,
						'time'        => time()
					);
					$insert      = $db->where('id', $if_exist->id)->update('atten_reason', $update_data);					
					$message = 'Reason Updated!';
				}
				
			} else {
				$insert = $db->insert('atten_reason', array(
					'Badgenumber' => $get_uid,
					'reason'      => $get_reason,
					'text'        => $get_text,
					'date'        => $get_date,
					'time'        => time()
				));
				$message = 'Reason Added!';
			}
		}

		if ($insert) {
            // Build log message
            $logUser     = 'User #' . $get_uid;
            $logDate     = $get_date;
            $logText     = !empty($get_text) ? ': ' . $get_text : '';
            $logDetails  = "{$logUser} on {$logDate}{$logText}";
        
            if ($get_reason == ' ') {
                $logType = 'delete';
                $logMessage = "Removed attendance reason for {$logDetails}";
            } elseif ($if_exist) {
                $logType = 'update';
                $logMessage = "Updated attendance reason to '{$get_reason}' for {$logDetails}";
            } else {
                $logType = 'create';
                $logMessage = "Added attendance reason '{$get_reason}' for {$logDetails}";
            }
        
            logActivity('attendance_reason', $logType, $logMessage);
            
            
			$response = array(
				'status'  => 200,
				'target_id'  => $get_uid . '_' . str_replace('-', '_', $get_date),
				'reason_text' => $get_reason,
				'message' => $message
			);
        } else {
			$response = array(
				'status'  => 400,
				'message' => $errors
			);
		}

		header("Content-type: application/json");
		echo json_encode($response);
		exit();
	}

	if ($s == "reason_modal") {
		$get_date = isset($_POST['date']) ? Wo_Secure($_POST['date']) : '';
		$get_uid = isset($_POST['user_id']) ? Wo_Secure($_POST['user_id']) : '';
		
		if (empty($get_uid) || empty($get_date)) {
			$errors[] = 'Something Went Wrong!';
		} else {
			$wo['reason']['date'] = $get_date;
			$wo['reason']['user_id'] = $get_uid;

			$response = array(
				'status' => (empty($errors)) ? 200 : 400,
				'result' => Wo_LoadManagePage('attendance/add_reason')
			);

			header("Content-type: application/json");
			echo json_encode($response);
			exit();
		}
	}
}