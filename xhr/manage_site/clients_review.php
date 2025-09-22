<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
Global $s, $a, $_POST, $_GET;
if ($s == "clients_review") {
	if ($a == "delete_review") {
		// Retrieve and sanitize input
		$review_id = isset($_POST['review_id']) ? Wo_Secure($_POST['review_id']) : '';

		// Validation check
		if (empty($review_id)) {
			$data = array(
				'status' => 400,
				'message' => 'Review ID is required!'
			);
		} else {
			// Delete the review from the database
			$delete = $db->where('id', $review_id)->delete(T_CLIENTS_REVIEW);

			if ($delete) {
				$data = array(
					'status' => 200,
					'message' => 'Review deleted successfully!'
				);
			} else {
				$data = array(
					'status' => 400,
					'message' => 'Failed to delete review. Please try again!'
				);
			}
		}
	}

	if ($a == "edit_review") {
		// Retrieve and sanitize input
		$review_id = isset($_POST['review_id']) ? Wo_Secure($_POST['review_id']) : '';
		$name = isset($_POST['client_name']) ? Wo_Secure($_POST['client_name']) : '';
		$designation = isset($_POST['designation']) ? Wo_Secure($_POST['designation']) : '';
		$review = isset($_POST['review']) ? Wo_Secure($_POST['review']) : '';
		$rating = isset($_POST['rating']) ? Wo_Secure($_POST['rating']) : '';
		$featured = isset($_POST['featured']) ? Wo_Secure($_POST['featured']) : '';

		// Handle file upload if provided
		$media = [];
		if (!empty($_FILES['photo'])) {
			$fileInfo = array(
				'file' => $_FILES["photo"]["tmp_name"],
				'name' => $_FILES['photo']['name'],
				'size' => $_FILES["photo"]["size"],
				'type' => $_FILES["photo"]["type"],
				'types' => 'jpeg,jpg,png,bmp,gif',
				'compress' => true,
				'crop' => array(
					'width' => 45,
					'height' => 45
				)
			);
			$media = Wo_ShareFile($fileInfo);
		}

		// Validation checks
		if (empty($review_id)) {
			$data = array(
				'status' => 400,
				'message' => 'Review ID is required!'
			);
		} else if (empty($name)) {
			$data = array(
				'status' => 400,
				'message' => 'Name is required!'
			);
		} else if (empty($designation)) {
			$data = array(
				'status' => 400,
				'message' => 'Designation is required!'
			);
		} else if (empty($review)) {
			$data = array(
				'status' => 400,
				'message' => 'Review is required!'
			);
		} else if (empty($rating)) {
			$data = array(
				'status' => 400,
				'message' => 'Rating is required!'
			);
		}

		// Prepare data for update
		$update_data = array(
			'name' => $name,
			'designation' => $designation,
			'review' => $review,
			'product' => 'Moon Hill',
			'rating' => $rating,
			'featured' => $featured,
		);

		// Only update the image if a new file was uploaded
		if (!empty($media['filename'])) {
			$update_data['image'] = $media['filename'];
		}

		// Update the review in the database
		$update = $db->where('id', $review_id)->update(T_CLIENTS_REVIEW, $update_data);

		if ($update) {
			$data = array(
				'status' => 200,
				'message' => 'Review updated successfully!'
			);
		} else {
			$data = array(
				'status' => 400,
				'message' => 'Failed to update review. Please try again!'
			);
		}
	}
	if ($a == "add_review") {
		$name = isset($_POST['client_name']) ? Wo_Secure($_POST['client_name']) : '';
		$designation = isset($_POST['designation']) ? Wo_Secure($_POST['designation']) : '';
		$review = isset($_POST['review']) ? Wo_Secure($_POST['review']) : '';
		$rating = isset($_POST['rating']) ? Wo_Secure($_POST['rating']) : '';
		$featured = isset($_POST['featured']) ? Wo_Secure($_POST['featured']) : '';

		if (!empty($_FILES['photo'])) {
			$fileInfo = array(
				'file' => $_FILES["photo"]["tmp_name"],
				'name' => $_FILES['photo']['name'],
				'size' => $_FILES["photo"]["size"],
				'type' => $_FILES["photo"]["type"],
				'types' => 'jpeg,jpg,png,bmp,gif',
				'compress' => true,
				'crop' => array(
					'width' => 45,
					'height' => 45
				)
			);
			$media    = Wo_ShareFile($fileInfo);
		}
		
		if (empty($name)) {
			$data = array(
				'status' => 400,
				'message' => 'Name is required!'
			);
		} else if (empty($designation)) {
			$data = array(
				'status' => 400,
				'message' => 'Designation is required!'
			);
		} else if (empty($designation)) {
			$data = array(
				'status' => 400,
				'message' => 'Designation is required!'
			);
		} else if (empty($review)) {
			$data = array(
				'status' => 400,
				'message' => 'Review is required!'
			);
		} else if (empty($rating)) {
			$data = array(
				'status' => 400,
				'message' => 'Rating is required!'
			);
		} else if (!$_FILES['photo'] && isset($media) && empty($media['filename'])) {
			$data = array(
				'status' => 400,
				'message' => 'Photo is required!'
			);
		} else {
			
			$insert = $db->insert(T_CLIENTS_REVIEW, array(
				'name' => $name,
				'designation' => $designation,
				'image' => $media['filename'],
				'review' => $review,
				'product' => 'Moon Hill',
				'rating' => $rating,
				'featured' => $featured,
				'time' => time()
			));
			
			if ($insert) {
				$data = array(
					'status' => 200
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