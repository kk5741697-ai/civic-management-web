<?php
if (!defined('ROOT_DIR')) {
    define('ROOT_DIR', __DIR__ . '/');
}

// Create a TADFactory instance with configuration options.
require('assets/libraries/zk/vendor/autoload.php');
use TADPHP\TADFactory;
use TADPHP\TAD;

$zk_options = [
	'ip' => '103.198.137.159',
	'com_key' => 0,        // 14230 by default.
	'udp_port' => 4370      // 4370 by default.
];

if (!isset($zk)) {
	$tad_factory = new TADFactory($zk_options);
	$zk = $tad_factory->get_instance();
}
    
function is_connected($mode = 'status') {
    global $zk;

    $response = $zk->get_device_name()->to_array();
    
    $status = ($response['Row']['Information'] == 'F18/ID') ? true : false;

    return $status;
}


// Function to retrieve all users and exclude the 'Row' key
function zk_get_all_users() {
    global $zk;

    // Retrieve the TADResponse object and convert it to an array
    $response = $zk->get_all_user_info()->to_array();

    // Check if 'Row' key exists and return its value; otherwise return an empty array
    return isset($response['Row']) ? $response['Row'] : [];
}

// Function to get a user by PIN
function zk_get_user_by_pin($pin) {
	Global $zk;
	
	$user_info = $zk->get_user_info(['pin'=>$pin])->to_array();

	if ($user_info) {
		return $user_info;
	}
    return null; // User not found
}

// Function to delete a user by PIN
function zk_delete_user_by_pin($pin) {
    global $zk;

    $user = zk_get_user_by_pin($pin);
    if ($user) {
        // Assuming delete_user function takes PIN as a parameter
        return $zk->delete_user($pin);
    }
    return false; // User not found
}

// Function to edit a user by PIN
function zk_edit_user_by_pin($pin, $newData) {
    global $zk;

    // Fetch the current user to verify that it exists
    $user = zk_get_user_by_pin($pin);
    if ($user) {
        // Prepare data for the update
        // Merge new data with existing user data
        $updateData = [
            'pin' => $pin, // Ensure pin is included in the update data
        ];

        // Add only provided fields to the update data
        if (isset($newData['name'])) {
            $updateData['name'] = $newData['name'];
        }
        if (isset($newData['privilege'])) {
            $updateData['privilege'] = $newData['privilege'];
        }
        if (isset($newData['password'])) {
            $updateData['password'] = $newData['password'];
        }

        // Print data to check if it's being sent correctly
        // Comment this out in production
        echo "Update Data: ";
        print_r($updateData);
        
        // Call the method to update user information
        try {
            $result = $zk->set_user_info($updateData);
            
            // Check if the result is as expected
            echo "Update Result: ";
            print_r($result);
            
            if ($result) {
                return true; // User updated successfully
            } else {
                // Handle case where update fails
                echo 'Update failed: No response or failure message from set_user_info';
                return false;
            }
        } catch (Exception $e) {
            // Catch and display any errors
            echo 'Exception: ' . $e->getMessage();
            return false;
        }
    }
    return false; // User not found
}

function zk_update_user_template($data, $finger_id = 0) {
    global $zk;

    // Call the method to update the user template
    try {
        $result = $zk->set_user_template($data);
        
        // Check if the result is as expected
        echo "Update Result: ";
        print_r($result);
        
        if ($result) {
            return true; // Template updated successfully
        } else {
            // Handle case where update fails
            echo 'Update failed: No response or failure message from set_user_template';
            return false;
        }
    } catch (Exception $e) {
        // Catch and display any errors
        echo 'Exception: ' . $e->getMessage();
        return false;
    }
}
// Function to create a new user
function zk_create_user($userData) {
    global $zk;

    // Assuming userData is an associative array with necessary fields
    // and that PIN is auto-generated or handled differently
    return $zk->set_user_info($userData);
}

