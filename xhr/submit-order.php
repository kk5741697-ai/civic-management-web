<?php

if ($f == "submit-order") {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Collect POST data
        $name    = $_POST['order_name'] ?? '';
        $phone   = $_POST['order_phone'] ?? '';
        $katha   = $_POST['order_katha'] ?? '';
        $notes   = $_POST['order_notes'] ?? '';
        $project = $_POST['project'] ?? '';
        
        switch ($project) {
            case 'hill_town':
                $page_id = '259547413906965';
                $project_name = 'Civic Hill Town - New Lead';
                break;
        
            case 'moon_hill':
                $page_id = '1932174893479181';
                $project_name = 'Civic Moon Hill - New Lead';
                break;
        
            default:
                $page_id = '0';
                $project_name = 'Default Title';
                break;
        }



        // Optional: Validate inputs here
        if (empty($name) || empty($phone) || empty($katha)) {
            http_response_code(400);
            echo json_encode(['status' => 400, 'message' => 'Missing required fields']);
            exit;
        }

        // Save to file or DB
        // file_put_contents('orders.txt', "$name, $phone, $katha, $notes, $project\n", FILE_APPEND);

        // Insert to DB table T_LEADS (optional)
        $data_array = [
            'source'     => 'Website',
            'phone'      => $phone,
            'name'       => $name,
            'profession' => '',
            'company'    => '',
            'email'      => 'N/A',
            'project'    => $project,
            'additional' => json_encode(['notes' => $notes, 'katha' => $katha]),
            'created'    => time(),
            'given_date' => time(),
            'thread_id'  => 0,
            'assigned'   => 0,
            'member'     => 0,
            'page_id'    => $page_id,
            'time'       => time(),
        ];

        // Assuming $db is your DB instance
        if (!empty($db)) {
            $db->insert(T_LEADS, $data_array);
        }

        // Return success response
        header("Content-Type: application/json");
        echo json_encode(['status' => 200, 'message' => 'Order submitted successfully']);
        exit;
    } else {
        http_response_code(405); // Method Not Allowed
        echo json_encode(['status' => 405, 'message' => 'Method Not Allowed']);
        exit;
    }
}
