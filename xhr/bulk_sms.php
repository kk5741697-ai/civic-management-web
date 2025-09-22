<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
if ($f == "bulk_sms") {
	
	if ($s == "report") {
		// Retrieve and sanitize date inputs from POST or set default values
		$date_start = isset($_POST['data_start']) ? Wo_Secure($_POST['data_start']) : date('Y-m-01');
		$date_end = isset($_POST['data_end']) ? Wo_Secure($_POST['data_end']) : '';
		$get_uid = isset($_POST['user_id']) ? Wo_Secure($_POST['user_id']) : '';
		
		setcookie("start_end_sms", $date_start . ' to ' . $date_end, time() + (10 * 365 * 24 * 60 * 60), '/');
		setcookie('sms_user', $get_uid, time() + (86400 * 30), "/");
		
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

		// Check if the search value is not empty
		if (!empty($searchValue)) {
			// Check if the search value is numeric
			if (is_numeric($searchValue)) {
				// Numeric search, match with `inv_id`
				$searchConditions[] = array('crm_sms_report.id', ltrim($searchValue, '#'), '=');
			} else {
			}
		}
		$start_timestamp = strtotime($date_start);
		$end_timestamp = strtotime($date_end);
		
		if (!empty($start_timestamp) && !empty($end_timestamp)) {
			$db->where('time', $start_timestamp, '>=')->where('time', $end_timestamp, '<=');
		}
		
		if (!empty($get_uid) && is_numeric($get_uid) && $get_uid != 999) {
			$db->where('user_id', $get_uid);
		}
		
		foreach ($searchConditions as $condition) {
			$db->where($condition[0], $condition[1], $condition[2]);
		}

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
		$count = $countDb->getValue(T_SMS, 'COUNT(*)');

		$db->pageLimit = $_POST['length'];
		$link = '';

		$all_sms = $db->objectbuilder()->paginate(T_SMS, $page_num);
		
		// Prepare data for DataTables
		$outputData = array();
		$store_pay_amount = array();
		
		foreach ($all_sms as $sms) {
			if ($sms->status == 'Delivered') {
				$status = '<span class="badge bg-success">Delivered</span>';
			} else if ($sms->status == 'Sending') {
				$status = '<span class="badge bg-info">Sending</span>';
			} else {
				$status = '<span class="badge bg-danger">' . $sms->status . '</span>';
			}
			
			$outputData[] = array(
				'id' => $sms->id,
				'senderid' => $sms->senderid,
				'contacts' => $sms->contacts,
				'cost' => $sms->cost,
				'status' => $status,
				'msg' => $sms->msg,
				'time' => Wo_Time_Elapsed_String_word($sms->time),
			);
		}

		// Send JSON response
		$data = array(
			"draw" => intval($_POST['draw']),
			"recordsTotal" => $count,
			"recordsFiltered" => $db->totalPages * $_POST['length'],
			"data" => $outputData
		);
	}
	
	if ($s == "fetch_status") {
		$balance_elitbuzz = sms_get_balance();
		$balance_iglweb = sms_get_balance('iglWeb');

		$data['status'] = 200;

		if ($balance_elitbuzz) {
			$balance_elitbuzz = str_replace('Your Balance is:BDT ', '', $balance_elitbuzz);
			$balance_elitbuzz = str_replace(', ', '', $balance_elitbuzz);

			// Check if the balance is numeric before saving
			if (is_numeric($balance_elitbuzz)) {
				Wo_SaveConfig('elitbuzz_balance', (int)$balance_elitbuzz);
			}

			$total_sms_elitbuzz = number_format($balance_elitbuzz / 0.53, 0);

			$data['current_balance_elitbuzz'] = '৳' . number_format($balance_elitbuzz, 2);
			$data['total_sms_elitbuzz'] = $total_sms_elitbuzz;
		} else {
			$data['current_balance_elitbuzz'] = '--';
			$data['total_sms_elitbuzz'] = '--';
		}

		if ($balance_iglweb) {
			$balance_iglweb = str_replace(' tk', '', $balance_iglweb);
			$balance_iglweb = str_replace(',', '', $balance_iglweb);

			// Check if the balance is numeric before saving
			if (is_numeric($balance_iglweb)) {
				Wo_SaveConfig('iglweb_balance', (int)$balance_iglweb);
			}

			$total_sms_iglweb = number_format($balance_iglweb / 0.53, 0);

			$data['current_balance_iglweb'] = '৳' . number_format($balance_iglweb, 2);
			$data['total_sms_iglweb'] = $total_sms_iglweb;
		} else {
			$data['current_balance_iglweb'] = '--';
			$data['total_sms_iglweb'] = '--';
		}

		
	}
	if ($s == "send_sms") {
		// Get and sanitize form data on AJAX submit
		$msg = isset($_POST['msg']) ? Wo_Secure($_POST['msg']) : '';
		$contacts = isset($_POST['contacts']) ? Wo_Secure($_POST['contacts']) : '';
		$senderid = isset($_POST['senderid']) ? Wo_Secure($_POST['senderid']) : '';
		$sms_vendor = isset($_POST['sms_vendor']) ? Wo_Secure($_POST['sms_vendor']) : '';
		$user_id = isset($_POST['user_id']) ? Wo_Secure($_POST['user_id']) : '';
		$sms_type = isset($_POST['sms_type']) ? Wo_Secure($_POST['sms_type']) : '';

		// Validate inputs
		if (empty($msg)) {
			$data['status'] = 400;
			$data['message'] = 'Message content can\'t be empty!';
		} else if (empty($sms_vendor)) {
			$data['status'] = 400;
			$data['message'] = 'Please select SMS vendor!';
		} else if (empty($senderid)) {
			$data['status'] = 400;
			$data['message'] = 'Please select sender ID!';
		} else if (empty($contacts)) {
			$data['status'] = 400;
			$data['message'] = 'Send to can\'t be empty!';
		} else if (empty($user_id)) {
			$data['status'] = 400;
			$data['message'] = 'User ID can\'t be empty!';
		} else if (empty($sms_type)) {
			$data['status'] = 400;
			$data['message'] = 'SMS type can\'t be empty!';
		} else {
			$data['status'] = 200;
		}

		// Process the request if validations pass
		if ($data['status'] !== 400) {
			// Detect if the message is Unicode
			$isUnicode = preg_match('/[^\x20-\x7E]/', $msg); // Checks for non-ASCII characters

			// Prepare contacts for processing
			$contactsArray = explode(',', $contacts); // Assuming contacts are comma-separated

			// Placeholder values
			$placeholders = [
				'#[plot_number]' => '',
				'#[file_id]' => '',
				'#[birthday]' => '',
				'#[this_month]' => '',
				'#[client_name]' => '',
			];
			
			$responses = [];
			$projectData = [];

			foreach ($contactsArray as $contact) {
				$dynamicValues = [];
				$contact = trim($contact);
				
				if ($sms_type != 'custom') {
					$client = GetCustomerById($contact);
					
					if (!$client) {
						continue;
					}
					
					$purchase = GetPurchaseByClientId($client['id']);
					$additional = GetAddiData_cId($client['id']);
					
					$contact = $client['phone'];
					$plots = [];
					
					if (!empty($purchase)) {
						foreach ($purchase as $value) {
							$plots[] = extractNumbers($value['plot']);
						}
					}
					
					if (!empty($plots)) {
						$dynamicValues['#[plot_number]'] = implode(', ', $plots); // Convert array to string
					}
					if (!empty($client['name'])) {
						$dynamicValues['#[client_name]'] = $client['name'];
					}
				}

				$finalMsg = $msg;
				foreach ($dynamicValues as $placeholder => $value) {
					$finalMsg = str_replace($placeholder, $value, $finalMsg);
				}

				$data_array = [
					'type' => $isUnicode ? 'unicode' : 'text',
					'sms_vendor' => $sms_vendor,
					'senderid' => $senderid,
					'contacts' => $contact,
					'msg' => $finalMsg,
					'user_id' => $user_id,
					'sms_type' => $sms_type
				];
				
				// Uncomment to send SMS
				$response = sms_send($data_array); 
				$responses[] = $response;

				if (isset($response) && strpos($response, 'balance is low! Current Balance is:') !== false) {
					$data['status'] = 400;
					$data['response'] = $response;
					break;
				}
			}
			
			if ($data['status'] !== 400) {
				$data['status'] = 200;
				$data['response'] = implode('; ', $responses);
			}
		}
	}

	if ($s == 'sms_form') {
		$user_id = isset($_POST['user_id']) ? Wo_Secure($_POST['user_id']) : '';
		$sms_type = isset($_POST['sms_type']) ? Wo_Secure($_POST['sms_type']) : '';
		
		$wo['sms_form']['user_id'] = $user_id;
		$wo['sms_form']['sms_type'] = $sms_type;
		setcookie('sms_type', $sms_type, time() + (86400 * 30), "/");

		$data = array(
			'status'  => 200,
			'result' => Wo_LoadManagePage('sms_report/sms_form')
		);
	}
	if ($s == 'custom_message') {
		// Read the JSON input
		$input = json_decode(file_get_contents('php://input'), true);

		// Check if custom_message exists in the decoded input
		$custom_message = isset($input['custom_message']) ? $input['custom_message'] : '';

		// Optionally, set a cookie if sms_type is custom
		if (isset($_COOKIE['sms_type']) && $_COOKIE['sms_type'] == 'custom') {
			setcookie('custom_message', $custom_message, time() + (86400 * 30), "/");
		}

		$data['message'] = $custom_message;

		if (isset($_COOKIE['sms_type']) && !empty($_COOKIE['sms_type'])) {
			$data['sms_type'] = $_COOKIE['sms_type'];
		}
	}

	
    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}
