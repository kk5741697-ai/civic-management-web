<?php
require_once "./assets/libraries/phpSpreadsheet/vendor/autoload.php";
use PhpOffice\PhpSpreadsheet\IOFactory;

error_reporting(E_ALL);
ini_set("display_errors", 1);
date_default_timezone_set("Asia/Dhaka");

$is_lockout = is_lockout();

if ($s == "lockout_check") {
    if ($is_lockout == false) {
        $data = [
            "status" => 200,
            "message" => "Session still alive!",
        ];
    } else {
        $data = [
            "status" => 400,
            "message" => "Session Timeout!",
        ];
    }

    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}

if ($s == "lockout_update") {
    $lock_update = update_lockout_time();
    $reset_status = reset_working_status();
    if ($lock_update) {
        $data = [
            "status" => 200,
        ];
    } else {
        $data = [
            "status" => 400,
        ];
    }

    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}

if ($f == "manage_leads") {
    // ----- recursive sanitizer -----
    function sanitize_recursive($v) {
        if (is_array($v)) {
            $out = [];
            foreach ($v as $k => $vv) $out[$k] = sanitize_recursive($vv);
            return $out;
        }
        return strip_tags((string)$v);
    }
    
    // Use sanitized copy
    $post = sanitize_recursive($_POST ?? []);
    

    // UPDATE ALL QUOTAS
    if ($s === 'update_all_quotas') {
        $weights = $post['weights'] ?? [];
        $global = $post['global_share'] ?? [];

        if (!is_array($weights)) {
            $data = ['success' => false, 'message' => 'Invalid weight data'];
        } else {
            foreach ($weights as $user_id => $project_weights) {
                $uid = (int)$user_id;
                $g = isset($global[$user_id]) ? (float)$global[$user_id] : 1.0;

                // upsert global_share table
                $exists = $db->where('user_id', $uid)->getValue('crm_user_capacity', 'user_id');
                if ($exists) {
                    $db->where('user_id', $uid)->update('crm_user_capacity', ['global_share' => $g]);
                } else {
                    $db->insert('crm_user_capacity', ['user_id' => $uid, 'global_share' => $g]);
                }

                // collect and clamp
                $san = [];
                $total = 0;
                foreach ($project_weights as $project => $raw) {
                    $w = is_numeric($raw) ? (int)$raw : 0;
                    $w = max(0, min(100, $w));
                    $san[$project] = $w;
                    $total += $w;
                }

                // if total > 0 and != 100 then scale to 100
                if ($total > 0 && $total !== 100) {
                    // scale proportionally
                    $scaled = [];
                    foreach ($san as $project => $v) $scaled[$project] = ($v / $total) * 100;
                    // rounding to integers while ensuring sum == 100
                    $rounded = [];
                    foreach ($scaled as $project => $v) $rounded[$project] = (int)floor($v);
                    $diff = 100 - array_sum($rounded);
                    if ($diff > 0) {
                        // distribute diff to largest fractional parts
                        $fracs = [];
                        foreach ($scaled as $project => $v) $fracs[] = ['project'=>$project,'frac'=>$v - floor($v)];
                        usort($fracs, function($a,$b){ return $b['frac'] <=> $a['frac']; });
                        for ($i=0;$i<$diff;$i++){
                            $rounded[$fracs[$i % count($fracs)]['project']] += 1;
                        }
                    }
                    $san = $rounded;
                }

                // save to DB
                foreach ($san as $project => $w) {
                    $weight_int = (int)$w;
                    $participating = ($weight_int > 0) ? 1 : 0;
                    $db->replace('crm_assignment_rules', [
                        'user_id'       => $uid,
                        'project'       => $project,
                        'raw_weight'    => $weight_int,
                        'participating' => $participating
                    ]);
                }
            }

            // compute project totals and return them
            $project_totals = [];
            $projRows = $db->get(T_CRM_ASSIGNMENT_RULES, null, ['project','SUM(raw_weight) as total']);
            // Some DB libraries don't allow SUM in simple get; fallback query:
            if (!$projRows) {
                $projRows = $db->rawQuery("SELECT project, SUM(raw_weight) AS total FROM crm_assignment_rules GROUP BY project");
            }
            foreach ($projRows as $row) {
                $kp = is_object($row) ? $row->project : $row['project'];
                $tot = is_object($row) ? (int)$row->total : (int)$row['total'];
                $project_totals[$kp] = $tot;
            }

            $data = ['success' => true, 'project_totals' => $project_totals];
        }

        header('Content-Type: application/json');
        echo json_encode($data);
        exit();
    }

    // ADD PUNISHED
    if ($s === 'add_punished') {
        $user_id = (int)($post['user_id'] ?? 0);
        if (!$user_id) {
            $data = ['success' => false, 'message' => 'User ID missing'];
        } else {
            $exists = $db->where('user_id', $user_id)->getValue('crm_punished_users', 'user_id');
            if (!$exists) $db->insert('crm_punished_users', ['user_id' => $user_id]);
            $data = ['success' => true];
        }
        header('Content-Type: application/json');
        echo json_encode($data);
        exit();
    }

    // REMOVE PUNISHED
    if ($s === 'remove_punished') {
        $user_id = (int)($post['user_id'] ?? 0);
        if (!$user_id) {
            $data = ['success' => false, 'message' => 'User ID missing'];
        } else {
            $db->where('user_id', $user_id)->delete('crm_punished_users');
            $data = ['success' => true];
        }
        header('Content-Type: application/json');
        echo json_encode($data);
        exit();
    }

    // ADD REASSIGNMENT
    if ($s === 'add_reassignment') {
        $lead_id   = trim($post['lead_id'] ?? '');
        $project   = trim($post['project'] ?? '');
        $from_user = (int)($post['from_user'] ?? 0);
        $to_user   = (int)($post['to_user'] ?? 0);
        $mode      = trim($post['mode'] ?? 'normal');
        $date      = trim($post['date'] ?? date('Y-m-d'));

        if (!$lead_id || !$project || !$from_user || !$to_user || !$mode) {
            $data = ['success' => false, 'message' => 'Missing required fields'];
        } else {
            $db->insert('crm_lead_reassignments', [
                'lead_id'   => $lead_id,
                'project'   => $project,
                'from_user' => $from_user,
                'to_user'   => $to_user,
                'mode'      => $mode,
                'date'      => $date
            ]);
            $data = ['success' => true];
        }
        header('Content-Type: application/json');
        echo json_encode($data);
        exit();
    }

    // DELETE REASSIGNMENT
    if ($s === 'delete_reassignment') {
        $lead_id   = trim($post['lead_id'] ?? '');
        $project   = trim($post['project'] ?? '');
        $from_user = (int)($post['from_user'] ?? 0);
        $to_user   = (int)($post['to_user'] ?? 0);
        $date      = trim($post['date'] ?? '');

        if (!$lead_id || !$project || !$from_user || !$to_user || !$date) {
            $data = ['success' => false, 'message' => 'Missing required fields'];
        } else {
            $db->where('lead_id', $lead_id)
                ->where('project', $project)
                ->where('from_user', $from_user)
                ->where('to_user', $to_user)
                ->where('date', $date)
                ->delete('crm_lead_reassignments');
            $data = ['success' => true];
        }
        header('Content-Type: application/json');
        echo json_encode($data);
        exit();
    }

    if ($s === 'add_lead') {
        header('Content-Type: application/json');
    
        // 1) Grab & sanitize inputs
        $source       = trim($_POST['insert_source']      ?? '');
        $client_name  = trim($_POST['insert_client_name'] ?? '');
        $profession   = trim($_POST['insert_profession']  ?? '');
        $phone        = trim($_POST['insert_phone']       ?? '');
        $project      = trim($_POST['insert_project']     ?? '');
        $katha        = trim($_POST['insert_katha']       ?? '');
        $time         = time();
    
        // 2) Validate required fields
        $missing = [];
        if ($source     === '') $missing[] = 'Source';
        if ($client_name === '') $missing[] = 'Client Name';
        if ($phone       === '') $missing[] = 'Phone';
        
        //Get original katha label
        $orig_katha = '';
        
        if ($katha == '3_katha') {
            $orig_katha = '৩ কাঠা';
        } else if ($katha == '4_katha') {
            $orig_katha = '৪ কাঠা';
        } else if ($katha == '5_katha') {
            $orig_katha = '5 কাঠা';
        } else if ($katha == '10_katha') {
            $orig_katha = '১০ কাঠা';
        } else {
            $orig_katha = '';
        }
        
        if ($project == 'moon_hill') {
            $page_id = '1932174893479181';
            $page_name = 'Civic Real Estate Ltd.';
        } else if ($project == 'hill_town' || $project == 'abedin' || $project == 'ashridge') {
            $page_id = '259547413906965';
            $page_name = 'Civic Design & Development Ltd.';
        } else {
            $page_id = '0';
            $page_name = 'N/A';
        }
    
        if (!empty($missing)) {
            echo json_encode([
                'status'  => 400,
                'message' => 'Missing fields: ' . implode(', ', $missing)
            ]);
            exit;
        }
    
    
        $additional = [
            'form_id' => 'N/A',
            'form_name' => 'N/A',
            'project' => $project ?? 'N/A',
            'page_name' => $page_name ?? 'N/A',
            'thread_id' => '0',
            'platform' => 'local',
            'ad_name' => 'N/A',
            'কত_কাঠার_প্লট_নির্বাচন_করুন।' => $orig_katha
        ];
        
        // 3) Normalize phone: strip anything but digits
        $phone_number = preg_replace('/\D+/', '', $phone);
    
        // 4) Check for duplicates in the last 6 days
        $range_start = strtotime('-6 days');
        $is_exist = $db
          ->where('created', $range_start, '>=')
          ->where('phone',   $phone_number)
          ->getOne(T_LEADS);
    
        if ($is_exist) {
            echo json_encode([
                'status'  => 400,
                'message' => "Lead Already Exists: {$is_exist->name} ({$is_exist->phone}) from {$is_exist->source}"
            ]);
            exit;
        }
    
        // 5) Insert new lead
        $lead_data = [
            'name'        => $client_name,
            'profession'  => $profession,
            'company'     => '',
            'source'      => $source,
            'project'     => $project,
            'phone'       => $phone_number,
            'created'     => $time,
            'given_date'  => $time,
            'status'      => '0',
            'page_id'     => $page_id,
            'time'        => $time,
            'additional'  => json_encode($additional)
        ];
        
        $insert = $db->insert(T_LEADS, $lead_data);
        
        // 6) Return success
        if ($insert) {
            echo json_encode([
                'status'  => 200,
                'message' => "Lead added successfully!"
            ]);
        } else {
            echo json_encode([
                'status'  => 400,
                'message' => "Something went wrong!"
            ]);  
        }
        exit;
    }

    if ($s == "fetch_reminders") {
        $reset_status = reset_working_status();

        $bannedDb = clone $db;

        if (
            Wo_IsAdmin() ||
            Wo_IsModerator() ||
            check_permission("manage-leads")
        ) {
            $get_uid = isset($_POST["user_id"])
                ? Wo_Secure($_POST["user_id"])
                : "";
            $selected_user = true;
        } else {
            if ($wo["user"]["is_team_leader"] == true) {
                $get_uid = isset($_POST["user_id"])
                    ? Wo_Secure($_POST["user_id"])
                    : $wo["user"]["user_id"];
                $selected_user = $db
                    ->where("user_id", $get_uid)
                    ->where("is_team_leader", 1)
                    ->getOne(T_USERS);
                // $selected_user = true;
            } else {
                $get_uid = $wo["user"]["user_id"];
                $selected_user = false;
            }
        }
        $is_leader_status = $db
            ->where("user_id", $get_uid)
            ->where("is_team_leader", 1)
            ->getOne(T_USERS);
        // Retrieve and sanitize date inputs from POST or set default values
        $date_start = isset($_POST["data_start"])
            ? Wo_Secure($_POST["data_start"])
            : date("Y-m-01");
        $date_end = isset($_POST["data_end"])
            ? Wo_Secure($_POST["data_end"])
            : "";
        $status_id = isset($_POST["status_id"])
            ? Wo_Secure($_POST["status_id"])
            : 999;

        // Set cookies with user ID and date range information
        setcookie(
            "status_id",
            $status_id,
            time() + 10 * 365 * 24 * 60 * 60,
            "/"
        );
        setcookie("default_u", $get_uid, time() + 10 * 365 * 24 * 60 * 60, "/");
        setcookie("start_end", $date_start . " to " . $date_end, time() + 10 * 365 * 24 * 60 * 60, "/");
        
        // Adjust date format and set timestamps
        if (empty($date_end)) {
            // If $date_end is empty, set it to the end of the selected day
            $date_end = $date_start . " 23:59:59";
            $date_start = $date_start . " 00:00:00";
        } else {
            // If both dates are provided, set timestamps for the entire days
            $date_start = $date_start . " 00:00:00";
            $date_end = $date_end . " 23:59:59";
        }

        // Fetch and process data
        $page_num = isset($_POST["start"])
            ? $_POST["start"] / $_POST["length"] + 1
            : 1;

        // Get the search value
        $searchValue = isset($_POST["search"]["value"])
            ? $_POST["search"]["value"]
            : "";

        // Initialize conditions array for filtering
        $searchConditions = [];

        // Check if the search value is not empty
        if (!empty($searchValue)) {
            // Check if the search value is numeric (for phone number search)
            if (is_numeric($searchValue)) {
                // Remove any non-numeric characters (if needed) from the search string
                $searchValue = preg_replace("/\D/", "", $searchValue); // Remove non-numeric characters (if any)
                // Use LIKE operator for partial phone number match (not exact match)
                $searchConditions[] = [
                    "crm_leads.phone",
                    "%" . $searchValue . "%",
                    "LIKE",
                ];
            } else {
                // Handle search for assigned ID or user (if the value starts with '#')
                if ($searchValue === "#") {
                    // If only '#' is provided, no search condition (this could be handled as a special case if needed)
                } elseif (strpos($searchValue, "#") === 0) {
                    // If search starts with '#', treat it as assigned ID search
                    $searchValue = str_replace("#", "", $searchValue); // Remove the '#' from the search value
                    $searchConditions[] = [
                        "crm_leads.assigned",
                        $searchValue,
                        "=",
                    ];
                } else {
                    // Text search: search by name using LIKE operator (for general search)
                    $searchConditions[] = [
                        "crm_leads.name",
                        "%" . $searchValue . "%",
                        "LIKE",
                    ];
                }
            }
        }

        $start_timestamp = strtotime($date_start);
        $end_timestamp = strtotime($date_end);

        // 		if (is_numeric($status_id) && $status_id != 999) {
        // 			if ($selected_user) {
        // 				if ($status_id == 4) {
        // 					$db->where('status', $status_id)->where('member', 0);
        // 				} else if ($status_id == 0) {
        // 					$db->where('status', '0')->where('member', 0);
        // 				} else {
        // 					$db->where('status', $status_id);
        // 				}
        // 			} else {
        // 				if ($status_id == 4) {
        // 					$db->where('status', $status_id)->where('assigned', 0, '>');
        // 				} else if ($status_id == 0) {
        // 					$db->where('status', '0')->where('assigned', 0, '>');
        // 				} else {
        // 					$db->where('status', $status_id);
        // 				}
        // 			}
        // 		}
        if (!empty($start_timestamp) && !empty($end_timestamp)) {
            $db->where("remind_at", $start_timestamp, ">=")->where(
                "remind_at",
                $end_timestamp,
                "<="
            );
        }
        if (!empty($get_uid) && is_numeric($get_uid) && $get_uid != 999) {
            if (Wo_IsAdmin() || Wo_IsModerator() || check_permission("manage-leads")) {
                if ($is_leader_status) {
                    $db->where("user_id", $get_uid);
                } else {
                    $db->where("user_id", $get_uid);
                }
            } else {
                if ($selected_user) {
                    $db->where("user_id", $get_uid);
                } else {
                    $db->where("user_id", $get_uid);
                }
            }
        }
        if (
            !empty($get_uid) &&
            is_numeric($get_uid) &&
            $get_uid == 999 &&
            $wo["user"]["is_team_leader"] == true
        ) {
            $db->where("assigned", $wo["user"]["user_id"])->orwhere(
                "member",
                $get_uid
            );
        }

        if ($get_uid == 888) {
            if (
                Wo_IsAdmin() ||
                Wo_IsModerator() ||
                check_permission("manage-leads")
            ) {
                $bannedUsers = $bannedDb
                    ->where("banned", "1")
                    ->get(T_USERS, null, "user_id");
            } else {
                $bannedUsers = $bannedDb
                    ->where("banned", "1")
                    ->where("leader_id", $get_uid)
                    ->get(T_USERS, null, "user_id");
            }
            if (!empty($bannedUsers)) {
                // Extract user IDs into an array
                $bannedUserIds = array_column($bannedUsers, "user_id");

                // Fetch leads assigned to banned users
                $db->where("user_id", $bannedUserIds, "IN");
            }
        }

        foreach ($searchConditions as $condition) {
            $db->where($condition[0], $condition[1], $condition[2]);
        }

        // Order by the column specified by the user
        $orderColumn = isset($_POST["order"][0]["column"])
            ? $_POST["order"][0]["column"]
            : null;
        $orderDirection = isset($_POST["order"][0]["dir"])
            ? $_POST["order"][0]["dir"]
            : null;

        if ($orderColumn !== null) {
            if ($orderColumn == 0) {
                $db->orderBy(
                    "remind_at",
                    $orderDirection == "asc" ? "ASC" : "DESC"
                );
            } else {
                $db->orderBy("remind_at", "DESC");
            }
        } else {
            $db->orderBy("remind_at", "DESC");
        }

        if ($orderColumn !== null) {
            if ($orderColumn == 0) {
                $db->orderBy(
                    "remind_at",
                    $orderDirection == "asc" ? "ASC" : "DESC"
                );
            } else {
                $db->orderBy("remind_at", "DESC");
            }
        } else {
            $db->orderBy("remind_at", "DESC");
        }
        $countDb = clone $db;
        $count = $countDb->getValue(T_REMARKS, "COUNT(*)");

        $db->pageLimit = $_POST["length"];
        $link = "";

        // Paginate reminders
        $reminders = $db->objectbuilder()->paginate(T_REMARKS, $page_num);

        // Collect unique lead IDs from reminders
        $lead_ids = [];
        foreach ($reminders as $reminder) {
            if (!in_array($reminder->lead_id, $lead_ids)) {
                $lead_ids[] = $reminder->lead_id;
            }
        }

        // Query all related leads in one go
        $leads_data = [];
        if (!empty($lead_ids)) {
            $leadsResults = $db
                ->where("lead_id", $lead_ids, "IN")
                ->get(T_LEADS);
            // Re-index the leads data by lead_id
            foreach ($leadsResults as $lead) {
                $leads_data[$lead->lead_id] = $lead;
            }
        }

        // Prepare data for DataTables
        $outputData = [];

        foreach ($reminders as $value) {
            // Retrieve team member data
            $team_member = Wo_UserData($value->user_id);
            $member_name = "";
            if ($team_member) {
                $member_name = isset($team_member["first_name"])
                    ? '<img src="' .
                        $wo["site_url"] .
                        "/" .
                        $team_member["avatar_24"] .
                        '" class="user-img" style="width: 24px; height: 24px; border-radius: 35px; margin-right: 8px;">' .
                        $team_member["first_name"]
                    : "";
            }

            // Get the lead record for the current reminder
            $lead = isset($leads_data[$value->lead_id])
                ? $leads_data[$value->lead_id]
                : null;

            if ($lead) {
                $project_name = "N/A";

                $additionalData = json_decode($lead->additional, true); // Use 'true' to get an associative array

                $additionalData["page_id"] = isset($additionalData["page_id"])
                    ? $additionalData["page_id"]
                    : "";

                if ($additionalData["page_id"] == "259547413906965") {
                    $project_name = "Hill Town";
                } elseif ($additionalData["page_id"] == "1932174893479181") {
                    $project_name = "Moon Hill";
                } else {
                    $project_name = "N/A";
                }

                $reminderDate = date("Y-m-d", $value->remind_at);
                $today = date("Y-m-d");
                $displayRemindAt =
                    $reminderDate == $today
                        ? date("h:i A", $value->remind_at)
                        : date("d.m.Y h:i A", $value->remind_at);

                $outputData[] = [
                    "id" => $value->id,
                    "name" => $lead->name,
                    "remarks" => $value->remarks,
                    "project" => $project_name,
                    "remind_at" => $displayRemindAt,
                    "member" => $member_name,
                ];
            }
        }

        // Send JSON response
        $data = [
            "draw" => intval($_POST["draw"]),
            "recordsTotal" => $count,
            "recordsFiltered" => $db->totalPages * $_POST["length"],
            "data" => $outputData,
        ];
    }

    if ($s == "fetch_leads") {
        $reset_status = reset_working_status();

        $bannedDb = clone $db;

        if (Wo_IsAdmin() || Wo_IsModerator() || check_permission("manage-leads")) {
            $get_uid = isset($_POST["user_id"]) ? Wo_Secure($_POST["user_id"]) : "";
            $selected_user = true;
        } else {
            if ($wo["user"]["is_team_leader"] == true) {
                $get_uid = isset($_POST["user_id"]) ? Wo_Secure($_POST["user_id"]) : $wo["user"]["user_id"];
                $selected_user = $db->where("user_id", $get_uid)->where("is_team_leader", 1)->getOne(T_USERS);
                // $selected_user = true;
            } else {
                $get_uid = $wo["user"]["user_id"];
                $selected_user = false;
            }
        }
        $is_leader_status = $db->where("user_id", $get_uid)->where("is_team_leader", 1)->getOne(T_USERS);
        // Retrieve and sanitize date inputs from POST or set default values
        $date_start = isset($_POST["data_start"]) ? Wo_Secure($_POST["data_start"]) : date("Y-m-01");
        $date_end = isset($_POST["data_end"]) ? Wo_Secure($_POST["data_end"]) : "";
        $status_id = isset($_POST["status_id"]) ? Wo_Secure($_POST["status_id"]) : 999;
        $project = isset($_POST["project"]) ? Wo_Secure($_POST["project"]) : 'all';

        // Set cookies with user ID and date range information
        setcookie("status_id", $status_id, time() + 10 * 365 * 24 * 60 * 60, "/");
        setcookie("project", $project, time() + 10 * 365 * 24 * 60 * 60, "/");
        setcookie("default_u", $get_uid, time() + 10 * 365 * 24 * 60 * 60, "/");
        setcookie("start_end", $date_start . " to " . $date_end, time() + 10 * 365 * 24 * 60 * 60, "/");
        // Adjust date format and set timestamps
        if (empty($date_end)) {
            // If $date_end is empty, set it to the end of the selected day
            $date_end = $date_start . " 23:59:59";
            $date_start = $date_start . " 00:00:00";
        } else {
            // If both dates are provided, set timestamps for the entire days
            $date_start = $date_start . " 00:00:00";
            $date_end = $date_end . " 23:59:59";
        }

        // Fetch and process data
        $page_num = isset($_POST["start"]) ? $_POST["start"] / $_POST["length"] + 1 : 1;

        // Get the search value
        $searchValue = isset($_POST["search"]["value"]) ? $_POST["search"]["value"] : "";

        // Initialize conditions array for filtering
        $searchConditions = [];

        // Check if the search value is not empty
        if (!empty($searchValue)) {
            // Check if the search value is numeric (for phone number search)
            if (is_numeric($searchValue)) {
                // Remove any non-numeric characters (if needed) from the search string
                $searchValue = preg_replace("/\D/", "", $searchValue); // Remove non-numeric characters (if any)
                // Use LIKE operator for partial phone number match (not exact match)
                $searchConditions[] = ["crm_leads.phone", "%" . $searchValue . "%", "LIKE", ];
            } else {
                // Handle search for assigned ID or user (if the value starts with '#')
                if ($searchValue === "#") {
                    // If only '#' is provided, no search condition (this could be handled as a special case if needed)
                } elseif (strpos($searchValue, "#") === 0) {
                    // If search starts with '#', treat it as assigned ID search
                    $searchValue = str_replace("#", "", $searchValue); // Remove the '#' from the search value
                    $searchConditions[] = ["crm_leads.assigned", $searchValue, "=", ];
                } else {
                    // Text search: search by name using LIKE operator (for general search)
                    $searchConditions[] = ["crm_leads.name", "%" . $searchValue . "%", "LIKE", ];
                }
            }
        }
        
        $start_timestamp = strtotime($date_start);
        $end_timestamp = strtotime($date_end);
        
        // Set the max end timestamp to today's 23:59:59
        $today_end_timestamp = strtotime(date('Y-m-d') . ' 23:59:59');
        
        if ($end_timestamp > $today_end_timestamp) {
            $end_timestamp = $today_end_timestamp;
        }

        if (is_numeric($status_id) && $status_id != 999) {
            if ($selected_user) {
                if ($status_id == 4) {
                    $db->where("status", $status_id)->where("member", 0);
                } elseif ($status_id == 0) {
                    $db->where("status", "0")->where("member", 0);
                } else {
                    $db->where("status", $status_id);
                }
            } else {
                if ($status_id == 4) {
                    $db->where("status", $status_id)->where("assigned", 0, ">");
                } elseif ($status_id == 0) {
                    $db->where("status", "0")->where("assigned", 0, ">");
                } else {
                    $db->where("status", $status_id);
                }
            }
        }
        if ($project != 'all') {
            if ($project == 'moon_hill') {
                $page_id = '1932174893479181';
                $page_name = 'Civic Real Estate Ltd.';
            } else if ($project == 'hill_town' || $project == 'abedin' || $project == 'ashridge') {
                $page_id = '259547413906965';
                $page_name = 'Civic Design & Development Ltd.';
            } else {
                $page_id = '0';
                $page_name = 'N/A';
            }
            
            $db->where("project", $project);
        }
        if (!empty($start_timestamp) && !empty($end_timestamp)) {
            $db->where("created", $start_timestamp, ">=")->where(
                "created",
                $end_timestamp,
                "<="
            );
        }
        if (!empty($get_uid) && is_numeric($get_uid) && $get_uid != 999) {
            if (Wo_IsAdmin() || Wo_IsModerator() || check_permission("manage-leads")) {
                if ($is_leader_status) {
                    $db->where("assigned", $get_uid);
                } else {
                    $db->where("member", $get_uid)->where("assigned", 0, ">");
                }
            } else {
                if ($selected_user) {
                    $db->where("assigned", $get_uid)->where("member", 0);
                } else {
                    $db->where("member", $get_uid)->where("assigned", 0, ">");
                }
            }
        }
        if (!empty($get_uid) && is_numeric($get_uid) && $get_uid == 999 && $wo["user"]["is_team_leader"] == true) {
            $db->where("assigned", $wo["user"]["user_id"])->orwhere("member", $get_uid);
        }

        if ($get_uid == 888) {
            if (Wo_IsAdmin() || Wo_IsModerator() || check_permission("manage-leads")) {
                $bannedUsers = $bannedDb->where("banned", "1")->get(T_USERS, null, "user_id");
            } else {
                $bannedUsers = $bannedDb->where("banned", "1")->where("leader_id", $get_uid)->get(T_USERS, null, "user_id");
            }
            if (!empty($bannedUsers)) {
                // Extract user IDs into an array
                $bannedUserIds = array_column($bannedUsers, "user_id");

                // Fetch leads assigned to banned users
                $db->where("assigned", $bannedUserIds, "IN")->orwhere("member", $bannedUserIds, "IN");
            }
        }

        foreach ($searchConditions as $condition) {
            $db->where($condition[0], $condition[1], $condition[2]);
        }

        // Order by the column specified by the user
        $orderColumn = isset($_POST["order"][0]["column"]) ? $_POST["order"][0]["column"] : null;
        $orderDirection = isset($_POST["order"][0]["dir"]) ? $_POST["order"][0]["dir"] : null;

        if ($orderColumn !== null) {
            if ($orderColumn == 0) {
                $db->orderBy( "lead_id", $orderDirection == "asc" ? "ASC" : "DESC");
            } else {
                $db->orderBy("lead_id", "DESC");
            }
        } else {
            $db->orderBy("lead_id", "DESC");
        }

        if ($orderColumn !== null) {
            if ($orderColumn == 0) {
                $db->orderBy("lead_id", $orderDirection == "asc" ? "ASC" : "DESC");
            } else {
                $db->orderBy("lead_id", "DESC");
            }
        } else {
            $db->orderBy("lead_id", "DESC");
        }
        $countDb = clone $db;
        $count = $countDb->getValue(T_LEADS, "COUNT(*)");

        $db->pageLimit = $_POST["length"];
        $link = "";

        $leads = $db->objectbuilder()->paginate(T_LEADS, $page_num);

        // Prepare data for DataTables
        $outputData = [];
        $store_pay_amount = [];

        // Group leads by phone number
        $groupedLeads = [];
        
        foreach ($leads as $lead) {
            // Normalize phone number
            $phone_number = str_replace(["+880", "880"], "0", $lead->phone);
            $phone_number = correctNumber($phone_number);
        
            $uniqueKey = $phone_number; // Group by normalized phone number
        
            // Determine project name
            $additionalData = json_decode($lead->additional, true);
            $additionalData["page_id"] = isset($additionalData["page_id"]) ? $additionalData["page_id"] : "";
        
            if ($lead->project == 'hill_town') {
                $project_name = 'Hill Town';
            } else if ($lead->project == 'moon_hill') {
                $project_name = 'Moon Hill';
            } else if ($lead->project == 'abedin') {
                $project_name = 'Civic Abedin';
            } else if ($lead->project == 'ashridge') {
                $project_name = 'Civic Ashridge';
            } else if ($additionalData['page_id'] == '259547413906965') {
                $project_name = 'Hill Town';
            } else if ($additionalData['page_id'] == '1932174893479181') {
                $project_name = 'Moon Hill';
            } else {
                $project_name = 'N/A';
            }
        
            // Prepare status text
            if ($lead->status == 0 && $lead->assigned == 0) {
                $status = '<span class="badge bg-danger">N/A</span>';
            } elseif ($lead->status == 0 && $lead->assigned > 0) {
                $status = '<span class="badge bg-danger">Not Started</span>';
            } elseif ($lead->status == 1 && $lead->assigned > 0) {
                $status = '<span class="badge bg-success">Completed</span>';
            } elseif ($lead->status == 3 && !empty($lead->quick_remarks)) {
                $status = '<span class="badge bg-info">ReWorking</span>';
            } elseif ($lead->status == 3) {
                $status = '<span class="badge bg-info">Working</span>';
            } elseif ($lead->status == 4) {
                $status = '<span class="badge bg-info">Pending</span>';
            } elseif ($lead->status == 5) {
                $status = '<span class="badge bg-success">Sale Done</span>';
            } elseif ($lead->status == 6) {
                $status = '<span class="badge bg-success">Visit Done</span>';
            } elseif ($lead->status == 7) {
                $status = '<span class="badge bg-info">Ready Plot</span>';
            } elseif ($lead->status == 8) {
                $status = '<span class="badge bg-info">Semi-Ready Plot</span>';
            } elseif ($lead->status == 9) {
                $status = '<span class="badge bg-warning">Not Interest</span>';
            } elseif ($lead->status == 10) {
                $status = '<span class="badge bg-warning">Not capable to buy</span>';
            } elseif ($lead->status == 11) {
                $status = '<span class="badge bg-warning">Location not match</span>';
            } elseif ($lead->status == 12) {
                $status = '<span class="badge bg-warning">Closed</span>';
            } elseif ($lead->status == 13) {
                $status = '<span class="badge bg-warning">Low Budget</span>';
            } elseif ($lead->status == 14) {
                $status = '<span class="badge bg-success">Positive</span>';
            } else {
                $status = '<span class="badge bg-danger">N/A</span>';
            }
        
            // Actions
            if (Wo_IsAdmin() || Wo_IsModerator() || check_permission("manage-leads")) {
                $actions = '<div class="d-flex align-items-center gap-3 fs-6 justify-content-center">
                    <a href="javascript:;" class="text-primary" title="View" onclick="viewLead(' . $lead->lead_id . ')">
                        <i class="bx bx-book-open"></i>
                    </a>
                    <a href="javascript:;" class="text-danger" title="Delete" onclick="deleteLead(' . $lead->lead_id . ')">
                        <i class="bx bx-trash"></i>
                    </a>
                </div>';
            } else {
                $actions = '<div class="d-flex align-items-center gap-3 fs-6 justify-content-center">
                    <a href="javascript:;" class="text-primary" title="View" onclick="viewLead(' . $lead->lead_id . ')">
                        <i class="bx bx-book-open"></i>
                    </a>
                </div>';
            }
        
            $team_leader = Wo_UserData($lead->assigned);
            $leader_name = ($team_leader) ? '<img src="' . $wo["site_url"] . '/' . $team_leader["avatar_24"] . '" class="user-img" style="width:24px;height:24px;border-radius:35px;margin-right:8px;">' . $team_leader["first_name"] : '';
        
            $team_member = Wo_UserData($lead->member);
            $member_name = ($team_member) ? '<img src="' . $wo["site_url"] . '/' . $team_member["avatar_24"] . '" class="user-img" style="width:24px;height:24px;border-radius:35px;margin-right:8px;">' . $team_member["first_name"] : '';
        
            if (!isset($groupedLeads[$uniqueKey])) {
                $groupedLeads[$uniqueKey] = [
                    "id" => [$lead->lead_id], // Store all IDs in an array
                    "name" => $lead->name,
                    "phone" => maskPhoneNumber($phone_number),
                    "remarks" => $lead->remarks,
                    "projects" => [$project_name], // Start array of projects
                    "created" => date("d.m.Y", $lead->created),
                    "given_date" => date("d M Y", $lead->given_date),
                    "team_leader" => $leader_name,
                    "member" => $member_name,
                    "status" => [$status], // Keep all statuses
                    "actions" => $actions,
                    "is_hotline" => ($lead->source == 'Hotline')
                ];
            } else {
                if (!in_array($project_name, $groupedLeads[$uniqueKey]['projects'])) {
                    $groupedLeads[$uniqueKey]['projects'][] = $project_name;
                }
                // if (!in_array($status, $groupedLeads[$uniqueKey]['status'])) {
                //     $groupedLeads[$uniqueKey]['status'][] = $status;
                // }
                // $groupedLeads[$uniqueKey]['id'][] = $lead->lead_id; // Add more IDs
            }
        }
        
        // Convert grouped projects and statuses to strings
        foreach ($groupedLeads as &$leadData) {
            $leadData['project'] = implode(", ", $leadData['projects']);
            // $leadData['status'] = implode(", ", $leadData['status']);
            // $leadData['id'] = implode(", ", $leadData['id']); // Combine all IDs
            unset($leadData['projects']);
        }
        
        $outputData = array_values($groupedLeads);


        // Send JSON response
        $data = [
            "draw" => intval($_POST["draw"]),
            "recordsTotal" => $count,
            "recordsFiltered" => $db->totalPages * $_POST["length"],
            "data" => $outputData,
        ];
    }

    if ($s == "assign_lead_modal") {
        $data = [
            "status" => 200,
            "result" => Wo_LoadManagePage("leads/assign_lead"),
        ];
    }
    if ($s == "assign_lead") {
        $allots = $_POST["allot"];
        foreach ($allots as $allot) {
            $i = 1;
            $leads = "";
            if ($allot["user_mode"] == "leader") {
                $leads = $db->where("assigned", $wo["user"]["user_id"])->where("member", 0)->get(T_LEADS);
            } elseif ($allot["user_mode"] == "admin") {
                $leads = $db->where("assigned", 0)->where("member", 0)->get(T_LEADS);
            }
            foreach ($leads as $lead) {
                // Assuming $allot['given'] refers to the number of leads to process for this $allot
                if ($i <= $allot["given"]) {
                    if ($allot["user_mode"] == "leader") {
                        $update = $db->where("lead_id", $lead->lead_id)->update(T_LEADS, ["member" => $allot["user_id"], "last_activity" => time()]);
                    } elseif ($allot["user_mode"] == "admin") {
                        $update = $db->where("lead_id", $lead->lead_id)->update(T_LEADS, ["assigned" => $allot["user_id"],"last_activity" => time()]);
                    }
                    $i++;
                } else {
                    break; // Exit the inner loop if limit is reached
                }
            }
        }

        $data = [
            "status" => 200,
            "method" => "assign lead",
            "message" => "Assign lead successful to users.",
        ];
    }

    if ($s == "view_lead_modal") {
        $lead_id = isset($_POST["lead_id"]) ? Wo_Secure($_POST["lead_id"]) : "";
    
        if (empty($lead_id)) {
            $data = [
                "status" => 400,
                "message" => "Something went wrong!",
            ];
        } else {
            $lead = $db->where("lead_id", $lead_id)->getOne(T_LEADS);
    
            if (!$lead) {
                $data = [
                    "status" => 404,
                    "message" => "Lead not found!",
                ];
                echo json_encode($data);
                exit;
            }
    
            // ✅ Check permissions and update status/view time
            if (!Wo_IsAdmin() && !Wo_IsModerator() && !$wo["user"]["management"]) {
                $status = $lead->status;
    
                if ($lead->assigned == 0) {
                    $status = $lead->status;
                } elseif ($lead->assigned > 0 && (!empty($lead->remarks) || !empty($lead->quick_remarks))) {
                    $status = 3;
                } elseif ($lead->assigned > 0 && (empty($lead->remarks) || empty($lead->quick_remarks))) {
                    $status = 3;
                }
    
                $viewed = ($lead->viewed == '0') ? time() : $lead->viewed;
    
                $db->where("lead_id", $lead_id)->update(T_LEADS, [
                    "status" => $status,
                    "last_activity" => time(),
                    "viewed" => $viewed,
                ]);
            }
    
            // ✅ Check for duplicates in the last 15 days (same phone, different projects)
            $four_days_ago = strtotime("-15 days");
    
            $duplicateLeads = $db->where("phone", $lead->phone)
                ->where("created", $four_days_ago, ">=")
                ->get(T_LEADS);
    
            $projectNames = [];
    
            if (!empty($duplicateLeads)) {
                foreach ($duplicateLeads as $dup) {
                    $project_name = "N/A";
                    $additionalData = json_decode($dup->additional, true);
                    $page_id = $additionalData["page_id"] ?? "";
    
                    if ($dup->project == 'hill_town') {
                        $project_name = 'Hill Town';
                    } elseif ($dup->project == 'moon_hill') {
                        $project_name = 'Moon Hill';
                    } elseif ($dup->project == 'abedin') {
                        $project_name = 'Civic Abedin';
                    } elseif ($dup->project == 'ashridge') {
                        $project_name = 'Civic Ashridge';
                    } elseif ($page_id == '259547413906965') {
                        $project_name = 'Hill Town';
                    } elseif ($page_id == '1932174893479181') {
                        $project_name = 'Moon Hill';
                    }
    
                    if (!in_array($project_name, $projectNames)) {
                        $projectNames[] = $project_name;
                    }
                }
            }
    
            // ✅ Add projects info to lead object
            $lead->projects = (!empty($projectNames)) ? implode(", ", $projectNames) : "";
    
            // ✅ Extract plot size (কাঠা) from additionalData
            $additionalData = json_decode($lead->additional, true);
            $plotSize = "N/A";
    
            if (!empty($additionalData)) {
                foreach ($additionalData as $key => $value) {
                    if (str_contains($key, "কাঠা")) {
                        $plotSize = $value;
                        break;
                    } elseif ($key == 'katha') {
                        $plotSize = $value;
                        break;
                    }
                }
            }
    
            if ($plotSize && !($lead->project == 'abedin' || $lead->project == 'ashridge')) {
                $lead->plot_size = str_replace('_', ' ', $plotSize);
            } else {
                $lead->plot_size = "";
            }
    
            // ✅ Add warning HTML if duplicates found
            $lead->warning_html = "";
            if (!empty($projectNames)) {
                // Clean project names
                $lead->projects = implode(", ", array_unique(array_filter(array_map('trim', $projectNames))));
            
                // ✅ Show warning only if there are multiple projects (comma present)
                if (strpos($lead->projects, ',') !== false) {
                    $lead->warning_html = '
                        <div class="col w-100">
                            <div class="alert alert-warning mb-2 p-2">
                                <i class="bx bx-error-circle"></i> This lead exists in other projects: 
                                <strong>' . htmlspecialchars($lead->projects, ENT_QUOTES, 'UTF-8') . '</strong>
                            </div>
                        </div>';
                }
            }
            
            // ✅ Send data to view template
            $data = [
                "status" => 200,
                "result" => Wo_LoadManagePage("leads/view_lead", ["lead" => $lead]),
            ];
        }
    }



    if ($s == "view_reminder_modal") {
        $lead_id = isset($_POST["lead_id"]) ? $_POST["lead_id"] : "";

        if (empty($lead_id)) {
            $data = [
                "status" => 400,
                "message" => "Something went wrong!",
            ];
        } else {
            $remark = $db->where("id", $lead_id)->getOne(T_REMARKS);
            if ($remark) {
                $lead = $db->where("lead_id", $remark->lead_id)->getOne(T_LEADS);
                if ($lead) {
                    $data = [
                        "status" => 200,
                        "result" => Wo_LoadManagePage(
                            "reminders/view_reminder"
                        ),
                    ];
                } else {
                    $data = [
                        "status" => 400,
                        "message" => "Lead not found!",
                    ];
                }
            } else {
                $data = [
                    "status" => 400,
                    "message" => "Reminder not found!",
                ];
            }
        }
    }
    if ($s == "update_remarks") {
        $type = isset($_POST["type"]) ? $_POST["type"] : "";
        $is_system = isset($_POST["is_system"]) ? $_POST["is_system"] : "";
        $lead_id = isset($_POST["lead_id"]) ? $_POST["lead_id"] : "";

        if (empty($lead_id)) {
            $data = [
                "status" => 400,
                "message" => "Something went wrong!",
            ];
        } else {
            $update = [
                "last_activity" => time(),
            ];
            $unset_remark = false;
            if ($type == "custom" && isset($_POST["remarks"]) && !empty($_POST["remarks"])) {
                $update["remarks"] = isset($_POST["remarks"]) ? strtolower($_POST["remarks"]) : "";
                $is_admin = isset($_POST["is_admin"]) ? strtolower($_POST["is_admin"]) : "";

                if ($is_admin == true) {
                    $unset_remark = true;
                }
                if ($is_admin == true || $wo["user"]["is_team_leader"] == true || $is_system == '2') {
                    $is_system = '2';
                } else {
                    $is_system = '0';
                }
                if ($is_admin == true || ($wo["user"]["is_team_leader"] == true && $update["remarks"] == "change_assigned")) {
                    if ($update["remarks"] == "change_assigned") {
                        $lead_data = $db->where("lead_id", $lead_id)->getOne(T_LEADS);
                        
                        if ($lead_data->member == "0") {
                            $assgined_id = $lead_data->assigned;
                        } else {
                            $assgined_id = $lead_data->member;
                        }
                        $user_data = Wo_UserData($assgined_id);
                        
                        $update["remarks"] = "<small>" . $wo["user"]["first_name"] . " assigned </small> ~ <strong>" . str_replace("-", " ", ucwords($user_data["name"])) . "</strong>";
                        
                        $unset_remark = true;
                    } else {
                        $update["remarks"] = "<small>" . $wo["user"]["first_name"] . " set </small> ~ <strong>" . str_replace("-", " ", ucwords($update["remarks"])) . "</strong>";
                    }
                } else {
                    $assgined_id = $wo["user"]["user_id"];
                }
                
                $insert = $db->insert(T_REMARKS, [
                    "lead_id" => $lead_id,
                    "user_id" => $assgined_id,
                    "is_system" => $is_system,
                    "remarks" => $update["remarks"],
                    "time" => time(),
                ]);
                
                if ($unset_remark) {
                    unset($update["remarks"]);
                }
            } else {
                $update["quick_remarks"] = isset($_POST["quick_remarks"]) ? strtolower($_POST["quick_remarks"]) : "";
            }
            
            $lead = $db->where("lead_id", $lead_id)->getOne(T_LEADS);
            
            if (!empty($lead->quick_remarks) && !in_array($lead->quick_remarks, ["semi-ready plot", "ready plot", "not interest", "low budget", "sale complete", "positive"])) {
                $update["status"] = 4;
            } else {
                $update["status"] = $lead->status;
            }
            
            //first lead response time
            if (($lead->assigned == $wo['user']['user_id'] || $lead->member == $wo['user']['user_id'])  && $lead->response == '0') {
                $update['response'] = time();
            }
            
            $lead = $db->where("lead_id", $lead_id)->update(T_LEADS, $update);
            
            $data = [
                "status" => 200,
                "result" => Wo_LoadManagePage("leads/remarks"),
            ];
        }
    }

    if ($s == "update_lead_status") {
        $lead_id = isset($_POST["lead_id"]) ? $_POST["lead_id"] : "";
        $status = isset($_POST["status"]) ? $_POST["status"] : "";
        
        if (!empty($status)) {
            $status = $status;
        } else {
            $lead = $db->where("lead_id", $lead_id)->getOne(T_LEADS);
            
            if (!empty($lead->quick_remarks)) {
                if ($lead->quick_remarks == "sale done") {
                    $status = 5;
                } elseif ($lead->quick_remarks == "visit done") {
                    $status = 6;
                } elseif ($lead->quick_remarks == "ready plot") {
                    $status = 7;
                } elseif ($lead->quick_remarks == "semi-ready plot") {
                    $status = 8;
                } elseif ($lead->quick_remarks == "not interest") {
                    $status = 9;
                } elseif ($lead->quick_remarks == "not capable to buy") {
                    $status = 10;
                } elseif ($lead->quick_remarks == "location not match") {
                    $status = 11;
                } elseif ($lead->quick_remarks == "closed") {
                    $status = 12;
                } elseif ($lead->quick_remarks == "low budget") {
                    $status = 13;
                } elseif ($lead->quick_remarks == "positive") {
                    $status = 14;
                } else {
                    $status = 1; //pending
                }
            } elseif ($lead->assigned > 0 && !empty($lead->remarks)) {
                $status = 4;
            } else {
                $status = 0; //not started
            }
        }
        
        if (empty($lead_id)) {
            $data = [
                "status" => 400,
                "message" => "Something went wrong!",
            ];
        } else {
            $lead = $db->where("lead_id", $lead_id)->update(T_LEADS, [
                "status" => $status,
                "last_activity" => time(),
            ]);
            $data = [
                "status" => 200,
                "lead_status" => $status,
            ];
        }
    }
    if ($s == "delete_lead") {
        $lead_id = isset($_POST["lead_id"]) ? $_POST["lead_id"] : "";

        if (empty($lead_id)) {
            $data = [
                "status" => 400,
                "message" => "Something went wrong!",
            ];
        } else {
            $delete = $db->where("lead_id", $lead_id)->delete(T_LEADS);
            $delete = $db->where("lead_id", $lead_id)->delete(T_REMARKS);
            $data = [
                "status" => $delete ? 200 : 400,
            ];
        }
    }

    if ($s == "change_assigned") {
        $user_id = isset($_POST["user_id"]) ? $_POST["user_id"] : "";
        $lead_id = isset($_POST["lead_id"]) ? $_POST["lead_id"] : "";
    
        // Validate inputs
        if (empty($lead_id) || empty($user_id)) {
            $data = [
                "status" => 400,
                "message" => "Lead ID or User ID is missing!",
            ];
        } else {
            // Fetch the user data
            $user = Wo_UserData($user_id);
    
            // Validate that the user exists
            if (empty($user)) {
                $data = [
                    "status" => 400,
                    "message" => "User not found!",
                ];
            } else {
                // Prepare update array for the selected lead
                $update_array = [];
    
                if ($user["is_team_leader"] == true) {
                    // If user is a team leader, assign them and set member to 0
                    $update_array["assigned"] = $user["user_id"];
                    $update_array["member"] = "0";
                } else {
                    // If user is not a team leader, assign their leader as the assigned user
                    $update_array["assigned"] = $user["leader_id"];
                    $update_array["member"] = $user["user_id"];
                }
    
                // Update last activity timestamp
                $update_array["last_activity"] = time();
    
                // Fetch current lead details for duplicate check
                $lead = $db->where("lead_id", $lead_id)->getOne(T_LEADS, ["phone", "name"]);
    
                // ✅ Update the selected lead
                $update = $db->where("lead_id", $lead_id)->update(T_LEADS, $update_array);
    
                // ✅ If update success, update duplicates from last 15 days
                if ($update) {
                    $four_days_ago = strtotime("-15 days");
    
                    if (!empty($lead->phone) && !empty($lead->name)) {
                        // Find duplicates: same phone AND same name, within last 15 days, excluding current lead
                        $duplicateLeads = $db->where("phone", $lead->phone)
                            ->where("name", $lead->name)
                            ->where("lead_id", $lead_id, "!=")
                            ->where("created", $four_days_ago, ">=")
                            ->get(T_LEADS, null, ["lead_id"]);
    
                        if (!empty($duplicateLeads)) {
                            foreach ($duplicateLeads as $dup) {
                                $db->where("lead_id", $dup->lead_id)->update(T_LEADS, $update_array);
                            }
                        }
                    }
    
                    $data = [
                        "status" => 200,
                        "message" => "Lead assigned successfully (including duplicates if any).",
                    ];
    
                    // Send notification to the user
                    $notif_data = [
                        "subject" => "Lead Assigned",
                        "comment" => $wo["user"]["name"] . " has assigned new leads",
                        "type" => "leads",
                        "url" => "https://civicgroupbd.com/management/leads?lead_id=" . $lead_id,
                        "user_id" => $user["user_id"],
                    ];
    
                    RegisterNotification($notif_data);
    
                    sendWebNotification(
                        $user["user_id"],
                        "You have new leads",
                        $wo["user"]["name"] . " has assigned new leads",
                        "https://civicgroupbd.com/management/leads"
                    );
                } else {
                    $data = [
                        "status" => 400,
                        "message" => "Failed to assign lead. Please try again later.",
                    ];
                }
            }
        }
    }

    if ($s == "given_date") {
        $lead_id = isset($_POST["lead_id"]) ? $_POST["lead_id"] : "";
        $value = isset($_POST["value"]) ? $_POST["value"] : "";

        // Validate inputs
        if (empty($lead_id) || empty($value)) {
            $data = [
                "status" => 400,
                "message" => "Lead ID or Distribute date is missing!",
            ];
        } else {
            // Ensure lead_id is a valid integer
            if (!is_numeric($lead_id) || $lead_id <= 0) {
                $data = [
                    "status" => 400,
                    "message" => "Invalid Lead ID!",
                ];
            } else {
                // Validate the given date
                $timestamp = strtotime($value) + 1;
                if ($timestamp === false) {
                    $data = [
                        "status" => 400,
                        "message" => "Invalid Distribute Date format!",
                    ];
                } else {
                    // Proceed to update the database
                    $update = $db
                        ->where("lead_id", $lead_id)
                        ->update(T_LEADS, ["given_date" => $timestamp]);

                    if ($update) {
                        $data = [
                            "status" => 200,
                            "message" =>
                                "Lead distribute date updated successfully",
                        ];
                    } else {
                        $data = [
                            "status" => 400,
                            "message" =>
                                "Failed to update lead distribute date. Please try again later.",
                        ];
                    }
                }
            }
        }
    }
    if ($s == "process_upload") {
        function importLeadsFromCSVorXLS($filePath, $source)
        {
            global $db; // Assuming $db is your database connection object
            $result = "";

            try {
                // Load the spreadsheet (XLS/XLSX)
                $spreadsheet = IOFactory::load($filePath);

                // Get the active sheet
                $sheet = $spreadsheet->getActiveSheet();

                // Read the data from the sheet and convert it to an array
                $data = $sheet->toArray();

                // Get the headers (first row)
                $headers = $data[0];

                // Define normalized headers for various fields
                $normalizedHeadersMap = [
                    "name" => ["full_name", "name", "full name", "first name"],
                    "phone" => [
                        "phone_number",
                        "phone",
                        "phone number",
                        "contact",
                    ],
                    "profession" => ["job_title", "profession", "designation"],
                    "company" => ["company_name", "company", "organization"],
                    "email" => [
                        "email",
                        "email address",
                        "email_id",
                        "contact_email",
                    ],
                    "created" => ["created_time", "created", "time", "date"],
                    // Add more fields as necessary
                ];

                // Initialize an empty array to hold the normalized headers
                $normalizedHeaders = [];

                // Loop through each header and match to the predefined normalized headers
                foreach ($headers as $header) {
                    $normalizedHeader = null;

                    // Check each predefined header group
                    foreach ($normalizedHeadersMap as $key => $variations) {
                        if (
                            in_array(
                                strtolower($header),
                                array_map("strtolower", $variations)
                            )
                        ) {
                            $normalizedHeader = $key;
                            break;
                        }
                    }

                    // If a match was found, use the normalized header, otherwise retain the original header
                    if ($normalizedHeader) {
                        $normalizedHeaders[] = $normalizedHeader;
                    } else {
                        $normalizedHeaders[] = $header; // Keep the original if no match is found
                    }
                }

                // Initialize an empty array to hold the processed data
                $processedData = [];

                // Loop through the rest of the rows (skipping the header row)
                for ($i = 1; $i < count($data); $i++) {
                    $row = $data[$i];

                    // Initialize the row data with default values
                    $rowData = [
                        "phone_number" => null,
                        "full_name" => null,
                        "company_name" => null,
                        "profession" => null,
                        "email" => null,
                        "created" => time(), // Default to current time if no valid created date
                        "additional" => [], // Store the rest of the data in the 'additional' array
                    ];

                    // Loop through each column in the current row
                    for ($j = 0; $j < count($row); $j++) {
                        $column = $normalizedHeaders[$j];
                        $value = $row[$j];

                        // Store the value based on the normalized header
                        switch ($column) {
                            case "phone":
                            case "phone_number":
                                $rowData["phone_number"] = $value;
                                break;
                            case "full_name":
                            case "name":
                                $rowData["full_name"] = $value;
                                break;
                            case "company":
                            case "company_name":
                                $rowData["company_name"] = $value;
                                break;
                            case "profession":
                            case "job_title":
                                $rowData["profession"] = $value;
                                break;
                            case "email":
                                $rowData["email"] = $value;
                                break;
                            case "created":
                                // Normalize the created date
                                $rowData["created"] = normalizeCreatedDate(
                                    $value
                                );
                                break;
                            default:
                                // Store any additional fields in the 'additional' array
                                $rowData["additional"][$column] = $value;
                                break;
                        }
                    }

                    // Validate lead data (name, phone, email should not be empty)
                    if (
                        isset($rowData["full_name"]) &&
                        isset($rowData["phone_number"]) &&
                        !empty($rowData["full_name"]) &&
                        !empty($rowData["phone_number"])
                    ) {
                        // Standardize the phone number if necessary
                        $phone_number = str_replace(
                            [" ", "-", "+"],
                            "",
                            $rowData["phone_number"]
                        ); // Clean phone number

                        // Check if the lead already exists in the database
                        $range_start = strtotime("-6 days"); // Check leads from the last 6 days
                        $is_exist = $db
                            ->where("created", $range_start, ">=")
                            ->where("phone", $phone_number)
                            ->get(T_LEADS);

                        if (!$is_exist) {
                            // If lead doesn't exist, insert into the database
                            $lead_data = [
                                "source" => $source,
                                "phone" => $phone_number,
                                "name" => $rowData["full_name"],
                                "company" => $rowData["company_name"] ?? "",
                                "email" => $rowData["email"] ?? "",
                                "profession" => $rowData["profession"] ?? "",
                                "additional" => json_encode(
                                    $rowData["additional"]
                                ), // Store additional data as JSON
                                "created" => $rowData["created"],
                                "given_date" => $rowData["created"],
                                "time" => time(),
                            ];

                            // Insert the lead data into the database
                            $db->insert(T_LEADS, $lead_data);
                            $result .= "Lead: {$rowData["full_name"]} ({$rowData["phone_number"]}) from {$source}<br>";
                        } else {
                            // Lead already exists within the time range
                            $result .= "Lead Already Exists: {$rowData["full_name"]} ({$rowData["phone_number"]}) from {$source}<br>";
                        }
                    } else {
                        // Skip the row if the lead data is incomplete
                        $result .=
                            "Skipping row due to missing name or phone or created date<br>";
                    }
                }
            } catch (Exception $e) {
                // Handle file loading errors
                $result = "Error loading the spreadsheet: " . $e->getMessage();
            }

            return $result;
        }

        $source = isset($_POST["source"]) ? $_POST["source"] : "";
        if (empty($source)) {
            $data = [
                "status" => 400,
                "message" => "Please Select Source!",
            ];
        } else {
            // Check if file was uploaded without errors
            if (
                isset($_FILES["csvFile"]) &&
                $_FILES["csvFile"]["error"] == UPLOAD_ERR_OK
            ) {
                $fileInfo = [
                    "file" => $_FILES["csvFile"]["tmp_name"],
                    "name" => $_FILES["csvFile"]["name"],
                    "size" => $_FILES["csvFile"]["size"],
                    "type" => $_FILES["csvFile"]["type"],
                ];

                // Check if the uploaded file is a CSV file
                $fileExtension = pathinfo(
                    $fileInfo["name"],
                    PATHINFO_EXTENSION
                );
                if (strtolower($fileExtension) !== "xls") {
                    $data = [
                        "status" => 400,
                        "message" => "Error: Only XLS files are allowed.",
                    ];
                    echo json_encode($data);
                    exit();
                }

                // Call your function to upload the CSV file
                $media = Wo_ShareXLSFile($fileInfo);

                // Check if the file was successfully uploaded
                if (isset($media["filename"])) {
                    $filename = $media["filename"];
                    $importResult = importLeadsFromCSVorXLS($filename, $source);
                    $message = !empty($importResult)
                        ? "Import Successful!"
                        : "No leads were imported.";
                    // unlink($filename); // Remove the uploaded CSV file

                    $data = [
                        "status" => 200,
                        "result" => "<br>" . $message . "<br>" . $importResult,
                    ];
                } else {
                    $data = [
                        "status" => 400,
                        "message" => "Failed to upload CSV file.",
                    ];
                }
            } else {
                $data = [
                    "status" => 400,
                    "message" => "Error uploading file.",
                ];
            }
        }
    }

    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}