function zk_get_attendance_by_date_range($startDate = null, $endDate = null, $pin = null) {
    global $zk;

    if ($startDate === null) {
        $startDate = date('Y-m-d');
    }

    if ($endDate === null) {
        $endDate = date('Y-m-d');
    }

    $start = new DateTime($startDate);
    $end = new DateTime($endDate);
    $end->modify('+1 day'); // Include end date in the range

    $attendanceRecords = [];

    for ($date = $start; $date < $end; $date->modify('+1 day')) {
        $formattedDate = $date->format('Y-m-d');
        
        $params = ['date' => $formattedDate];
        if ($pin !== null) {
            $params['pin'] = $pin;
        }
        
        $response = $zk->get_att_log($params)->to_array();
        
        if (isset($response['Row']) && is_array($response['Row'])) {
            $attendanceRecords = array_merge($attendanceRecords, $response['Row']);
        }
    }
    
    return $attendanceRecords;
}

// Function to delete attendance records by date range
function zk_delete_attendance_by_date_range($startDate, $endDate) {
    global $zk;

    // Call a method to delete attendance records between the given dates
    return $zk->delete_data($startDate, $endDate);
}
function zk_restart() {
    global $zk;
	$response = $zk->restart();
    return $response;
}

function zk_poweroff() {
    global $zk;
    return $zk->poweroff();
}

function zk_Update_dbAttendance_from_machine($startDate = null, $endDate = null) {
    global $zk, $db, $config;

    $can_del = false; // Initialize variable

    try {
        // Set default start and end dates if not provided
        if ($startDate === null) {
            $startDate = date('Y-m-d', strtotime('yesterday'));
        }

        if ($endDate === null) {
            $endDate = date('Y-m-d');
        }

        $time = time();

        if ($config['last_atten_update'] <= time() - 60) {
            $response = zk_get_attendance_by_date_range($startDate, $endDate);
            foreach ($response as $log) {
				if (is_array($log) && isset($log['Status']) && isset($log['DateTime'])) {
					$status = ($log['Status'] == 1) ? 'o' : 'i';
					$formattedDate = date('Y-m-d', strtotime($log['DateTime']));
					$date_time_start = $formattedDate . " 00:00:00";
					$date_time_end = $formattedDate . " 23:59:59";

					// Prepare data for update or insert
					$data = [
						'USERID'     => $log['PIN'],
						'CHECKTIME'  => $log['DateTime'],
						'CHECKTYPE'  => $status,
						'active'     => '1',
						'entry_time' => date('Y-m-d H:i:s'),
					];

					// Check for existing record based on type
					if ($status == 'o') {
						$existingRecord = $db->where('USERID', $log['PIN'])
											 ->where('CHECKTYPE', 'o')
											 ->where('CHECKTIME', $date_time_start, '>=')
											 ->where('CHECKTIME', $date_time_end, '<=')
											 ->orderBy('CHECKTIME', 'DESC')
											 ->getOne('atten_in_out');
						if ($existingRecord) {
							$db->where('id', $existingRecord->id)->update('atten_in_out', $data);
						} else {
							$db->insert('atten_in_out', $data);
						}
					} elseif ($status == 'i') {
						$existingRecord = $db->where('USERID', $log['PIN'])
											 ->where('CHECKTYPE', 'i')
											 ->where('CHECKTIME', $date_time_start, '>=')
											 ->where('CHECKTIME', $date_time_end, '<=')
											 ->orderBy('CHECKTIME', 'ASC')
											 ->getOne('atten_in_out');

						if ($existingRecord) {
							// Uncomment if updates for 'i' status are needed
							// $db->where('id', $existingRecord->id)->update('atten_in_out', $data);
						} else {
							$db->insert('atten_in_out', $data);
						}
					}

					$can_del = true;
				} else {
					$can_del = false;
					// Handle the unexpected format, e.g., log an error
					error_log("Unexpected log format: " . print_r($log, true));
				}
            }
            Wo_SaveConfig('last_atten_update', $time);
        }

        // Uncomment to delete all attendance data from the machine every 1 hour
        if ($can_del && ($config['last_atten_update'] <= time() - 3900)) {
            $zk->delete_data(['value' => 3]);
        }

    } catch (Exception $e) {
        // Log the exception or handle the error
        error_log("Error in zk_Update_dbAttendance_from_machine: " . $e->getMessage());
    }
}
?>