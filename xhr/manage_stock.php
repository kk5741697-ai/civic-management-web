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

if ($f == "manage_stock") {
    // Check user permissions
    if (!(Wo_IsAdmin() || Wo_IsModerator() || check_permission("manage-inventory"))) {
        echo json_encode([
            'status' => 404,
            'message' => "You don’t have permission"
        ]);
        exit;
    }

    // ===============================
    //  🛠️  EDIT STOCK MODAL
    // ===============================
    if ($s == 'edit_modal') {
        $id = $_POST['id'] ?? '';
        if (empty($id)) {
            echo json_encode(['status' => 400, 'message' => 'Something went wrong!']);
        } else {
            $stock = $db->where('id', $id)->getOne(T_STOCK);
            echo json_encode(['status' => 200, 'result' => Wo_LoadManagePage('stock/edit')]);
        }
        exit;
    }

    // 3A) Price History
    if ($s === 'fetch_price_history') { 
        $stock_id = (int) ($_POST['stock_id'] ?? 0);
        if (!$stock_id) {
            echo json_encode(['status'=>400,'message'=>'Invalid stock ID']);
            exit;
        }
        $rows = $db->where('stock_id',$stock_id)
                   ->orderBy('date','ASC')
                   ->arraybuilder()
                   ->get('crm_stock_price_history', null, ['date','price']);
        $data = [];
        foreach ($rows as $r) {
            $data[] = [
              'date'  => date('Y-m-d', (int)$r['date']),
              'price' => (float)$r['price']
            ];
        }
        logActivity('stock', 'view', "Fetched price history for stock_id: {$stock_id}");

        echo json_encode(['status'=>200,'data'=>$data]);
        exit;
    }
    
    // 3B) Quantity History
    if ($s === 'fetch_quantity_history') {
        $stock_id = (int) ($_POST['stock_id'] ?? 0);
        if (!$stock_id) {
            echo json_encode(['status'=>400,'message'=>'Invalid stock ID']);
            exit;
        }
        $rows = $db->where('stock_id',$stock_id)
                   ->orderBy('date','ASC')
                   ->arraybuilder()
                   ->get('crm_stock_quantity_history', null, ['date','quantity']);
        $data = [];
        foreach ($rows as $r) {
            $data[] = [
              'date'     => date('Y-m-d', (int)$r['date']),
              'quantity' => (int)$r['quantity']
            ];
        }
        logActivity('stock', 'view', "Fetched quantity history for stock_id: {$stock_id}");

        echo json_encode(['status'=>200,'data'=>$data]);
        exit;
    }


    // ===============================
    //  ➖ USE STOCK
    // ===============================
    if ($s === 'use_stock') {
        $stockIds = $_POST['stock_id'] ?? [];
        $qtys     = $_POST['quantity'] ?? [];
        $user_id  = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
        $time     = time();
    
        // Basic input validation
        if (!$user_id || count($stockIds) !== count($qtys)) {
            echo json_encode(['status' => 400, 'message' => 'Invalid input.']);
            exit;
        }
    
        $db->startTransaction();
        $errors = [];
    
        foreach ($stockIds as $i => $sid) {
            $sid = (int)$sid;
            $use = (int)$qtys[$i];
    
            if ($sid < 1 || $use < 1) {
                $errors[] = "❌Row " . ($i + 1) . " is invalid.";
                continue;
            }
    
            // Fetch stock with item name
            $stock = $db->where('id', $sid)->getOne(T_STOCK);
            if (!$stock) {
                $errors[] = "❌Row " . ($i + 1) . ": stock #{$sid} not found.";
                continue;
            }
    
            $itemName = !empty($stock->name) ? $stock->name : "Item #{$sid}";
            $curQty = (int)$stock->quantity;
    
            if ($curQty < $use) {
                $errors[] = "❌{$itemName}: only {$curQty} available.";
                continue;
            }
    
            $newQty = $curQty - $use;
    
            // Update stock quantity
            $updated = $db->where('id', $sid)->update(T_STOCK, [
                'quantity'   => $newQty,
                'updated_at' => $time
            ]);
    
            if (!$updated) {
                $errors[] = "❌{$itemName}: failed updating stock.";
                continue;
            }
    
            // Insert usage log
            $db->insert(T_STOCK_USAGE, [
                'stock_id' => $sid,
                'user_id'  => $user_id,
                'quantity' => $use,
                'date'     => $time
            ]);
    
            // Insert quantity history
            $db->insert(T_STOCK_QUANTITY, [
                'stock_id'  => $sid,
                'date'      => $time,
                'quantity'  => $newQty,
                'used'      => $use,
                'remaining' => $newQty
            ]);
            
            logActivity( 'stock', 'update', "Used {$itemName}: {$use} {$stock->unit} used_by: {$user_id}" );
        }
    
        if (!empty($errors)) {
            $db->rollback();
            echo json_encode([
                'status' => 400,
                'message' => implode("\n", $errors) // New line separated ❌ messages
            ]);
        } else {
            $db->commit();
            echo json_encode(['status' => 200, 'message' => 'All items processed successfully.']);
        }
    
        exit;
    }




    if ($s === 'fetch_usage') {
        // Read DataTables params
        $start       = isset($_POST['start'])      ? (int) $_POST['start']       : 0;
        $length      = isset($_POST['length'])     ? (int) $_POST['length']      : 10;
        $draw        = isset($_POST['draw'])       ? (int) $_POST['draw']        : 1;
        $filterUser  = isset($_POST['user_id'])    ? trim($_POST['user_id'])     : '';
        $ds          = trim($_POST['data_start']  ?? '');
        $de          = trim($_POST['data_end']    ?? '');
    
        setcookie("stock_usr", $filterUser, time() + (10 * 365 * 24 * 60 * 60), '/');
        setcookie("stock_date", $ds . ' to ' . $de, time() + (10 * 365 * 24 * 60 * 60), '/');
    
        // Set date range
        if ($ds && $de) {
            // Detect if format is YYYY-MM (monthly)
            if (preg_match('/^\d{4}-\d{2}$/', $ds) && preg_match('/^\d{4}-\d{2}$/', $de)) {
                // First day of start month
                $startTs = strtotime($ds . '-01 00:00:00');
                // Last day of end month
                $endTs   = strtotime(date("Y-m-t 23:59:59", strtotime($de . '-01')));
            } else {
                // Fallback to YYYY-MM-DD (daily)
                $startTs = strtotime("$ds 00:00:00");
                $endTs   = strtotime("$de 23:59:59");
            }
        
            $db->where('date', $startTs, '>=');
            $db->where('date', $endTs, '<=');
        }
    
        // Filter by user
        if ($filterUser !== '') {
            $db->where('user_id', $filterUser);
        }
    
        // Group by user_id, stock_id, and month
        $groupedUsages = $db->rawQuery("
            SELECT 
                stock_id,
                user_id,
                DATE_FORMAT(FROM_UNIXTIME(date), '%Y-%m') AS usage_month,
                SUM(quantity) AS total_used,
                MAX(date) AS last_date
            FROM " . T_STOCK_USAGE . "
            " . ($filterUser || $ds ? "WHERE 1=1" : "") . "
            " . ($filterUser ? " AND user_id = '{$filterUser}'" : "") . "
            " . ($ds ? " AND date >= {$startTs} AND date <= {$endTs}" : "") . "
            GROUP BY stock_id, user_id, usage_month
            ORDER BY last_date DESC
            LIMIT {$start}, {$length}
        ");
    
        // Count total grouped rows
        $countResult = $db->rawQuery("
            SELECT COUNT(*) as total 
            FROM (
                SELECT stock_id 
                FROM " . T_STOCK_USAGE . "
                " . ($filterUser || $ds ? "WHERE 1=1" : "") . "
                " . ($filterUser ? " AND user_id = '{$filterUser}'" : "") . "
                " . ($ds ? " AND date >= {$startTs} AND date <= {$endTs}" : "") . "
                GROUP BY stock_id, user_id, DATE_FORMAT(FROM_UNIXTIME(date), '%Y-%m')
            ) as grouped
        ");
    
        $recordsTotal = $countResult[0]->total;
        $recordsFiltered = $recordsTotal;
    
        // Prepare final response data
        $data = [];
    
        foreach ($groupedUsages as $r) {
            $sid = (int) $r->stock_id;
            $uid = (int) $r->user_id;
            $q   = (int) $r->total_used;
            $d   = (int) $r->last_date;
            $monthLabel = date('F Y', $d);
    
            // Stock details
            $st = $db->where('id', $sid)->getOne(T_STOCK, ['name', 'unit']);
            $sname = $st ? $st->name : '—';
            $unit  = $st && !empty($st->unit) ? $st->unit : 'pcs';
    
            // Remaining
            $rem = (int) $db->where('stock_id', $sid)->where('date', $d)->getValue(T_STOCK_QUANTITY, 'remaining');
    
            // User details
            $u = $db->where('user_id', $uid)->getOne(T_USERS, ['username', 'avatar', 'first_name', 'last_name']);
            $uname  = $u ? $u->username : '—';
            $avatar = $u && !empty($u->avatar) ? $u->avatar : 'default.png';
            $avatar_url = './../' . $avatar;
    
            $first_name = isset($u->first_name) && !empty($u->first_name) ? cleanName($u->first_name) : '';
            $last_name  = isset($u->last_name) && !empty($u->last_name) ? cleanName($u->last_name) : '';
            $full_name = (!$first_name && !$last_name) 
                ? htmlspecialchars($uname)
                : trim(htmlspecialchars($first_name . ' ' . $last_name));
    
            $data[] = [
                'date'        => $monthLabel,
                'stock_name'  => htmlspecialchars($sname),
                'quantity'    => $q . ' ' . $unit,
                'remaining'   => $rem . ' ' . $unit,
                'user_name'   => '<img src="' . $avatar_url . '" class="user-img" style="width: 24px; height: 24px; border-radius: 35px; margin-right: 8px;"> ' . $full_name
            ];
        }
    
        // ✅ Improved logActivity message
        $logParts = ["Fetched stock usage listing"];
        if ($filterUser !== '') $logParts[] = "user_id={$filterUser}";
        if ($ds && $de) $logParts[] = "date_range={$ds} to {$de}";
        else $logParts[] = "month_year={$month_year}";
        $logParts[] = "total_records=" . count($data);
        $logMessage = implode(", ", $logParts);
        logActivity('stock', 'view', $logMessage);
    
        // Send JSON
        echo json_encode([
            'draw'            => $draw,
            'recordsTotal'    => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data'            => $data
        ]);
        exit;
    }






    // ===============================
    //  ✏️ UPDATE STOCK
    // ===============================
    if ($s == 'update_stock') {
        $id = (int) ($_POST['id'] ?? 0);
        $icon = trim($_POST['icon'] ?? '');
        $name = trim($_POST['item_name'] ?? '');
        $unit = trim($_POST['unit'] ?? '');
        $quantity = isset($_POST['quantity']) ? (int) $_POST['quantity'] : null;
        $price = isset($_POST['price']) ? (float) $_POST['price'] : null;
        $low_stock = (int) ($_POST['low_stock_threshold'] ?? 0);
        $time = time();

        // Validate
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

        $stock_item = $db->where('id', $id)->getOne(T_STOCK);
        if (!$stock_item) {
            echo json_encode(['status' => 404, 'message' => 'Stock item not found.']);
            exit;
        }

        // Prevent name duplication
        if ($name !== $stock_item->name) {
            $duplicate = $db->where('name', $name)->getOne(T_STOCK);
            if ($duplicate) {
                echo json_encode(['status' => 400, 'message' => 'Another item with this name already exists.']);
                exit;
            }
        }

        // Update stock
        $db->where('id', $id)->update(T_STOCK, [
            'icon' => $icon,
            'name' => $name,
            'unit' => $unit,
            'quantity' => $quantity,
            'price' => $price,
            'low_stock_threshold' => $low_stock,
            'updated_at' => $time
        ]);
        $logDetails = "Name: {$name}, Unit: {$unit}, Quantity: {$quantity}, Price: {$price}";
        logActivity('stock', 'update', $logDetails);

        // Track price change
        if ((float)$stock_item->price !== $price) {
            $db->insert(T_STOCK_PRICE, [
                'stock_id' => $id,
                'date' => $time,
                'price' => $price
            ]);
            
            logActivity('stock', 'update', "Price updated for {$name}: old {$stock_item->price}, new {$price}");
        }

        // Track quantity change
        if ((int)$stock_item->quantity !== $quantity) {
            $total_used = $db->where('stock_id', $id)->getValue(T_STOCK_USAGE, 'SUM(quantity)') ?? 0;
            $db->insert(T_STOCK_QUANTITY, [
                'stock_id' => $id,
                'date' => $time,
                'quantity' => $quantity,
                'used' => $total_used,
                'remaining' => $quantity
            ]);
            
            logActivity('stock', 'update', "Quantity updated for {$name}: old {$stock_item->quantity}, new {$quantity}");
        }

        echo json_encode(['status' => 200, 'message' => 'Stock item updated successfully!']);
        exit;
    }

    // ===============================
    //  ➕ ADD NEW STOCK ITEM
    // ===============================
    if ($s == 'add_stock') {
        $icon = trim($_POST['icon'] ?? '');
        $name = trim($_POST['item_name'] ?? '');
        $unit = trim($_POST['unit'] ?? '');
        $quantity = isset($_POST['quantity']) ? (int) $_POST['quantity'] : null;
        $price = isset($_POST['price']) ? (float) $_POST['price'] : null;
        $low_stock = isset($_POST['low_stock_threshold']) ? (int) $_POST['low_stock_threshold'] : 15;
        $time = time();

        $missing = [];
        if ($icon === '') $missing[] = 'Icon';
        if ($name === '') $missing[] = 'Name';
        if ($unit === '') $missing[] = 'Unit';
        if ($quantity === null || $quantity < 0) $missing[] = 'Quantity';
        if ($price === null || $price < 0) $missing[] = 'Price';

        if ($missing) {
            echo json_encode(['status' => 400, 'message' => 'Missing or invalid fields: ' . implode(', ', $missing)]);
            exit;
        }

        if ($db->where('name', $name)->getOne(T_STOCK)) {
            echo json_encode(['status' => 400, 'message' => 'Item with this name already exists.']);
            exit;
        }

        $insert_id = $db->insert(T_STOCK, [
            'icon' => $icon,
            'name' => $name,
            'unit' => $unit,
            'quantity' => $quantity,
            'price' => $price,
            'low_stock_threshold' => $low_stock,
            'updated_at' => $time
        ]);

        $db->insert(T_STOCK_PRICE, ['stock_id' => $insert_id, 'date' => $time, 'price' => $price]);
        $db->insert(T_STOCK_QUANTITY, [
            'stock_id' => $insert_id,
            'date' => $time,
            'quantity' => $quantity,
            'used' => 0,
            'remaining' => $quantity
        ]);
        
        logActivity( 'stock', 'create', "Added new stock item: {$name} ({$quantity} {$unit}, price: {$price})" );
        echo json_encode(['status' => 200, 'message' => 'Stock item added successfully!']);
        exit;
    }

// ===============================
//  📊 FETCH ALL STOCK (DataTables)
// ===============================
if ($s == "fetch") {
    // 1) total count for pagination
    $countDb = clone $db;
    $recordsTotal = $countDb->getValue(T_STOCK, "COUNT(*)");

    // 2) month filter (Y‑m)
    $month_year = $_POST['month_year'] ?? date('Y-m');          // e.g. "2025-06"
    $startTs    = strtotime("$month_year-01 00:00:00");         // inclusive
    $endTs      = strtotime("+1 month", $startTs);             // exclusive

    // 3) DataTables paging
    $db->pageLimit = (int) $_POST["length"];
    $page_num = isset($_POST["start"])
              ? ($_POST["start"] / $_POST["length"] + 1)
              : 1;

    // 4) fetch page of stocks
    $stocks = $db->objectbuilder()->paginate(T_STOCK, $page_num);

    $output = [];
    foreach ($stocks as $st) {
        // — Sum of quantities added in month
        $total_added = (int) $db
            ->where('stock_id', $st->id)
            ->where('date',     $startTs,  '>=') 
            ->where('date',     $endTs,    '<')
            ->getValue(T_STOCK_QUANTITY, 'SUM(quantity)');

        // — Sum of quantities used in month
        $total_used  = (int) $db
            ->where('stock_id', $st->id)
            ->where('date',     $startTs,  '>=') 
            ->where('date',     $endTs,    '<')
            ->getValue(T_STOCK_USAGE,    'SUM(quantity)');

        // — Last-known remaining in month (if any)
        $last_remaining = $db
            ->where('stock_id', $st->id)
            ->where('date',     $startTs, '>=')
            ->where('date',     $endTs,   '<')
            ->orderBy('date',   'DESC')
            ->getValue(T_STOCK_QUANTITY, 'remaining');
        $remaining = ($last_remaining !== null) 
                   ? (int) $last_remaining 
                   : ($st->quantity);  // fallback to master table

        $is_low = $remaining <= $st->low_stock_threshold;

        // — build action buttons
        $actions = '<div class="d-flex align-items-center gap-3 fs-6 justify-content-center">'
                 . ((Wo_IsAdmin()||Wo_IsModerator())
                     ? '<a href="javascript:;" class="text-danger" title="Delete" onclick="deleteStock('.$st->id.')">'
                       .'<i class="bx bx-trash"></i></a>'
                     : '')
                 . '</div>';

        $output[] = [
            "id"              => $st->id,
            "item"            => '<i class="'.$st->icon.'"></i> '.$st->name,
            "unit"            => $st->unit,
            "quantity"        => $remaining,
            "prev_stock"      => $total_used + $remaining,            // calc separately if needed
            "used_stock"      => $total_used,
            "total_added"     => $total_added,
            "total_used"      => $total_used,
            "low_stock"       => $is_low,
            "price"           => $st->price,
            "last_update"     => date('d-m-Y h:i A', $st->updated_at),
            "actions"         => $actions,
            "stock_threshold" => $st->low_stock_threshold
        ];
    }
    
    logActivity('stock', 'view', "Fetched stock listing for Item Management, month_year={$month_year}");

    // 5) send JSON
    echo json_encode([
        "draw"            => intval($_POST["draw"]),
        "recordsTotal"    => $recordsTotal,
        "recordsFiltered" => $db->totalPages * (int)$_POST["length"],
        "data"            => $output
    ]);
    exit;
}



    if ($s === 'delete_stock') {
        // Only allow admins / moderators
        if (!Wo_IsAdmin() && !Wo_IsModerator()) {
            echo json_encode(['status' => 403, 'message' => 'Permission denied.']);
            exit;
        }
    
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        if ($id < 1) {
            echo json_encode(['status' => 400, 'message' => 'Invalid stock ID.']);
            exit;
        }
        
        $st_name = $db->where('id', $id)->getValue(T_STOCK, 'name');

        $db->startTransaction();
    
        // Delete related quantity history
        $db->where('stock_id', $id)->delete(T_STOCK_QUANTITY);
        // Delete related usage history
        $db->where('stock_id', $id)->delete(T_STOCK_USAGE);
    
        // Finally delete the master stock record
        $deleted = $db->where('id', $id)->delete(T_STOCK);
    
        if ($deleted) {
            $db->commit();
            echo json_encode([
                'status'  => 200,
                'message' => 'Stock item deleted successfully.'
            ]);
            logActivity( 'stock', 'delete', "Deleted stock item: {$id} - {$st_name}" );
        } else {
            $db->rollback();
            echo json_encode([
                'status'  => 500,
                'message' => 'Failed to delete stock item.'
            ]);
        }
        exit;
    }

}
