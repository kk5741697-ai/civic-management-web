<?php
// error_reporting(E_ALL);
// ini_set('display_errors', 1);

$is_lockout = is_lockout();
    
if ($s == 'lockout_check') {
	if ($is_lockout == false) {
		$data = array(
			"status" => 200,
			"message" => 'Session still alive!'
		);
	} else {
		$data = array(
			"status" => 400,
			"message" => 'Session Timeout!'
		);
	}
	
	$data['unseen_notification'] = getNotifications($wo['user']['user_id'], true);
	
	header("Content-type: application/json");
	echo json_encode($data);
	exit();
}

if ($s == 'lockout_update') {
	$lock_update = update_lockout_time();
	$reset_status = reset_working_status();
	if ($lock_update) {
		$data = array(
			"status" => 200
		);
	} else {
		$data = array(
			"status" => 400
		);
	}
	
	$data['unseen_notification'] = getNotifications($wo['user']['user_id'], true);
	
	Wo_LastSeen($wo['user']['user_id']);
	
	header("Content-type: application/json");
	echo json_encode($data);
	exit();
}

$lock_update = update_lockout_time();
if ($s == 'lockout_register') {
	$pass = isset($_POST['password']) ? $_POST['password'] : '';
	
	$check = update_lockout($pass);
	
	if ($check) {
		$status = 200;
		$message = 'Success!';
	} else {
		$status = 400;
		$message = 'Wrong credentials!';
	}
	
	$data = array(
		'status' => $status,
		'message' => $message
	);
	
	header("Content-type: application/json");
	echo json_encode($data);
	exit();
}
if ($f == 'manage_settings' && $wo['loggedin'] == true) {
	if ($s == "client_files_download") {
		$type = isset($_POST['type']) ? Wo_Secure($_POST['type']) : '';
		$client_id = isset($_POST['client_id']) ? Wo_Secure($_POST['client_id']) : '';

		if (empty($type)) {
			$data = array(
				'status' => 400,
				'message' => 'Type is required!'
			);
		} else {
			$file_path = ''; // Set the correct path to your file
			$allowedTypes = ['thanks_letter', 'payment', 'deed_of_agreement'];

			if (in_array($type, $allowedTypes)) {
				$download = true;
			} else {
				$download = false;
				$data = [
					'status' => 400
				];
			}
			if ($download == true) {
				$hash_id = Wo_CreateMainSession();
				$data = array(
					'status' => 200,
					'result' => $site_url . '/download.php?dl_type=' . $type . '&client_id=' . $client_id . '&token=' . $hash_id
				);
			} else {
				$data = array(
					'status' => 400,
					'message' => 'File not found!'
				);
			}
			if ($download == false) {
				header("Content-type: application/json");
				echo json_encode($data);
				exit();
			}
		}
	}
	if ($s == "manage_advance") {
		$get_uid = isset($_POST['user_id']) ? Wo_Secure($_POST['user_id']) : '';
		$get_date = isset($_POST['date']) ? Wo_Secure($_POST['date']) : '';
		$advance = isset($_POST['advance']) ? Wo_Secure($_POST['advance']) : '';
		
		$status = 200;
		if (empty($get_date) || empty($get_uid)) {
			$status = 400;
			$message = 'Advance amount is required!';
		}
		if (Wo_IsAdmin() || Wo_IsModerator() || check_permission('manage-advance-salary')) {
			// $status = 200;
		} else {
			$status = 400;
			$message = 'Permission required!';
		}
		if ($status == 200) {
			if (empty($advance)) {
				$advance = 0;
			}
			$is_exist = $db->where('Badgenumber', $get_uid)->where('time', $get_date)->getOne(T_ADVANCE_SALARY);
			if ($is_exist) {
				$data_array = array(
					'amount' => $advance
				);
				$update = $db->where('id', $is_exist->id)->update(T_ADVANCE_SALARY, $data_array);
				if ($update) {
					$status = 200;
					$message = 'Advance amount Updated!';
				} else {
					$status = 400;
					$message = 'Something went worng!';
				}
			} else {
				$data_array = array(
					'Badgenumber' => $get_uid,
					'amount' => $advance,
					'time' => $get_date
				);
				$insert = $db->insert(T_ADVANCE_SALARY, $data_array);
				if ($insert) {
					$status = 200;
					$message = 'Advance amount Granted!';
				} else {
					$status = 400;
					$message = 'Something went worng!';
				}
			}
		}
		$data = array(
			'status' => $status,
			'message' => $message
		);
	}
	
	if ($s == "advance_modal") {
		$get_date = isset($_GET['date']) ? Wo_Secure($_GET['date']) : '';
		$get_uid = isset($_GET['user_id']) ? Wo_Secure($_GET['user_id']) : '';
		
		if (empty($get_uid) || empty($get_date)) {
			$errors[] = 'Something Went Wrong!';
			$data = array(
				'status' => 400,
				'result' => $errors
			);
		} else {
			$wo['advance']['date'] = $get_date;
			$wo['advance']['user_id'] = $get_uid;
			
			$advance = $db->where('Badgenumber', $get_uid)->where('time', $get_date)->getOne(T_ADVANCE_SALARY);
			$wo['advance']['amount'] = (is_object($advance) && property_exists($advance, 'amount')) ? $advance->amount : 0;
			
			$data = array(
				'status' => (empty($errors)) ? 200 : 400,
				'result' => Wo_LoadManagePage('salary_report/advance-modal')
			);
		}
	}
	if ($s == 'salary_report') {
		$errors = array();
		$result = '';
		
		if (Wo_IsAdmin() || Wo_IsModerator() || check_permission('manage-salary')) {
			$get_uid = isset($_POST['user_id']) ? Wo_Secure($_POST['user_id']) : '';
		} else {
			$get_uid = $wo['user']['user_id'];
		}
		
		$month_year = isset($_POST['month_year']) ? Wo_Secure($_POST['month_year']) : '';
		// Check if month_year is not empty
		if (!empty($month_year)) {
			// Convert month_year to DateTime object for the first day of the month
			$start_date = new DateTime($month_year . '-01');

			// Calculate the last day of the month
			$end_date = clone $start_date;
			$end_date->modify('last day of this month');

			// Format the dates as needed
			$month_start = $start_date->format('Y-m-d');
			$month_end = $end_date->format('Y-m-d');
		} else {
			// Handle the case when month_year is empty
			// You can set default values or handle it based on your requirements
			$month_start = '';
			$month_end = '';
		}
		
		setcookie("default_u", $get_uid, time() + (10 * 365 * 24 * 60 * 60), '/');
		setcookie("month_year", $month_year, time() + (10 * 365 * 24 * 60 * 60), '/');

		if (empty($get_uid) || empty($month_year) || empty($month_start) || empty($month_end)) {
			$errors[] = 'Something Went Wrong!';
		} else {
			// Check if user_id is 0, fetch all users' data
			if ($get_uid == 999 || $get_uid == 888 || $get_uid == 777) {
				if ($get_uid == 888) {
					$get_users = $db->where('active', '1')->where('banned', '0')->where('management', '1')->orderBy('serial', 'ASC')->get(T_USERS);
				} else if ($get_uid == 777) {
					$get_users = $db->where('active', '1')->where('banned', '0')->where('management', '0')->orderBy('serial', 'ASC')->get(T_USERS);
				} else {
					$get_users = $db->where('active', '1')->where('banned', '0')->orderBy('serial', 'ASC')->get(T_USERS);
				}
				// Initialize the result array and totals
				$result = [];
				$totalWorkingDays = 0;
				$totalLate = 0;
				$totalAbsent = 0;
				$totalGrossSalary = 0;
				$totalAdvance = 0;
				$totalPayable = 0;

				$i = 1;
				// Loop through all users
				foreach ($get_users as $userlist) {
					// Fetch and process attendance data for each user
					$userResult = fetchAndProcessSalary($userlist, $month_start, $month_end, $i++);

					// Sum values for each user
					$totalWorkingDays += $userResult[0]['r_working_day'];
					$totalLate += $userResult[0]['r_late'];
					$totalAbsent += $userResult[0]['r_absent'];
					$totalGrossSalary += $userResult[0]['r_gross_salary'];
					// $totalAdvance += $userResult[0]['r_advance'];
					$totalPayable += $userResult[0]['r_payable'];

					// Add the user result to the final result
					$result = array_merge($result, $userResult);
				}

				// Add a row for the totals
				$result[] = array(
					'sl' => '',
					'name' => '<b>Total</b>',
					'designation' => '',
					'working_day' => '<b>---</b>',
					// 'working_day' => '<b>' . number_format($totalWorkingDays) . '</b>',
					// 'late' => '<b>' . number_format($totalLate) . '</b>',
					'late' => '<b>---</b>',
					'absent' => '<b>' . number_format($totalAbsent) . '</b>',
					'gross_salary' => '<b>৳' . number_format($totalGrossSalary) . '</b>',
					// 'advance' => '<b>৳' . number_format($totalAdvance) . '</b>',
					// 'payable' => '<b>৳' . number_format($totalPayable) . '</b>',
					'payable' => '--',
					'signature' => '',
					'action' => ''
				);
				
			} else {
				// Fetch the specified user's data
				$atten_user = $db->where('active', '1')->where('banned', '0')->where('user_id', $get_uid)->getOne(T_USERS);

				// Process attendance data for the specified user
				$result = fetchAndProcessSalary($atten_user, $month_start, $month_end, 1);
			}
		}
		$data = array(
			'status' => (empty($errors)) ? 200 : 400,
			'result' => (empty($result)) ? array() : $result
		);
		header("Content-type: application/json");
		echo json_encode($data);
		exit();
	}
	
	if ($s == 'particular_autocomplete') {
		$term = isset($_GET['term']) ? $_GET['term'] : '';

		if (!empty($term)) {
			// Assuming $db is your database connection, and T_DEBIT is your table particular
			$suggestions = $db->groupBy('particular')
							  ->where('particular', '%' . $term . '%', 'LIKE')
							  ->get(T_DEBIT, 10, 'particular');

			// Format the suggestions as required by the Autocomplete widget
			$formattedSuggestions = [];
			foreach ($suggestions as $suggestion) {
				$formattedSuggestions[] = array(
					'label' => $suggestion->particular,
					'value' => $suggestion->particular,
				);
			}

			// Send JSON response
			$data = array(
				'status' => 200,
				'suggestions' => $formattedSuggestions
			);
		} else {
			// Send JSON response for an empty term
			$data = array(
				'status' => 400,
				'message' => 'Invalid search term'
			);
		}
	}


	if ($s == 'debit_autocomplete') {
		$term = isset($_GET['term']) ? $_GET['term'] : '';

		if (!empty($term)) {
			// Assuming $db is your database connection, and T_DEBIT is your table name
			$suggestions = $db->groupBy('name')
							  ->where('name', '%' . $term . '%', 'LIKE')
							  ->get(T_DEBIT, 10, 'name');

			// Format the suggestions as required by the Autocomplete widget
			$formattedSuggestions = [];
			foreach ($suggestions as $suggestion) {
				$formattedSuggestions[] = array(
					'label' => $suggestion->name,
					'value' => $suggestion->name,
				);
			}

			// Send JSON response
			$data = array(
				'status' => 200,
				'suggestions' => $formattedSuggestions
			);
		} else {
			// Send JSON response for an empty term
			$data = array(
				'status' => 400,
				'message' => 'Invalid search term'
			);
		}
	}

    if ($s == 'delete_debit') {
        $debit_ids = isset($_POST['debit_ids']) ? $_POST['debit_ids'] : '';
    
        if (empty($debit_ids)) {
            $status = 400;
            $message = 'No IDs provided for deletion.';
        } else {
            $exp_ids = explode(',', $debit_ids);
            $delete_check = true;
    
            foreach ($exp_ids as $debit) {
                $delete_check &= $db->where('id', $debit)->delete(T_DEBIT);
            }
    
            if ($delete_check) {
                $status = 200;
                $message = 'Delete success!';
            } else {
                $status = 400;
                $message = 'Something went wrong during deletion.';
            }
        }
    
        // ✅ Log deletion attempt
        if (!empty($debit_ids)) {
            $idList = implode(', ', $exp_ids);
            $logDetails = "Deleted debit entries with IDs: {$idList}";
            $logType = ($status == 200) ? 'delete' : 'error';
            logActivity('debits', $logType, $logDetails);
        }
    
        // Send JSON response
        $data = array(
            'status' => $status,
            'message' => $message
        );
    }


	if ($s == 'create_debit') {
		// Assuming you have initialized your database connection as $db
		$get_data = isset($_POST['debit']) ? $_POST['debit'] : [];

		// Check if 'particular' and 'amount' arrays exist in $get_data
		if (isset($get_data['particular']) && isset($get_data['amount'])) {
        	$totalAmount = 0;
        	$createdCount = 0;
        
        	foreach ($get_data['particular'] as $key => $particular) {
        		$currentParticular = $particular;
        		$currentAmount = isset($get_data['amount'][$key]) ? (float)$get_data['amount'][$key] : 0;
        		$formattedDate = isset($get_data['date']) ? date('Y-m-d H:i:s', strtotime($get_data['date'])) : date('Y-m-d H:i:s');
        
        		$insertData = array(
        			'time' => $formattedDate,
        			'name' => isset($get_data['name']) ? $get_data['name'] : '',
        			'particular' => $currentParticular,
        			'amount' => $currentAmount,
        		);
        
        		if ($db->insert(T_DEBIT, $insertData)) {
        			$createdCount++;
        			$totalAmount += $currentAmount;
        		}
        	}
        
        	// ✅ Log activity
        	if ($createdCount > 0) {
        		$logDetails = "Created {$createdCount} debit entr" . ($createdCount > 1 ? 'ies' : 'y') .
        					  " totaling " . number_format($totalAmount, 2) .
        					  (!empty($get_data['name']) ? " (Name: " . $get_data['name'] . ")" : '');
        		logActivity('debits', 'create', $logDetails);
        	}
        
        	$data = array(
        		'status' => 200,
        		'message' => 'Entries added successfully',
        	);
        } else {
			// Handle the case when 'particular' or 'amount' arrays are missing
			$data = array(
				'status' => 400,
				'message' => 'Invalid data format',
			);
		}
	}
	if ($s == 'fetch_debit') {
		// Fetch and process data
		$page_num = isset($_POST['start']) ? $_POST['start'] / $_POST['length'] + 1 : 1;

		// Get the search value
		$searchValue = isset($_POST['search']['value']) ? $_POST['search']['value'] : '';

		// Initialize conditions array for filtering
		$searchConditions = array();

		// Order by the column specified by the user
		$orderColumn = isset($_POST['order'][0]['column']) ? $_POST['order'][0]['column'] : null;
		$orderDirection = isset($_POST['order'][0]['dir']) ? $_POST['order'][0]['dir'] : null;

		if ($searchValue) {
			$db->where('name', '%' . $searchValue . '%', 'LIKE')->orWhere('particular', '%' . $searchValue . '%', 'LIKE')->orWhere('time', '%' . $searchValue . '%', 'LIKE');
		}
		if ($orderColumn !== null) {
			if ($orderColumn == 0) {
				$db->orderBy('id', $orderDirection == 'asc' ? 'ASC' : 'DESC');
			} else if ($orderColumn == 1) {
				$db->orderBy('time', $orderDirection == 'asc' ? 'ASC' : 'DESC');
			} else {
				$db->orderBy('id', 'DESC');
			}
		} else {
			$db->orderBy('id', 'DESC');
		}
		
		$db->pageLimit = $_POST['length'];
		$link = '';

		$debits = $db->objectbuilder()->paginate(T_DEBIT, $page_num);
		
		// Initialize total amount
		$total_amount = 0;

		// Prepare data for DataTables
		$outputData = array();

		foreach ($debits as $debit) {
			$time = date('d M Y', strtotime($debit->time));
			$unique_key = $time . $debit->name;

			if (!isset($groupedDebits[$unique_key])) {
				// Initialize the group if it doesn't exist
				$groupedDebits[$unique_key] = array(
					'time' => $time,
					'name' => $debit->name,
					'particular' => array(),
					'amount' => array(),
					'action' => '', // Initialize action field
				);
			}

			// Add data to the group
			$groupedDebits[$unique_key]['particular'][] = $debit->particular;
			$groupedDebits[$unique_key]['amount'][] = $debit->amount;

			// Accumulate total amount
			$total_amount += $debit->amount;

			// Accumulate IDs in the array for each group
			$groupedDebits[$unique_key]['ids'][] = $debit->id;
		}

		// Process the groupedDebits and add to outputData
		foreach ($groupedDebits as $group) {
			// Implode all IDs for the current group
			$imp_ids = implode(',', $group['ids']);

			// Add the action link to the group
			$group['action'] = '<a href="javascript:;" class="text-danger" data-bs-toggle="tooltip" data-bs-placement="bottom"
								title="" data-bs-original-title="Delete" aria-label="Delete" onclick="deleteDebit(`' . $imp_ids . '`)">
								<ion-icon name="trash-outline"></ion-icon>
							</a>';

			$outputData[] = array(
				'id' => $group['ids'],
				'time' => $group['time'],
				'name' => $group['name'],
				'particular' => implode('<br>', $group['particular']),
				'amount' => '৳' . implode('<br>৳', $group['amount']),
				'action' => $group['action'],
			);
		}

		// Add Total Amount row
		$outputData[] = array(
			'id' => '',
			'time' => '<strong>Total Amount:</strong>',
			'name' => '',
			'particular' => '',
			'amount' => '<strong>৳' . number_format($total_amount) . '</strong>',
			'action' => '',
		);
		
		// Send JSON response
		$data = array(
			"draw" => intval($_POST['draw']),
			"recordsTotal" => $db->totalPages * $_POST['length'],
			"recordsFiltered" => $db->totalPages * $_POST['length'],
			"data" => $outputData
		);
	}
	
	if ($s == 'viewInvoice_modal') {
		$invoice_id = isset($_POST['invoice_id']) ? $_POST['invoice_id'] : '';
		
		if (empty($invoice_id)) {
			$data = array(
				'status'  => 400,
				'message' => 'Something went wrong!'
			);
		} else {
			$wo['invoice'] = GetInvoiceById($invoice_id);

			$data = array(
				'status'  => 200,
				'result' => Wo_LoadManagePage('invoice/view_invoice')
			);
		}
	}
    if ($s == 'assign_project') {
    	$client_id = isset($_GET['client']) ? $_GET['client'] : '';
    	$project_id = isset($_GET['project']) ? $_GET['project'] : '';
    	$katha = isset($_GET['katha']) ? $_GET['katha'] : '';
    	$per_katha_rate = isset($_GET['per_katha']) ? $_GET['per_katha'] : '';
    	$block = isset($_GET['block']) ? $_GET['block'] : '';
    	$plot = isset($_GET['plot']) ? $_GET['plot'] : '';
    	$plotString = implode('', array_map(function($value) {
    		return "[#{$value}]";
    	}, $plot));
    
    	if (empty($client_id) || empty($project_id) || empty($katha) || empty($per_katha_rate) || empty($block) || empty($plotString)) {
    		$data = array(
    			'status'  => 400,
    			'message' => 'All fields are required!'
    		);
    	} else {
    		$data_array = array(
    			'project_id' => $project_id,
    			'customer_id' => $client_id,
    			'katha' => $katha,
    			'rate' => $per_katha_rate,
    			'block' => $block,
    			'plot' => $plotString,
    		);
    
    		$is_exist = $db->where('project_id', $project_id)->where('customer_id', $client_id)->getOne(T_PURCHASE);
    		if ($is_exist) {
    			$insert = $db->where('id', $is_exist->id)->update(T_PURCHASE, $data_array);
    			$message = 'Project Already Assigned & Updated Details!';
    		} else {
    			$insert = $db->insert(T_PURCHASE, $data_array);
    			$message = 'Project Assigned!';
    		}
    
    		if (!$insert) {
    			$message = 'Something Went Wrong!';
    		}
    
    		// ✅ Log assignment
    		$clientName = 'Client #' . $client_id;
    		$projectName = 'Project #' . $project_id;
    		$logDetails = "{$clientName} assigned to {$projectName} with {$katha} Katha at rate {$per_katha_rate}. Block: {$block}, Plots: {$plotString}";
    		$logType = $is_exist ? 'update' : 'create';
    		logActivity('project_assignment', $logType, $logDetails);
    
    		$data = array(
    			'status'  => 200,
    			'message' => $message
    		);
    	}
    }
	if ($s == 'get_calculation') {
		$data = array();
		$client_id = isset($_GET['client']) ? $_GET['client'] : '';
		$purchase_id = isset($_GET['purchase']) ? $_GET['purchase'] : '';
		
		$purchase = GetPurchaseById($purchase_id);
		$total_amount = $purchase['katha'] * $purchase['rate'];
		
		
		// Calculate the total paid amount for the specific purchase
		$total_due_calculate = $db->where('purchase_id', $purchase_id)
								 ->getValue(T_INVOICE, 'SUM(pay_amount)');
		// Calculate the remaining due amount
		$due_amount = $total_amount - $total_due_calculate;

		if ($purchase) {
			$data = array(
				'status' => 200,
				'total'  => number_format($total_amount),
				'due'  => number_format($due_amount),
			);
		} else {
			$data = array(
				'status' => 500,
				'error'  => 'Calculation Error!'  // You might want to customize the error message
			);
		}
	}
	if ($s == 'get_project_options') {
		$data = array();
		$client_id = isset($_GET['client']) ? $_GET['client'] : '';

		$purchase = $db->where('customer_id', $client_id)->get(T_PURCHASE);
		$clientData = $db->where('id', $client_id)->getOne(T_CUSTOMERS);

		if ($purchase) {
			$options = array();
			$i = 0;
			foreach ($purchase as $value) {
				$project = GetProjectById($value->project_id);
				$i++;
				$selected = ($i == 1) ? 'selected' : '';
				$options[] = array(
					'id'       => $value->id,
					'text'     => $project['name'],
					'selected' => $selected
				);
			}

			$data = array(
				'status' => 200,
				'items'  => $options
			);
		} else {
			$data = array(
				'status' => 500,
				'clientName' => $clientData->name,
				'error'  => 'Purchase not found'  // You might want to customize the error message
			);
		}
	}
	if ($s == 'get_status') {
		$data = array();
		$project_id = isset($_GET['project']) ? $_GET['project'] : '';
		$client_id = isset($_GET['client']) ? $_GET['client'] : '';

		$client = GetCustomerById($project_id);
		$project = GetPurchaseByClientId($client_id);
		
		if ($query) {
			while ($fetched_data = mysqli_fetch_assoc($query)) {
				// Assuming 'id' and 'name' are the columns in your customers table
				$options[] = array(
					'id'   => $fetched_data['id'],
					'text' => $fetched_data['name']
				);
			}

			$data = array(
				'status' => 200,
				'items'  => $options
			);
		} else {
			$data = array(
				'status' => 500,
				'error'  => mysqli_error($sqlConnect)
			);
		}
	}

	if ($s == 'fetch_customers') {
		$data = array();
		$search_query = isset($_GET['q']) ? $_GET['q'] : '';

		$options = array(); // Initialize $options here

		if (!empty($search_query)) {
			if (is_numeric($search_query)) {
				$search_condition = " WHERE `id` LIKE '" . $search_query . "'";
			} else {
				$search_condition = " WHERE `name` LIKE '%" . mysqli_real_escape_string($sqlConnect, $search_query) . "%'";
			}
		}

		$query = mysqli_query($sqlConnect, "SELECT * FROM " . T_CUSTOMERS . $search_condition . " ORDER BY `id` ASC LIMIT 5");

		if ($query) {
			while ($fetched_data = mysqli_fetch_assoc($query)) {
				// Assuming 'id' and 'name' are the columns in your customers table
				$options[] = array(
					'id'   => $fetched_data['file_id'],
					'text' => $fetched_data['name'] . ' #' . $fetched_data['id']
				);
			}

			$data = array(
				'status' => 200,
				'items'  => $options
			);
		} else {
			$data = array(
				'status' => 500,
				'error'  => mysqli_error($sqlConnect)
			);
		}
	}


	if ($s == 'create_invoice') {
		$client_id = isset($_GET['client']) ? $_GET['client'] : '';
		$purchase_id = isset($_GET['purchase']) ? $_GET['purchase'] : '';
		$pay_amount = isset($_GET['pay_amount']) ? $_GET['pay_amount'] : '';
		$pay_date = isset($_GET['pay_date']) ? $_GET['pay_date'] : '';
		$pay_type = isset($_GET['pay_type']) ? $_GET['pay_type'] : '';
		$bank_name = isset($_GET['bank_name']) ? $_GET['bank_name'] : '';
		$bank_branch = isset($_GET['bank_branch']) ? $_GET['bank_branch'] : '';
		$is_booking_money = isset($_GET['is_booking_money']) ? $_GET['is_booking_money'] : 0;
		$remarks = isset($_GET['remarks']) ? $_GET['remarks'] : '';
		$message = '';
		
		$purchase = GetPurchaseById($purchase_id);
		
		$total_amount = $purchase['katha'] * $purchase['rate'];
		// Calculate the total paid amount for the specific purchase
		$total_due_calculate = $db->where('purchase_id', $purchase_id)
								 ->getValue(T_INVOICE, 'SUM(pay_amount)');
		$intotal_pay = $total_due_calculate + $pay_amount;
		if (empty($client_id) || empty($purchase_id) || empty($client_id)) {
			$message = 'Something went wrong!';
		} else if (empty($pay_amount)) {
			$message = 'Pay amount is required!';
		} else if (empty($pay_date)) {
			$message = 'Pay date is required!';
		} else if (empty($pay_type)) {
			$message = 'Payment type is required!';
		}
		if (!empty($pay_amount)) {
			if ($pay_type == 'cheque') {
				if (empty($bank_name)) {
					$message = 'Bank name is required!';
				} else if (empty($bank_branch)) {
					$message = 'Bank branch is required!';
				}
			}
		}
		
		if (empty($message)) {
			if ($total_due_calculate >= $total_amount) {
				$data = array(
					'status'  => 400,
					'message' => 'Alredy paid!'
				);
			} else if ($intotal_pay > $total_amount) {
				$data = array(
					'status'  => 400,
					'message' => 'Pay Amount is longer than due!'
				);
			} else {
				if (empty($client_id) || empty($purchase_id) || empty($pay_amount)) {
					$data = array(
						'status'  => 400,
						'message' => 'All fields are required!'
					);
				} else {
					
					$data_array = array(
						'purchase_id' => $purchase_id,
						'customer_id' => $client_id,
						'is_booking' => $is_booking_money,
						'pay_amount' => $pay_amount,
						'pay_type' => $pay_type,
						'bank_name' => $bank_name,
						'bank_branch' => $bank_branch,
						'inv_time' => $pay_date,
						'remarks' => $remarks
					);
					
					$insert = $db->insert(T_INVOICE, $data_array);

					if ($insert) {
						
						if (strtotime($purchase['p_time']) > strtotime($pay_date)) {
							$data_array = array(
								'p_time' => $pay_date,
							);
							$update = $db->where('id', $purchase['id'])->update(T_PURCHASE, $data_array);
						}
						
						$client = GetCustomerById($client_id);
						if (strtotime($client['time']) > strtotime($pay_date)) {
							$data_array = array(
								'time' => $pay_date,
							);
							$update = $db->where('id', $client['id'])->update(T_CUSTOMERS, $data_array);
						}
						
						$message = 'Invoice Created!';
					} else {
						$message = 'Something Went Wrong!';
					}
				}
					$data = array(
						'status'  => 200,
						'message' => $message
					);
			}
		} else {
			$data = array(
				'status'  => 400,
				'message' => $message
			);
		}
	}
	
	if ($s == 'default_rate') {
		$project_id = isset($_GET['project_id']) ? $_GET['project_id'] : '';
		$projectData = $db->where('id', $project_id)->getOne(T_PROJECTS);
		
		$data = array(
			'status'  => 200,
			'default_rate' => $projectData->default_rate
		);
	}

	if ($s == 'delete_invoice') {
		// Get the Client ID value
		$invoice_id = isset($_POST['invoice_id']) ? $_POST['invoice_id'] : '';
		if (empty($invoice_id)) {
			$status = 400;
			$message = 'Something went wrong!';
		} else {
			$invoice = GetInvoiceById($invoice_id);

			// Delete invoices
			$invoiceDeleteResult = $db->where('inv_id', $invoice['inv_id'])->delete(T_INVOICE);
			
			// Check if all delete operations were successful
			if ($invoiceDeleteResult) {
				$status = 200;
				$message = 'Delete success!';
			} else {
				$status = 400;
				$message = 'Something went wrong!';
			}
		}

		// Send JSON response
		$data = array(
			'status'  => $status,
			'message' => $message
		);
	}

   if ($s == 'fetch_invoices') {
		// Fetch and process data
		$page_num = isset($_POST['start']) ? $_POST['start'] / $_POST['length'] + 1 : 1;

		// Get the search value
		$searchValue = isset($_POST['search']['value']) ? $_POST['search']['value'] : '';

		// Initialize conditions array for filtering
		$searchConditions = array();

		// Check if the search value is not empty
		if (!empty($searchValue)) {
			// Check if the search value is numeric
			if (is_numeric($searchValue)) {
				// Numeric search, match with `inv_id`
				$searchConditions[] = array('crm_invoice.inv_id', ltrim($searchValue, '#'), '=');
			} else {
				if ($searchValue === '#') {
					
				} else if (strpos($searchValue, '#') === 0) {
					$searchValue = str_replace('#','',$searchValue);
					$searchConditions[] = array('crm_invoice.customer_id', $searchValue, '=');
				} else {
					// Text search, use LIKE operator on the customer's name
					$searchConditions[] = array('crm_customers.name', '%' . $searchValue . '%', 'LIKE');					
				}
			}
		}

		// Apply search conditions to the query
		// Use a custom join condition within the WHERE clause
		foreach ($searchConditions as $condition) {
			if ($condition[0] === 'crm_customers.name') {
				// Use a join condition for the 'name' column in crm_customers
				$db->join('crm_customers', 'crm_customers.id = crm_invoice.customer_id', 'LEFT');
			}
			$db->where($condition[0], $condition[1], $condition[2]);
		}
		
		// Order by the column specified by the user
		$orderColumn = isset($_POST['order'][0]['column']) ? $_POST['order'][0]['column'] : null;
		$orderDirection = isset($_POST['order'][0]['dir']) ? $_POST['order'][0]['dir'] : null;

		if ($orderColumn !== null) {
			if ($orderColumn == 0) {
				$db->orderBy('inv_id', $orderDirection == 'asc' ? 'ASC' : 'DESC');
			} else {
				$db->orderBy('inv_id', 'DESC');
			}
		} else {
			$db->orderBy('inv_id', 'DESC');
		}
		$db->pageLimit = $_POST['length'];
		$link = '';

		$invoices = $db->objectbuilder()->paginate(T_INVOICE, $page_num);
		
		// Prepare data for DataTables
		$outputData = array();
		$store_pay_amount = array();

		foreach ($invoices as $invoice) {
			$customer = GetCustomerById($invoice->customer_id);
			$purchase = GetPurchaseById($invoice->purchase_id);
			$project = GetProjectById($purchase['project_id']);
			$total_amount = $purchase['rate'] * $purchase['katha'];

			// Check if purchase_id exists in the array, if not, initialize it
			if (!isset($store_pay_amount[$invoice->purchase_id])) {
				$store_pay_amount[$invoice->purchase_id] = 0;
			}

			// Add the paid amount to the existing amount for the purchase_id
			$store_pay_amount[$invoice->purchase_id] += $invoice->pay_amount;

			// Calculate the remaining due amount
			$due = $store_pay_amount[$invoice->purchase_id];
			$total_due = $total_amount - $due;
			if ($total_due <= 0) {
				$status = '<span class="badge bg-success">Paid</span>';
			} else {
				$status = '<span class="badge bg-danger">Due</span>';
			}
			
			$outputData[] = array(
				'id' => $invoice->inv_id,
				'customer_name' => $customer['name'] . ' #' . $customer['id'],
				'project_name' => $project['name'],
				'total_amount' => '৳' . number_format($total_amount),
				'pay_amount' => '৳' . number_format($invoice->pay_amount),
				'remaining_due' => '৳' . number_format($total_due),
				'formatted_time' => date('d M Y g:i A', strtotime($invoice->inv_time)),
				'status' => $status, // Assuming $status is calculated elsewhere
				'actions' => '<div class="d-flex align-items-center gap-3 fs-6">
								<a href="javascript:;" class="text-primary" data-bs-toggle="tooltip" data-bs-placement="bottom"
									title="" data-bs-original-title="View detail" aria-label="Views" onclick="viewInvoice(' . $invoice->inv_id . ')">
									<i class="bx bx-book-open"></i>
								</a>
								<a href="javascript:;" class="text-danger" data-bs-toggle="tooltip" data-bs-placement="bottom"
									title="" data-bs-original-title="Delete" aria-label="Delete" onclick="deleteInvoice(' . $invoice->inv_id . ')">
									<i class="bx bx-trash"></i>
								</a>
							</div>'
			);
		}

		// Send JSON response
		$data = array(
			"draw" => intval($_POST['draw']),
			"recordsTotal" => $db->totalPages * $_POST['length'],
			"recordsFiltered" => $db->totalPages * $_POST['length'],
			"data" => $outputData
		);
	}
	if ($s != 'salary_report' || $s != 'client_files_download') {
		header("Content-type: application/json");
		echo json_encode($data);
		exit();
	}
}