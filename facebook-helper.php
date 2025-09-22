<?php
// facebook-helper.php
require_once('assets/init.php');
$VERIFY_TOKEN = "mr1aminul";  // Your verify token
$own_pages = array('1932174893479181');

// Subscription Fields
$subscribedFields = [
    'messaging_postbacks',
    'messaging_referrals',
    'leadgen',
    'messages',
    'message_reads',
    'comments',
];

// Verify token for GET request
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['hub_verify_token'])) {
    if ($_GET['hub_verify_token'] === $VERIFY_TOKEN) {
        echo $_GET['hub_challenge'];
        exit;
    } else {
        http_response_code(403);
        echo "Invalid verify token";
        exit;
    }
}

// Handle POST events from Facebook
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    // file_put_contents('debug.log', $rawInput, FILE_APPEND); // Log raw data

    $input = json_decode($rawInput, true);

    if ($input) {
        // file_put_contents('debug.log', print_r($input, true), FILE_APPEND); // Log structured data

        if (isset($input['entry'])) {
            foreach ($input['entry'] as $entry) {
                if (isset($entry['messaging'])) {
                    foreach ($entry['messaging'] as $message) {
                        if (isset($message['message']) && !isset($message['delivery']) && !isset($message['read'])) {
                            processEvent('messages', $message);
                        }
                        if (isset($message['postback'])) {
                            processEvent('messaging_postbacks', $message['postback']);
                        }
                        if (isset($message['referral'])) {
                            processEvent('messaging_referrals', $message['referral']);
                        }
                        $proc = processThreadIds();
                    }
                }

                if (isset($entry['leadgen'])) {
                    processEvent('leadgen', $entry['leadgen']);
                }

                if (isset($entry['changes'])) {
                    foreach ($entry['changes'] as $change) {
                        if ($change['value']['item'] === 'comment') {
                            handleNewComments($change['value']);
                        }
                    }
                }
            }
        }
    }

    // Respond with a 200 status code
    http_response_code(200);
    flush();
    ob_flush();
} else {
    http_response_code(405);
    echo "Method Not Allowed";
    // file_put_contents('debug.log', "Method Not Allowed at: " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);
}

