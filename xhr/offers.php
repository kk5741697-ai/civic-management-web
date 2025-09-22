<?php
if ($f == 'offers') {
	if ($s == "delete_modal") {
		$offer_id = isset($_POST['offer_id']) ? Wo_Secure($_POST['offer_id']) : '';
		
		if (empty($offer_id)) {
			$errors[] = 'Something Went Wrong!';
		} else {
			$response = array(
				'status' => (empty($errors)) ? 200 : 400,
				'result' => Wo_LoadPage('offers/modal')
			);

			header("Content-type: application/json");
			echo json_encode($response);
			exit();
		}
	}
	if ($s == "delete_offer") {
		$offer_id = isset($_POST['offer_id']) ? Wo_Secure($_POST['offer_id']) : '';
		
		if (empty($offer_id)) {
			$errors[] = 'Something Went Wrong!';
		} else {
			$delete = $db->where('id', $offer_id)->delete(T_OFFERS);
			$response = array(
				'status' => ($delete) ? 200 : 400
			);

			header("Content-type: application/json");
			echo json_encode($response);
			exit();
		}
	}
	if ($s == 'edit') {
		if (empty($_POST['title'])) {
			$errors[] = $error_icon . 'Title is required!';
		} else if (empty($_POST['content'])) {
			$errors[] = $error_icon . 'Content is required!';
		} else if (empty($_POST['offer_id'])) {
			$errors[] = $error_icon . 'Critical Error!';
		} else if (isset($_FILES['thumbnail']['name'])) {
			$allowed = array(
				'png',
				'jpg',
				'jpeg'
			);
			$new_string = pathinfo($_FILES['thumbnail']['name']);
			if (!in_array(strtolower($new_string['extension']), $allowed)) {
				$errors[] = $new_string['extension'];
			}
		}
		if (empty($errors)) {
			$offer_id	= Wo_Secure($_POST['offer_id']);
			$name		= $_POST['title'];
			$content	= $_POST['content'];
			if (count($_FILES['thumbnail']['name']) > 0) {
				$fileInfo = array(
					'file' => $_FILES["thumbnail"]["tmp_name"],
					'name' => $_FILES['thumbnail']['name'],
					'size' => $_FILES["thumbnail"]["size"],
					'type' => $_FILES["thumbnail"]["type"],
					'types' => 'png,jpg,jpeg'
				);
				$file     = Wo_ShareFile($fileInfo);
				$filename = $file['filename'];
			}
			if (empty($filename)) {
				$errors[] = $error_icon . 'File not valid!';
			}
			if (!isset($_FILES['thumbnail']['name'])) {
				$get_file = $db->where('id', $offer_id)->getOne(T_OFFERS);
				$filename	= $get_file->thumbnail;
			}
			
			$post_data = array(
				'id' => $offer_id,
				'title' => $name,
				'content' => $content,
				'thumbnail' => $filename,
				'active' => 1,
				'time' => time()
			);
			$id = Wo_EditOffers($post_data);
			
			if ($id) {
				$data = array(
					'status' => 200,
					'message' => 'Success!',
					'url' => $wo['site_url'] . '/offers/' . $offer_id
				);
			} else {
				$errors[] = $error_icon . $wo['lang']['processing_error'];
				$data = array(
					'status' => 400,
					'errors' => $errors
				);
			}
		} else {
			$data = array(
				'status' => 400,
				'errors' => $errors
			);
		}
	}
	if ($s == 'create') {
		if (empty($_POST['title'])) {
			$errors[] = $error_icon . 'Title is required!';
		} else if (empty($_POST['content'])) {
			$errors[] = $error_icon . 'Content is required!';
		} else if (isset($_FILES['thumbnail']['name'])) {
			$allowed = array(
				'png',
				'jpg',
				'jpeg'
			);
			$new_string = pathinfo($_FILES['thumbnail']['name']);
			if (!in_array(strtolower($new_string['extension']), $allowed)) {
				$errors[] = $new_string['extension'];
			}
		}
		if (empty($errors)) {
			$name		= $_POST['title'];
			$content	= $_POST['content'];
			if (count($_FILES['thumbnail']['name']) > 0) {
				$fileInfo = array(
					'file' => $_FILES["thumbnail"]["tmp_name"],
					'name' => $_FILES['thumbnail']['name'],
					'size' => $_FILES["thumbnail"]["size"],
					'type' => $_FILES["thumbnail"]["type"],
					'types' => 'png,jpg,jpeg'
				);
				$file     = Wo_ShareFile($fileInfo);
			}
			if (empty($file['filename'])) {
				$errors[] = $error_icon . 'File not valid!';
			}
			$filename	= $file['filename'];
			
			$post_data = array(
				'title' => $name,
				'content' => $content,
				'thumbnail' => $filename,
				'active' => 1,
				'time' => time()
			);
			$id = Wo_RegisterOffers($post_data);
			
			if ($id) {
				$data = array(
					'status' => 200,
					'message' => 'Success!',
					'url' => $wo['site_url'] . '/offers/' . $id
				);
			} else {
				$errors[] = $error_icon . $wo['lang']['processing_error'];
				$data = array(
					'status' => 400,
					'errors' => $errors
				);
			}
		} else {
			$data = array(
				'status' => 400,
				'errors' => $errors
			);
		}
	}
}
header("Content-type: application/json");
echo json_encode($data);
exit();
