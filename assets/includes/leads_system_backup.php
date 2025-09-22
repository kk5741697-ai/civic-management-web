<?php
$show_import_status = true;

require_once ROOT_DIR . "assets/libraries/web-push/vendor/autoload.php";
use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;


// File path to store the VAPID keys
$vapidKeysFile = ROOT_DIR . 'vapid_keys.json';

// Check if the VAPID keys already exist
if (!file_exists($vapidKeysFile)) {
    $vapid_keys = VAPID::createVapidKeys();
    file_put_contents($vapidKeysFile, json_encode($vapid_keys));
} else {
    $vapid_keys = json_decode(file_get_contents($vapidKeysFile), true);
}

// Store VAPID keys globally
$wo['config']['vapid_public_key'] = $vapid_keys['publicKey'];
$wo['config']['vapid_private_key'] = $vapid_keys['privateKey'];

// Retrieve subscription details for a user
function get_user_subscription($user_id) {
    $subscriptionFile = ROOT_DIR . 'subscriptions.json';
    if (!file_exists($subscriptionFile)) {
        throw new Exception("Subscription file not found.");
    }

    $subscriptionData = json_decode(file_get_contents($subscriptionFile), true);
    if (!isset($subscriptionData[$user_id]) || !is_array($subscriptionData[$user_id])) {
        throw new Exception("No subscriptions found for user ID: $user_id.");
    }

    return $subscriptionData[$user_id]; // Return all subscriptions for the user
}


// Send a web push notification
function sendWebNotification($user_id, $title, $message, $url = '', $image = '') {
    Global $vapid_keys;
    try {
        $subscriptions = get_user_subscription($user_id); // Get all subscriptions for the user

        if (empty($subscriptions)) {
            error_log("No valid subscriptions found for user ID: $user_id");
            return false;
        }

        $push = new WebPush([ 
            'VAPID' => [
                'subject' => 'mailto:admin@civicgroupbd.com',
                'publicKey' => $vapid_keys['publicKey'],
                'privateKey' => $vapid_keys['privateKey'],
            ],
        ]);

        $payload_data = [
            'title' => $title,
            'body' => $message,
            'icon' => 'https://civicgroupbd.com/manage/assets/images/logo-icon-2.png',
            'badge' => 'https://civicgroupbd.com/manage/assets/images/logo-icon-2.png',
        ];

        // Set the onclic_url
        if (!empty($url)) {
            $payload_data['onclic_url'] = $url;
        } else {
            $payload_data['onclic_url'] = 'https://civicgroupbd.com/management'; // Default URL
        }

        // Set the image
        if (!empty($image)) {
            $payload_data['image'] = $image;
        }

        // Convert the payload data to JSON
        $payload = json_encode($payload_data);

        $successCount = 0;

        foreach ($subscriptions as $subscription) {
            $webPushSubscription = Subscription::create([
                'endpoint' => $subscription['endpoint'],
                'publicKey' => $subscription['keys']['p256dh'],
                'authToken' => $subscription['keys']['auth'],
            ]);

            $result = $push->sendOneNotification($webPushSubscription, $payload);

            if ($result->isSuccess()) {
                error_log("Notification sent successfully to user ID: $user_id (endpoint: {$subscription['endpoint']})");
                $successCount++;
            } else {
                error_log("Failed to send notification to endpoint: {$subscription['endpoint']}. Error: " . $result->getReason());
            }
        }

        return $successCount > 0;
    } catch (Exception $e) {
        error_log("Error: " . $e->getMessage());
        return false;
    }
}

include_once(ROOT_DIR . "assets/libraries/facebook-graph/vendor/autoload.php");
use Facebook\Facebook;
use Facebook\FacebookRequest;
use Facebook\Exceptions\FacebookResponseException;
use Facebook\Exceptions\FacebookSDKException;

$fb_connect = new Facebook([
    'app_id' => '539681107316774',
    'app_secret' => '20d756d9f811dd41ba813368f88a4cbb',
    'default_graph_version' => 'v21.0',
]);

function logDebug($message) {
    file_put_contents('debug2.log', "[" . date("Y-m-d H:i:s") . "] $message\n", FILE_APPEND);
}

function read_fb_api_setup_data() {
    $file_path = ROOT_DIR . 'fb_api_setup.json';

    // Check if the file exists
    if (!file_exists($file_path)) {
        return null; // File does not exist
    }

    // Read the JSON file
    $json_data = file_get_contents($file_path);

    // Decode the JSON data
    $data = json_decode($json_data, true);

    return $data; // Return the decoded data
}
$api_config = read_fb_api_setup_data();
if ($show_import_status == true) {
	echo '<pre style=" background: #f3f3f3; padding: 15px; border-radius: 8px; ">';
}