// Function to post a reply to a comment on Facebook with a mention
function post_comment_reply($comment_id, $message) {
    global $pageAccessToken;

    sleep(rand(3, 4)); // Delay

    $url = "https://graph.facebook.com/v21.0/{$comment_id}/comments";
    $params = [
        'message' => $message,
        'access_token' => $pageAccessToken,
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded_response = json_decode($response, true);
    if ($http_code !== 200) {
        file_put_contents('debug.log', "Error posting comment: HTTP $http_code - " . print_r($decoded_response, true) . "\n", FILE_APPEND);
    }
    return $decoded_response;
}

// Function to send a private message
function send_private_message($user_id, $message) {
    global $pageAccessToken;

    $url = "https://graph.facebook.com/v21.0/me/messages";
    $params = [
        'recipient' => json_encode(['id' => $user_id]),
        'message' => json_encode(['text' => $message]),
        'access_token' => $pageAccessToken,
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded_response = json_decode($response, true);
    if ($http_code !== 200) {
        file_put_contents('debug.log', "Error sending private message: HTTP $http_code - " . print_r($decoded_response, true) . "\n", FILE_APPEND);
    }
    return $decoded_response;
}
// echo '<br />';
// echo '<br />';

// 1164026622066054_959650403022013
// print_r(send_private_message('9370875049639538', 'Assalamu Alaikum'));
// exit();

// Function to handle new comments and post a reply with a mention
function handleNewComments($data) {
    global $own_pages;
    
    // file_put_contents('debug.log', "New Comment Event: " . print_r($data, true), FILE_APPEND);

    // Check if the verb is "add" (ignore other actions like "remove")
    if ($data['verb'] !== 'add') {
        // file_put_contents('debug.log', "Skipping comment as verb is not 'add': " . $data['verb'] . "\n", FILE_APPEND);
        return;
    }

    $commenterId = $data['from']['id']; // The user who commented
    $commenterName = $data['from']['name']; // The user who commented
    $commentText = $data['message'];    // The text of the comment
    $commentId = $data['comment_id'];   // The comment ID

    // Extract page name based on 'to' field
    if (in_array($commenterId, $own_pages)) {
        return;
    }
    
    // Check if the comment has already been processed
    $processedComments = json_decode(file_get_contents('processed_comments.json'), true) ?: [];
    if (in_array($commentId, $processedComments)) {
        // file_put_contents('debug.log', "Skipping duplicate comment: $commentId\n", FILE_APPEND);
        return;
    }

    // Mark this comment as processed
    $processedComments[] = $commentId;
    file_put_contents('processed_comments.json', json_encode($processedComments));

    // Generate a reply based on the comment text
    $replyData = get_reply_text($commentText);

    // Get the user's name to mention in the reply
    $user_name = ''; // Initialize user name variable

    if (isset($commenterName)) {
        $user_name = $commenterName;
        // file_put_contents('debug.log', "Commenter name is: $user_name\n", FILE_APPEND);
    }

    // Post a reply to the comment if a reply message is available
    if ($commentId && !empty($replyData['comment_reply'])) {
        // Mention the user in the reply
        $mention_message = "@[{$commenterId}] {$replyData['comment_reply']}";
        post_comment_reply($commentId, $mention_message);
    }

    // Send a private message if a private message is available
    if (!empty($replyData['private_message'])) {
        send_private_message($commenterId, $replyData['private_message']);
    }

    // React to the comment if a reaction is specified
    react_to_comment($commentId, $replyData['reaction']);
}
function react_to_comment($commentId, $reaction = 'like') {
    global $pageAccessToken;
    
    // Add delay to prevent rate-limiting
    sleep(rand(5, 7));

    // Set the URL for the Graph API endpoint to add a reaction (use /reactions for non-LIKE reactions)
    $url = "https://graph.facebook.com/v21.0/{$commentId}/likes";
    
    // Set the POST data for the request
    $data = [
        'type' => 'LOVE', // Reaction type (e.g., LIKE, LOVE, WOW, etc.)
        'access_token' => $pageAccessToken
    ];
    
    // Initialize cURL session
    $ch = curl_init();
    
    // Set cURL options
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    
    // Execute the request and capture the response
    $response = curl_exec($ch);
    
    // Handle any cURL errors
    if (curl_errno($ch)) {
        echo 'cURL Error: ' . curl_error($ch);
        return false;
    }
    
    // Close the cURL session
    curl_close($ch);
    
    // Decode the response to check for success
    $responseData = json_decode($response, true);
    
    // Check if the reaction was successfully added
    if (isset($responseData['id'])) {
        echo "Successfully added reaction: " . $responseData['id'];
        return true;
    } else {
        echo "Error: Unable to add reaction.";
        return false;
    }
}
function handleNewMessage($messageData) {
    global $db, $fb_connect, $pageAccessToken;

    // Helper function for debug logging
    function logDebug($message) {
        // file_put_contents('debug2.log', "[" . date("Y-m-d H:i:s") . "] $message\n", FILE_APPEND);
    }

    // Extract necessary data from the incoming message
    $senderId = $messageData['sender']['id'] ?? null;
    $recipientId = $messageData['recipient']['id'] ?? null;
    $messageText = $messageData['message']['text'] ?? '';
    $messageId = $messageData['message']['mid'] ?? '';
    $timestamp = $messageData['timestamp'] ?? time();

    if (!$messageId) {
        logDebug("Message ID missing. Aborting.");
        return;
    }

    // Prepare the batch request
    $requests = [
        $fb_connect->request('GET', "/$messageId?fields=thread_id,from,to")
    ];

    try {
        // Send the batch request
        $responses = $fb_connect->sendBatchRequest($requests, $pageAccessToken)->getDecodedBody();

        if (isset($responses[0]['body'])) {
            //logDebug("Raw response body: " . print_r($responses[0], true));

            if (isset($responses[0]['error'])) {
                logDebug("Error in batch response: " . print_r($responses[0]['error'], true));
            } else {
                // Process thread data
                $threadData = json_decode($responses[0]['body'], true);

                if (isset($threadData['thread_id'])) {
                    $thread_id = $threadData['thread_id'];
                    //logDebug("Thread ID extracted: $thread_id");

                    // Ensure thread_id is unique before adding to file
                    $filePath = 'thread_ids.json';

                    // Read existing thread IDs from the JSON file
                    $existingThreadIds = [];
                    if (file_exists($filePath)) {
                        $existingThreadIds = json_decode(file_get_contents($filePath), true) ?? [];
                    }

                    // Check if the thread ID already exists
                    if (!in_array($thread_id, $existingThreadIds)) {
                        // Add thread ID to the array and write back to the file
                        $existingThreadIds[] = $thread_id;
                        file_put_contents($filePath, json_encode($existingThreadIds, JSON_PRETTY_PRINT));
                        //logDebug("Thread ID $thread_id added to the JSON file.");
                    } else {
                        //logDebug("Thread ID $thread_id already exists. Skipping.");
                    }
                } else {
                    //logDebug("Thread data not found in response.");
                }
            }
        } else {
            //logDebug("No body in batch response.");
        }
        
    } catch (Facebook\Exceptions\FacebookResponseException $e) {
        logDebug("Graph API error: " . $e->getMessage());
    } catch (Facebook\Exceptions\FacebookSDKException $e) {
        logDebug("Facebook SDK error: " . $e->getMessage());
    } catch (Exception $e) {
        logDebug("General error: " . $e->getMessage());
    }
}
function processThreadIds($filePath = 'thread_ids.json') {
    global $db, $fb_connect, $pageAccessToken, $own_pages;

    //sleep(6);
    
    // File to track execution state
    $stateFile = 'execution_state.json';

    // Helper function for debug logging
    function logDebug2($message) {
        // file_put_contents('debug2.log', "[" . date("Y-m-d H:i:s") . "] $message\n", FILE_APPEND);
    }

    file_put_contents('debug3.log', "Webhook triggered inside function\n", FILE_APPEND);
    
    // Load execution state
    $state = [];
    if (file_exists($stateFile)) {
        $state = json_decode(file_get_contents($stateFile), true) ?? [];
    }

    $isExecuting = $state['is_executing'] ?? false;
    $lastExecutionTime = $state['last_execution_time'] ?? 0;
    $currentTime = time();

    // Check if it's been less than 2 seconds since the last execution
    if ($currentTime - $lastExecutionTime < 2) {
        logDebug2("Less than 2 seconds since the last execution. Aborting.");
        return;
    }

    if ($isExecuting) {
        logDebug2("Process already executing. Aborting.");
        return;
    }

    // Update state to mark as executing and set last execution time
    $state['is_executing'] = true;
    $state['last_execution_time'] = $currentTime;
    file_put_contents($stateFile, json_encode($state));

    // Check if the file exists
    if (!file_exists($filePath)) {
        file_put_contents($filePath, json_encode([])); // Initialize empty array in JSON format
        $state['is_executing'] = false;
        file_put_contents($stateFile, json_encode($state));
        return;
    }

    // Read thread IDs from the JSON file
    $threadIds = json_decode(file_get_contents($filePath), true) ?? [];

    if (empty($threadIds)) {
        logDebug2("No thread IDs found in the file.");
        $state['is_executing'] = false;
        file_put_contents($stateFile, json_encode($state));
        return;
    }

    logDebug2("Processing " . count($threadIds) . " thread IDs.");

    // Prepare to batch requests in chunks of 10
    $chunkSize = 10;
    $requests = [];
    $processedThreadIds = [];
    
    // Split thread IDs into chunks of 10
    $threadChunks = array_chunk($threadIds, $chunkSize);
    
    // Process each chunk of requests
    foreach ($threadChunks as $chunk) {
        $requests = [];
        foreach ($chunk as $threadId) {
            logDebug2("Preparing batch request for thread ID: $threadId");
            $requests[] = $fb_connect->request('GET', "/$threadId?fields=messages{message,from,to,created_time,id,sticker,attachments.limit(1){generic_template,id,image_data,name,size,video_data,file_url}},can_reply,message_count,unread_count,updated_time");
        }

        try {
            // Send the batch request for the current chunk
            $responses = $fb_connect->sendBatchRequest($requests, $pageAccessToken)->getDecodedBody();

            $msg_type = []; // Initialize an empty array for message types
            $message_text = [];
            $is_good = false;
            $is_phone = false;
                
            foreach ($responses as $index => $response) {
                $threadId = $chunk[$index];
                $threadIdRf = str_replace('t_', '', $threadId);

                if (isset($response['body'])) {
                    $messageData = json_decode($response['body'], true);

                    logDebug2("Response for thread ID $threadId: " . print_r($messageData, true));

                    // Check for existing thread and message
                    $existingThread = $db->where('thread_id', $threadIdRf)->getOne(T_U_CHATS, ['thread_id','id']);
                    $can_reply = $messageData['can_reply'];
                    $message_count = $messageData['message_count'];
                    $unread_count = $messageData['unread_count'];
                    $updated_time = strtotime($messageData['updated_time']);
                    
                    if ($messageData['messages']['data']) {
                        $pageName = '';
                        foreach ($messageData['messages']['data'] as $msg) {
                            // Extract 'from' and 'to' and make sure these fields are valid
                            $from = isset($msg['from']) ? $msg['from'] : null;
                            $to = isset($msg['to']['data']) ? $msg['to']['data'][0] : null;

                            // Check if 'from' and 'to' are valid and extract the 'id'
                            $fromId = $from['id'] ?? '';
                            $fromName = $from['name'] ?? '';

                            $toId = isset($to['id']) ? $to['id'] : '';

                            // Extract page name based on 'to' field
                            if ($to && isset($to['id'])) {
                                $pageName = !in_array($toId, $own_pages) ? $to['name'] : $fromName;
                                $pageId = !in_array($toId, $own_pages) ? $to['id'] : $fromId;
                            }

                            // Check if message already exists in the database
                            $existingMessage = $db->where('msg_id', $msg['id'])->getOne(T_MESSAGES, ['msg_id']);
                            if ($existingMessage) {
                                logDebug2("Duplicate message skipped: " . $msg['id']);
                                continue;
                            }

                            // Process media attachments
                            $attachments = $msg['attachments']['data'] ?? [];
                            $mediaFilename = '';
                            $mediaName = '';

                            if (!empty($attachments)) {
                                foreach ($attachments as $attachment) {
                                    $video_data = $attachment['video_data'];
                                    if (isset($video_data['url'])) {
                        				$mediaFilename = Wo_ImportFileFromUrl($video_data['url'], $fileend);
                                        $mediaName = isset($attachment['name']) ? $attachment['name'] : '';
                                        break;
                                    }
                                    $image_data = $attachment['image_data'];
                                    if (isset($image_data['url'])) {
                        				$mediaFilename = Wo_ImportFileFromUrl($image_data['url'], $fileend);
                                        $mediaName = isset($attachment['name']) ? $attachment['name'] : '';
                                        break;
                                    }
                                    $file_data = $attachment;
                                    if (isset($file_data['file_url'])) {
                        				$mediaFilename = Wo_ImportFileFromUrl($file_data['url'], $fileend);
                                        $mediaName = isset($attachment['name']) ? $attachment['name'] : '';
                                        break;
                                    }
                                }
                            }
                            
                            
                            // Insert message data
                            $msgData_array = [
                                'from_id' => Wo_Secure($fromId, 0),
                                'to_id' => Wo_Secure($toId, 0),
                                'page_id' => Wo_Secure($pageId, 0),
                                'msg_id' => Wo_Secure($msg['id'], 0),
                                'thread_id' => Wo_Secure($threadIdRf, 0),
                                'text' => Wo_Secure($msg['message'], 1),
                                'media' => Wo_Secure($mediaFilename, 0),
                                'mediaFileName' => Wo_Secure($mediaName, 0),
                                'time' => strtotime($msg['created_time']),
                                'stickers' => (isset($msg['sticker']) && Wo_IsUrl($msg['sticker'])) ? $msg['sticker'] : '',
                            ];
                            
                            // Save message in the database
                            $db->insert(T_MESSAGES, $msgData_array);
                            
                            // Detect specific keywords (phone number, email, katha, etc.)
                            $detected_keywords = detect_keywords($msg['message']);
                            
                            if (!in_array($fromId, $own_pages) && $msg['message']) {
                                
                                if (isset($detected_keywords['phone_number']) && $detected_keywords['phone_number']) {
                                    $is_phone = true;
                                }
                                
                                if (isset($detected_keywords['type'])) {
                                    $msg_type[] = $detected_keywords['type'];
                                }
                                if (isset($msg['message'])) {
                                    $message_text[] = $msg['message'];
                                }
                                
                                // Check each detected keyword and insert metadata
                                foreach ($detected_keywords as $name => $value) {
                                    // Before inserting, check if the key already exists for the thread_id
                                    $existing = $db->where('thread_id', $threadIdRf)->where('name', $name)->getOne(T_MESSAGES_META);
                                    
                                    if (!empty($existing)) {
                                        $db->where('name', Wo_Secure($name, 0))->update(T_MESSAGES_META, [
                                            'value' => Wo_Secure(implode(', ', (array)$value), 0)
                                        ]);
                                    } else {
                                        $db->insert(T_MESSAGES_META, [
                                            'thread_id' => Wo_Secure($threadIdRf, 0),
                                            'name' => Wo_Secure($name, 0),
                                            'value' => Wo_Secure(implode(', ', (array)$value), 0)
                                        ]);
                                    }
                                }
                            }
                        }
                        
                        $first_msg_type = $msg_type[0] ?: 'get_msg';
                        $last_msg_type = end($msg_type) ?: 'get_msg';
                        $first_message_text = $message_text[0] ?: '';
                        $last_message_text = end($message_text) ?: '';

                        if (!$existingThread) {
                            
                            $get_userId = insertMessageAssignment($threadIdRf, $pageId, $message_count, $unread_count, $updated_time, $is_phone, $first_msg_type);
                            $senderProfile = $db->where('page_id', $pageId)->getOne(T_PAGES, ['page_id']);
                            
                            if (!$senderProfile) {
                                logDebug2("Sender profile not found, fetching from Facebook...");
                                $senderProfile_array = array(
                                    'page_id' => Wo_Secure($pageId, 0),
                                    'page_name' => Wo_Secure($pageName, 1),
                                    'page_title' => Wo_Secure($pageName, 1),
                                    'page_description' => 'Civic Real Estate Ltd',
                                    'page_category' => 'Land',
                                    'user_id' => '1',
                                    'active' => '1',
                                    'time' => strtotime($msg['created_time'])
                                );
                                
                                $db->insert(T_PAGES, $senderProfile_array);
                            }
                            $get_userId = is_numeric($get_userId) ? $get_userId : 1;
                            if (!empty($last_message_text)) {
                                sendWebNotification($get_userId, $pageName, $last_message_text, 'https://civicgroupbd.com/management/messenger?thread=' . $threadIdRf);
                            }
                        } else {
                            $assign_updateArr = [];
                            $chat_updateArr = [
                                'message_count' => $message_count,
                                'unread_count' => $unread_count,
                                'time' => $updated_time
                            ];
                            
                            if ($is_phone) {
                                $assign_updateArr['is_phone'] = $is_phone;
                                // $chat_updateArr['is_phone'] = $is_phone;
                            }
                            
                            
                            // Update existing thread data
                            $db->where('thread_id', $threadIdRf)->update(T_U_CHATS, $chat_updateArr);
                            
                            //Send notification
                            if (!empty($last_message_text)) {
                                $get_userId = MessageAssignmentuser($threadIdRf);
                                $get_userId = is_numeric($get_userId) ? $get_userId : 1;
                                sendWebNotification($get_userId, $pageName, $last_message_text, 'https://civicgroupbd.com/management/messenger?thread=' . $threadIdRf);
                            }                        }
                    } else {
                        logDebug2("No messages found for thread ID $threadId.");
                    }

                    $processedThreadIds[] = $threadId; // Add to processed list
                } elseif (isset($response['error'])) {
                    logDebug2("Error for thread ID $threadId: " . print_r($response['error'], true));
                }
            }
        } catch (Exception $e) {
            logDebug2("Error during batch request for chunk: " . $e->getMessage());
        }
    }
    // Remove processed thread IDs from the file
    $remainingThreadIds = array_diff($threadIds, $processedThreadIds);
    file_put_contents($filePath, json_encode($remainingThreadIds, JSON_PRETTY_PRINT));
    
    // Reset execution state
    $state['is_executing'] = false;
    file_put_contents($stateFile, json_encode($state));
    logDebug2("Execution state reset.");
}
    file_put_contents('debug3.log', "Webhook triggered outside function\n", FILE_APPEND);
?>
