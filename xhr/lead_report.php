<?php
// error_reporting(E_ALL);
// ini_set('display_errors', 1);

if ($f == "lead_report") {
	$lock_update = update_lockout_time();
	date_default_timezone_set('Asia/Dhaka');
	
	$errors = array();
	$result = array();  // Initialize as an array for consistency

	if ($s == "submit_form") {
		$date = isset($_POST['date_value']) ? Wo_Secure($_POST['date_value']) : '';
		$lead_report = isset($_POST['lead_report']) ? $_POST['lead_report'] : [];

		// Ensure $lead_report is an array before iterating
		if (is_array($lead_report)) {
			foreach ($lead_report as $report) {
				$if_exist = $db->where('user_id', $report['user_id'])->where('date', $date)->getOne(T_LEADS_REPORT);
				
				if ($if_exist) {
					$update = $db->where('id', $if_exist->id)
								 ->update(T_LEADS_REPORT, array(
									 'user_id'	=> $report['user_id'],
									 'date'		=> $date,
									 'positive' => $report['positive'],
									 'negative' => $report['negative'],
									 'visit' 	=> $report['visit'],
									 'sale' 	=> $report['sale'],
								 ));
					$result = 'Lead report updated!';
				} else {
					$insert = $db->insert(T_LEADS_REPORT, array(
								 'user_id'	=> $report['user_id'],
								 'date'		=> $date,
								 'positive' => $report['positive'],
								 'negative' => $report['negative'],
								 'visit'	=> $report['visit'],
								 'sale'		=> $report['sale'],
							 ));
					$result = 'Lead report updated!';
				}
				
			}
		} else {
			$result = 'No lead reports found.';
		}

		
		// echo $date;
		// Fetch the report for the specified user and date
		
		
		$response = array(
			'status' => 200,
			'result' => $result
		);
	}
	
	if ($s == "form") {
		$get_date = isset($_POST['date']) ? Wo_Secure($_POST['date']) : '';
		
		setcookie("lead_report_date", $get_date, time() + (10 * 365 * 24 * 60 * 60), '/');
		
		if (empty($get_date)) {
			$errors[] = 'Date is missing!';
			
			$response = array(
				'status' => 400,
				'error' => $errors
			);
		} else {
			$response = array(
				'status' => (empty($errors)) ? 200 : 400,
				'result' => Wo_LoadManagePage('lead_report/form')
			);
		}
	}
	
	if ($s == "fetch_report") {
		$get_date = isset($_POST['date']) ? Wo_Secure($_POST['date']) : '';
        $project = isset($_POST["project"]) ? Wo_Secure($_POST["project"]) : 'all';
        
		$date_start = isset($_POST['data_start']) ? Wo_Secure($_POST['data_start']) : '';
		$date_end = isset($_POST['data_end']) ? Wo_Secure($_POST['data_end']) : '';


		setcookie("fetch_lead_report_date", $date_start . ' to ' . $date_end, time() + (10 * 365 * 24 * 60 * 60), '/');
        setcookie("project", $project, time() + 10 * 365 * 24 * 60 * 60, "/");
        
		if (empty($date_start) || empty($date_end)) {
			$errors[] = 'Date is missing!';
			
			$response = array(
				'status' => 400,
				'error' => $errors
			);
		} else {
			$response = array(
				'status' => (empty($errors)) ? 200 : 400,
				'result' => Wo_LoadManagePage('lead_report/fetch_report')
			);
		}
	}
	
	header("Content-type: application/json");
	echo json_encode($response);
	exit();
}