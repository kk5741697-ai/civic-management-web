<?php 
if ($f == 'contact_us') {
    if ($wo['config']['reCaptcha'] == 1) {
        if (empty($_POST['g-recaptcha-response'])) {
            $errors[] = $error_icon . $wo['lang']['please_check_details'];
        }
        else{
            $recaptcha_data = array(
            'secret' => $wo['config']['recaptcha_secret_key'],
            'response' => $_POST['g-recaptcha-response']
            );

            $verify = curl_init();
            curl_setopt($verify, CURLOPT_URL, "https://www.google.com/recaptcha/api/siteverify");
            curl_setopt($verify, CURLOPT_POST, true);
            curl_setopt($verify, CURLOPT_POSTFIELDS, http_build_query($recaptcha_data));
            curl_setopt($verify, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($verify, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($verify);
            $response = json_decode($response);
            if (!$response->success) {
                $errors[] = $error_icon . $wo['lang']['reCaptcha_error'];
            }
        }
    }
    if (empty($_POST['form_fields']['project']) || empty($_POST['form_fields']['name']) || empty($_POST['form_fields']['phone']) || empty($_POST['form_fields']['email']) || empty($_POST['form_fields']['message'])) {
        $errors[] = $error_icon . $wo['lang']['please_check_details'];
    } else if (!filter_var($_POST['form_fields']['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = $error_icon . $wo['lang']['email_invalid_characters'];
    }
	
    if (empty($errors)) {
		$project		= Wo_Secure($_POST['form_fields']['project'],1);
        $name			= Wo_Secure($_POST['form_fields']['name'],1);
        $address		= Wo_Secure($_POST['form_fields']['address'],1);
        $katha			= Wo_Secure($_POST['form_fields']['katha'],1);
        $profession		= Wo_Secure($_POST['form_fields']['profession'],1);
        $phone			= Wo_Secure($_POST['form_fields']['phone'],1);
        $email			= Wo_Secure($_POST['form_fields']['email'],1);
        $message_text	= Wo_Secure($_POST['form_fields']['message'],1);
		
		$message = 'Project : ' . $project . 'Katha : ' . $katha . 'Name : ' . $name .'Address : ' . $address . ' \n Profession : ' . $profession . '\n Phone : ' . $phone . '\n Email : ' . $email . '\n Message Body : ' . $message_text;
        $send_message_data = array(
            'from_email' => $wo['config']['siteEmail'],
            'from_name' => $name,
            'reply-to' => $email,
            'project' => $project,
            'name' => $name,
            'katha' => $katha,
            'phone' => $phone,
            'email' => $email,
            'profession' => $profession,
            'address' => $address,
            'to_email' => $wo['config']['siteEmail'],
            'to_name' => $wo['config']['siteName'],
            'subject' => 'Contact us new message',
            'charSet' => 'utf-8',
            'insert_database' => 1,
            'message_body' => $message_text,
            'is_html' => false
        );
        $send              = Wo_SendMessage($send_message_data);
        if ($send) {
            $data = array(
                'status' => 200,
                'message' => $success_icon . $wo['lang']['email_sent']
            );
        } else {
            $errors[] = $error_icon . $wo['lang']['processing_error'];
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
