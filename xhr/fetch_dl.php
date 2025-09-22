<?php
require_once "./assets/libraries/phpSpreadsheet/vendor/autoload.php";
use PhpOffice\PhpSpreadsheet\IOFactory;

error_reporting(E_ALL);
ini_set("display_errors", 1);
date_default_timezone_set("Asia/Dhaka");

if ($f === "fetch_dl") {

    // 1) Retrieve & sanitize inputs (with defaults)
    $date_start = Wo_Secure($_POST["data_start"] ?? date("Y-m-01"));
    $date_end   = Wo_Secure($_POST["data_end"]   ?? "");

    // 2) Default empty end → same as start
    if (empty($date_end)) {
        $date_end = $date_start;
    }

    // 3) Persist filters in cookies
    setcookie("status_id", Wo_Secure($_POST["status_id"] ?? 999),  time() + 10*365*24*60*60, "/");
    setcookie("project",   Wo_Secure($_POST["project"]   ?? 'all'), time() + 10*365*24*60*60, "/");
    setcookie("start_end", "$date_start to $date_end",     time() + 10*365*24*60*60, "/");

    // 4) Build timestamps for full days
    $input_start_ts = strtotime("$date_start 00:00:00");
    $input_end_ts   = strtotime("$date_end   23:59:59");

    // 5) Compute cutoff = “7 days ago at 23:59:59”
    $cutoff_ts = strtotime(date('Y-m-d', strtotime('-7 days')) . ' 23:59:59');

    // 6) If start is after cutoff → return empty
    if ($input_start_ts > $cutoff_ts) {
        header("Content-Type: application/json");
        echo json_encode([
            "draw"            => intval($_POST["draw"] ?? 0),
            "recordsTotal"    => 0,
            "recordsFiltered" => 0,
            "data"            => []
        ]);
        exit();
    }

    // 7) Clamp end to cutoff
    $start_ts = $input_start_ts;
    $end_ts   = min($input_end_ts, $cutoff_ts);

    // 8) DataTables pagination parameters
    $page_num   = isset($_POST["start"], $_POST["length"])
                ? ($_POST["start"] / $_POST["length"]) + 1
                : 1;
    $per_page   = intval($_POST["length"] ?? 10);
    $searchValue = $_POST["search"]["value"] ?? "";

    // 9) Build search conditions
    $searchConditions = [];
    if ($searchValue !== "") {
        if (is_numeric($searchValue)) {
            $digits = preg_replace("/\D/", "", $searchValue);
            $searchConditions[] = ["crm_leads.phone", "%{$digits}%", "LIKE"];
        } elseif (strpos($searchValue, "#") === 0) {
            $assigned = ltrim($searchValue, "#");
            $searchConditions[] = ["crm_leads.assigned", $assigned, "="];
        } else {
            $searchConditions[] = ["crm_leads.name", "%{$searchValue}%", "LIKE"];
        }
    }

    // 10) Apply date clamp + exclude statuses
    $db->where("created", $start_ts, ">=")
       ->where("created",   $end_ts,   "<=")
       ->where("status", ["14","5","6"], "NOT IN");

    // 11) Apply search filters
    foreach ($searchConditions as $c) {
        $db->where($c[0], $c[1], $c[2]);
    }

    // 12) Ordering (fixed direction logic)
    $orderCol  = $_POST["order"][0]["column"] ?? null;
    $orderDir  = strtoupper($_POST["order"][0]["dir"] ?? "DESC") === "ASC" ? "ASC" : "DESC";
    if ($orderCol === "0") {
        $db->orderBy("lead_id", $orderDir);
    } else {
        $db->orderBy("lead_id", "DESC");
    }

    // 13) Clone for total count
    $countDb = clone $db;
    $total   = $countDb->getValue(T_LEADS, "COUNT(*)");

    // 14) Pagination & fetch
    $db->pageLimit = $per_page;
    $leads = $db->objectbuilder()->paginate(T_LEADS, $page_num);

    // 15) Build output array (with +7‑day display shift)
    $outputData = [];
    foreach ($leads as $lead) {
        // Normalize phone
        $p = preg_replace('/^(?:\+?88)?01/', '01', $lead->phone);
        $p = correctNumber($p);

        // Extract “katha”
        $additional = json_decode($lead->additional, true) ?: [];
        $plotSize = "N/A";
        foreach ($additional as $key => $val) {
            if (str_contains($key, "কাঠা") || $key === "katha") {
                $plotSize = $val;
                break;
            }
        }

        // **Here’s your “trick”**: show created as 7 days newer
        $display_date = date("d.m.Y", strtotime('+7 days', $lead->created));

        $outputData[] = [
            "created" => $display_date,
            "name"    => $lead->name,
            "phone"   => $p,
            "katha"   => $plotSize
        ];
    }

    // 16) Return JSON
    header("Content-Type: application/json");
    echo json_encode([
        "draw"            => intval($_POST["draw"] ?? 0),
        "recordsTotal"    => $total,
        "recordsFiltered" => $db->totalPages * $per_page,
        "data"            => $outputData
    ]);
    exit();
}
