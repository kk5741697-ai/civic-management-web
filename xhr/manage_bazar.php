<?php
// ===============================
//  🔐 CONFIGURATION & SECURITY
// ===============================
// error_reporting(E_ALL);
// ini_set("display_errors", 1);
date_default_timezone_set("Asia/Dhaka");
header("Content-type: application/json");


$is_lockout = is_lockout();
if ($s == "lockout_check") {
    echo json_encode([
        "status" => $is_lockout ? 400 : 200,
        "message" => $is_lockout ? "Session Timeout!" : "Session still alive!"
    ]);
    exit;
}

if ($f == "manage_bazar") {
    // Check user permissions
    if (!(Wo_IsAdmin() || Wo_IsModerator() || check_permission("bazar") || check_permission("manage-bazar") || $wo['user']['is_bazar'] == 1)) {
        echo json_encode([
            'status' => 404,
            'message' => "You don’t have permission"
        ]);
        exit;
    }

    $time = time();

    // ===============================
    //  ➕ ADMIN ADD NEW BAZAR ITEM
    // ===============================
    if ($s == 'admin_add_bazar') {
        $icon = trim($_POST['icon'] ?? '');
        $name = trim($_POST['item_name'] ?? '');
        $unit = trim($_POST['unit'] ?? '');
        $quantity = (int) ($_POST['quantity'] ?? 0);
        $price = (float) ($_POST['price'] ?? 0);
        $low_bazar = (int) ($_POST['low_bazar_threshold'] ?? 15);

        // Validation
        $missing = [];
        if (!$icon) $missing[] = 'Icon';
        if (!$name) $missing[] = 'Name';
        if (!$unit) $missing[] = 'Unit';
        if ($quantity < 0) $missing[] = 'Quantity';
        if ($price < 0) $missing[] = 'Price';
        if ($missing) {
            echo json_encode(['status'=>400, 'message'=>'Missing or invalid fields: '.implode(', ', $missing)]);
            exit;
        }

        if ($db->where('name', $name)->getOne(T_BAZAR)) {
            echo json_encode(['status'=>400,'message'=>'Item already exists']);
            exit;
        }

        $db->startTransaction();
        $bazar_id = $db->insert(T_BAZAR, [
            'icon'=>$icon,
            'name'=>$name,
            'unit'=>$unit,
            'quantity'=>$quantity,
            'price'=>$price,
            'low_bazar_threshold'=>$low_bazar,
            'updated_at'=>$time
        ]);
        if (!$bazar_id) {
            $db->rollback();
            echo json_encode(['status'=>500,'message'=>'Failed to add item']);
            exit;
        }

        $db->insert(T_BAZAR_QUANTITY, [
            'bazar_id'=>$bazar_id,
            'date'=>$time,
            'quantity'=>$quantity,
            'used'=>0,
            'remaining'=>$quantity,
            'is_hidden'=>1
        ]);
        $db->insert(T_BAZAR_PRICE, ['bazar_id'=>$bazar_id,'date'=>$time,'price'=>$price]);
        $db->commit();
        logActivity('bazar','create',"Admin added bazar {$name}");

        echo json_encode(['status'=>200,'message'=>'Bazar item added successfully', 'bazar_id'=>$bazar_id]);
        exit;
    }

    // ===============================
    //  ✏️ UPDATE BAZAR ITEM (Admin)
    // ===============================
    if ($s === 'update_bazar') {
        $id = (int) ($_POST['id'] ?? 0);
        $icon = trim($_POST['icon'] ?? '');
        $name = trim($_POST['item_name'] ?? '');
        $unit = trim($_POST['unit'] ?? '');
        $quantity = isset($_POST['quantity']) ? (int) $_POST['quantity'] : null;
        $price = isset($_POST['price']) ? (float) $_POST['price'] : null;
        $low_bazar = (int) ($_POST['low_bazar_threshold'] ?? 0);

        $missing = [];
        if (!$id) $missing[] = 'Item ID';
        if ($icon === '') $missing[] = 'Icon';
        if ($name === '') $missing[] = 'Name';
        if ($unit === '') $missing[] = 'Unit';
        if ($quantity === null || $quantity < 0) $missing[] = 'Quantity';
        if ($price === null || $price < 0) $missing[] = 'Price';
        if ($missing) {
            echo json_encode(['status' => 400, 'message' => 'Missing or invalid fields: ' . implode(', ', $missing)]);
            exit;
        }

        $bazar_item = $db->where('id',$id)->getOne(T_BAZAR);
        if (!$bazar_item) { echo json_encode(['status'=>404,'message'=>'Bazar item not found.']); exit; }

        if ($name !== $bazar_item->name && $db->where('name',$name)->getOne(T_BAZAR)) {
            echo json_encode(['status'=>400,'message'=>'Another item with this name already exists.']); exit;
        }

        $db->where('id',$id)->update(T_BAZAR, [
            'icon' => $icon,
            'name' => $name,
            'unit' => $unit,
            'quantity' => $quantity,
            'price' => $price,
            'low_bazar_threshold' => $low_bazar,
            'updated_at' => $time
        ]);

        if ((float)$bazar_item->price !== $price) {
            $db->insert(T_BAZAR_PRICE, ['bazar_id'=>$id,'date'=>$time,'price'=>$price]);
        }
        $user_id = $wo['user']['user_id'];
        if ((int)$bazar_item->quantity !== $quantity) {
            $total_used = $db->where('bazar_id',$id)->getValue(T_BAZAR_USAGE,'SUM(quantity)') ?? 0;
            $db->insert(T_BAZAR_QUANTITY, [
                'bazar_id'=>$id,'date'=>$time,
                'user_id'   => $user_id, 'quantity'=>$quantity,
                'used'=>$total_used,'remaining'=>$quantity,
                'is_hidden'=>0
            ]);
        }

        logActivity('bazar','update',"Updated bazar item ID {$id}: {$name}");
        echo json_encode(['status'=>200,'message'=>'Bazar item updated successfully!']);
        exit;
    }

    // ===============================
    //  🗑️ DELETE BAZAR ITEM (Admin)
    // ===============================
    if ($s === 'delete_item') {
        if (!Wo_IsAdmin() && !Wo_IsModerator()) {
            echo json_encode(['status'=>403,'message'=>'Permission denied.']); exit;
        }

        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($id < 1) { echo json_encode(['status'=>400,'message'=>'Invalid bazar ID.']); exit; }

        $st_name = $db->where('id',$id)->getValue(T_BAZAR,'name');
        $db->startTransaction();
        $db->where('bazar_id',$id)->delete(T_BAZAR_QUANTITY);
        $db->where('bazar_id',$id)->delete(T_BAZAR_USAGE);
        $deleted = $db->where('id',$id)->delete(T_BAZAR);
        if ($deleted) {
            $db->commit();
            logActivity('bazar','delete',"Deleted bazar item: {$st_name}");
            echo json_encode(['status'=>200,'message'=>'Bazar item deleted successfully!']);
        } else {
            $db->rollback();
            echo json_encode(['status'=>500,'message'=>'Failed to delete bazar item.']);
        }
        exit;
    }

    // ===============================
    //  ➕ BULK CREDIT ADD (Non-admin)
    // ===============================
    if ($s == 'bulk_add_bazar') {
        $bazarIds   = $_POST['bazar_id'] ?? [];
        $quantities = $_POST['quantity'] ?? [];
        $prices     = $_POST['price'] ?? [];
        $user_id  = (int) ($_POST['user_id'] ?? 0);
        $entry_date_raw = trim($_POST['entry_date'] ?? '');
    
        if (!count($bazarIds) || count($bazarIds) !== count($quantities) || count($bazarIds) !== count($prices)) {
            echo json_encode(['status'=>400,'message'=>'Invalid input']); exit;
        }
    
        // Build entry timestamp: combine provided date with current time-of-day
        $now = time();
        $time_of_day = date('H:i:s', $now);
        $entry_ts = false;
        if ($entry_date_raw !== '') {
            $candidate = $entry_date_raw . ' ' . $time_of_day;
            $entry_ts = strtotime($candidate);
        }
        if ($entry_ts === false) $entry_ts = $now; // fallback
    
        $db->startTransaction();
        $errors = [];
        $successCount = 0;
    
        foreach ($bazarIds as $i => $b_id) {
            $b_id = (int)$b_id;
            $qty = isset($quantities[$i]) ? $quantities[$i] : null;
            $price = isset($prices[$i]) ? $prices[$i] : null;
    
            // validate numeric & allow decimals
            if (!is_numeric($qty) || !is_numeric($price)) {
                $errors[] = "Row ".($i+1)." has non-numeric qty/price";
                continue;
            }
    
            $qty = (float)$qty;
            $price = (float)$price;
    
            if ($b_id < 1 || $qty <= 0 || $price < 0) {
                $errors[] = "Row ".($i+1)." has invalid values";
                continue;
            }
    
            $bazar = $db->where('id',$b_id)->getOne(T_BAZAR);
            if (!$bazar) { $errors[] = "Row ".($i+1)." item not found"; continue; }
    
            // ensure current quantity treated as float
            $currentQty = isset($bazar->quantity) ? (float)$bazar->quantity : 0.0;
            $newQty = $currentQty + $qty;
    
            $db->where('id',$b_id)->update(T_BAZAR, [
                'quantity'   => $newQty,
                'price'      => $price,
                'updated_at' => $entry_ts
            ]);
    
            $db->insert(T_BAZAR_QUANTITY, [
                'bazar_id'  => $b_id,
                'date'      => $entry_ts,
                'user_id'   => $user_id,
                'quantity'  => $qty,
                'used'      => 0,
                'remaining' => $newQty,
                'is_hidden' => 0
            ]);
    
            $db->insert(T_BAZAR_PRICE, [
                'bazar_id' => $b_id,
                'date'     => $entry_ts,
                'price'    => $price
            ]);
    
            logActivity('bazar','update',"Bulk add_bazar: {$bazar->name} +{$qty} units, price updated {$price}");
            $successCount++;
        }
    
        $db->commit();
        echo json_encode([
            'status' => $successCount > 0 ? 200 : 400,
            'message' => ($successCount ? "{$successCount} items added successfully." : '') .
                         (!empty($errors) ? ' Errors: '.implode('; ', $errors) : '')
        ]);
        exit;
    }
    
    // ===============================
    //  ➖ USE BAZAR (Debit) - with hidden tracking
    // ===============================
    if ($s === 'use_bazar') {
        $bazarIds = $_POST['bazar_id'] ?? [];
        $qtys     = $_POST['quantity'] ?? [];
        $user_id  = (int) ($_POST['user_id'] ?? 0);
        $entry_date_raw = trim($_POST['entry_date'] ?? '');
    
        if (!$user_id || !count($bazarIds) || count($bazarIds) !== count($qtys)) {
            echo json_encode(['status'=>400,'message'=>'Invalid input']); exit;
        }
    
        // Build entry timestamp: combine provided date with current time-of-day
        $now = time();
        $time_of_day = date('H:i:s', $now);
        $entry_ts = false;
        if ($entry_date_raw !== '') {
            $candidate = $entry_date_raw . ' ' . $time_of_day;
            $entry_ts = strtotime($candidate);
        }
        if ($entry_ts === false) $entry_ts = $now; // fallback
    
        $db->startTransaction();
        $errors = [];
        $successCount = 0;
    
        foreach ($bazarIds as $i => $b_id) {
            $b_id = (int)$b_id;
            $useRaw = isset($qtys[$i]) ? $qtys[$i] : null;
    
            if (!is_numeric($useRaw)) {
                $errors[] = "Row ".($i+1)." invalid quantity";
                continue;
            }
    
            $use = (float)$useRaw;
    
            $bazar = $db->where('id',$b_id)->getOne(T_BAZAR);
            if (!$bazar) { $errors[] = "Row ".($i+1)." item not found"; continue; }
            if ($use <= 0) { $errors[] = "Row ".($i+1)." invalid quantity"; continue; }
    
            // totals as floats (support decimals)
            $totalAdded = (float) ($db->where('bazar_id',$b_id)->getValue(T_BAZAR_QUANTITY,'SUM(quantity)') ?: 0);
            $totalUsed  = (float) ($db->where('bazar_id',$b_id)->getValue(T_BAZAR_USAGE,'SUM(quantity)') ?: 0);
            $remaining  = $totalAdded - $totalUsed;
    
            if ($remaining < $use) {
                $errors[] = "Row ".($i+1)." insufficient stock: {$bazar->name} remaining {$remaining} {$bazar->unit}";
                continue;
            }
    
            $currentQty = isset($bazar->quantity) ? (float)$bazar->quantity : 0.0;
            $newQty = $currentQty - $use;
            if ($newQty < 0) $newQty = 0;
    
            $db->where('id',$b_id)->update(T_BAZAR, [
                'quantity'   => $newQty,
                'updated_at' => $entry_ts
            ]);
    
            $db->insert(T_BAZAR_USAGE, [
                'bazar_id' => $b_id,
                'user_id'  => $user_id,
                'quantity' => $use,
                'date'     => $entry_ts
            ]);
    
            // Insert hidden tracking entry (used)
            $db->insert(T_BAZAR_QUANTITY, [
                'bazar_id'  => $b_id,
                'date'      => $entry_ts,
                'user_id'   => $user_id,
                'quantity'  => 0,
                'used'      => $use,
                'remaining' => $newQty,
                'is_hidden' => 1
            ]);
    
            logActivity('bazar','update',"Used {$bazar->name} -{$use} units by user {$user_id}");
            $successCount++;
        }
    
        $db->commit();
        echo json_encode([
            'status' => $successCount > 0 ? 200 : 400,
            'message' => ($successCount ? "{$successCount} items processed successfully." : '') .
                         (!empty($errors) ? ' Errors: '.implode('; ', $errors) : '')
        ]);
        exit;
    }


    // ===============================
    //  📊 FETCH ALL ENTRIES (non admin - User view)
    // ===============================
    if ($s === 'fetch_all_entries') {
        $start  = isset($_POST['start']) ? (int)$_POST['start'] : 0;
        $length = isset($_POST['length']) ? (int)$_POST['length'] : 10;
        $draw   = isset($_POST['draw']) ? (int)$_POST['draw'] : 1;
    
        $start_date = $_POST['data_start'] ?? date('Y-m-d');
        $end_date   = $_POST['data_end'] ?? date('Y-m-d');
        $startTs = strtotime($start_date . ' 00:00:00');
        $endTs   = strtotime($end_date . ' 23:59:59');
    
        // Fetch Credit (Add) - ignore hidden tracking entries
        $db->where('date', $startTs, '>=');
        $db->where('date', $endTs, '<=');
        $db->where('is_hidden', 0);
        $addEntries = $db->join(T_BAZAR, T_BAZAR . ".id=" . T_BAZAR_QUANTITY . ".bazar_id")
                         ->join(T_USERS, T_USERS . ".user_id=" . T_BAZAR_QUANTITY . ".user_id", 'LEFT')
                         ->objectbuilder()
                         ->get(T_BAZAR_QUANTITY, null, [
                             T_BAZAR_QUANTITY . '.bazar_id',
                             T_BAZAR_QUANTITY . '.date',
                             T_BAZAR_QUANTITY . '.quantity',
                             T_BAZAR_QUANTITY . '.user_id',
                             T_BAZAR . '.name as item_name',
                             T_BAZAR . '.unit',
                             T_USERS . '.username',
                             T_USERS . '.first_name',
                             T_USERS . '.last_name'
                         ]);
        foreach ($addEntries as &$entry) $entry->type = 'add_bazar';
    
        // Fetch Debit (Use)
        $db->where('date', $startTs, '>=');
        $db->where('date', $endTs, '<=');
        $useEntries = $db->join(T_BAZAR, T_BAZAR . ".id=" . T_BAZAR_USAGE . ".bazar_id")
                         ->join(T_USERS, T_USERS . ".user_id=" . T_BAZAR_USAGE . ".user_id")
                         ->objectbuilder()
                         ->get(T_BAZAR_USAGE, null, [
                             T_BAZAR_USAGE . '.bazar_id',
                             T_BAZAR_USAGE . '.date',
                             T_BAZAR_USAGE . '.quantity',
                             T_BAZAR_USAGE . '.user_id',
                             T_BAZAR . '.name as item_name',
                             T_BAZAR . '.unit',
                             T_USERS . '.username',
                             T_USERS . '.first_name',
                             T_USERS . '.last_name'
                         ]);
        foreach ($useEntries as &$entry) $entry->type = 'use_bazar';
    
        // Merge and sort
        $entries = array_merge($addEntries, $useEntries);
        usort($entries, fn($a,$b)=>$a->date <=> $b->date); // oldest first
    
        // Calculate running remaining per item
        $runningRemaining = [];
        $output = [];
        foreach ($entries as $row) {
            $b_id = $row->bazar_id;
            if (!isset($runningRemaining[$b_id])) $runningRemaining[$b_id] = 0;
    
            if ($row->type === 'add_bazar') {
                $runningRemaining[$b_id] += $row->quantity;
                $userName = $row->first_name;
            } else { // Debit
                $runningRemaining[$b_id] -= $row->quantity;
                $userName = 'Kitchen';
            }
    
            // Only show visible entries (ignore hidden)
            if ($row->type === 'add_bazar' || $row->type === 'use_bazar') {
                $output[] = [
                    'date'        => date('d-m-Y', $row->date),
                    'type'        => ucwords(str_replace('_', ' ', $row->type)),
                    'item_name'   => '<i class="' . $row->icon . '"></i> ' . htmlspecialchars($row->item_name),
                    'quantity'    => $row->quantity . ' ' . $row->unit,
                    'remaining'   => $runningRemaining[$b_id] . ' ' . $row->unit,
                    'user_name'   => htmlspecialchars($userName),
                    'actions'     => (Wo_IsAdmin()||Wo_IsModerator())
                                    ? '<a href="javascript:;" class="text-danger" onclick="deleteItem('.$row->bazar_id.')"><i class="bx bx-trash"></i></a>'
                                    : ''
                ];
            }
        }
    
        $output = array_reverse($output); // latest first
        $paged = array_slice($output, $start, $length);
    
        echo json_encode([
            'draw'            => $draw,
            'recordsTotal'    => count($output),
            'recordsFiltered' => count($output),
            'data'            => $paged
        ]);
        exit;
    }

    // ===============================
    //  📈 FETCH PRICE & USAGE HISTORY FOR CHART
    // ===============================
    if ($s === 'fetch_history') {
        $bazar_id = (int) ($_POST['bazar_id'] ?? 0);
        if (!$bazar_id) { 
            echo json_encode(['status'=>400,'message'=>'Invalid Bazar ID']); 
            exit; 
        }
    
        $start_date = $_POST['data_start'] ?? null;
        $end_date   = $_POST['data_end'] ?? null;
    
        $startTs = $start_date ? strtotime($start_date . ' 00:00:00') : null;
        $endTs   = $end_date   ? strtotime($end_date . ' 23:59:59') : null;
    
        // --- Price history ---
        $db->where('bazar_id', $bazar_id);
        if ($startTs && $endTs) {
            $db->where('date', $startTs, '>=');
            $db->where('date', $endTs, '<=');
        }
        $priceHistory = $db->orderBy('date','ASC')->get(T_BAZAR_PRICE, null, ['date','price']);
    
        // --- Usage history (all, including hidden) ---
        $db->where('bazar_id', $bazar_id);
        if ($startTs && $endTs) {
            $db->where('date', $startTs, '>=');
            $db->where('date', $endTs, '<=');
        }
        $usageHistory_all = $db->orderBy('date','ASC')->get(T_BAZAR_QUANTITY, null, ['date','quantity','used','remaining','is_hidden']);
    
        // --- Usage history (visible only, for charts/tables) ---
        $db->where('bazar_id', $bazar_id);
        if ($startTs && $endTs) {
            $db->where('date', $startTs, '>=');
            $db->where('date', $endTs, '<=');
        }
        $db->where('is_hidden', 0);
        $usageHistory_visible = $db->orderBy('date','ASC')->get(T_BAZAR_QUANTITY, null, ['date','quantity','used','remaining']);
    
        echo json_encode([
            'status' => 200,
            'priceHistory' => $priceHistory,
            'usageHistory_all' => $usageHistory_all,
            'usageHistory_visible' => $usageHistory_visible
        ]);
        exit;
    }

    // ===============================
    //  📦 FETCH STOCK STATUS (item-wise for admin table)
    // ===============================
    if ($s === 'fetch_stock_status') {
        $start  = isset($_POST['start']) ? (int)$_POST['start'] : 0;
        $length = isset($_POST['length']) ? (int)$_POST['length'] : 10;
        $draw   = isset($_POST['draw']) ? (int)$_POST['draw'] : 1;
    
        // Accept either month_year (YYYY-MM) or data_start/data_end (YYYY-MM-DD)
        $month_year = trim($_POST['month_year'] ?? '');
        $data_start = trim($_POST['data_start'] ?? '');
        $data_end   = trim($_POST['data_end'] ?? '');
    
        if ($month_year) {
            // build month range (YYYY-MM)
            $parts = explode('-', $month_year);
            if (count($parts) === 2) {
                $y = (int)$parts[0];
                $m = (int)$parts[1];
                // first day of month
                $startTs = strtotime(sprintf('%04d-%02d-01 00:00:00', $y, $m));
                // last day of month
                $endTs = strtotime(date('Y-m-t 23:59:59', $startTs));
            } else {
                // fallback to current month
                $startTs = strtotime(date('Y-m-01 00:00:00'));
                $endTs   = strtotime(date('Y-m-t 23:59:59'));
            }
        } elseif ($data_start && $data_end) {
            $startTs = strtotime($data_start . ' 00:00:00');
            $endTs   = strtotime($data_end . ' 23:59:59');
        } else {
            // default to current month
            $startTs = strtotime(date('Y-m-01 00:00:00'));
            $endTs   = strtotime(date('Y-m-t 23:59:59'));
        }
    
        // fetch all bazar items (item-wise)
        $items = $db->orderBy('name','ASC')->get(T_BAZAR, null, ['id','icon','name','unit','quantity','price','updated_at','low_bazar_threshold']);
    
        $rows = [];
        $sl = 0;
        foreach ($items as $it) {
            $sl++;
            $id = (int)$it->id;
    
            // -------------------------
            // prev_bazar: last visible remaining BEFORE startTs
            // -------------------------
            $lastVisible = $db->where('bazar_id', $id)
                ->where('is_hidden', 0)
                ->where('date', $startTs, '<')
                ->orderBy('date', 'DESC')
                ->getOne(T_BAZAR_QUANTITY, ['remaining']);
            
            $prev_bazar = $lastVisible && isset($lastVisible->remaining) 
                ? (int)$lastVisible->remaining 
                : 0;
            
            // -------------------------
            // used_bazar: sum of usages between startTs and endTs (inclusive)
            // -------------------------
            $used_bazar = (int) (
                $db->where('bazar_id', $id)
                   ->where('date', [$startTs, $endTs], 'BETWEEN')
                   ->getValue(T_BAZAR_USAGE, 'SUM(quantity)') ?? 0
            );
    
    
            // -------------------------
            // current quantity & latest price & last updated
            // -------------------------
            $curr_bazar = $it->quantity;
    
            $latestPrice = $db->where('bazar_id', $id)->orderBy('date','DESC')->getOne(T_BAZAR_PRICE, 'price');
            $price = $latestPrice ? (float)$latestPrice->price : (float)$it->price;
            $total_price = $price * ($used_bazar + $curr_bazar);
    
            $last_updated = (int)$it->updated_at;
    
            // action (delete entire item)
            $actions = (Wo_IsAdmin() || Wo_IsModerator())
                ? '<a href="javascript:;" class="text-danger" onclick="deleteItem('.$id.')"><i class="bx bx-trash"></i></a>'
                : '';
    
            $rows[] = [
                'id'               => $id,
                'sl'             => $sl,
                'item'             => '<i class="' . $it->icon . '"></i> ' . htmlspecialchars($it->name),
                'unit'             => htmlspecialchars($it->unit),
                'prev_bazar'       => $prev_bazar . ' ' . htmlspecialchars($it->unit),
                'used_bazar'       => $used_bazar . ' ' . htmlspecialchars($it->unit),
                'quantity'         => $curr_bazar . ' ' . htmlspecialchars($it->unit),
                'price'            => '৳' . number_format($price, 2),
                'total'            => '৳' . number_format($total_price, 2),
                'last_update'      => $last_updated ? date('d-m-Y H:i', $last_updated) : '',
                'actions'          => $actions,
                'bazar_threshold'  => (int)$it->low_bazar_threshold
            ];
        }
    
        $recordsTotal = count($rows);
        $paged = array_slice($rows, $start, $length);
    
        echo json_encode([
            'draw'            => $draw,
            'recordsTotal'    => $recordsTotal,
            'recordsFiltered' => $recordsTotal,
            'data'            => $paged
        ]);
        exit;
    }
    
    // ===============================
    //  🛠️  EDIT BAZAR ITEM MODAL
    // ===============================
    if ($s == 'edit_modal') {
        $id = $_POST['id'] ?? '';
        if (empty($id)) {
            echo json_encode(['status' => 400, 'message' => 'Something went wrong!']);
        } else {
            $bazar = $db->where('id', $id)->getOne(T_BAZAR);
            echo json_encode(['status' => 200, 'result' => Wo_LoadManagePage('bazar_manage/edit')]);
        }
        exit;
    }
}
?>
