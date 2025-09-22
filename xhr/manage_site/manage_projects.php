<?php
if ($s == "manage_projects") {
	if ($a == "sort") {
		// Decode JSON data from POST body
		$input = file_get_contents('php://input');
		$orders = json_decode($input, true); // true converts it into an associative array

		if (json_last_error() === JSON_ERROR_NONE) {
			// Check if orders is an array
			if (is_array($orders) && isset($orders['order'])) {
				$orders = $orders['order'];
				foreach ($orders as $order) {
					$db->where('id', $order['id'])->update(T_PROJECTS, array('position' => $order['position']));
					$response = array('status' => 200, 'message' => 'Orders processed successfully');
				}
			} else {
				$response = array('status' => 400, 'message' => 'Invalid order data format');
			}
		} else {
			$response = array('status' => 400, 'message' => 'Invalid JSON data');
		}
	}
	if ($a == "delete") {
		// Retrieve and sanitize input
		$id = isset($_POST['id']) ? Wo_Secure($_POST['id']) : '';

		// Validation check
		if (empty($id)) {
			$data = array(
				'status' => 400,
				'message' => 'Something went wrong!'
			);
		} else {
			// Delete the review from the database
			$delete = $db->where('id', $id)->delete(T_PROJECTS);

			if ($delete) {
				$data = array(
					'status' => 200,
					'message' => 'Deleted successfully!'
				);
			} else {
				$data = array(
					'status' => 400,
					'message' => 'Failed to delete. Please try again!'
				);
			}
		}
	}

	if ($a == "add") {
		$project_name = isset($_POST['project_name']) ? Wo_Secure($_POST['project_name']) : '';
		$project_location = isset($_POST['project_location']) ? Wo_Secure($_POST['project_location']) : '';
		$project_type = isset($_POST['project_type']) ? Wo_Secure($_POST['project_type']) : '';
		$project_status = isset($_POST['project_status']) ? Wo_Secure($_POST['project_status']) : '';
		$project_pogress = isset($_POST['project_pogress']) ? Wo_Secure($_POST['project_pogress']) : '';
		$website = isset($_POST['website']) ? Wo_Secure($_POST['website']) : '';
		
		
		if (empty($project_name)) {
			$data = array(
				'status' => 400,
				'message' => 'Project name is required!'
			);
		} else if (empty($project_location)) {
			$data = array(
				'status' => 400,
				'message' => 'Project location is required!'
			);
		} else if (empty($project_type)) {
			$data = array(
				'status' => 400,
				'message' => 'Project type is required!'
			);
		} else if (empty($project_status)) {
			$data = array(
				'status' => 400,
				'message' => 'Project status is required!'
			);
		} else if (empty($project_pogress)) {
			$data = array(
				'status' => 400,
				'message' => 'Project progress is required!'
			);
		} else if (empty($website)) {
			$data = array(
				'status' => 400,
				'message' => 'Someting went wrong!'
			);
		} else {
			
		$project_name = isset($_POST['project_name']) ? Wo_Secure($_POST['project_name']) : '';
		$project_location = isset($_POST['project_location']) ? Wo_Secure($_POST['project_location']) : '';
		$project_type = isset($_POST['project_type']) ? Wo_Secure($_POST['project_type']) : '';
		$project_status = isset($_POST['project_status']) ? Wo_Secure($_POST['project_status']) : '';
		$project_pogress = isset($_POST['project_pogress']) ? Wo_Secure($_POST['project_pogress']) : '';
		$website = isset($_POST['website']) ? Wo_Secure($_POST['website']) : '';
			
			$insert = $db->insert(T_PROJECTS, array(
				'name' => htmlspecialchars_decode($project_name),
				'type' => htmlspecialchars_decode($project_type),
				'progress' => htmlspecialchars_decode($project_pogress),
				'location' => htmlspecialchars_decode($project_location),
				'active' => htmlspecialchars_decode($project_status),
				'website' => htmlspecialchars_decode($website),
				'posted' => time()
			));
			
			if ($insert) {
				$data = array(
					'status' => 200,
					'message' => 'Added successfully!'
				);
			} else {
				$data = array(
					'status' => 400,
					'message' => 'Something went wrong!'
				);
			}
		}
	}
}