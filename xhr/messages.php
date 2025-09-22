<?php
if ($f == 'messages') {
	Global $fb_connect, $pageAccessToken;

    if ($s == 'get_user_messages') {
        if (!empty($_GET['user_id']) AND is_numeric($_GET['user_id']) AND $_GET['user_id'] > 0 && Wo_CheckMainSession($hash_id) === true) {
            $html       = '';
            $user_id    = $_GET['user_id'];
            $can_replay = true;
            $recipient  = Wo_UserData($user_id);
            $messages   = Wo_GetMessages(array(
                'user_id' => $user_id,
                'type' => 'user'
            ));
            if (!empty($recipient['user_id']) && $recipient['message_privacy'] == 1) {
                if (Wo_IsFollowing($wo['user']['user_id'], $recipient['user_id']) === false) {
                    $can_replay = false;
                }
            } elseif (!empty($recipient['user_id']) && $recipient['message_privacy'] == 2) {
                $can_replay = false;
            }
            foreach ($messages as $wo['message']) {
                $wo['message']['color'] = Wo_GetChatColor($wo['user']['user_id'], $recipient['user_id']);
                $html .= Wo_LoadManagePage('messenger/messages-text-list');
            }
            $_SESSION['chat_active_background']         = $recipient['user_id'];
            $_SESSION['session_active_page_background'] = 0;
            $wo['chat']['color']                        = Wo_GetChatColor($wo['user']['user_id'], $recipient['user_id']);
            $data                                       = array(
                'status' => 200,
                'html' => $html,
                'can_replay' => $can_replay,
                'view_more_text' => $wo['lang']['view_more_messages'],
                'video_call' => 0,
                'audio_call' => 0,
                'color' => $wo['chat']['color'],
                'block_url' => $recipient['url'] . '?block_user=block&redirect=messages',
                'url' => $recipient['url'],
                'avatar' => $recipient['avatar']
            );
            $data['lastseen']                           = '';
            if ($wo['config']['user_lastseen'] == 1 && $recipient['showlastseen'] != 0) {
                $data['lastseen'] = Wo_UserStatus($recipient['user_id'], $recipient['lastseen']);
            }
            if ($wo['config']['video_chat'] == 1) {
                if ($recipient['lastseen'] > time() - 60) {
                    $data['video_call'] = 200;
                }
            }
            if ($wo['config']['audio_chat'] == 1) {
                if ($recipient['lastseen'] > time() - 60) {
                    $data['audio_call'] = 200;
                }
            }
            $attachments      = Wo_GetLastAttachments($user_id);
            $attachments_html = '';
            if (!empty($attachments)) {
                foreach ($attachments as $key => $value) {
                    $attachments_html .= '<li data-href="' . $value . '" onclick="Wo_OpenLighteBox(this,event);"><span><img src="' . $value . '"></span></li>';
                }
            }
            $data['attachments_html'] = $attachments_html;
            $data['messages_count']   = Wo_CountMessages(array(
                'new' => false,
                'user_id' => $user_id
            ));
            $data['posts_count']      = $recipient['details']['post_count'];
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'get_group_messages' && isset($_GET['group_id']) && is_numeric($_GET['group_id']) && $_GET['group_id'] > 0 && Wo_CheckMainSession($hash_id)) {
        $html     = '';
        $group_id = $_GET['group_id'];
        $messages = Wo_GetGroupMessages(array(
            'group_id' => $group_id
        ));
        $onclick  = "Wo_ExitGroupChat";
        if (Wo_IsGChatOwner($group_id)) {
            $onclick = "Wo_DeleteGroupChat";
        }
        @Wo_UpdateGChatLastSeen($group_id);
        foreach ($messages as $wo['message']) {
            $html .= Wo_LoadManagePage('messenger/group-text-list');
        }
        $data = array(
            'status' => 200,
            'html' => $html,
            'view_more_text' => $wo['lang']['view_more_messages'],
            'onclick' => $onclick
        );
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'get_page_messages' && !empty($_GET['from_user_id']) && !empty($_GET['page_id'])) {
		// Define the start of "today" or a custom timestamp
		$todayStart = strtotime('today');
        $thread_id  = $_GET['thread_id'];
        $page_id	= Wo_Secure($_GET['page_id']);
		$import_status = "Unknown, Function are disabled.";
		
        $html               = '';
        $page               = Wo_PageData($page_id);
		$assigned_id        = $db->where('thread_id', $thread_id)->where('page_id', $page_id)->getOne(T_U_CHATS, array('assigned'));
        $assign_details     = $db->where('thread_id', $thread_id)->where('user_id', $assigned_id->assigned)->getOne(T_MESSAGES_ASSIGN, array('msg_type'));
		    
		if ($assigned_id && $assigned_id->assigned != '999') {
			$assigned_user = Wo_UserData($assigned_id->assigned);
		} else {
			$assigned_user = false;
		}
		
        $user_id                                    = $_GET['from_user_id'];
        $_SESSION['chat_active_background']         = 0;
        $_SESSION['session_active_page_background'] = $page_id . '_' . $user_id;
        $messages                                   = Wo_GetPageMessages(array(
            'page_id' => $page_id,
            'thread_id' => $thread_id
        ));
        foreach ($messages as $wo['message']) {
            $html .= Wo_LoadManagePage('messenger/page-chat-list');
        }
        $data = array(
            'status' => 200,
            'avatar' => $page['avatar'],
            'user_name' => $page['name'],
            'thread_id' => $thread_id,
            'profile_link' => 'https://www.facebook.com/demo_profile_link',
            'import_status' => $import_status,
            'html' => $html,
            'view_more_text' => $wo['lang']['view_more_messages']
        );
        
        if (check_permission('manage-messages') || Wo_IsAdmin()) {
            if ($assigned_user) {
    			$data['assigned_user'] = 'Assigned to: <span class="assigned_user"><img style="width: 21px;height: 21px;min-width: auto;margin-right: 1px;" alt="' . $assigned_user['name'] . '" src="' . $assigned_user['avatar'] . '"> ' . $assigned_user['name'] . '</span>';
    			$data['assigned_user'] .= ' * Type: ' . $assign_details->msg_type;
    		} else {
    			$data['assigned_user'] = '<span class="assigned_user">N/A</span> * Type: ' . $assign_details->msg_type;
    		}
        } else {
            $data['assigned_user'] = 'Type: ' . $assign_details->msg_type;
        }
        
		
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'send_message') {
		
		if ($wo['user']['message_privacy'] != 2) {
			$reply_id = 0;
			$story_id = 0;
			
			if (isset($_POST['page_id']) && is_numeric($_POST['page_id'])) {
				$page_data = Wo_PageData($_POST['page_id']);
				$invalid_file = 1;

				if (!empty($page_data)) {
					$html = '';
					$media = '';
					$mediaFilename = '';
					$mediaName = '';
					$invalid_file = 0;

					// File handling
					if (isset($_FILES['sendMessageFile']['name'])) {
						if ($_FILES['sendMessageFile']['size'] > $wo['config']['maxUpload']) {
							$invalid_file = 1; // File size is too large
						} else if (Wo_IsFileAllowed($_FILES['sendMessageFile']['name'], $_FILES["sendMessageFile"]["type"]) == false) {
							$invalid_file = 2; // Unsupported file type
						} else {
							$fileInfo = array(
								'file' => $_FILES["sendMessageFile"]["tmp_name"],
								'name' => $_FILES['sendMessageFile']['name'],
								'size' => $_FILES["sendMessageFile"]["size"],
								'type' => $_FILES["sendMessageFile"]["type"]
							);
							$media = Wo_ShareFile2($fileInfo);
							$mediaFilename = $media['filename'];
							$mediaName = $media['name'];
						}
					} else if (!empty($_POST['record-file']) && !empty($_POST['record-name'])) {
						$mediaFilename = $_POST['record-file'];
						$mediaName = $_POST['record-name'];
					}

					$message_text = !empty($_POST['textSendMessage']) ? $_POST['textSendMessage'] : '';
					
					$send_from = Wo_Secure($_POST['from_id']);
					$send_to = Wo_Secure($_POST['page_id']);
					$thread_id = Wo_Secure($_POST['thread_id']);

					// If reply id exists, set it
					$reply_id = !empty($_POST['reply_id']) && is_numeric($_POST['reply_id']) && $_POST['reply_id'] > 0 ? Wo_Secure($_POST['reply_id']) : 0;

					// Prepare message data
					$msg_data = array("recipient_id" => $send_to);

					if (!empty($message_text)) {
						$msg_data['text'] = Wo_Secure(str_replace('<br>', "\n", $message_text), 0);
					}

					// Handle media types
					if ($mediaFilename) {
						$mediaFilename_ext = strtolower(pathinfo($mediaFilename, PATHINFO_EXTENSION));
						$valid_image_ext = ['jpg', 'jpeg', 'png', 'gif'];
						$valid_video_ext = ['mp4', 'avi', 'mov'];

						if (in_array($mediaFilename_ext, $valid_image_ext)) {
							$msg_type = 'image';
						} elseif (in_array($mediaFilename_ext, $valid_video_ext)) {
							$msg_type = 'video';
						} else {
							$msg_type = 'file'; // Default to file
						}
						$msg_data['url'] = Wo_Secure($mediaFilename, 0);
					} else {
						$msg_type = 'text'; // No media, text message
					}

					// Send message
					$response = sendFacebookMessage($msg_type, "t_" . $thread_id, $msg_data);

					if (isset($response['response']['message_id'])) {
						$data_array = array(
							'from_id' => Wo_Secure($send_from, 0),
							'page_id' => Wo_Secure($send_to, 0),
							'to_id' => Wo_Secure($send_to, 0),
							'thread_id' => $thread_id,
							'msg_id' => Wo_Secure($response['response']['message_id'], 0),
							'text' => Wo_Secure($message_text, 0),
							'media' => Wo_Secure($mediaFilename, 0),
							'mediaFileName' => Wo_Secure($mediaName, 0),
							'time' => time(),
							'reply_id' => $reply_id
						);

						$last_id = Wo_RegisterPageMessage($data_array, time(), 0, 0);

						ob_clean(); // Optional, ensure this is needed

						if ($last_id && $last_id > 0) {
							$messages = Wo_GetPageMessages(array(
								'id' => $last_id,
								'page_id' => $_POST['page_id']
							));
							foreach ($messages as $wo['message']) {
								$html .= Wo_LoadManagePage('messenger/page-chat-list');
							}

							// Prepare response data
							$data = array(
								'status' => 200,
								'html' => $html,
								'file' => isset($_FILES['sendMessageFile']['name']),
								'invalid_file' => $invalid_file
							);
						}
					} else {
						$data = array(
							'status' => 500,
							'invalid_file' => 'Message could not be sent. Please try again.',
							'error' => $response,
						);
					}

					// Handle invalid file case
					if ($invalid_file > 0 && empty($last_id)) {
						$data['status'] = 500;
						$data['invalid_file'] = $invalid_file;
					}
				}
			} else {
				$data = array('status' => 400); // Missing page_id
			}
		} else {
			$data = array('status' => 400); // Message privacy not allowed
		}

		header("Content-type: application/json");
		echo json_encode($data);
		exit();
    }
    if ($s == 'register_message_record') {
        if (isset($_POST['audio-filename']) && isset($_FILES['audio-blob']['name'])) {
            $fileInfo       = array(
                'file' => $_FILES["audio-blob"]["tmp_name"],
                'name' => $_FILES['audio-blob']['name'],
                'size' => $_FILES["audio-blob"]["size"],
                'type' => $_FILES["audio-blob"]["type"]
            );
            $media          = Wo_ShareFile2($fileInfo);
            $data['url']    = $media['filename'];
            $data['status'] = 200;
            $data['name']   = $media['name'];
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'upload_record') {
        if (isset($_POST['audio-filename']) && isset($_FILES['audio-blob']['name'])) {
            $fileInfo       = array(
                'file' => $_FILES["audio-blob"]["tmp_name"],
                'name' => $_FILES['audio-blob']['name'],
                'size' => $_FILES["audio-blob"]["size"],
                'type' => $_FILES["audio-blob"]["type"]
            );
            $media          = Wo_ShareFile2($fileInfo);
            $data['status'] = 200;
            $data['url']    = $media['filename'];
            $data['name']   = $media['name'];
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'load_previous_messages') {
        $html = '';
        if (!empty($_GET['page_id']) && !empty($_GET['from_id']) && !empty($_GET['before_message_id']) && !empty($_GET['thread_id'])) {
            $page_id           = Wo_Secure($_GET['page_id']);
            $page_tab          = Wo_PageData($page_id);
            $before_message_id = Wo_Secure($_GET['before_message_id']);
            $thread_id			= Wo_Secure($_GET['thread_id']);
            $messages          = Wo_GetPageMessages(array(
                'page_id' => $page_id,
                'offset' => $before_message_id,
                'old' => true,
                'from_id' => $page_tab['page_id'],
                'thread_id' => $thread_id,
                'to_id' => !empty($_GET['from_id']) ? Wo_Secure($_GET['from_id']) : 0
            ));
            if ($messages > 0) {
                foreach ($messages as $wo['message']) {
                    $html .= Wo_LoadManagePage('messenger/group-text-list');
                }
                $data = array(
                    'status' => 200,
                    'html' => $html
                );
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'update_recipients') {
        $html  = '';
        $users = Wo_GetMessagesUsers($wo['user']['user_id']);
        $array = array();
        if (!empty($users)) {
            foreach ($users as $key => $value) {
                $array[] = $value;
            }
        }
        array_multisort(array_column($array, "chat_time"), SORT_DESC, $array);
        $data = array(
            'status' => 404
        );
        if (count($array) > 0) {
            foreach ($array as $wo['recipient']) {
                if (!empty($wo['recipient']['message']['page_id'])) {
                    $message                              = Wo_GetPageMessages(array(
                        'page_id' => $wo['recipient']['message']['page_id'],
                        'from_id' => $wo['recipient']['message']['user_id'],
                        'to_id' => $wo['recipient']['message']['conversation_user_id'],
                        'limit' => 1,
                        'limit_type' => 1
                    ));
                    $wo['page_message']['message']        = $message[0];
                    $wo['session_active_page_background'] = (!empty($_SESSION['session_active_page_background'])) ? $_SESSION['session_active_page_background'] : 0;
                    $wo['session_active_background']      = 0;
                    $html .= Wo_LoadManagePage('messenger/messages-page-list');
                } else {
                    $wo['session_active_background']      = (!empty($_SESSION['chat_active_background'])) ? $_SESSION['chat_active_background'] : 0;
                    $wo['session_active_page_background'] = 0;
                    $html .= Wo_LoadManagePage('messenger/messages-recipients-list');
                }
            }
            ob_clean();
            
            $data = array(
                'status' => 200,
                'html' => $html
            );
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'get_new_messages') {
        $html                        = '';
        $data['update_group_status'] = Wo_CheckLastGroupAction();
        $reactions                   = array();
        if (isset($_GET['user_id']) && is_numeric($_GET['user_id']) && $_GET['user_id'] > 0 && Wo_CheckMainSession($hash_id) === true) {
            $user_id = Wo_Secure($_GET['user_id']);
            if (!empty($user_id)) {
                $user_id  = $_GET['user_id'];
                $messages = Wo_GetMessages(array(
                    'after_message_id' => $_GET['message_id'],
                    'user_id' => $user_id,
                    'type' => 'user'
                ));
                if (count($messages) > 0) {
                    foreach ($messages as $wo['message']) {
                        $html .= Wo_LoadManagePage('messenger/messages-text-list');
                    }
                    $data                   = array(
                        'status' => 200,
                        'html' => $html,
                        'sender' => $wo['user']['user_id']
                    );
                    $recipient              = Wo_UserData($user_id);
                    $data['messages_count'] = Wo_CountMessages(array(
                        'new' => false,
                        'user_id' => $user_id
                    ));
                    $data['posts_count']    = $recipient['details']['post_count'];
                }
                $data['is_typing'] = 0;
                if (!empty($user_id) && $wo['config']['message_typing'] == 1) {
                    $isTyping = Wo_IsTyping($user_id);
                    if ($isTyping === true) {
                        $img               = Wo_UserData($user_id);
                        $data['is_typing'] = 200;
                        $data['img']       = $img['avatar'];
                        $data['typing']    = $wo['config']['theme_url'] . '/img/loading_dots.gif';
                    }
                }
                $reacted_messages = $db->where("message_id IN (SELECT m.id FROM " . T_MESSAGES . " m WHERE (m.from_id = '" . $user_id . "' AND m.to_id = '" . $wo['user']['user_id'] . "') OR (m.from_id = '" . $wo['user']['user_id'] . "' AND m.to_id = '" . $user_id . "'))")->orderBy("id", "Desc")->get(T_REACTIONS, 20);
                foreach ($reacted_messages as $key => $value) {
                    $reactions[] = array(
                        'id' => $value->message_id,
                        'reactions' => Wo_GetPostReactions($value->message_id, 'message')
                    );
                }
            }
        } else if (isset($_GET['group_id']) && is_numeric($_GET['group_id']) && $_GET['group_id'] > 0 && Wo_CheckMainSession($hash_id) === true) {
            $group_id = Wo_Secure($_GET['group_id']);
            if (!empty($group_id)) {
                $group_id = $group_id;
                $messages = Wo_GetGroupMessages(array(
                    'offset' => $_GET['message_id'],
                    'group_id' => $group_id,
                    'new' => true
                ));
                if (count($messages) > 0) {
                    foreach ($messages as $wo['message']) {
                        $html .= Wo_LoadManagePage('messenger/group-text-list');
                    }
                    $data = array(
                        'status' => 200,
                        'html' => $html
                    );
                    @Wo_UpdateGChatLastSeen($group_id);
                }
                $reacted_messages = $db->where("message_id IN (SELECT m.id FROM " . T_MESSAGES . " m WHERE (m.group_id = '" . $group_id . "'))")->orderBy("id", "Desc")->get(T_REACTIONS, 20);
                foreach ($reacted_messages as $key => $value) {
                    $reactions[] = array(
                        'id' => $value->message_id,
                        'reactions' => Wo_GetPostReactions($value->message_id, 'message')
                    );
                }
            }
        } else if (!empty($_GET['from_id']) && !empty($_GET['page_id']) && Wo_CheckMainSession($hash_id) === true) {
            $page_id  = Wo_Secure($_GET['page_id']);
            $page     = Wo_PageData($page_id);
            $user_id  = $_GET['from_id'];
            $messages = Wo_GetPageMessages(array(
                'page_id' => $page_id,
                'from_id' => $page['user_id'],
                'to_id' => !empty($_GET['from_id']) ? Wo_Secure($_GET['from_id']) : 0,
                'offset' => $_GET['message_id'],
                'new' => true
            ));
            if (count($messages) > 0) {
                foreach ($messages as $wo['message']) {
                    $html .= Wo_LoadManagePage('messenger/page-chat-list');
                }
                $data = array(
                    'status' => 200,
                    'html' => $html
                );
            }
            $reacted_messages = $db->where("message_id IN (SELECT m.id FROM " . T_MESSAGES . " m WHERE (m.page_id = '" . $page_id . "' AND m.to_id = '" . $wo['user']['user_id'] . "') OR (m.page_id = '" . $page_id . "' AND m.from_id = '" . $wo['user']['user_id'] . "'))")->orderBy("id", "Desc")->get(T_REACTIONS, 20);
            foreach ($reacted_messages as $key => $value) {
                $reactions[] = array(
                    'id' => $value->message_id,
                    'reactions' => Wo_GetPostReactions($value->message_id, 'message')
                );
            }
        }
        if (!empty($user_id)) {
            $data['color'] = Wo_GetChatColor($wo['user']['user_id'], $user_id);
        }
        if (!empty($reactions)) {
            $data['reactions'] = $reactions;
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'delete_message') {
        if (isset($_GET['message_id'])) {
            $message_id = Wo_Secure($_GET['message_id']);
            $message    = $db->where('id', $message_id)->getOne(T_MESSAGES);
            if (!empty($message_id) || is_numeric($message_id) || $message_id > 0) {
                if (Wo_DeleteMessage($message_id) === true) {
                    $data['status'] = 200;
                    // if (!empty($message)) {
                        // $user_id = $message->to_id;
                        // if ($message->to_id == $wo['user']['id']) {
                            // $user_id = $message->from_id;
                        // }
                        // $recipient              = Wo_UserData($user_id);
                        // $data['messages_count'] = Wo_CountMessages(array(
                            // 'new' => false,
                            // 'user_id' => $user_id
                        // ));
                        // $data['posts_count']    = $recipient['details']['post_count'];
                    // }
                }
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'delete_conversation') {
        if (isset($_GET['user_id']) && Wo_CheckMainSession($hash_id) === true) {
            $user_id = Wo_Secure($_GET['user_id']);
            if (!empty($user_id) || is_numeric($user_id) || $user_id > 0) {
                if (Wo_DeleteConversation($user_id) === true) {
                    $data = array(
                        'status' => 200,
                        'message' => $wo['lang']['conver_deleted']
                    );
                }
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'clear_group_chat') {
        if (isset($_GET['id']) && Wo_CheckMainSession($hash_id) === true) {
            $id = Wo_Secure($_GET['id']);
            if (!empty($id) || is_numeric($id) || $id > 0) {
                if (Wo_DeleteConversation($user_id) === true) {
                    $data = array(
                        'status' => 200,
                        'message' => $wo['lang']['no_messages_here_yet']
                    );
                }
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'get_last_message_seen_status') {
        if (isset($_GET['last_id'])) {
            $message_id = Wo_Secure($_GET['last_id']);
            if (!empty($message_id) || is_numeric($message_id) || $message_id > 0) {
                $seen = Wo_SeenMessage($message_id);
                if ($seen > 0) {
                    $data = array(
                        'status' => 200,
                        'time' => $seen['time'],
                        'seen' => $seen['seen']
                    );
                }
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
    if ($s == 'register_reaction') {
        $data            = array(
            'status' => 400
        );
        $reactions_types = array_keys($wo['reactions_types']);
        if (!empty($_GET['message_id']) && is_numeric($_GET['message_id']) && $_GET['message_id'] > 0 && !empty($_GET['reaction']) && in_array($_GET['reaction'], $reactions_types)) {
            $message_id = Wo_Secure($_GET['message_id']);
            $message    = $db->where('id', $message_id)->getOne(T_MESSAGES);
            if (!empty($message)) {
                $is_reacted = $db->where('user_id', $wo['user']['user_id'])->where('message_id', $message_id)->getValue(T_REACTIONS, 'COUNT(*)');
                if ($is_reacted > 0) {
                    $db->where('user_id', $wo['user']['user_id'])->where('message_id', $message_id)->delete(T_REACTIONS);
                }
                $db->insert(T_REACTIONS, array(
                    'user_id' => $wo['user']['id'],
                    'message_id' => $message_id,
                    'reaction' => Wo_Secure($_GET['reaction'])
                ));
                $data = array(
                    'status' => 200,
                    'reactions' => Wo_GetPostReactions($message_id, 'message'),
                    'like_lang' => $wo['lang']['liked']
                );
                if (Wo_CanSenEmails()) {
                    $data['can_send'] = 1;
                }
                $data['dislike'] = 0;
            }
        }
        header("Content-type: application/json");
        echo json_encode($data);
        exit();
    }
}
