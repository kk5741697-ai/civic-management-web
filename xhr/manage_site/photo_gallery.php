<?php
if ($s == "photo_gallery") {
	if ($a == "sort") {
		// Decode JSON data from POST body
		$input = file_get_contents('php://input');
		$orders = json_decode($input, true); // true converts it into an associative array

		if (json_last_error() === JSON_ERROR_NONE) {
			// Check if orders is an array
			if (is_array($orders) && isset($orders['order'])) {
				$orders = $orders['order'];
				foreach ($orders as $order) {
					$db->where('id', $order['id'])->update(T_PHOTO_GALLERY, array('position' => $order['position']));
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
				'message' => 'Photo ID is required!'
			);
		} else {
			// Delete the review from the database
			$imgLink = $db->where('id', $id)->getOne(T_PHOTO_GALLERY);
			$delete = $db->where('id', $id)->delete(T_PHOTO_GALLERY);

			if ($delete) {
				$image = $imgLink->image;
				$smallImage = str_replace('_image.', '_image_small.', $image);

				if (file_exists($image)) {
					unlink($image);
				}

				if (file_exists($smallImage)) {
					unlink($smallImage);
				}
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

	if ($a == "edit") {
		// Retrieve and sanitize input
		$id = isset($_POST['id']) ? Wo_Secure($_POST['id']) : '';
		$name = isset($_POST['name']) ? Wo_Secure($_POST['name']) : '';
		$product = isset($_POST['product']) ? Wo_Secure($_POST['product']) : '';
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
					'width' => 600,
					'height' => 600
				)
			);
			$media = Wo_ShareFile($fileInfo);
		}

		// Validation checks
		if (empty($id)) {
			$data = array(
				'status' => 400,
				'message' => 'ID is required!'
			);
		} else if (empty($name)) {
			$data = array(
				'status' => 400,
				'message' => 'Name is required!'
			);
		} else if (empty($product)) {
			$data = array(
				'status' => 400,
				'message' => 'Product name is required!'
			);
		} else if (empty($featured)) {
			$data = array(
				'status' => 400,
				'message' => 'Featured is required!'
			);
		}

		// Prepare data for update
		$update_data = array(
			'name' => htmlspecialchars_decode($name),
			'product' => htmlspecialchars_decode($product),
			'featured' => $featured,
		);

		// Only update the image if a new file was uploaded
		if (!empty($media['filename'])) {
			$update_data['image'] = $media['filename'];
		}

		// Update the review in the database
		$update = $db->where('id', $id)->update(T_PHOTO_GALLERY, $update_data);

		if ($update) {
			$data = array(
				'status' => 200,
				'message' => 'Updated successfully!'
			);
		} else {
			$data = array(
				'status' => 400,
				'message' => 'Failed to update review. Please try again!'
			);
		}
	}
	if ($a == "add") {
		$name = isset($_POST['name']) ? Wo_Secure($_POST['name']) : '';
		$product = isset($_POST['product']) ? Wo_Secure($_POST['product']) : '';
		$featured = isset($_POST['featured']) ? Wo_Secure($_POST['featured']) : '';
		
		if (!empty($_FILES['photo'])) {
			$fileInfo = array(
				'file' => $_FILES["photo"]["tmp_name"],
				'name' => $_FILES['photo']['name'],
				'size' => $_FILES["photo"]["size"],
				'type' => $_FILES["photo"]["type"],
				'types' => 'jpeg,jpg,png,bmp,gif,webp',
				'compress' => true
			);
			$media    = Wo_ShareFile($fileInfo, 1, true);
		}
		
		if (empty($name)) {
			$data = array(
				'status' => 400,
				'message' => 'Name is required!'
			);
		} else if (empty($product)) {
			$data = array(
				'status' => 400,
				'message' => 'Product name is required!'
			);
		} else if (empty($featured)) {
			$data = array(
				'status' => 400,
				'message' => 'Featured is required!'
			);
		} else if (!$_FILES['photo'] && isset($media) && empty($media['filename'])) {
			$data = array(
				'status' => 400,
				'message' => 'Photo is required!'
			);
		} else {
			$insert = $db->insert(T_PHOTO_GALLERY, array(
				'name' => htmlspecialchars_decode($name),
				'image' => $media['filename'],
				'product' => htmlspecialchars_decode($product),
				'featured' => $featured,
				'time' => time()
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