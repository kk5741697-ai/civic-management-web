<?php 
if ($f == 'career') {
    if (empty($_POST['form_fields']['name'])) {
        $errors[] = $error_icon . 'Name is required!';
    } else if (empty($_POST['form_fields']['phone'])) {
        $errors[] = $error_icon . 'Phone number is required!';
    } else if (empty($_POST['form_fields']['position'])) {
        $errors[] = $error_icon . 'Position is required!';
    } else if (empty($_POST['form_fields']['email'])) {
        $errors[] = $error_icon . 'Email is required!';
    } else if (empty($_POST['form_fields']['message'])) {
        $errors[] = $error_icon . 'Message is required!';
    } else if (!isset($_FILES['postPhotos']['name'])) {
        $errors[] = $error_icon . 'CV file is required!';
    } else if (!filter_var($_POST['form_fields']['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = $error_icon . $wo['lang']['email_invalid_characters'];
    } else if (isset($_FILES['postPhotos']['name'])) {
		$allowed = array(
			'png',
			'jpg',
			'jpeg',
			'pdf'
		);
		for ($i = 0; $i < count($_FILES['postPhotos']['name']); $i++) {
			$new_string = pathinfo($_FILES['postPhotos']['name'][$i]);
			if (!in_array(strtolower($new_string['extension']), $allowed)) {
				$errors[] = 'File not valid';
			}
		}
	}
	
	$if_exist_email = $db->where('email',Wo_Secure($_POST['form_fields']['email']))->getOne(T_CAREER);
	$if_exist_phone = $db->where('phone',Wo_Secure($_POST['form_fields']['phone']))->getOne(T_CAREER);
	
	
    if (!empty($if_exist_email) || !empty($if_exist_phone)) {
		$errors[] = $error_icon . 'You have already applied!';
	}
    if (empty($errors)) {
        $post_data['name']			= Wo_Secure($_POST['form_fields']['name'],1);
        $post_data['phone']			= Wo_Secure($_POST['form_fields']['phone'],1);
        $post_data['position']		= Wo_Secure($_POST['form_fields']['position'],1);
        $post_data['sub_position']	= Wo_Secure($_POST['form_fields']['sub_position'],1);
        $post_data['email']			= Wo_Secure($_POST['form_fields']['email'],1);
        $post_data['message']		= Wo_Secure($_POST['form_fields']['message'],1);

		
		if (count($_FILES['postPhotos']['name']) > 0) {
			for ($i = 0; $i < count($_FILES['postPhotos']['name']); $i++) {
				$fileInfo = array(
					'file' => $_FILES["postPhotos"]["tmp_name"][$i],
					'name' => $_FILES['postPhotos']['name'][$i],
					'size' => $_FILES["postPhotos"]["size"][$i],
					'type' => $_FILES["postPhotos"]["type"][$i],
					'types' => 'pdf'
				);
				$file     = Wo_ShareFile($fileInfo);
			}
		}
		
		if (empty($file['filename'])) {
			$errors[] = $error_icon . 'File not valid!';
		}
		
        $post_data['postPhotos']	= $file['filename'];
		
		if (empty($errors)) {
			$id = Wo_RegisterCareer($post_data);
			
			if ($id) {
				$data = array(
					'status' => 200,
					'message' => 'Success!',
					'url' => $wo['site_url'],
					'id' => $id
				);
			} else {
				$errors[] = $error_icon . $wo['lang']['processing_error'];
			}
		}
		
    }
    header("Content-type: application/json");
    if (!empty($errors)) {
        echo json_encode(array(
            'errors' => $errors
        ));
    } else {
        echo json_encode($data);
    }
    exit();
}
