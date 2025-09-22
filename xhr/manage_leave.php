<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('Asia/Dhaka');
if ($f == 'manage_leave') {
	if ($s == 'fetch_report') {
	    $myUid = $wo['user']['user_id'];
		if (Wo_IsAdmin() || Wo_IsModerator() || check_permission('update-leave') || check_permission('leave-application') || $wo['user']['is_team_leader'] == true) {
			$get_uid = isset($_POST['user_id']) ? Wo_Secure($_POST['user_id']) : '';
		} else {
			$get_uid = $wo['user']['user_id'];
		}
		
		// Retrieve and sanitize date inputs from POST or set default values
		$date_start = isset($_POST['data_start']) ? Wo_Secure($_POST['data_start']) : date('Y-m-01');
		$date_end = isset($_POST['data_end']) ? Wo_Secure($_POST['data_end']) : '';
		
		setcookie("default_u_leave", $get_uid, time() + (10 * 365 * 24 * 60 * 60), '/');
		setcookie("start_end2", $date_start . ' to ' . $date_end, time() + (10 * 365 * 24 * 60 * 60), '/');
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

        // Filter by date range
        if (!empty($start_timestamp) && !empty($end_timestamp)) {
            $db2->where('leave_from', $start_timestamp, '>=')
                ->where('leave_to', $end_timestamp, '<=');
        }
        
        // Filter by user
        if (!empty($get_uid) && is_numeric($get_uid) && $get_uid != 999) {
            $db2->where('user_id', $get_uid);
        } else {
            if (!empty($wo['user']['is_team_leader']) && $wo['user']['is_team_leader'] == true) {
                // Get all users under this leader (including self)
                $get_users = $db->where('active', '1')
                                ->where('(leader_id = ? OR user_id = ?)', [$wo['user']['user_id'], $wo['user']['user_id']])
                                ->orderBy('serial','ASC')
                                ->get(T_USERS, null, 'user_id');
        
                // Extract only user_id values into array
                $user_ids = array_column($get_users, 'user_id');
        
                if (!empty($user_ids)) {
                    $db2->where('user_id', $user_ids, 'IN');
                } else {
                    $db2->where('user_id', 0); // fallback to no results
                }
            }
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
		
        // 1) Determine year range text
        $start_year = (int) date('Y', strtotime($date_start));
        $end_year   = (int) date('Y', strtotime($date_end));
        $range_text = '';
        
        // 2) Define a base wrapper template with placeholders
        $baseTemplate = '
        <div class="d-flex align-items-start gap-2">
            <div>
                <p class="mb-0 fs-6">{label} {range}</p>
            </div>
            <div class="ms-auto">
                <ion-icon name="bar-chart"></ion-icon>
            </div>
        </div>
        <div class="d-flex align-items-center mt-3">
            <div style="width:100%; flex-direction: column; display:flex; gap:10px;">
                {bars}
            </div>
        </div>
        ';
                
        /**
         * Build the per‐year bar HTML, specially formatting “Casual” as "X of Y"
         */
        function buildYearBars($uid, $type, $start_year, $end_year) {
            $bars = '';
            for ($year = $start_year; $year <= $end_year; $year++) {
                // how many days remaining (may be negative = overused)
                $remaining = remaining_leave($uid, $type, $year);
        
                // if it's "casual", also fetch the total allowance for that year
                if ($type === 'casual') {
                    // assumes calculateTotalLeaves can accept a year parameter
                    // if not, drop the $year argument
                    $totalForYear = calculateTotalLeaves($uid, 'casual', $year);
        
                    if ($remaining < 0) {
                        $displayValue = 'Overused: ' . abs($remaining) . " of {$totalForYear}";
                        $itemClass    = ' overused';
                    } else {
                        $displayValue = "{$remaining} of {$totalForYear}";
                        $itemClass    = '';
                    }
                }
                else {
                    // non-casual: just show days (or Overused)
                    if ($remaining < 0) {
                        $displayValue = 'Overused: ' . abs($remaining);
                        $itemClass    = ' overused';
                    } else {
                        $displayValue = $remaining;
                        $itemClass    = '';
                    }
                }
        
                $bars .= "
                <span class=\"year_item{$itemClass}\" style=\"display:flex;align-items:flex-end;gap:5px;justify-content:space-between;\">
                    <h4 class=\"mb-0\">
                      {$displayValue} 
                      <span style=\"margin-bottom:2px;color:gray;font-size:15px;\">Days</span>
                    </h4>
                    <small style=\"margin-left:8px;color:#666;\">{$year}</small>
                </span>";
            }
            return $bars;
        }


        
        // 4) Build each template
        $template_casual  = str_replace(
            ['{label}','{range}','{bars}'],
            ['Casual', $range_text, buildYearBars($get_uid, 'casual',  $start_year, $end_year)],
            $baseTemplate
        );
        
        $template_sick    = str_replace(
            ['{label}','{range}','{bars}'],
            ['Sick',   $range_text, buildYearBars($get_uid, 'sick',    $start_year, $end_year)],
            $baseTemplate
        );
        
        $template_earned  = str_replace(
            ['{label}','{range}','{bars}'],
            ['Earned', $range_text, buildYearBars($get_uid, 'earned',  $start_year, $end_year)],
            $baseTemplate
        );
        
        $template_maternity = str_replace(
            ['{label}','{range}','{bars}'],
            ['Maternity', $range_text, buildYearBars($get_uid, 'maternity', $start_year, $end_year)],
            $baseTemplate
        );
        
        // 5) Now simply assign:
        $remaining_days        = $template_casual;
        $sick_remaining_days   = $template_sick;
        $earned_remaining_days = $template_earned;
        $maternity_remaining_days = $template_maternity;
        
        // echo "Total casual leave remaining between {$start_year} and {$end_year}: "
		
// 		$remaining_days = str_replace('-', 'Overused: -', $leave_counts['casual']) . '<small> of ' . $leave_counts['total_casual'] . '</small>';

// 		$remaining_days = $template_casual;
// 		$sick_remaining_days = str_replace('-', 'Overused: -', remaining_leave($get_uid, 'sick'));
// 		$maternity_remaining_days = str_replace('-', 'Overused: -', remaining_leave($get_uid, 'maternity'));
// 		$earned_remaining_days = str_replace('-', 'Overused: -', remaining_leave($get_uid, 'earned'));
			
		$countDb = clone $db2;
		$count = $countDb->where('leave_from', $start_timestamp, '>=')->where('leave_to', $end_timestamp, '<=')->getValue(T_LEAVES, 'SUM(days)') ?? 0;
        $totalApproved = $countDb->where('leave_from', $start_timestamp, '>=')->where('leave_to', $end_timestamp, '<=')->where('is_approved', 1)->where('user_id', $get_uid)->getValue(T_LEAVES, 'SUM(days)') ?? 0;
        $totalPending = $countDb->where('leave_from', $start_timestamp, '>=')->where('leave_to', $end_timestamp, '<=')->where('is_approved', 0)->where('user_id', $get_uid)->getValue(T_LEAVES, 'SUM(days)') ?? 0;
        $totalRejected = $countDb->where('leave_from', $start_timestamp, '>=')->where('leave_to', $end_timestamp, '<=')->where('is_approved', 2)->where('user_id', $get_uid)->getValue(T_LEAVES, 'SUM(days)') ?? 0;


		$db2->pageLimit = $_POST['length'];
		$link = '';

		$leaves = $db2->objectbuilder()->paginate(T_LEAVES, $page_num);
		
		// Prepare data for DataTables
		$outputData = array();
		
		foreach ($leaves as $leave) {
			$user_data = Wo_UserData($leave->user_id);

			if (check_permission('update-leave') == true || Wo_IsAdmin() == true || Wo_IsModerator() == true) {
				if (Wo_IsAdmin() == true || Wo_IsModerator() == true) {
					$actions = '<div class="d-flex align-items-center gap-3 fs-6 justify-content-center">
									<a href="javascript:;" class="text-primary" data-bs-toggle="tooltip" data-bs-placement="bottom"
										title="" data-bs-original-title="View detail" aria-label="Views" onclick="update_leave(' . $leave->id . ')">
										<i class="fadeIn animated bx bx-edit-alt"></i>
									</a><a href="javascript:;" class="text-primary" data-bs-toggle="tooltip" data-bs-placement="bottom"
										title="" data-bs-original-title="View detail" aria-label="Views" onclick="download_leave(' . $leave->id . ')">
										<i class="lni lni-download"></i>
									</a><a href="javascript:;" class="text-danger" data-bs-toggle="tooltip" data-bs-placement="bottom"
										title="" data-bs-original-title="View detail" aria-label="Views" onclick="delete_leave(' . $leave->id . ')">
										<i class="fadeIn animated bx bx bx-trash-alt"></i>
									</a>
								</div>';
				} else {
					$actions = '<div class="d-flex align-items-center gap-3 fs-6 justify-content-center">
									<a href="javascript:;" class="text-primary" data-bs-toggle="tooltip" data-bs-placement="bottom"
										title="" data-bs-original-title="View detail" aria-label="Views" onclick="update_leave(' . $leave->id . ')">
										<i class="fadeIn animated bx bx-edit-alt"></i>
									</a><a href="javascript:;" class="text-primary" data-bs-toggle="tooltip" data-bs-placement="bottom"
										title="" data-bs-original-title="View detail" aria-label="Views" onclick="download_leave(' . $leave->id . ')">
										<i class="lni lni-download"></i>
									</a>
								</div>';
				}
			} else {
				$actions = '<div class="d-flex align-items-center gap-3 fs-6 justify-content-center">
								<a href="javascript:;" class="text-primary" data-bs-toggle="tooltip" data-bs-placement="bottom"
									title="" data-bs-original-title="View detail" aria-label="Views" onclick="download_leave(' . $leave->id . ')">
									<i class="lni lni-download"></i>
								</a>
							</div>';
			}
			
			if ($leave->is_approved == 1) {
				if ($leave->is_paid == 1) {
					$paid_unpaid = 'Paid';
				} else {
					$paid_unpaid = 'Unpaid';
				}
			} else {
				$paid_unpaid = 'N/A';
			}
			
			$status = '';
			if ($leave->is_approved == 0) {
				$status = '<span class="badge bg-info">Pending</span>';
			} else if ($leave->is_approved == 1) {
				$status = '<span class="badge bg-success">Approved</span>';
			} else if ($leave->is_approved == 2) {
				$status = '<span class="badge bg-danger">Rejected</span>';
			}
			
			$outputData[] = array(
				'id' => $leave->id,
				'name' => '<img src="' . $wo['site_url'] . '/' . $user_data['avatar_24'] . '" class="user-img" style=" width: 24px; height: 24px; border-radius: 35px; margin-right: 8px; ">' . $user_data['name'],
				'type' => $leave->type,
				'reason' => $leave->reason,
				'posted' => date('d M Y', $leave->posted),
				'leave_from' =>  date('d M Y', $leave->leave_from),
				'leave_to' =>  date('d M Y', $leave->leave_to),
				'days' => $leave->days,
				'paid_unpaid' => $paid_unpaid,
				'status' => $status,
				'actions' => $actions
			);
		}
		// Send JSON response
		$data = array(
			"draw" => intval($_POST['draw']),
			"recordsTotal" => $count ?? 0,
			"totalLeave" => $count ?? 0,
			"totalApproved" => $totalApproved ?? '0',
			"totalPending" => $totalPending ?? '0',
			"totalRejected" => $totalRejected ?? '0',
			"remaining_days" => $remaining_days,
			"sick_remaining_days" => $sick_remaining_days,
			"maternity_remaining_days" => $maternity_remaining_days,
			"earned_remaining_days" => $earned_remaining_days,
			"recordsFiltered" => $db->totalPages * $_POST['length'],
			"data" => $outputData
		);
	}
	
	if ($s == 'apply_leave_Modal') {
        if (empty($_POST['user_id'])) {
            $error = 'Please select user for apply leave!';
        }
        if (empty($error)) {
			$user_id = $_POST['user_id'];
			$data = array(
				'status'  => 200,
				'result' => Wo_LoadManagePage('leave_report/apply_leave')
			);
		} else {
            $data = array(
                'status' => 500,
                'message' => $error
            );
        }
	}
	if ($s == 'update_leave_Modal') {
		$leave_id = isset($_POST['leave_id']) ? Wo_Secure($_POST['leave_id']) : '';
		$data = array(
			'status'  => 200,
			'result' => Wo_LoadManagePage('leave_report/update_leave')
		);
	}
	
	if ($s == 'apply_leave') {
        if (empty($_POST['user_id'])) {
            $error = 'Something went wrong!';
        } else if (empty($_POST['leave_start_end'])) {
            $error = 'Please select leave date';
        } else if (empty($_POST['leave_type'])) {
            $error = 'Please select type of leave';
        } else if (empty($_POST['leave_reason'])) {
            $error = 'Please describe leave reason';
        }
        if (empty($error)) {
            $range   = !empty($_POST['leave_start_end']) ? Wo_Secure($_POST['leave_start_end']) : '';
			
			$parts = explode(' to ', $range);

			if (count($parts) === 1) {
				$date_start = $date_end = trim($parts[0]);
			} elseif (count($parts) === 2) {
				$date_start = trim($parts[0]);
				$date_end = trim($parts[1]);
			}
			
            $leave_type = !empty($_POST['leave_type']) ? Wo_Secure($_POST['leave_type']) : '';
            $leave_reason = !empty($_POST['leave_reason']) ? Wo_Secure($_POST['leave_reason']) : '';
			
			// Adjust date format and set timestamps
			if (empty($date_end)) {
				$date_end = $date_start . ' 23:59:59';
				$date_start = $date_start . ' 00:00:00';
			} else {
				// If both dates are provided, set timestamps for the entire days
				$date_start = $date_start . ' 00:00:00';
				$date_end = $date_end . ' 23:59:59';
			}
			
			$start_timestamp = strtotime($date_start);
			$end_timestamp = strtotime($date_end);
			$days = calculateDurationInDays($start_timestamp, $end_timestamp);
			
			$insert = $db->insert(T_LEAVES, array(
					'user_id' => $_POST['user_id'],
					'type' => $leave_type,
					'days' => $days,
					'reason' => stripslashes($leave_reason),
					'leave_from' => $start_timestamp,
					'leave_to' => $end_timestamp,
					'is_approved' => 0,
					'posted' => time()
				));
			if ($insert) {
				$user = Wo_UserData($_POST['user_id']);
				$name = $user['name'];
				
				$notif_data = array(
					'subject' => 'Leave Application',
					'comment' => cleanName($name) . ' want ' . $days . ' days leave.',
					'type' => 'leave_report',
					'url' => '/management/leave_report?user=' . $_POST['user_id'],
					'user_id' => $_POST['user_id']
				);

				$response = RegisterNotification($notif_data);
			}
        } else {
            $data = array(
                'status' => 500,
                'message' => $error
            );
        }
	}
	if ($s == 'delete_leave') {
        if (check_permission('update-leave') == false && Wo_IsAdmin() == false && Wo_IsModerator() == false) {
            $error = 'You don`t have permission';
        }
        if (empty($error)) {
            $leave_id   = !empty($_POST['leave_id']) ? Wo_Secure($_POST['leave_id']) : '';
			$delete = $db->where('id', $leave_id)->delete(T_LEAVES);
            $data = array(
                'status' => 200,
                'message' => 'Leave are deleted!'
            );
        } else {
            $data = array(
                'status' => 500,
                'message' => $error
            );
        }
	}
	
	if ($s == 'update_leave') {
        if (empty($_POST['is_approved'])) {
            $error = 'Please select leave is approved or not';
        } else if (empty($_POST['leave_type'])) {
            $error = 'Please select type of leave';
        } else if (check_permission('update-leave') == false && Wo_IsAdmin() == false && Wo_IsModerator() == false) {
            $error = 'You don`t have permission';
        }
        if (empty($error)) {
            $leave_id   = !empty($_POST['leave_id']) ? Wo_Secure($_POST['leave_id']) : '';
            $leave_type   = !empty($_POST['leave_type']) ? Wo_Secure($_POST['leave_type']) : '';
            $is_approved   = !empty($_POST['is_approved']) ? $_POST['is_approved'] : 0;
            $is_paid   = !empty($_POST['is_paid']) ? $_POST['is_paid'] : 0;
			if ($is_approved == 9) {
				$is_approved = 0;
			}
			
			$update = $db->where('id', $leave_id)->update(T_LEAVES, array(
				'type' => $leave_type,
				'is_approved' => $is_approved,
				'is_paid' => $is_paid
			));
        } else {
            $data = array(
                'status' => 500,
                'message' => $error
            );
        }
	}
	if ($s == 'download_leave') {
		$leave_id   = !empty($_POST['leave_id']) ? Wo_Secure($_POST['leave_id']) : '';
        if (!empty($leave_id)) {
			$hash_id = Wo_CreateMainSession();
			$data = array(
				'status' => 200,
				'result' => $site_url . '/download.php?dl_type=leave&id=' . $leave_id . '&token=' . $hash_id
			);
        } else {
            $data = array(
                'status' => 500,
                'message' => 'Leave not found!'
            );
        }
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