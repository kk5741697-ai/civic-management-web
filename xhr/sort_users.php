<?php
if ($f == "sort_users") {
    $lock_update = update_lockout_time();
    date_default_timezone_set('Asia/Dhaka');
    
    $errors = array();
    $result = array();  // Initialize as an array for consistency

    if (Wo_IsAdmin() || Wo_IsModerator()) {
        if ($s == "global") {
            // Decode JSON data from POST body
            $input = file_get_contents('php://input');
            $orders = json_decode($input, true); // true converts it into an associative array

            if (json_last_error() === JSON_ERROR_NONE) {
                // Check if orders is an array
				if (is_array($orders) && isset($orders['order'])) {
					$orders = $orders['order'];
					print_r($orders);
					foreach ($orders as $order) {
						// Process each order here
						$user_id = $order['user_id'];
						$type = $order['type'];
						
						// Set $start_from based on the type
						if ($type == 'management') {
							$start_from = 100;
						} elseif ($type == 'employee') { // Changed from 'management' to 'other_type' for example
							$start_from = 200;
						} else {
							$start_from = 999;
						}
						
						// Ensure $start_from is an integer
						$position = $start_from + (int) $order['position'];
						
						$db->where('user_id', $user_id)->update(T_USERS, array('serial' => $position));
							if ($db->where('user_id', $user_id)->update(T_USERS, array('serial' => $position))) {
								// Update successful
							} else {
								// Handle update failure
							}
					$response = array('status' => 200, 'message' => 'Orders processed successfully');

					}
				} else {
                    $response = array('status' => 400, 'message' => 'Invalid order data format');
                }
            } else {
                $response = array('status' => 400, 'message' => 'Invalid JSON data');
            }
        }
		if ($s == "for_leads") {
			// Decode JSON data from POST body
			$input = file_get_contents('php://input');
			$orders = json_decode($input, true); // true converts it into an associative array

			if (json_last_error() === JSON_ERROR_NONE) {
				// Check if orders is an array
				if (is_array($orders) && isset($orders['order'])) {
					$orders = $orders['order'];

					// Step 1: Organize orders into team leaders and their respective members
					$team_leaders = []; // Store team leaders by their user_id
					$members_by_leader = []; // Store team members grouped by their leader's user_id
					$current_leader = null; // To keep track of the most recent team leader

					// Loop through the orders to segregate team leaders and members
					foreach ($orders as $order) {
						if ($order['team_leader'] === true) {
							// If it's a team leader, store them
							$team_leaders[$order['user_id']] = $order;
							// Initialize an empty array for each leader's members
							$members_by_leader[$order['user_id']] = [];
							// Update the current_leader to the latest team leader
							$current_leader = $order['user_id'];
							
							// Update the team leaders position in T_USERS
							$db->where('user_id', $order['user_id'])->where('is_team_leader', '1')->update(T_USERS, array('position' => $order['position']));
						} else {
							// If it's a team member, we need to assign a leader
							$leader_id = $order['leader_id'] ?? $current_leader; // Use the last team leader if no leader_id is given

							// Add member to the corresponding leader's array
							if ($leader_id && isset($members_by_leader[$leader_id])) {
								$members_by_leader[$leader_id][] = $order;
							}
						}
					}

					// Step 2: Update the leader_id for each member based on their assigned leader
					foreach ($members_by_leader as $leader_id => $members) {
						
						foreach ($members as $member) {
							// Prepare the data to update in the database
							$update_data = array(
								'leader_id' => $leader_id, // Set the leader_id for the member
								'position' => $member['position'] // Keep the position as is
							);

							// Update the database with the new leader_id and position in T_USERS
							$db->where('user_id', $member['user_id'])->update(T_USERS, $update_data);

							// Also, update the assigned leader_id in T_LEADS table
							$db->where('member', $member['user_id'])->update(T_LEADS, array('assigned' => $leader_id));
						}
					}

					// Step 3: Return the response after processing
					$response = array('status' => 200, 'message' => 'Orders processed and leader_ids updated successfully', 'orders' => $orders, 'members_by_leader' => $members_by_leader);
				} else {
					$response = array('status' => 400, 'message' => 'Invalid order data format');
				}
			} else {
				$response = array('status' => 400, 'message' => 'Invalid JSON data');
			}
		}
    } else {
        $response = array(
            'status' => 400,
            'message' => 'Sorry! You don`t have permission to do this!'
        );
    }

    header("Content-type: application/json");
    echo json_encode($response);
    exit();
}
