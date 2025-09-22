<?php 
if ($f == "update_user_password") {
    if (isset($_POST['user_id']) && is_numeric($_POST['user_id']) && $_POST['user_id'] > 0 && Wo_CheckSession($hash_id) === true) {
        $Userdata = Wo_UserData($_POST['user_id']);
        $admin_mode = (isset($_POST['admin_mode']) && !empty($_POST['admin_mode'])) ? $_POST['admin_mode'] : false;
        
        if (!empty($Userdata['user_id'])) {
            // For admin panel, we no longer check current or repeat password.
            // We only require that first name, email, and birthday are provided.
            if (empty($_POST['first_name']) || empty($_POST['email']) || empty($_POST['birthday'])) {
                $errors[] = $error_icon . $wo['lang']['please_check_details'];
            }
            
            if (empty($errors)) {
                $first_name   = Wo_Secure($_POST['first_name']);
                $last_name    = Wo_Secure($_POST['last_name']);
                $email        = Wo_Secure($_POST['email']);
                $phone        = Wo_Secure($_POST['phone']);
                $birthday     = Wo_Secure($_POST['birthday']);
                $joining_date = Wo_Secure($_POST['joining_date']);
                $manage_pass  = (isset($_POST['manage_pass']) && !empty($_POST['manage_pass'])) ? $_POST['manage_pass'] : '';
                $designation  = (isset($_POST['designation']) && !empty($_POST['designation'])) ? $_POST['designation'] : '';
                $department   = (isset($_POST['department']) && !empty($_POST['department'])) ? $_POST['department'] : '';

                // Build the update data array
                $Update_data = array(
                    'first_name'   => $first_name,
                    'last_name'    => $last_name,
                    'email'        => $email,
                    'phone_number' => $phone,
                    'joining_date' => $joining_date,
                    'birthday'     => $birthday,
                    'manage_pass'  => $manage_pass
                );
                
                if (!empty($designation)) {
                    $Update_data['designation'] = $designation;
                }
                if (!empty($department)) {
                    $Update_data['department'] = $department;
                }
                
                // Update the password only if a new one is provided.
                if (isset($_POST['new_password']) && !empty($_POST['new_password'])) {
                    $Update_data['password'] = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
                }
                
                $message = $wo['lang']['setting_updated'];
                
                // Additional update for admin mode with attendance integration.
                if ($admin_mode == true && (Wo_IsAdmin() || Wo_IsModerator())) {
                    if (!function_exists("zk_create_user")) {
                        if ($wo["loggedin"] == true) {
                            require_once('assets/includes/zk_functions2.php');
                        }
                    }
                    
                    if (isset($_FILES['signature']['name'])) {
                        if (Wo_UploadImage($_FILES["signature"]["tmp_name"], $_FILES['signature']['name'], 'signature', $_FILES['signature']['type'], $_POST['user_id']) === true) {
                            $Userdata = Wo_UserData($_POST['user_id']);
                        }
                    }
                    
                    $Update_data['serial']             = $_POST['serial'];
                    $Update_data['management']         = $_POST['management'];
                    $Update_data['exclude_attendance'] = $_POST['exclude_attendance'];
                    
                    $user = zk_get_user_by_pin($_POST['user_id']);
                    $name = $Update_data['first_name'] . ' ' . $Update_data['last_name'];
                    
                    if ($user) {
                        $template_data = [
                            'pin'       => $_POST['user_id'],
                            'name'      => $name,
                            'privilege' => (Wo_IsAdmin() && $_POST['user_id'] == 1) ? 14 : 1, // 14 = admin
                        ];
                    
                        // Optional: Set password if admin and user_id is 1
                        if (Wo_IsAdmin() && $_POST['user_id'] == 1) {
                            $template_data['password'] = '25462'; // change to a secure password or random generator
                        }
                    
                        $updateStatus = zk_update_user_template($template_data);
                        ob_clean();
                        $message .= $updateStatus ? ' User template updated successfully in Attendance machine!' : ' Failed to update user template in Attendance machine!';
                    } else {
                        $newUserData = [
                            'pin'       => $_POST['user_id'],
                            'name'      => $name,
                            'privilege' => (Wo_IsAdmin() && $_POST['user_id'] == 1) ? 14 : 1,
                        ];
                    
                        if (Wo_IsAdmin() && $_POST['user_id'] == 1) {
                            $newUserData['password'] = '25462'; // set admin password
                        }
                    
                        $createResult = zk_create_user($newUserData);
                        $message .= $createResult ? ' Also user created in Attendance machine!' : 'Failed to create user in Attendance machine!';
                    }
                }
                
                if (Wo_UpdateUserData($_POST['user_id'], $Update_data)) {
                    $user_id = Wo_Secure($_POST['user_id']);
                    if ($admin_mode == false) {
                        $session_id = (!empty($_SESSION['user_id'])) ? $_SESSION['user_id'] : $_COOKIE['user_id'];
                        $session_id = Wo_Secure($session_id);
                        mysqli_query($sqlConnect, "DELETE FROM " . T_APP_SESSIONS . " WHERE `user_id` = '{$user_id}' AND `session_id` <> '{$session_id}'");
                    }
                    
                    $data = array(
                        'status'  => 200,
                        'message' => $success_icon . $message
                    );
                }
            }
        }
    }
    header("Content-type: application/json");
    if (isset($errors)) {
        echo json_encode(array('errors' => $errors));
    } else {
        echo json_encode($data);
    }
    exit();
}
?>
