<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('Asia/Dhaka');
if ($f == 'manage_rent') {
	
	if ($s == 'download_report') {
		// Retrieve and sanitize date inputs from POST or set default values
		$date_start = isset($_POST['data_start']) ? Wo_Secure($_POST['data_start']) : date('Y-m-01');
		$date_end = isset($_POST['data_end']) ? Wo_Secure($_POST['data_end']) : '';
		$user_id = isset($_POST['user_id']) ? Wo_Secure($_POST['user_id']) : '';
		
		
        if (!empty($date_start) && !empty($date_end)) {
			$hash_id = Wo_CreateMainSession();
			$data = array(
				'status' => 200,
				'result' => $site_url . '/download.php?dl_type=rent_report&user_id=' . $user_id . '&date_start=' . $date_start . '&date_end=' . $date_end . '&token=' . $hash_id
			);
        } else {
            $data = array(
                'status' => 500,
                'message' => 'Please select a date!'
            );
        }
	}
	if ($s == 'fetch_report') {
		if (Wo_IsAdmin() || Wo_IsModerator() || check_permission('check-rent-report') || check_permission('register-rent-report') || check_permission('update-rent')) {
			$get_uid = isset($_POST['user_id']) ? Wo_Secure($_POST['user_id']) : '';
		} else {
			$get_uid = $wo['user']['user_id'];
		}
		$remaining_days = str_replace('-', 'Overused: -', remaining_leave($get_uid)) . '<small> of ' . calculateTotalLeaves($get_uid) . '</small>';
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
		
		if (!empty($start_timestamp) && !empty($end_timestamp)) {
			$db->where('visit_date', $start_timestamp, '>=')->where('visit_date', $end_timestamp, '<=');
		}
		if (!empty($get_uid) && is_numeric($get_uid) && $get_uid != 999) {
			$db->where('user_id', $get_uid);
		}
		
		if (!empty($searchValue) && is_numeric($searchValue)) {
			$db->where('id', ltrim($searchValue, '#'));
		}
		
		// foreach ($searchConditions as $condition) {
			// $db->where($condition[0], $condition[1], $condition[2]);
		// }

		// Order by the column specified by the user
		$orderColumn = isset($_POST['order'][0]['column']) ? $_POST['order'][0]['column'] : null;
		$orderDirection = isset($_POST['order'][0]['dir']) ? $_POST['order'][0]['dir'] : null;

		if ($orderColumn !== null) {
			if ($orderColumn == 0) {
				$db->orderBy('id', $orderDirection == 'asc' ? 'ASC' : 'DESC');
			} else {
				$db->orderBy('id', 'DESC');
			}
		} else {
			$db->orderBy('id', 'DESC');
		}
		
		if ($orderColumn !== null) {
			if ($orderColumn == 0) {
				$db->orderBy('id', $orderDirection == 'asc' ? 'ASC' : 'DESC');
			} else {
				$db->orderBy('id', 'DESC');
			}
		} else {
			$db->orderBy('id', 'DESC');
		}
		
			
		$countDb = clone $db;
		function getPaymentSum($countDb, $start_timestamp, $end_timestamp, $get_uid, $status = -1) {
			$query = $countDb->where('visit_date', $start_timestamp, '>=')
							 ->where('visit_date', $end_timestamp, '<=');
			
			if ($status >= 0) {
				$query->where('status', $status);
			}
			if ($get_uid != 0) {
				$query->where('user_id', $get_uid);
			}

			return $query->getValue(T_RENT_REPORT, 'SUM(payment)') ?? 0;
		}


		if (!empty($get_uid) && is_numeric($get_uid) && $get_uid != 999) {
			$count = $countDb->where('visit_date', $start_timestamp, '>=')->where('visit_date', $end_timestamp, '<=')->getValue(T_RENT_REPORT, 'COUNT(*)');
			$totalApproved = $countDb->where('visit_date', $start_timestamp, '>=')->where('visit_date', $end_timestamp, '<=')->where('status', 1)->where('user_id', $get_uid)->getValue(T_RENT_REPORT, 'COUNT(*)');
			$totalPending = $countDb->where('visit_date', $start_timestamp, '>=')->where('visit_date', $end_timestamp, '<=')->where('status', 0)->where('user_id', $get_uid)->getValue(T_RENT_REPORT, 'COUNT(*)');
			$totalRejected = $countDb->where('visit_date', $start_timestamp, '>=')->where('visit_date', $end_timestamp, '<=')->where('status', 2)->where('user_id', $get_uid)->getValue(T_RENT_REPORT, 'COUNT(*)');
			
			$total_Payment = getPaymentSum($countDb, $start_timestamp, $end_timestamp, $get_uid);
			$totalApproved_Payment = getPaymentSum($countDb, $start_timestamp, $end_timestamp, $get_uid, 1);
			$totalPending_Payment = getPaymentSum($countDb, $start_timestamp, $end_timestamp, $get_uid, 0);
			$totalRejected_Payment = getPaymentSum($countDb, $start_timestamp, $end_timestamp, $get_uid, 2);
			
		} else {
			$count = $countDb->where('visit_date', $start_timestamp, '>=')->where('visit_date', $end_timestamp, '<=')->getValue(T_RENT_REPORT, 'COUNT(*)');
			$totalApproved = $countDb->where('visit_date', $start_timestamp, '>=')->where('visit_date', $end_timestamp, '<=')->where('status', 1)->getValue(T_RENT_REPORT, 'COUNT(*)');
			$totalPending = $countDb->where('visit_date', $start_timestamp, '>=')->where('visit_date', $end_timestamp, '<=')->where('status', 0)->getValue(T_RENT_REPORT, 'COUNT(*)');
			$totalRejected = $countDb->where('visit_date', $start_timestamp, '>=')->where('visit_date', $end_timestamp, '<=')->where('status', 2)->getValue(T_RENT_REPORT, 'COUNT(*)');
			
			$total_Payment = getPaymentSum($countDb, $start_timestamp, $end_timestamp, 0);
			$totalApproved_Payment = getPaymentSum($countDb, $start_timestamp, $end_timestamp, 0, 1);
			$totalPending_Payment = getPaymentSum($countDb, $start_timestamp, $end_timestamp, 0, 0);
			$totalRejected_Payment = getPaymentSum($countDb, $start_timestamp, $end_timestamp, 0, 2);
		}
		$db->pageLimit = $_POST['length'];
		$link = '';

		$rents = $db->objectbuilder()->paginate(T_RENT_REPORT, $page_num);
		
		// Prepare data for DataTables
		$outputData = array();
		
		foreach ($rents as $rent) {
			$user_data = Wo_UserData($rent->user_id);

			if (check_permission('update-rent') == true || check_permission('register-rent-report') == true || Wo_IsAdmin() == true || Wo_IsModerator() == true) {
				if (check_permission('update-rent') == true || Wo_IsAdmin() == true || Wo_IsModerator() == true) {
					$actions = '<div class="d-flex align-items-center gap-3 fs-6 justify-content-center">
									<a href="javascript:;" class="text-primary" data-bs-toggle="tooltip" data-bs-placement="bottom"
										title="" data-bs-original-title="View detail" aria-label="Views" onclick="update_rent(' . $rent->id . ')">
										<i class="fadeIn animated bx bx-edit-alt"></i>
									</a><a href="javascript:;" class="text-danger" data-bs-toggle="tooltip" data-bs-placement="bottom"
										title="" data-bs-original-title="View detail" aria-label="Views" onclick="delete_rent(' . $rent->id . ')">
										<i class="fadeIn animated bx bx bx-trash-alt"></i>
									</a>
								</div>';
				} else if (check_permission('register-rent-report') == true) {
					$actions = '<div class="d-flex align-items-center gap-3 fs-6 justify-content-center">
									<a href="javascript:;" class="text-primary" data-bs-toggle="tooltip" data-bs-placement="bottom"
										title="" data-bs-original-title="View detail" aria-label="Views" onclick="update_rent(' . $rent->id . ')">
										<i class="fadeIn animated bx bx-edit-alt"></i>
									</a>
								</div>';
				} else {
					$actions = '';					
				}
			} else {
				$actions = '';
			}
			
			if ($rent->payment > 0) {
				$payment = '৳' . number_format($rent->payment);
			} else {
				$payment = 'N/A';
			}
			
			$status = '';
			if ($rent->status == 0) {
				$status = '<span class="badge bg-info">Due</span>';
			} else if ($rent->status == 1) {
				$status = '<span class="badge bg-success">Paid</span>';
			} else if ($rent->status == 2) {
				$status = '<span class="badge bg-danger">Rejected</span>';
			}
			
			$outputData[] = array(
				'id' => $rent->id,
				'name' => '<img src="' . $wo['site_url'] . '/' . $user_data['avatar_24'] . '" class="user-img" style=" width: 24px; height: 24px; border-radius: 35px; margin-right: 8px; ">' . $user_data['name'],
				'visit_date' => date('d M Y', $rent->visit_date),
				'vehcal_type' => $rent->vehcal_type,
				'vendor' => $rent->vendor,
				'destination' => $rent->destination,
				'person' => $rent->person,
				'bill_date' => date('d M Y', $rent->bill_date),
				'payment' => $payment,
				'status' => $status,
				'actions' => $actions
			);
		}
		
		// Send JSON response
		$data = array(
			"draw" => intval($_POST['draw']),
			"recordsTotal" => $count,
			"totalLeave" => '<span>' . $count . '</span><span>৳' . number_format($total_Payment) . '</span>',
			"totalApproved" => '<span>' . $totalApproved . '</span><span>৳' . number_format($totalApproved_Payment) . '</span>',
			"totalPending" => '<span>' . $totalPending . '</span><span>৳' . number_format($totalPending_Payment) . '</span>',
			"totalRejected" => '<span>' . $totalRejected . '</span><span>৳' . number_format($totalRejected_Payment) . '</span>',
			"recordsFiltered" => $db->totalPages * $_POST['length'],
			"data" => $outputData
		);
	}
	
	
	
	
	if ($s == 'register_rent') {
        if (empty($_POST['user_id'])) {
            $error = 'Something went wrong!';
        } else if (empty($_POST['visit_date'])) {
            $error = 'Please select visit date';
        } else if (empty($_POST['vehcal_type'])) {
            $error = 'Please describe visit type';
        } else if (empty($_POST['vendor_select'])) {
            $error = 'Please describe visit type';
        } else if (empty($_POST['vendor']) && $_POST['vendor_select'] == 'Others') {
            $error = 'Please describe vendor name';
        } else if (empty($_POST['destination'])) {
            $error = 'Please describe destination!';
        }
		
        if (empty($error)) {
			$visit_date = isset($_POST['visit_date']) ? Wo_Secure($_POST['visit_date']) : '';
			$up_time = (isset($_POST['up_time']) && !empty($_POST['up_time'])) ? Wo_Secure($_POST['up_time']) : Null;
			$down_time = (isset($_POST['down_time']) && !empty($_POST['down_time'])) ? Wo_Secure($_POST['down_time']) : Null;
			$vehcal_type = isset($_POST['vehcal_type']) ? Wo_Secure($_POST['vehcal_type']) : '';
			$vendor_select = isset($_POST['vendor_select']) ? Wo_Secure($_POST['vendor_select']) : '';
			$vendor = isset($_POST['vendor']) ? Wo_Secure($_POST['vendor']) : '';
			$destination = isset($_POST['destination']) ? Wo_Secure($_POST['destination']) : '';
			$person = isset($_POST['person']) ? Wo_Secure($_POST['person']) : 0;
			$user_id = isset($_POST['user_id']) ? Wo_Secure($_POST['user_id']) : '';
			$payment = isset($_POST['payment']) ? Wo_Secure($_POST['payment']) : 0;
			
			if (empty($vendor) && $vendor_select == 'Office') {
				$vendor = 'Office';
			}
			
			$is_exist = $db->where('user_id', $user_id)->where('visit_date', $visit_date)->where('person', $person)->getOne(T_RENT_REPORT);
			$user_data = Wo_UserData($user_id);
			
			if (empty($is_exist)) {
				$insert = $db->insert(T_RENT_REPORT, array(
						'user_id' => $user_id,
						'visit_date' => strtotime($visit_date),
						'up_time' => $up_time,
						'down_time' => $down_time,
						'payment' => $payment,
						'vendor' => $vendor,
						'destination' => $destination,
						'vehcal_type' => $vehcal_type,
						'bill_date' => time(),
						'person' => $person,
						'status' => 0
					));
				
				if ($insert) {
					$data = array(
						'status' => 200,
						'message' => 'Your vehicle rent registration was completed successfully!'
					);
					$notif_data = array(
						'subject' => 'Vehicle Rent',
						'comment' => cleanName($user_data['name']) . ' apply for rent.',
						'type' => 'rent_report',
						'url' => '/management/rent_report?user=' . $user_id,
						'user_id' => $user_id
					);

					$response = RegisterNotification($notif_data);
				} else {
					$data = array(
						'status' => 500,
						'message' => 'Something went wront while inserting!'
					);
				}
			} else {
					$data = array(
						'status' => 500,
						'message' => 'A rental record already exists!'
					);
			}
			
        } else {
            $data = array(
                'status' => 500,
                'message' => $error
            );
        }
	}
	if ($s == 'delete_rent') {
        if (check_permission('update-rent') == false && Wo_IsAdmin() == false && Wo_IsModerator() == false) {
            $error = 'You don`t have permission';
        }
        if (empty($error)) {
            $rent_id   = !empty($_POST['rent_id']) ? Wo_Secure($_POST['rent_id']) : '';
			$delete = $db->where('id', $rent_id)->delete(T_RENT_REPORT);
            $data = array(
                'status' => 200,
                'message' => 'Rent are deleted!'
            );
        } else {
            $data = array(
                'status' => 500,
                'message' => $error
            );
        }
	}
	
	if ($s == 'update_rent') {
		if (empty($_POST['rent_id'])) {
			$error = 'Something went wrong!';
		} else if (!isset($_POST['status']) && $_POST['status'] !== '0') {
			$error = 'Please select rent status!';
		}

		if (empty($error)) {
			$rent_id = Wo_Secure($_POST['rent_id']);
			
			$status = isset($_POST['status']) ? Wo_Secure($_POST['status']) : 0;
			$up_time = !empty($_POST['up_time_update']) ? Wo_Secure($_POST['up_time_update']) : null;
			$down_time = !empty($_POST['down_time_update']) ? Wo_Secure($_POST['down_time_update']) : null;
			
			$visit_date = !empty($_POST['visit_date_update']) ? strtotime($_POST['visit_date_update']) : '';
			$person = !empty($_POST['person_update']) ? Wo_Secure($_POST['person_update']) : 0;
			$payment = !empty($_POST['payment_update']) ? Wo_Secure($_POST['payment_update']) : 0;


			$get_one = $db->where('id', $rent_id)->getOne(T_RENT_REPORT);

			$data_array = [];
			
			$can_update = false;
			if ($get_one->status != 1) {
				$can_update = true;
				
				if ($up_time !== null) {
					$data_array['up_time'] = $up_time;
				}
				if ($down_time !== null) {
					$data_array['down_time'] = $down_time;
				}
				if ($status !== null && check_permission('update-rent') == true || Wo_IsAdmin() || Wo_IsModerator()) {
					$data_array['status'] = $status;
				}
				if ($visit_date !== null) {
					$data_array['visit_date'] = $visit_date;
				}
				if ($person !== null) {
					$data_array['person'] = $person;
				}
				if ($payment !== null) {
					$data_array['payment'] = $payment;
				}
			}

			if (!empty($data_array) && $can_update == true) {
				$update = $db->where('id', $rent_id)->update(T_RENT_REPORT, $data_array);

				if ($update) {
					$data = array(
						'status' => 200,
						'message' => 'Vehicle rent entry updated!'
					);
				} else {
					$data = array(
						'status' => 500,
						'message' => 'Failed to update vehicle rent entry.'
					);
				}
			} else {
				$data = array(
					'status' => 400
				);
				if ($can_update == false) {
					$data['message'] = 'You can`t update approved invoice!';
				} else {
					$data['message'] = 'No data to update.';
				}
			}
		} else {
			$data = array(
				'status' => 400,
				'message' => $error
			);
		}

	}
	if ($s == 'update_rent_Modal') {
		$rent_id = isset($_POST['rent_id']) ? Wo_Secure($_POST['rent_id']) : '';
		$data = array(
			'status'  => 200,
			'result' => Wo_LoadManagePage('rent_report/update_rent')
		);
	}
	
	header("Content-type: application/json");
	echo json_encode($data);
	exit();
}