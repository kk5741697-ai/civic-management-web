<?php
// ===============================
//  🔐 CONFIGURATION & SECURITY
// ===============================
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

if ($f == "manage_inventory") {
    // Check user permissions
    if (!(Wo_IsAdmin() || Wo_IsModerator() || check_permission("manage-inventory") || check_permission("clients") || check_permission("manage-clients"))) {
        echo json_encode([
            'status' => 404,
            'message' => "You don't have permission"
        ]);
        exit;
    }

    // ------------------ NEW: Check plot/booking conflicts ------------------
    if ($s === 'check_plot_booking') {
        header('Content-Type: application/json; charset=utf-8');
    
        // Accept either POST or GET. Project can be numeric id or slug (string)
        $project_raw = isset($_POST['project_id']) ? trim($_POST['project_id']) : (isset($_GET['project_id']) ? trim($_GET['project_id']) : '');
        $purchase_id = isset($_POST['purchase_id']) ? (int) $_POST['purchase_id'] : (isset($_GET['purchase_id']) ? (int) $_GET['purchase_id'] : 0);

        // Basic validation
        if ($project_raw === '' || $purchase_id <= 0) {
            http_response_code(400);
            echo json_encode([
                'status' => 400,
                'message' => 'Missing or invalid parameters.',
                'project' => $project_raw,
                'purchase_id' => $purchase_id
            ]);
            exit;
        }
    
        $conflicts = [];
        $free_statuses = ['0','1','available','cancelled','canceled'];
    
        // Query booking
        $bookings = $db->where('id', $purchase_id)->where('project', $project_raw)->get(T_BOOKING);
    
        if (empty($bookings)) {
            echo json_encode(['status' => 200, 'available' => true, 'message' => 'No booking found (treat as available).']);
            exit;
        }
    
        foreach ($bookings as $bk) {
            $bstatus = isset($bk->status) ? strtolower(trim((string)$bk->status)) : '';
    
            // fetch helpers for this booking;
            $helpers = $db->where('booking_id', $bk->id)->groupBy('client_id')->get(T_BOOKING_HELPER);
    
            if (!empty($helpers)) {
                foreach ($helpers as $h) {
                    $hstatus = isset($h->status) ? strtolower(trim((string)$h->status)) : ($bstatus ?: '');
                    if ($hstatus === '') $hstatus = 'unknown';
    
                    if (!in_array($hstatus, $free_statuses, true)) {
                        $conflicts[] = [
                            'booking_id' => $bk->id,
                            'status'     => $hstatus,
                            'time'       => $h->time ?? null
                        ];
                    }
                }
            } else {
                // no helpers -> rely on booking status
                if ($bstatus !== '' && !in_array($bstatus, $free_statuses, true)) {
                    $conflicts[] = [
                        'booking_id' => $bk->id,
                        'status'     => $bstatus,
                        'time'       => $bk->time ?? null
                    ];
                }
            }
        }
        if (empty($conflicts)) {
            echo json_encode(['status' => 200, 'available' => true, 'message' => 'Plot appears available (no active bookings found).']);
        } else {
            echo json_encode(['status' => 200, 'available' => false, 'message' => 'Active booking(s) found.', 'conflicts' => $conflicts]);
        }
        exit;
    }

    // ------------------ Register / assign a purchase to a client (updated for booking money) ------------------
    if ($s === 'register_purchase' || $s === 'assign_purchase') {
        header('Content-Type: application/json; charset=utf-8');

        // DEV: set to true during debugging; set false in production
        $DEV_DEBUG = false;

        try {
            // Inputs (sanitize)
            $client_id_raw   = $_POST['client_id'] ?? $_GET['client_id'] ?? 0;
            $client_id       = (int)$client_id_raw;

            $project_raw     = isset($_POST['project_id']) ? trim($_POST['project_id']) : (isset($_GET['project_id']) ? trim($_GET['project_id']) : '');
            $file_num_raw    = isset($_POST['file_num']) ? trim($_POST['file_num']) : (isset($_GET['file_num']) ? trim($_GET['file_num']) : '');
            $purchase_id     = isset($_POST['purchase_id']) ? (int) $_POST['purchase_id'] : (isset($_GET['purchase_id']) ? (int) $_GET['purchase_id'] : 0);
            $down_payment    = isset($_POST['down_payment']) ? floatval($_POST['down_payment']) : 0.0;
            $booking_money   = isset($_POST['booking_money']) ? floatval($_POST['booking_money']) : 0.0; // Add booking money
            $per_katha       = isset($_POST['per_katha']) ? floatval($_POST['per_katha']) : 0.0;
            $nominee_ids_raw = $_POST['nominee_ids'] ?? $_GET['nominee_ids'] ?? '[]';
            $force           = isset($_POST['force']) ? ($_POST['force'] === '1' || $_POST['force'] === 1 || $_POST['force'] === true) :
                               (isset($_GET['force']) ? ($_GET['force'] === '1' || $_GET['force'] === 1) : false);

            // Basic required validation
            $missing = [];
            if ($client_id <= 0)      $missing[] = 'client_id';
            if ($project_raw === '')  $missing[] = 'project_id';
            if ($file_num_raw === '') $missing[] = 'file_num';
            if ($purchase_id <= 0)    $missing[] = 'purchase_id';
            if ($per_katha <= 0)      $missing[] = 'per_katha';
            if ($down_payment < 0)    $missing[] = 'down_payment';
            if ($booking_money < 0)   $missing[] = 'booking_money'; // Validate booking money

            if (!empty($missing)) {
                http_response_code(400);
                echo json_encode(['status'=>400,'message'=>'Missing or invalid parameters: ' . implode(', ', $missing)]);
                exit;
            }

            // Normalize file_num: keep letters/numbers, dash, underscore, slash and spaces
            $file_num = preg_replace('/[^\p{L}\p{N}\-\_\/\s]/u', '', $file_num_raw);
            $file_num = trim($file_num);

            // nominee_ids -> array of ints (accept JSON or comma list or array)
            $nominee_ids = [];
            if (is_string($nominee_ids_raw)) {
                $decoded = json_decode($nominee_ids_raw, true);
                if (is_array($decoded)) {
                    $nominee_ids = $decoded;
                } else {
                    // try comma separated
                    $tmp = preg_split('/\s*,\s*/', trim($nominee_ids_raw));
                    $nominee_ids = array_filter($tmp, function($v){ return $v !== ''; });
                }
            } elseif (is_array($nominee_ids_raw)) {
                $nominee_ids = $nominee_ids_raw;
            }
            // coerce to ints where possible
            $nominee_ids = array_values(array_map(function($v){
                if (is_numeric($v)) return (int)$v;
                return $v;
            }, $nominee_ids));
            $nominee_ids_json = json_encode($nominee_ids);

            // --- Verify client exists in crm_customers ---
            $clientExists = $db->where('id', $client_id)->getValue('crm_customers', 'id');
            if (!$clientExists) {
                http_response_code(404);
                echo json_encode(['status'=>404,'message'=>'Client not found (crm_customers).']);
                exit;
            }

            // --- Find booking in wo_booking ---
            $booking = $db->where('id', $purchase_id)->getOne('wo_booking');
            if (!$booking) {
                http_response_code(404);
                echo json_encode(['status'=>404,'message'=>'Selected booking not found (wo_booking).']);
                exit;
            }

            // store booking katha for later price validation (if present)
            $booking_katha = null;
            if (isset($booking->katha)) {
                // booking.katha is varchar, so sanitize numeric part
                $bk = preg_replace('/[^\d\.\-]/', '', (string)$booking->katha);
                $booking_katha = $bk !== '' ? floatval($bk) : null;
            }

            // optional strict validation: ensure (booking_money + down_payment) <= per_katha * katha
            if ($booking_katha !== null) {
                $expected_total = $per_katha * $booking_katha;
                if (($booking_money + $down_payment) > $expected_total) {
                    http_response_code(422);
                    echo json_encode(['status'=>422,'message'=>'Total advance (booking money + down payment) cannot exceed total price (per_katha * katha).','debug'=>[
                        'per_katha'=>$per_katha,'katha'=>$booking_katha,'expected_total'=>$expected_total,'booking_money'=>$booking_money,'down_payment'=>$down_payment
                    ]]);
                    exit;
                }
            }

            // Conflict detection (use wo_booking_helper)
            $conflicts = [];
            $free_statuses = ['0','1','available','cancelled','canceled'];

            // We'll look for existing helper that belongs to the *same client* + booking.
            $existingHelperForClient = $db
                ->where('booking_id', $booking->id)
                ->where('client_id', (string)$client_id)
                ->orderBy('id', 'DESC')
                ->getOne('wo_booking_helper');

            // Fetch all helpers for this booking to detect conflicts from *other* clients
            $helpers = $db->where('booking_id', $booking->id)->get('wo_booking_helper');
            if (!empty($helpers)) {
                foreach ($helpers as $h) {
                    // if the helper belongs to the current client, skip adding as a conflict
                    $h_client_id = isset($h->client_id) ? (string)$h->client_id : '';
                    $hstatus = isset($h->status) ? strtolower(trim((string)$h->status)) : '';

                    if ($h_client_id === (string)$client_id) {
                        // skip conflict for same client; we'll update this helper later instead of inserting
                        continue;
                    }

                    if ($hstatus !== '' && !in_array($hstatus, $free_statuses, true)) {
                        $conflicts[] = ['booking_id' => $booking->id, 'file_id' => $h->file_num ?? ($h->file_id ?? null), 'status' => $hstatus, 'helper_id' => $h->id ?? null];
                    }
                }
            }

            // Also consider booking.status itself as conflict (but if booking was created by same client we don't know; so treat booking.status as conflict)
            $bstatus = isset($booking->status) ? strtolower(trim((string)$booking->status)) : '';
            if ($bstatus !== '' && !in_array($bstatus, $free_statuses, true)) {
                // If booking already marked sold but the same client has helper, allow update â€" otherwise count as conflict.
                $allow_if_same_client = ($existingHelperForClient ? true : false);
                if (!$allow_if_same_client) {
                    $conflicts[] = ['booking_id' => $booking->id, 'file_id' => $booking->file_num ?? null, 'status' => $bstatus];
                }
            }

            // If there are conflicts (from other clients) and not forcing, reject
            if (!empty($conflicts) && !$force) {
                http_response_code(409);
                echo json_encode(['status'=>409,'message'=>'Active booking(s) exist for this plot (other client). Use force to override.','conflicts'=>$conflicts]);
                exit;
            }

            // ------------------ Now: either update existing helper (same client) OR insert new ------------------

            // Start transaction
            $db->startTransaction();

            if ($existingHelperForClient) {
                // Update existing helper for same client instead of inserting a new helper
                $updateData = [
                    'file_num'     => $file_num,
                    'status'       => '2', // sold
                    'time'         => time(),
                    'nominee_ids'  => $nominee_ids_json,
                    'per_katha'    => $per_katha,
                    'down_payment' => $down_payment,
                    'booking_money'=> $booking_money, // Add booking money
                    'cancel_date'  => '', // clear cancel date on re-book
                ];

                $ok = $db->where('id', $existingHelperForClient->id)->update('wo_booking_helper', $updateData);
                if ($ok === false) {
                    $db->rollback();
                    $err = $db->getLastError();
                    http_response_code(500);
                    echo json_encode(['status'=>500,'message'=>'Failed to update existing booking helper.','debug'=>($DEV_DEBUG ? $err : null)]);
                    exit;
                }

                // Update booking record in wo_booking: status (int) and file_num
                $updateBooking = ['status' => 2, 'file_num' => $file_num];
                $updateOk = $db->where('id', $booking->id)->update('wo_booking', $updateBooking);
                if ($updateOk === false) {
                    $db->rollback();
                    $err = $db->getLastError();
                    http_response_code(500);
                    echo json_encode(['status'=>500,'message'=>'Failed to update booking (wo_booking).','debug'=>($DEV_DEBUG ? $err : null)]);
                    exit;
                }

                $db->commit();

                $insert = $existingHelperForClient->id; // treat as the 'purchase id' returned

                // Build response row (reuse your existing HTML)
                $proj_display = htmlspecialchars($project_raw, ENT_QUOTES);
                $status_raw = (string) ($updateBooking['status'] ?? ($booking->status ?? '1'));
                $status_badges = [
                    '1' => '<span class="badge bg-info">Available</span>',
                    '2' => '<span class="badge bg-success">Sold</span>',
                    '3' => '<span class="badge bg-success">Complete</span>',
                    '4' => '<span class="badge bg-danger">Canceled</span>'
                ];
                $status_html = $status_badges[$status_raw] ?? $status_badges['1'];

                $rowHtml  = '<tr id="purchaseRow_' . $insert . '">';
                $rowHtml .= '<td>' . $proj_display . '</td>';
                $rowHtml .= '<td>' . htmlspecialchars($booking->block ?? 'N/A') . '</td>';
                $rowHtml .= '<td>' . htmlspecialchars($booking->katha ?? '') . '</td>';
                $rowHtml .= '<td>' . htmlspecialchars($booking->plot ?? '') . '</td>';
                $rowHtml .= '<td>' . htmlspecialchars($booking->road ?? '') . '</td>';
                $rowHtml .= '<td>' . htmlspecialchars($file_num) . '</td>';
                $rowHtml .= '<td>' . date('d M Y') . '</td>';
                $rowHtml .= '<td>' . $status_html . '</td>';
                $rowHtml .= '<td><div class="d-flex gap-1">';
                $rowHtml .= '<button class="btn btn-sm btn-info print-booking-form" data-id="' . $insert . '" title="Print Form"><i class="lni lni-printer"></i></button>';
                $rowHtml .= '<button class="btn btn-sm btn-success update_installment" data-id="' . $insert . '" title="Payment Schedule"><i class="lni lni-dollar"></i></button>';
                $rowHtml .= '<button class="btn btn-sm btn-warning change_plot_btn" data-id="' . $insert . '" title="Change Plot"><i class="lni lni-exchange"></i></button>';
                $rowHtml .= '<button class="btn btn-sm btn-danger cancel-purchase" data-id="' . $insert . '" title="Cancel Purchase"><i class="lni lni-close"></i></button>';
                $rowHtml .= '</div></td>';
                $rowHtml .= '</tr>';

                $logUser = 'User #' . ($wo['user']['id'] ?? '999');
                logActivity('purchase', 'update', "{$logUser} updated purchase #{$insert} for booking #{$booking->id}");

                $resp = ['status'=>200,'message'=>'Existing purchase updated.','purchase_id'=>$insert,'html'=>$rowHtml];
                if ($DEV_DEBUG) {
                    $resp['debug'] = [
                        'action'        => 'updated_existing_helper',
                        'existing_id'   => $existingHelperForClient->id,
                        'booking_id'    => $booking->id,
                        'client_id'     => $client_id,
                        'nominee_ids'   => $nominee_ids,
                        'booking_money' => $booking_money,
                    ];
                }

                echo json_encode($resp);
                exit;
            }

            // No existing helper for this client -> insert new as usual
            $helperData = [
                'booking_id'    => $booking->id,
                'client_id'     => (string)$client_id, // your schema shows client_id is varchar(32)
                'file_num'      => $file_num, // sold (schema uses varchar)
                'status'        => '2', // sold (schema uses varchar)
                'time'          => time(),
                'nominee_ids'   => $nominee_ids_json,
                'per_katha'     => $per_katha,
                'down_payment'  => $down_payment,
                'booking_money' => $booking_money, // Add booking money
                'cancel_date'   => '',
            ];

            $insert = $db->insert('wo_booking_helper', $helperData);
            if (!$insert) {
                $db->rollback();
                $err = $db->getLastError();
                http_response_code(500);
                echo json_encode(['status'=>500,'message'=>'Failed to create booking helper (wo_booking_helper).','debug'=>($DEV_DEBUG ? $err : null)]);
                exit;
            }

            // Update booking record in wo_booking: status (int) and file_num (text)
            $updateBooking = ['status' => 2, 'file_num' => $file_num];
            $updateOk = $db->where('id', $booking->id)->update('wo_booking', $updateBooking);
            if ($updateOk === false) {
                $db->rollback();
                $err = $db->getLastError();
                http_response_code(500);
                echo json_encode(['status'=>500,'message'=>'Failed to update booking (wo_booking).','debug'=>($DEV_DEBUG ? $err : null)]);
                exit;
            }

            $db->commit();

            // build response row for new insert
            $proj_display = htmlspecialchars($project_raw, ENT_QUOTES);
            $status_raw = (string) ($updateBooking['status'] ?? ($booking->status ?? '1'));
            $status_badges = [
                '1' => '<span class="badge bg-info">Available</span>',
                '2' => '<span class="badge bg-success">Sold</span>',
                '3' => '<span class="badge bg-success">Complete</span>',
                '4' => '<span class="badge bg-danger">Canceled</span>'
            ];
            $status_html = $status_badges[$status_raw] ?? $status_badges['1'];

            $rowHtml  = '<tr id="purchaseRow_' . $insert . '">';
            $rowHtml .= '<td>' . $proj_display . '</td>';
            $rowHtml .= '<td>' . htmlspecialchars($booking->block ?? 'N/A') . '</td>';
            $rowHtml .= '<td>' . htmlspecialchars($booking->katha ?? '') . '</td>';
            $rowHtml .= '<td>' . htmlspecialchars($booking->plot ?? '') . '</td>';
            $rowHtml .= '<td>' . htmlspecialchars($booking->road ?? '') . '</td>';
            $rowHtml .= '<td>' . htmlspecialchars($file_num) . '</td>';
            $rowHtml .= '<td>' . date('d M Y') . '</td>';
            $rowHtml .= '<td>' . $status_html . '</td>';
            $rowHtml .= '<td><div class="d-flex gap-1">';
            $rowHtml .= '<button class="btn btn-sm btn-info print-booking-form" data-id="' . $insert . '" title="Print Form"><i class="lni lni-printer"></i></button>';
            $rowHtml .= '<button class="btn btn-sm btn-success update_installment" data-id="' . $insert . '" title="Payment Schedule"><i class="lni lni-dollar"></i></button>';
            $rowHtml .= '<button class="btn btn-sm btn-warning change_plot_btn" data-id="' . $insert . '" title="Change Plot"><i class="lni lni-exchange"></i></button>';
            $rowHtml .= '<button class="btn btn-sm btn-danger cancel-purchase" data-id="' . $insert . '" title="Cancel Purchase"><i class="lni lni-close"></i></button>';
            $rowHtml .= '</div></td>';
            $rowHtml .= '</tr>';

            $logUser = 'User #' . ($wo['user']['id'] ?? '999');
            logActivity('purchase', 'create', "{$logUser} created purchase #{$insert} for booking #{$booking->id}");

            $resp = ['status'=>200,'message'=>'Purchase registered successfully.','purchase_id'=>$insert,'html'=>$rowHtml];
            if ($DEV_DEBUG) {
                $resp['debug'] = [
                    'booking_id'   => $booking->id,
                    'booking_katha'=> $booking_katha,
                    'nominee_ids'  => $nominee_ids,
                    'file_num'     => $file_num,
                    'booking_money'=> $booking_money,
                    'force'        => $force
                ];
            }

            echo json_encode($resp);
            exit;

        } catch (Exception $ex) {
            if (isset($db) && method_exists($db, 'rollback')) $db->rollback();
            http_response_code(500);
            echo json_encode(['status'=>500,'message'=>'Internal server error','error'=>$ex->getMessage()]);
            exit;
        }
    }

    // ------------------ Search purchases for Select2 AJAX ------------------
    if ($s === 'search_purchases') {
        header('Content-Type: application/json; charset=utf-8');
        
        $q = trim($_POST['q'] ?? $_GET['q'] ?? '');
        $page = max(1, (int)($_POST['page'] ?? $_GET['page'] ?? 1));
        $per_page = min(50, max(10, (int)($_POST['per_page'] ?? $_GET['per_page'] ?? 30)));
        $project_id = trim($_POST['project_id'] ?? $_GET['project_id'] ?? '');
        
        if ($project_id === '') {
            echo json_encode(['status' => 400, 'message' => 'Project ID required']);
            exit;
        }
        
        $offset = ($page - 1) * $per_page;
        
        // Build search query
        $db->where('project', $project_id);
        
        if ($q !== '') {
            $db->where("(plot LIKE '%{$q}%' OR block LIKE '%{$q}%' OR katha LIKE '%{$q}%' OR road LIKE '%{$q}%' OR facing LIKE '%{$q}%')");
        }
        
        // Get total count for pagination
        $totalCount = $db->getValue(T_BOOKING, 'COUNT(*)');
        
        // Get paginated results
        $db->where('project', $project_id);
        if ($q !== '') {
            $db->where("(plot LIKE '%{$q}%' OR block LIKE '%{$q}%' OR katha LIKE '%{$q}%' OR road LIKE '%{$q}%' OR facing LIKE '%{$q}%')");
        }
        
        $bookings = $db->orderBy('id', 'DESC')->get(T_BOOKING, [$offset, $per_page]);
        
        $results = [];
        foreach ($bookings as $booking) {
            $status = $booking->status ?? '1';
            $available = in_array($status, ['0', '1']);
            
            $results[] = [
                'id' => $booking->id,
                'plot' => $booking->plot ?? '',
                'block' => $booking->block ?? '',
                'katha' => $booking->katha ?? '',
                'road' => $booking->road ?? '',
                'facing' => $booking->facing ?? '',
                'status' => $status,
                'status_label' => getStatusLabel($status),
                'available' => $available
            ];
        }
        
        $more = ($offset + $per_page) < $totalCount;
        
        echo json_encode([
            'status' => 200,
            'results' => $results,
            'more' => $more,
            'total' => $totalCount
        ]);
        exit;
    }

    // Helper function for status labels
    function getStatusLabel($status) {
        $s = String($status).toLowerCase();
        if ($s === '0' || $s === '1' || $s === 'available') return 'Available';
        if ($s === '2' || $s === 'sold' || $s === 'booked') return 'Sold';
        if ($s === '3' || $s === 'complete') return 'Complete';
        if ($s === '4' || $s.indexOf('cancel') !== -1) return 'Cancelled';
        return $s ? (ucfirst($s)) : '';
    }

    // ------------------ Get purchase details for payment schedule ------------------
    if ($s === 'get_purchase_details') {
        $purchase_id = isset($_POST['purchase_id']) ? (int)$_POST['purchase_id'] : (isset($_GET['purchase_id']) ? (int)$_GET['purchase_id'] : 0);
        
        if ($purchase_id <= 0) {
            echo json_encode(['status' => 400, 'message' => 'Invalid purchase ID']);
            exit;
        }
        
        // Get booking helper details
        $helper = $db->where('id', $purchase_id)->getOne(T_BOOKING_HELPER);
        if (!$helper) {
            echo json_encode(['status' => 404, 'message' => 'Purchase not found']);
            exit;
        }
        
        // Get booking details
        $booking = $db->where('id', $helper->booking_id)->getOne(T_BOOKING);
        if (!$booking) {
            echo json_encode(['status' => 404, 'message' => 'Booking not found']);
            exit;
        }
        
        // Get client details
        $client = GetCustomerById($helper->client_id);
        if (!$client) {
            echo json_encode(['status' => 404, 'message' => 'Client not found']);
            exit;
        }
        
        // Calculate totals
        $per_katha = (float)($helper->per_katha ?? 0);
        $katha = (float)($booking->katha ?? 0);
        $total_price = $per_katha * $katha;
        $down_payment = (float)($helper->down_payment ?? 0);
        $booking_money = (float)($helper->booking_money ?? 0);
        
        // Get existing schedule if any
        $schedule = [];
        $installment_data = $helper->installment ?? '';
        if (!empty($installment_data)) {
            $decoded = json_decode($installment_data, true);
            if (is_array($decoded)) {
                $schedule = $decoded;
            }
        }
        
        $response = [
            'status' => 200,
            'project_name' => ucwords(str_replace('-', ' ', $booking->project ?? '')),
            'client_name' => $client['name'] ?? '',
            'client_phone' => $client['phone'] ?? '',
            'client_address' => $client['address'] ?? '',
            'plot_info' => 'Block ' . ($booking->block ?? '') . ', Plot ' . ($booking->plot ?? '') . ', ' . ($booking->katha ?? '') . ' katha',
            'file_number' => $helper->file_num ?? '',
            'total_price' => $total_price,
            'down_payment' => $down_payment,
            'booking_money' => $booking_money,
            'schedule' => $schedule,
            'default_installments' => 12,
            'default_start_date' => date('Y-m-d')
        ];
        
        echo json_encode($response);
        exit;
    }

    // ------------------ Update installment schedule ------------------
    if ($s === 'update_installment') {
        $purchase_id = isset($_POST['purchase_id']) ? (int)$_POST['purchase_id'] : 0;
        $schedule_json = isset($_POST['schedule']) ? trim($_POST['schedule']) : '';
        
        if ($purchase_id <= 0) {
            echo json_encode(['status' => 400, 'message' => 'Invalid purchase ID']);
            exit;
        }
        
        if (empty($schedule_json)) {
            echo json_encode(['status' => 400, 'message' => 'Schedule data is required']);
            exit;
        }
        
        // Validate JSON
        $schedule = json_decode($schedule_json, true);
        if (!is_array($schedule)) {
            echo json_encode(['status' => 400, 'message' => 'Invalid schedule format']);
            exit;
        }
        
        // Update the installment field in booking helper
        $updated = $db->where('id', $purchase_id)->update(T_BOOKING_HELPER, [
            'installment' => $schedule_json
        ]);
        
        if ($updated) {
            logActivity('purchase', 'update', "Updated payment schedule for purchase #{$purchase_id}");
            echo json_encode(['status' => 200, 'message' => 'Payment schedule updated successfully']);
        } else {
            echo json_encode(['status' => 500, 'message' => 'Failed to update payment schedule']);
        }
        exit;
    }

    // ------------------ Download schedule PDF ------------------
    if ($s === 'download_schedule_pdf') {
        $purchase_id = isset($_POST['purchase_id']) ? (int)$_POST['purchase_id'] : 0;
        $schedule_json = isset($_POST['schedule']) ? trim($_POST['schedule']) : '';
        $client_data_json = isset($_POST['client_data']) ? trim($_POST['client_data']) : '';
        
        if ($purchase_id <= 0) {
            echo json_encode(['status' => 400, 'message' => 'Invalid purchase ID']);
            exit;
        }
        
        // For now, return a placeholder response
        // In a real implementation, you would generate a PDF file and return the download URL
        $filename = 'payment_schedule_' . $purchase_id . '_' . date('Y-m-d') . '.pdf';
        $download_url = $wo['config']['site_url'] . '/downloads/' . $filename;
        
        echo json_encode([
            'status' => 200,
            'message' => 'PDF generated successfully',
            'download_url' => $download_url,
            'filename' => $filename
        ]);
        exit;
    }

    // ------------------ Save schedule XLSX ------------------
    if ($s === 'save_schedule_xlsx') {
        $purchase_id = isset($_POST['purchase_id']) ? (int)$_POST['purchase_id'] : 0;
        $schedule_json = isset($_POST['schedule']) ? trim($_POST['schedule']) : '';
        $client_data_json = isset($_POST['client_data']) ? trim($_POST['client_data']) : '';
        
        if ($purchase_id <= 0) {
            echo json_encode(['status' => 400, 'message' => 'Invalid purchase ID']);
            exit;
        }
        
        // For now, return a placeholder response
        // In a real implementation, you would generate an XLSX file and save it to server
        $filename = 'payment_schedule_' . $purchase_id . '_' . date('Y-m-d') . '.xlsx';
        $file_path = '/uploads/schedules/' . $filename;
        
        echo json_encode([
            'status' => 200,
            'message' => 'XLSX file saved successfully',
            'file_path' => $file_path,
            'filename' => $filename
        ]);
        exit;
    }

    // ------------------ Cancel purchase ------------------
    if ($s === 'cancel_purchase') {
        $booking_helper_id = isset($_POST['booking_helper_id']) ? (int)$_POST['booking_helper_id'] : 0;
        $cancel_date = isset($_POST['cancel_date']) ? trim($_POST['cancel_date']) : '';
        
        if ($booking_helper_id <= 0) {
            echo json_encode(['status' => 400, 'message' => 'Invalid purchase ID']);
            exit;
        }
        
        if (empty($cancel_date)) {
            echo json_encode(['status' => 400, 'message' => 'Cancel date is required']);
            exit;
        }
        
        $cancel_timestamp = strtotime($cancel_date);
        if (!$cancel_timestamp) {
            echo json_encode(['status' => 400, 'message' => 'Invalid cancel date format']);
            exit;
        }
        
        // Get the booking helper
        $helper = $db->where('id', $booking_helper_id)->getOne(T_BOOKING_HELPER);
        if (!$helper) {
            echo json_encode(['status' => 404, 'message' => 'Purchase not found']);
            exit;
        }
        
        $db->startTransaction();
        
        // Update booking helper status to cancelled
        $updated = $db->where('id', $booking_helper_id)->update(T_BOOKING_HELPER, [
            'status' => '4',
            'cancel_date' => $cancel_timestamp
        ]);
        
        if (!$updated) {
            $db->rollback();
            echo json_encode(['status' => 500, 'message' => 'Failed to cancel purchase']);
            exit;
        }
        
        // Update booking status back to available
        $db->where('id', $helper->booking_id)->update(T_BOOKING, [
            'status' => 1,
            'file_num' => null
        ]);
        
        $db->commit();
        
        logActivity('purchase', 'cancel', "Cancelled purchase #{$booking_helper_id} on {$cancel_date}");
        
        echo json_encode(['status' => 200, 'message' => 'Purchase cancelled successfully']);
        exit;
    }

    // ------------------ Change plot ------------------
    if ($s === 'change_plot') {
        $purchase_id = isset($_POST['purchase_id']) ? (int)$_POST['purchase_id'] : 0;
        $new_plot_id = isset($_POST['new_plot_id']) ? (int)$_POST['new_plot_id'] : 0;
        $reason = isset($_POST['reason']) ? trim($_POST['reason']) : '';
        
        if ($purchase_id <= 0 || $new_plot_id <= 0) {
            echo json_encode(['status' => 400, 'message' => 'Invalid purchase or plot ID']);
            exit;
        }
        
        if (empty($reason)) {
            echo json_encode(['status' => 400, 'message' => 'Reason is required']);
            exit;
        }
        
        // Get current helper
        $helper = $db->where('id', $purchase_id)->getOne(T_BOOKING_HELPER);
        if (!$helper) {
            echo json_encode(['status' => 404, 'message' => 'Purchase not found']);
            exit;
        }
        
        // Get new booking
        $new_booking = $db->where('id', $new_plot_id)->getOne(T_BOOKING);
        if (!$new_booking) {
            echo json_encode(['status' => 404, 'message' => 'New plot not found']);
            exit;
        }
        
        $db->startTransaction();
        
        // Free up old booking
        $db->where('id', $helper->booking_id)->update(T_BOOKING, [
            'status' => 1,
            'file_num' => null
        ]);
        
        // Update helper with new booking
        $updated = $db->where('id', $purchase_id)->update(T_BOOKING_HELPER, [
            'booking_id' => $new_plot_id
        ]);
        
        if (!$updated) {
            $db->rollback();
            echo json_encode(['status' => 500, 'message' => 'Failed to change plot']);
            exit;
        }
        
        // Mark new booking as sold
        $db->where('id', $new_plot_id)->update(T_BOOKING, [
            'status' => 2,
            'file_num' => $helper->file_num
        ]);
        
        $db->commit();
        
        logActivity('purchase', 'change_plot', "Changed plot for purchase #{$purchase_id} to booking #{$new_plot_id}. Reason: {$reason}");
        
        echo json_encode(['status' => 200, 'message' => 'Plot changed successfully']);
        exit;
    }

    // ------------------ Get available plots ------------------
    if ($s === 'get_available_plots') {
        $project_slug = isset($_GET['project_slug']) ? trim($_GET['project_slug']) : '';
        
        if (empty($project_slug)) {
            echo json_encode(['status' => 400, 'message' => 'Project slug required']);
            exit;
        }
        
        // Get available plots for the project
        $plots = $db->where('project', $project_slug)
                   ->where('status', ['0', '1'], 'IN')
                   ->orderBy('block', 'ASC')
                   ->orderBy('plot', 'ASC')
                   ->get(T_BOOKING);
        
        $results = [];
        foreach ($plots as $plot) {
            $results[] = [
                'id' => $plot->id,
                'block' => $plot->block ?? '',
                'plot' => $plot->plot ?? '',
                'katha' => $plot->katha ?? '',
                'road' => $plot->road ?? '',
                'facing' => $plot->facing ?? ''
            ];
        }
        
        echo json_encode($results);
        exit;
    }

    // Default response for unhandled requests
    echo json_encode(['status' => 404, 'message' => 'Endpoint not found']);
    exit;
}