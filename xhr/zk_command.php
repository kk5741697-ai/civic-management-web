<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
// if (!function_exists("zk_Update_dbAttendance_from_machine")) {
// 	if($wo["loggedin"] == true) {
		require_once('assets/includes/zk_functions2.php');
// 	}
// }
if ($f == "zk_command") {
    
    $action = isset($_POST['action']) ? Wo_Secure($_POST['action']) : '';
    Global $zk;

    // Initialize response array
    $data = array(
        'status' => 400,
        'response' => '',
        'action' => $action
    );

    try {
		if ($action == "update_leave_days") {
			$data['status'] = 200;
			
			// Get the first date of last month
			$firstDateLastMonth = new DateTime('first day of last month');
			$start_timestamp = $firstDateLastMonth->getTimestamp();

			// Get the last date of the current month
			$lastDateCurrentMonth = new DateTime('last day of this month');
			$end_timestamp = $lastDateCurrentMonth->getTimestamp();
			
			// Fetch leaves within the specified date range
			$leaves = $db->where('leave_from', $start_timestamp, '>=')->where('leave_to', $end_timestamp, '<=')->get(T_LEAVES);

			if ($leaves) {
				$data['response'] = 'No leaves found to update!';
				foreach ($leaves as $leave) {
					// Calculate the number of valid leave days (excluding holidays)
					$days = calculateDurationInDays($leave->leave_from, $leave->leave_to);
					
					// Check if the calculated days differ from the stored value
					if ($leave->days != $days) {                
						// Update the leave record with the new days count
						$update = $db->where('id', $leave->id)->update(T_LEAVES, ['days' => $days]);
						
						// Optional: Check if the update was successful
						if (!$update) {
							$data['response'] = 'Updated leave for ID: ' . $leave->id;
							return $data; // Exit if there's an update error
						} else {
							$data['response'] = 'Error updating leave for ID: ' . $leave->id;
							return $data; // Exit if there's an update error
						}
					}
				}
			} else {
				$data['response'] = 'No leaves found to update!';
			}
		}
        if ($action == "test_voice") {
            $response = $zk->test_voice();
            $data['status'] = 200;
            $data['response'] = print_r($response, true);
        }
        if ($action == "power_off") {
            $response = $zk->poweroff();
            $data['status'] = 200;
            $data['response'] = print_r($response, true);
        }
        if ($action == "reboot") {
            $response = $zk->restart();
            $data['status'] = 200;
            $data['response'] = print_r($response, true);
        }

        if ($action == "enable") {
            $response = $zk->enable();
            $data['status'] = 200;
            $data['response'] = print_r($response, true);
        }

        if ($action == "disable") {
            $response = $zk->disable();
            $data['status'] = 200;
            $data['response'] = print_r($response, true);
        }
        
        if ($action == "reset") {
        
            // Optional: disable device during reset
            $zk->disable();
        
            // Clear attendance logs
            $clearUsers = $zk->delete_admin();
            $clearLogs = $zk->clearAttendance();
        
            // Clear all users (optional)
            $clearUsers = $zk->clearUsers();
        
            // Re-enable device after reset
            $zk->enable();
            $zk->disconnect();
        
            $data['status'] = 200;
            $data['response'] = 'Logs cleared: ' . ($clearLogs ? 'Yes' : 'No');
        }


        // Open the door lock
        if ($action == "open_door") {
			
            $response = $zk->open_door()->to_array();
			if ($response['Row']['Information'] == 'Successfully!') {
				$data['status'] = 200;
				$response['Row']['Information'] = 'Door unlocked!';
			} else {
				$data['status'] = 400;
				$response['Row']['Information'] = 'Faild to unlock door!';
			}
            $data['response'] = $response['Row']['Information'];
        }
        // Open the door lock
        if ($action == "close_door") {
            $response = $zk->close_door()->to_array(); 
			
			if ($response['Row']['Information'] == 'Successfully!') {
				$data['status'] = 200;
				$response['Row']['Information'] = 'Door Locked!';
			} else {
				$data['status'] = 400;
				$response['Row']['Information'] = 'Faild to lock door!';
			}
			
            $data['response'] = $response['Row']['Information'];
        }

        // Get device statistics
        if ($action == "get_statistics") {
            $response = $zk->get_free_sizes();
            $data['status'] = 200;
            $data['response'] = print_r($response, true);
        }

        // Clear attendance logs
        if ($action == "clear_attendance_logs") {
            $response = $zk->delete_data(['value' => 3]);
            $data['status'] = 200;
            $data['response'] = print_r($response, true);
        }

        // Set date and time
        if ($action == "set_date_time") {
            $date = isset($_POST['date']) ? Wo_Secure($_POST['date']) : '';
            $time = isset($_POST['time']) ? Wo_Secure($_POST['time']) : '';
            $response = $zk->set_date(['date' => $date, 'time' => $time]);
            $data['status'] = 200;
            $data['response'] = print_r($response, true);
        }

        // Delete all users
        if ($action == "delete_all_users") {
            $response = $zk->delete_admin(); // Assuming you want to delete all users with admin privileges
            $data['status'] = 200;
            $data['response'] = print_r($response, true);
        }

        // Add more commands as needed here

    } catch (Exception $e) {
        $data['response'] = 'Error: ' . $e->getMessage();
    }

    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}
