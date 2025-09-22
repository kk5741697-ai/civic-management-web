<?php 
if ($f == 'locations') {
    if ($s == "fetch") {
        $get_uid = isset($_POST["user_id"]) ? Wo_Secure($_POST["user_id"]) : "";
        $date_start = isset($_POST["data_start"]) ? Wo_Secure($_POST["data_start"]) : date("Y-m-01");
        $date_end = isset($_POST["data_end"]) ? Wo_Secure($_POST["data_end"]) : "";

        setcookie("loc_user", $get_uid, time() + 10 * 365 * 24 * 60 * 60, "/");
        setcookie("loc_date", $date_start . ' to ' . $date_end, time() + 10 * 365 * 24 * 60 * 60, "/");
        
        // Set time ranges
        if (empty($date_end)) {
            $date_start .= " 00:00:00";
            $date_end = date("Y-m-d") . " 23:59:59";
        } else {
            $date_start .= " 00:00:00";
            $date_end .= " 23:59:59";
        }

        $start_timestamp = strtotime($date_start);
        $end_timestamp = strtotime($date_end);
        $today_end_timestamp = strtotime(date('Y-m-d') . ' 23:59:59');

        if ($end_timestamp > $today_end_timestamp) {
            $end_timestamp = $today_end_timestamp;
        }

        // Apply filters
        if (!empty($start_timestamp) && !empty($end_timestamp)) {
            $db->where("time", $start_timestamp, ">=")->where("time", $end_timestamp, "<=");
        }

        if (!empty($get_uid) && is_numeric($get_uid) && $get_uid != 999) {
            $db->where("user_id", $get_uid);
        }

        $locations = $db->orderBy('time', 'asc')->get(T_LOCATIONS);

        $data = [];

        foreach ($locations as $loc) {
            $user = $db->where('user_id', $loc->user_id)->getOne(T_USERS, ['username', 'avatar']);

            $data[] = [
                "id"           => $loc->id,
                "user_id"      => $loc->user_id,
                "lat"          => $loc->lat,
                "lng"          => $loc->lng,
                "time"         => $loc->time,
                "timeago"      => time() - $loc->time,
                "device_model" => $loc->device_model,
                "device_id"    => $loc->device_id,
                "username"     => $user->username ?? 'Unknown',
                "avatar"       => $user->avatar ?? 'default.jpg'
            ];
        }

        header("Content-Type: application/json");
        echo json_encode([
            "status" => "success",
            "locations" => $data
        ]);
        exit();
    }
}
