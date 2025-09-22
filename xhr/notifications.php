<?php
// Assuming $logged_user is already defined
$logged_user = $wo['user']['user_id']; // Example logged-in user ID

ini_set('display_errors', 1);
ini_set('display_startup_errors', 0);
error_reporting(1);

if ($f == 'notifications') {
    if ($s == 'fetch') {
        // Initialize data array
        $data = array(
            'status' => 500, // Default to an error status
            'notification' => '',
            'unseen_notification' => 0
        );
		
        // Prepare the response data
        $data['status'] = 200;
		
		$site_link = (isset($_POST['site'])) ? $_POST['site'] : 'management';
		
        $data['notification'] = getNotifications($logged_user, '', $site_link);
		$data['unseen_notification'] = getNotifications($logged_user, true, $site_link);

        // Output the response in JSON format
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
}

// Function to generate 'no notifications' HTML
function noNotificationHTML() {
    return '
    <a class="dropdown-item" href="javascript:;">
        <div class="d-flex align-items-center" style=" justify-content: center; flex-direction: column; padding: 55px 15px; ">
            <div class="notify text-primary mb-2">
                <ion-icon name="notifications-off-outline"></ion-icon>
            </div>
            <div class="flex-grow-1">
                <h6 class="msg-name text-center">No Notification Found</h6>
                <p class="msg-info text-center">You have no new notifications</p>
            </div>
        </div>
    </a>';
}
