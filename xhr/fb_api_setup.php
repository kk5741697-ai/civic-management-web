<?php 
if ($f == "fb_api_setup" && Wo_CheckSession($hash_id) === true) {
    
    $app_id =  isset($_POST['app_id']) && !empty($_POST['app_id']) ? $_POST['app_id'] : '';
    $app_secret =  isset($_POST['app_secret']) && !empty($_POST['app_secret']) ? $_POST['app_secret'] : '';
    $user_access_token =  isset($_POST['user_access_token']) && !empty($_POST['user_access_token']) ? $_POST['user_access_token'] : '';
    
    // Check and sanitize POST data
    $pages = isset($_POST['pages']) && !empty($_POST['pages']) ? $_POST['pages'] : [];
    $leads = isset($_POST['leads']) && !empty($_POST['leads']) ? $_POST['leads'] : [];
    $whatsapps = isset($_POST['whatsapps']) && !empty($_POST['whatsapps']) ? $_POST['whatsapps'] : [];

    
    // Processing pages array
    if (!empty($pages)) {
        foreach ($pages as $page_id => $page_data) {
            $status = isset($page_data['status']) ? $page_data['status'] : 0;
            
            if ($pages[$page_id]['picture']) {
                if (isset($wo['fb_api_data']['pages'][$page_id]['picture']) && !empty($wo['fb_api_data']['pages'][$page_id]['picture'])) {
                    unlink($wo['fb_api_data']['pages'][$page_id]['picture']); //remove from file previous page picture
                }
                $pages[$page_id]['picture'] = Wo_ImportFileFromUrl(urldecode($page_data['picture']), '_page_picture');
            } else {
                $pages[$page_id]['picture'] = $wo['fb_api_data']['pages'][$page_id]['picture'];
            }
        }
    }

    // Processing leads array
    if (!empty($leads)) {
        foreach ($leads as $lead_id => $lead_data) {
            $status = isset($lead_data['status']) ? $lead_data['status'] : 0;
            $form_ids = isset($lead_data['form_id']) ? (array) $lead_data['form_id'] : []; // Ensure it's always an array
    print_r($form_ids);
            foreach ($form_ids as $form_id) {
                // Process each selected form ID
                echo "Processing Lead ID: $lead_id, Form ID: $form_id, Status: $status <br>";
            }
        }
    }

    
    // Processing WhatsApp data
    if (!empty($whatsapps)) {
        foreach ($whatsapps as $whatsapp_id => $whatsapp_data) {
            $status = isset($whatsapp_data['status']) ? $whatsapp_data['status'] : 0;
            // Process WhatsApp data here
        }
    }
    
    // Prepare data to save in JSON
    $fb_api_data = [
        'app_id' => $app_id,
        'app_secret' => $app_secret,
        'user_access_token' => $user_access_token,
        'pages' => $pages,
        'leads' => $leads,
        'whatsapps' => $whatsapps,
    ];

    // Function to save data into fb_api_setup.json
    function save_fb_api_setup_data($data) {
        $file_path = 'fb_api_setup.json';
        
        // Convert the data to JSON format
        $json_data = json_encode($data, JSON_PRETTY_PRINT);
        
        // Check if the file exists, create it if not
        if (file_put_contents($file_path, $json_data)) {
            return true; // Successfully saved
        } else {
            return false; // Failed to save
        }
    }

    // Save data to the JSON file
    if (save_fb_api_setup_data($fb_api_data)) {
        $data = [
            'message' => 'Successfully Updated!',
            'status' => 200
        ];
    } else {
        $data = [
            'message' => 'Failed to save data.',
            'status' => 500
        ];
    }
    
    // Send JSON response back to the client
    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}