function getUserAssignment($orderBy = 'DESC') {
    global $db;
    
    // Get active users (team members and leaders)
    $get_users = $db->orderBy('position', $orderBy)
                    ->where('active', '1')
                    ->where('banned', '0')
                    ->where('leader_id', '0', '>')
                    ->orWhere('is_team_leader', '1')
                    ->where('active', '1')
                    ->where('banned', '0')
                    ->get(T_USERS, null, ['user_id', 'leader_id']);
    if (empty($get_users)) {
        throw new Exception("No users found for assignment.");
    }
    
    // Define the time range for the current year
    $year_start = strtotime('first day of this year 00:00:00');
    $year_end   = strtotime('last day of this year 23:59:59');
    
    // Group team members under their respective leader
    $report_leader = [];
    foreach ($get_users as $value) {
        $user_id   = $value->user_id;
        $leader_id = $value->leader_id;
        
        // Get this user's lead count for the current year
        $user_lead_count = $db->where('member', $user_id)
                              ->where('created', $year_start, '>=')
                              ->where('created', $year_end, '<=')
                              ->getValue(T_LEADS, 'COUNT(*)');
        
        // Only process team members (those with leader_id > 0)
        if ($leader_id > 0) {
            if (!isset($report_leader[$leader_id])) {
                $report_leader[$leader_id] = [
                    'leader_id'    => $leader_id,
                    'team_members' => []
                ];
            }
            $report_leader[$leader_id]['team_members'][] = [
                'user_id'    => $user_id,
                'lead_count' => $user_lead_count
            ];
        }
    }
    
    // Build the final report with each leader's total lead count.
    // Also, determine the overall selected user and leader.
    $final_report = [];
    $global_min_leads = null;
    $global_selected_user = null;
    $global_selected_leader = null;
    
    foreach ($report_leader as $leader_id => $data) {
        $total_leads = 0;
        $leader_selected_user = null;
        $leader_min_lead = null;
        
        foreach ($data['team_members'] as $member) {
            $total_leads += $member['lead_count'];
            
            // Find the team member with the lowest lead count for this leader
            if ($leader_min_lead === null || $member['lead_count'] < $leader_min_lead) {
                $leader_min_lead = $member['lead_count'];
                $leader_selected_user = $member['user_id'];
            }
        }
        
        $final_report[$leader_id] = [
            'leader_id'  => $leader_id,
            'lead_count' => $total_leads
        ];
        
        // Compare across leaders to select the overall team member and leader.
        if ($global_min_leads === null || $total_leads < $global_min_leads) {
            $global_min_leads = $total_leads;
            $global_selected_user = $leader_selected_user;
            $global_selected_leader = $leader_id;
        }
    }
    
    // Append the overall selected user and leader to the final report.
    $final_report['selected_user'] = $global_selected_user;
    $final_report['selected_leader'] = $global_selected_leader;
    
    return $final_report;
}
print_r(getUserAssignment());
function processLead($lead, $leadsData) {
    global $db;

    $rowData = [
        'phone_number'  => null,
        'full_name'     => null,
        'company_name'  => null,
        'profession'    => null,
        'email'         => null,
        'created'       => strtotime($lead['created_time']),
        'additional'    => [
            'form_id'     => $lead['form_id'] ?? 'N/A',
            'form_name'   => $leadsData['name'] ?? 'N/A',
            'page_id'     => $leadsData['page']['id'] ?? 'N/A',
            'page_name'   => $leadsData['page']['name'] ?? 'N/A',
            'thread_id'   => $lead['id'] ?? '0',
            'platform'    => $lead['platform'] ?? 'N/A',
            'ad_name'     => $lead['ad_name'] ?? 'N/A',
        ]
    ];

    // Handle field_data
    foreach ($lead['field_data'] as $field) {
        $key = $field['name'] ?? null;
        $value = $field['values'][0] ?? null;

        switch ($key) {
            case 'phone':
            case 'phone_number':
                $rowData['phone_number'] = $value;
                break;
            case 'name':
            case 'full_name':
                $rowData['full_name'] = $value;
                break;
            case 'email':
                $rowData['email'] = $value;
                break;
            case 'job_title':
                $rowData['profession'] = $value;
                break;
            case 'company':
            case 'company_name':
                $rowData['company_name'] = $value;
                break;
            case 'page_id':
                $rowData['page_id'] = $value;
                break;
            default:
                $rowData['additional'][$key] = $value;
        }
    }

    // Ensure required fields exist before proceeding
    if (empty($rowData['full_name']) || empty($rowData['phone_number'])) {
        return "Skipping lead due to missing name or phone.<br>";
    }

    // Normalize phone number (digits only)
    $phone_number = preg_replace('/[^0-9]/', '', $rowData['phone_number']);

    // Check if lead already exists
    $is_exist = $db->where('thread_id', $lead['id'])->getOne(T_LEADS, ['lead_id']);
    if ($is_exist) {
        return "Lead Already Exists: {$rowData['full_name']} ({$rowData['phone_number']}).<br>";
    }

    // Check if phone number exists
    $is_phone = $db->where('phone', $phone_number)->getOne(T_LEADS, ['assigned', 'member']);

    if ($is_phone && ($is_phone->assigned > 0 || $is_phone->member > 0)) {
        $selected_user = [
            'selected_leader' => $is_phone->assigned,
            'selected_user'   => $is_phone->member
        ];
    } else {
        $selected_user = getUserAssignment();
    }

    // Prepare lead data for insertion
    $data_array = [
        'source'     => 'Facebook',
        'phone'      => $phone_number,
        'name'       => $rowData['full_name'],
        'profession' => $rowData['profession'] ?? '',
        'company'    => $rowData['company_name'] ?? '',
        'email'      => $rowData['email'] ?? 'N/A',
        'additional' => json_encode($rowData['additional']),
        'created'    => $rowData['created'],
        'given_date' => $rowData['created'],
        'thread_id'  => $lead['id'],
        'assigned'   => $selected_user['selected_leader'] ?? 0,
        'member'     => $selected_user['selected_user'] ?? 0,
        'page_id'    => $leadsData['page']['id'] ?? '0',
        'time'       => time(),
    ];

    // Determine project name based on page_id
    switch ($data_array['page_id']) {
        case '259547413906965':
            $project_name = 'Civic Hill Town - New Lead';
            break;
        case '1932174893479181':
            $project_name = 'Civic Moon Hill - New Lead';
            break;
        default:
            $project_name = 'Default Title';
    }

    // Start transaction
    $db->startTransaction();

    try {
        // Insert notification
        $notification_user = $selected_user['selected_user'] > 0 ? $selected_user['selected_user'] : $selected_user['selected_leader'];
        $insert_notif = $db->insert(NOTIFICATION, [
            'subject' => 'Lead: ' . $rowData['full_name'],
            'comment' => 'You have new leads from ' . $project_name,
            'type'    => 'leads',
            'url'     => '/management/leads?lead_id=' . $lead['id'],
            'user_id' => $notification_user
        ]);

        if (!$insert_notif) {
            throw new Exception("Failed to insert notification.");
        }

        // Insert lead
        $insert_lead = $db->insert(T_LEADS, $data_array);

        if (!$insert_lead) {
            throw new Exception("Failed to insert lead.");
        }

        // Commit transaction if everything is successful
        $db->commit();

        // Send web notification
        sendWebNotification($notification_user, $rowData['full_name'], "You have new leads from $project_name", 'https://civicgroupbd.com/management/leads');

        return "Lead: {$rowData['full_name']} ({$rowData['phone_number']}) added.<br>";
    } catch (Exception $e) {
        // Rollback if any insert fails
        $db->rollback();
        return "Error: " . $e->getMessage();
    }
}

        $result = '';
        global $db;
        
        foreach ($api_config['leads'] as $page_id => $config) {
            if ($config['status'] == true) { //this mean import leads true
                $pageConfig = $api_config['pages'][$page_id];
                $pageAccessToken = $pageConfig['access_token'];
                
                // Prepare the batch request
                $requests = [];
                
                foreach ($config['form_id'] as $form_id) {
                    $requests[] = $fb_connect->request('GET', "/$form_id?fields=created_time,leads_count,page,page_id,organic_leads_count,name,leads.limit(200){ad_name,adset_id,adset_name,campaign_id,created_time,campaign_name,field_data,form_id,id,home_listing,partner_name,is_organic,platform,post,retailer_item_id,ad_id,vehicle},id,status,thank_you_page,tracking_parameters,test_leads.limit(10){field_data,form_id,home_listing,campaign_name,created_time,campaign_id,id,adset_name,is_organic,partner_name,platform,adset_id,post,ad_name,retailer_item_id,ad_id,vehicle},block_display_for_non_targeted_viewer");
                }
                try {
                    // Send the batch request
                    $responses = $fb_connect->sendBatchRequest($requests, $pageAccessToken)->getDecodedBody();
                    
                    if ($responses) {
                        foreach ($responses as $response) {
                            $leadsData = json_decode($response['body'], true);
                            if (!empty($leadsData['leads']['data'])) {
                                $processed_leads = [];
                                foreach ($leadsData['leads']['data'] as $lead) {
                                    $lead_id = $lead['id'] ?? null;
                                    
                                    if (isset($processed_leads[$lead_id])) {
                                        continue; // Skip already processed leads
                                    }
                                    
                                    $processed_leads[$lead_id] = true;
                                    $result .= processLead($lead, $leadsData);
                                }
                				
                            } else {
                				if ($show_import_status == true) {
                					echo "No leads found.";
                				}
                            }
                        }
                    }
                    
                } catch (Facebook\Exceptions\FacebookResponseException $e) {
                    logDebug("Graph API error: " . $e->getMessage());
                } catch (Facebook\Exceptions\FacebookSDKException $e) {
                    logDebug("Facebook SDK error: " . $e->getMessage());
                } catch (Exception $e) {
                    logDebug("General error: " . $e->getMessage());
                }
            }
            
        }




if ($show_import_status == true) {
	echo '</pre>';
}

?>