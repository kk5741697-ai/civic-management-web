<?php
ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL);
if ($f == 'login') {
    Global $android;
    
    if (!empty($_SESSION['user_id'])) {
        $_SESSION['user_id'] = '';
        unset($_SESSION['user_id']);
    }
    if (!empty($_COOKIE['user_id'])) {
        $_COOKIE['user_id'] = '';
        unset($_COOKIE['user_id']);
        setcookie('user_id', '', -1);
        setcookie('user_id', '', -1, '/');
    }
    if (!empty($_SESSION['user_id2'])) {
        $_SESSION['user_id2'] = '';
        unset($_SESSION['user_id2']);
    }
    if (!empty($_COOKIE['user_id2'])) {
        $_COOKIE['user_id2'] = '';
        unset($_COOKIE['user_id2']);
        setcookie('user_id2', '', -1);
        setcookie('user_id2', '', -1, '/');
    }
    $data_ = array();
    $phone = 0;
    if (isset($_POST['username']) && isset($_POST['password'])) {
        if ($wo['config']['prevent_system'] == 1) {
            if (!WoCanLogin()) {
                $errors[] = $error_icon . $wo['lang']['login_attempts'];
                header("Content-type: application/json");
                echo json_encode(array(
                    'errors' => $errors
                ));
                exit();
            }
        }
        $username = Wo_Secure($_POST['username']);
        $password = $_POST['password'];
        $result   = Wo_Login($username, $password);
        $code_id = Wo_UserIdForLogin($username);
        
        if ($result === false) {
            $errors[] = $error_icon . $wo['lang']['incorrect_username_or_password_label'];
            if ($wo['config']['prevent_system'] == 1) {
                WoAddBadLoginLog();
            }
        } else if (Wo_UserInactive($_POST['username']) === true) {
            $errors[] = $error_icon . $wo['lang']['account_disbaled_contanct_admin_label'];
        } else if (Wo_VerfiyIP($_POST['username']) === false) {
            $_SESSION['code_id'] = $code_id;
            $two_factor_hash = bin2hex(random_bytes(18));
            $db->where('user_id',$_SESSION['code_id'])->update(T_USERS,array('two_factor_hash' => $two_factor_hash));
            cache($_SESSION['code_id'], 'users', 'delete');
            $user_data = Wo_UserData($_SESSION['code_id']);
            setcookie("two_factor_method", $user_data['two_factor_method'], time() + (60 * 60), "/");
            setcookie("two_factor_username", $user_data['username'], time() + (60 * 60), "/");
            $_SESSION['two_factor_hash'] = $two_factor_hash;
            setcookie("two_factor_hash", $two_factor_hash, time() + (60 * 60));
            $_SESSION['code_id'] = $code_id;
            $data_               = array(
                'status' => 600,
                'location' => Wo_SeoLink('index.php?link1=unusual-login')
            );
            $phone               = 1;
        } else if (Wo_TwoFactor($_POST['username']) === false) {
            $_SESSION['code_id'] = $code_id;
            $two_factor_hash = bin2hex(random_bytes(18));
            $db->where('user_id',$_SESSION['code_id'])->update(T_USERS,array('two_factor_hash' => $two_factor_hash));
            cache($_SESSION['code_id'], 'users', 'delete');
            $user_data = Wo_UserData($_SESSION['code_id']);
            setcookie("two_factor_method", $user_data['two_factor_method'], time() + (60 * 60), "/");
            setcookie("two_factor_username", $user_data['username'], time() + (60 * 60), "/");
            $_SESSION['two_factor_hash'] = $two_factor_hash;
            setcookie("two_factor_hash", $two_factor_hash, time() + (60 * 60));
            $data_               = array(
                'status' => 600,
                'location' => $wo['config']['site_url'] . '/unusual-login?type=two-factor'
            );
            $phone               = 1;
        } else if (Wo_UserActive($_POST['username']) === false) {
            $_SESSION['code_id'] = $code_id;
            $data_               = array(
                'status' => 600,
                'location' => Wo_SeoLink('index.php?link1=user-activation')
            );
            $phone               = 1;
        }
        if (empty($errors) && $phone == 0) {
            $userid              = $code_id;
            $ip                  = Wo_Secure(get_ip_address());
            $update              = mysqli_query($sqlConnect, "UPDATE " . T_USERS . " SET `ip_address` = '{$ip}' WHERE `user_id` = '{$userid}'");
            cache($userid, 'users', 'delete');
            $session             = Wo_CreateLoginSession($code_id);
            $_SESSION['user_id'] = $session;
            Wo_DeleteBadLogins();
			setcookie("user_id", $session, time() + (10 * 365 * 24 * 60 * 60));
		    setcookie("user_id2", $code_id, time() + (10 * 365 * 24 * 60 * 60));

            setcookie('ad-con', htmlentities(json_encode(array(
                'date' => date('Y-m-d'),
                'ads' => array()
            ))), time() + (10 * 365 * 24 * 60 * 60));
            $data = array(
                'status' => 200
            );
            if (!empty($_POST['last_url'])) {
                $data['location'] = $_POST['last_url'];
            } else {
                $data['location'] = $wo['config']['site_url'];
            }
            $user_data = Wo_UserData($userid);
            if ($wo['config']['membership_system'] == 1 && $user_data['is_pro'] == 0) {
                $data['location'] = Wo_SeoLink('index.php?link1=go-pro');
            }
            
            $logType = 'login';
            $logMessage = "Successfully loggedin user: " . $user_data['username'];
        
            logActivity('create', $logType, $logMessage, $userid);
        }
    }
    header("Content-type: application/json");
    if (!empty($errors)) {
        echo json_encode(array(
            'errors' => $errors
        ));
    } else if (!empty($data_)) {
        echo json_encode($data_);
    } else {
        echo json_encode($data);
    }
    exit();
}
