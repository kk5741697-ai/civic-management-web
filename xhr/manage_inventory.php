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
    if (!(Wo_IsAdmin() || Wo_IsModerator() || check_permission("manage-inventory") || check_permission("clients"))) {
        echo json_encode([
            'status' => 404,
            'message' => "You don't have permission"
        ]);
        exit;
    }

    // Get all clients for transfer functionality
    if ($s == 'get_all_clients') {
        $clients = $db->orderBy('name', 'ASC')->get(T_CUSTOMERS, null, ['id', 'name', 'phone']);
        $result = [];
        foreach ($clients as $client) {
            $result[] = [
                'id' => $client->id,
                'name' => $client->name,
                'phone' => $client->phone
            ];
        }
        echo json_encode($result);
        exit;
    }

    // Get purchase details
    if ($s == 'get_purchase_details') {
        $purchase_id = isset($_GET['purchase_id']) ? (int)$_GET['purchase_id'] : 0;
        if (!$purchase_id) {
            echo json_encode(['status' => 400, 'message' => 'Invalid purchase ID']);
            exit;
        }

        $purchase = $db->where('id', $purchase_id)->getOne(T_BOOKING_HELPER);
        if (!$purchase) {
            echo json_encode(['status' => 404, 'message' => 'Purchase not found']);
            exit;
        }

        $booking = $db->where('id', $purchase->booking_id)->getOne(T_BOOKING);
        $project = $db->where('slug', $booking->project)->getOne(T_PROJECTS);

        echo json_encode([
            'status' => 200,
            'project_name' => $project->name ?? '',
            'project_slug' => $booking->project ?? '',
            'current_plot' => $booking->plot ?? '',
            'current_block' => $booking->block ?? ''
        ]);
        exit;
    }

    // Get available plots for project
    if ($s == 'get_available_plots') {
        $project_slug = isset($_GET['project_slug']) ? trim($_GET['project_slug']) : '';
        if (!$project_slug) {
            echo json_encode([]);
            exit;
        }

        $plots = $db->where('project', $project_slug)->where('status', '1')->get(T_BOOKING, null, ['id', 'block', 'plot', 'katha', 'road', 'facing']);
        $result = [];
        foreach ($plots as $plot) {
            $result[] = [
                'id' => $plot->id,
                'block' => $plot->block,
                'plot' => $plot->plot,
                'katha' => $plot->katha,
                'road' => $plot->road,
                'facing' => $plot->facing
            ];
        }
        echo json_encode($result);
        exit;
    }

    // Search purchases for Select2
    if ($s == 'search_purchases') {
        $q = isset($_GET['q']) ? trim($_GET['q']) : '';
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 30;
        $project_id = isset($_GET['project_id']) ? trim($_GET['project_id']) : '';

        $offset = ($page - 1) * $per_page;

        $db->where('status', '1'); // Available plots only
        if ($project_id) {
            $db->where('project', $project_id);
        }
        if ($q) {
            $db->where('(plot LIKE ? OR block LIKE ? OR road LIKE ?)', ["%$q%", "%$q%", "%$q%"]);
        }

        $total = $db->getValue(T_BOOKING, 'COUNT(*)');
        $plots = $db->orderBy('plot', 'ASC')->get(T_BOOKING, [$offset, $per_page], ['id', 'project', 'block', 'plot', 'katha', 'road', 'facing', 'status']);

        $results = [];
        foreach ($plots as $plot) {
            $results[] = [
                'id' => $plot->id,
                'text' => 'Block ' . $plot->block . ' • Plot ' . $plot->plot . ' • ' . $plot->katha . ' katha',
                'plot' => $plot->plot,
                'block' => $plot->block,
                'katha' => $plot->katha,
                'road' => $plot->road,
                'facing' => $plot->facing,
                'status' => $plot->status,
                'status_label' => 'Available'
            ];
        }

        echo json_encode([
            'results' => $results,
            'more' => ($offset + $per_page) < $total
        ]);
        exit;
    }

    // Register new purchase
    if ($s == 'register_purchase') {
        $client_id = isset($_POST['client_id']) ? (int)$_POST['client_id'] : 0;
        $project_id = isset($_POST['project_id']) ? trim($_POST['project_id']) : '';
        $purchase_id = isset($_POST['purchase_id']) ? (int)$_POST['purchase_id'] : 0;
        $file_num = isset($_POST['file_num']) ? trim($_POST['file_num']) : '';
        $per_katha = isset($_POST['per_katha']) ? (float)$_POST['per_katha'] : 0;
        $booking_money = isset($_POST['booking_money']) ? (float)$_POST['booking_money'] : 0;
        $down_payment = isset($_POST['down_payment']) ? (float)$_POST['down_payment'] : 0;
        $purchase_date = isset($_POST['purchase_date']) ? $_POST['purchase_date'] : date('Y-m-d');
        $nominee_ids = isset($_POST['nominee_ids']) ? $_POST['nominee_ids'] : [];
        $force = isset($_POST['force']) ? (bool)$_POST['force'] : false;

        // Validation
        if (!$client_id || !$project_id || !$purchase_id || !$file_num || $per_katha <= 0) {
            echo json_encode(['status' => 400, 'message' => 'Missing required fields']);
            exit;
        }

        // Check if plot is available
        $booking = $db->where('id', $purchase_id)->getOne(T_BOOKING);
        if (!$booking) {
            echo json_encode(['status' => 404, 'message' => 'Plot not found']);
            exit;
        }

        if ($booking->status != '1' && !$force) {
            echo json_encode(['status' => 409, 'message' => 'This plot is already booked. Do you want to force assign?']);
            exit;
        }

        $db->startTransaction();

        // Update booking status
        $db->where('id', $purchase_id)->update(T_BOOKING, [
            'status' => '2',
            'file_num' => $file_num
        ]);

        // Create booking helper
        $helper_id = $db->insert(T_BOOKING_HELPER, [
            'booking_id' => $purchase_id,
            'client_id' => $client_id,
            'file_num' => $file_num,
            'status' => '2',
            'time' => strtotime($purchase_date),
            'nominee_ids' => json_encode($nominee_ids),
            'per_katha' => $per_katha,
            'down_payment' => $down_payment,
            'booking_money' => $booking_money
        ]);

        if ($helper_id) {
            $db->commit();
            
            // Generate HTML for new row (optional)
            $purchase_data = [
                'id' => $helper_id,
                'client' => GetCustomerById($client_id),
                'booking' => [
                    'project' => $project_id,
                    'block' => $booking->block,
                    'plot' => $booking->plot,
                    'katha' => $booking->katha,
                    'road' => $booking->road,
                    'facing' => $booking->facing,
                    'file_num' => $file_num,
                    'status' => '2'
                ],
                'time' => strtotime($purchase_date)
            ];

            logActivity('inventory', 'create', "Registered new purchase for client {$client_id}, plot {$booking->plot}");

            echo json_encode([
                'status' => 200,
                'message' => 'Purchase registered successfully',
                'html' => Wo_LoadManagePage('clients/includes/purchase_row', ['purchase' => $purchase_data, 'index' => 0])
            ]);
        } else {
            $db->rollback();
            echo json_encode(['status' => 500, 'message' => 'Failed to register purchase']);
        }
        exit;
    }

    // Transfer purchase
    if ($s == 'transfer_purchase') {
        $purchase_id = isset($_POST['purchase_id']) ? (int)$_POST['purchase_id'] : 0;
        $target_client_id = isset($_POST['target_client_id']) ? (int)$_POST['target_client_id'] : 0;
        $transfer_date = isset($_POST['transfer_date']) ? $_POST['transfer_date'] : date('Y-m-d');
        $reason = isset($_POST['reason']) ? trim($_POST['reason']) : '';

        if (!$purchase_id || !$target_client_id || !$reason) {
            echo json_encode(['status' => 400, 'message' => 'Missing required fields']);
            exit;
        }

        $helper = $db->where('id', $purchase_id)->getOne(T_BOOKING_HELPER);
        if (!$helper) {
            echo json_encode(['status' => 404, 'message' => 'Purchase not found']);
            exit;
        }

        $target_client = $db->where('id', $target_client_id)->getOne(T_CUSTOMERS);
        if (!$target_client) {
            echo json_encode(['status' => 404, 'message' => 'Target client not found']);
            exit;
        }

        $db->startTransaction();

        // Update booking helper
        $updated = $db->where('id', $purchase_id)->update(T_BOOKING_HELPER, [
            'client_id' => $target_client_id,
            'transfer_date' => strtotime($transfer_date),
            'transfer_reason' => $reason,
            'transferred_by' => $wo['user']['user_id']
        ]);

        if ($updated) {
            $db->commit();
            
            logActivity('inventory', 'transfer', "Transferred purchase {$purchase_id} to client {$target_client_id}: {$reason}");
            
            echo json_encode(['status' => 200, 'message' => 'Purchase transferred successfully']);
        } else {
            $db->rollback();
            echo json_encode(['status' => 500, 'message' => 'Failed to transfer purchase']);
        }
        exit;
    }

    // Suspend purchase
    if ($s == 'suspend_purchase') {
        $purchase_id = isset($_POST['purchase_id']) ? (int)$_POST['purchase_id'] : 0;
        $suspend_date = isset($_POST['suspend_date']) ? $_POST['suspend_date'] : date('Y-m-d');
        $duration_days = isset($_POST['duration_days']) ? (int)$_POST['duration_days'] : 30;
        $reason = isset($_POST['reason']) ? trim($_POST['reason']) : '';

        if (!$purchase_id || !$reason) {
            echo json_encode(['status' => 400, 'message' => 'Missing required fields']);
            exit;
        }

        $helper = $db->where('id', $purchase_id)->getOne(T_BOOKING_HELPER);
        if (!$helper) {
            echo json_encode(['status' => 404, 'message' => 'Purchase not found']);
            exit;
        }

        $suspend_until = strtotime($suspend_date . ' +' . $duration_days . ' days');

        $db->startTransaction();

        $updated = $db->where('id', $purchase_id)->update(T_BOOKING_HELPER, [
            'status' => '5', // Suspended status
            'suspend_date' => strtotime($suspend_date),
            'suspend_until' => $suspend_until,
            'suspend_reason' => $reason,
            'suspended_by' => $wo['user']['user_id']
        ]);

        if ($updated) {
            $db->commit();
            
            logActivity('inventory', 'suspend', "Suspended purchase {$purchase_id} for {$duration_days} days: {$reason}");
            
            echo json_encode(['status' => 200, 'message' => 'Purchase suspended successfully']);
        } else {
            $db->rollback();
            echo json_encode(['status' => 500, 'message' => 'Failed to suspend purchase']);
        }
        exit;
    }

    // Cancel purchase
    if ($s == 'cancel_purchase') {
        $booking_helper_id = isset($_POST['booking_helper_id']) ? (int)$_POST['booking_helper_id'] : 0;
        $cancel_date = isset($_POST['cancel_date']) ? $_POST['cancel_date'] : date('Y-m-d');

        if (!$booking_helper_id) {
            echo json_encode(['status' => 400, 'message' => 'Invalid purchase ID']);
            exit;
        }

        $helper = $db->where('id', $booking_helper_id)->getOne(T_BOOKING_HELPER);
        if (!$helper) {
            echo json_encode(['status' => 404, 'message' => 'Purchase not found']);
            exit;
        }

        $db->startTransaction();

        // Update helper status to cancelled
        $updated_helper = $db->where('id', $booking_helper_id)->update(T_BOOKING_HELPER, [
            'status' => '4',
            'cancel_date' => strtotime($cancel_date),
            'cancelled_by' => $wo['user']['user_id']
        ]);

        // Update booking status back to available
        $updated_booking = $db->where('id', $helper->booking_id)->update(T_BOOKING, [
            'status' => '1',
            'file_num' => null
        ]);

        if ($updated_helper && $updated_booking) {
            $db->commit();
            
            logActivity('inventory', 'cancel', "Cancelled purchase {$booking_helper_id} on {$cancel_date}");
            
            echo json_encode(['status' => 200, 'message' => 'Purchase cancelled successfully']);
        } else {
            $db->rollback();
            echo json_encode(['status' => 500, 'message' => 'Failed to cancel purchase']);
        }
        exit;
    }

    // Get purchase history
    if ($s == 'get_purchase_history') {
        $purchase_id = isset($_GET['purchase_id']) ? (int)$_GET['purchase_id'] : 0;
        if (!$purchase_id) {
            echo json_encode(['status' => 400, 'message' => 'Invalid purchase ID']);
            exit;
        }

        $helper = $db->where('id', $purchase_id)->getOne(T_BOOKING_HELPER);
        if (!$helper) {
            echo json_encode(['status' => 404, 'message' => 'Purchase not found']);
            exit;
        }

        $history = [];
        
        // Creation event
        $history[] = [
            'type' => 'created',
            'title' => 'Purchase Created',
            'description' => 'Initial booking registered',
            'date' => date('d M Y H:i', $helper->time)
        ];

        // Get payment history
        $payments = $db->where('purchase_id', $purchase_id)->orderBy('inv_time', 'ASC')->get(T_INVOICE);
        foreach ($payments as $payment) {
            $history[] = [
                'type' => 'payment',
                'title' => 'Payment Received',
                'description' => '৳' . number_format($payment->pay_amount) . ' - ' . $payment->pay_type,
                'date' => date('d M Y H:i', strtotime($payment->inv_time))
            ];
        }

        // Transfer event
        if (!empty($helper->transfer_date)) {
            $history[] = [
                'type' => 'transfer',
                'title' => 'Purchase Transferred',
                'description' => $helper->transfer_reason ?? 'No reason provided',
                'date' => date('d M Y H:i', $helper->transfer_date)
            ];
        }

        // Suspension event
        if (!empty($helper->suspend_date)) {
            $history[] = [
                'type' => 'suspend',
                'title' => 'Purchase Suspended',
                'description' => $helper->suspend_reason ?? 'No reason provided',
                'date' => date('d M Y H:i', $helper->suspend_date)
            ];
        }

        // Cancellation event
        if (!empty($helper->cancel_date)) {
            $history[] = [
                'type' => 'cancel',
                'title' => 'Purchase Cancelled',
                'description' => 'Purchase was cancelled',
                'date' => date('d M Y H:i', $helper->cancel_date)
            ];
        }

        // Sort by date
        usort($history, function($a, $b) {
            return strtotime($a['date']) - strtotime($b['date']);
        });

        echo json_encode(['status' => 200, 'history' => $history]);
        exit;
    }

    // Check plot booking conflicts
    if ($s == 'check_plot_booking') {
        $project_id = isset($_POST['project_id']) ? trim($_POST['project_id']) : '';
        $purchase_id = isset($_POST['purchase_id']) ? (int)$_POST['purchase_id'] : 0;
        $file_num = isset($_POST['file_num']) ? trim($_POST['file_num']) : '';
        
        if (!$project_id || !$purchase_id) {
            echo json_encode(['status' => 400, 'message' => 'Missing parameters']);
            exit;
        }
        
        // Check if plot is available
        $booking = $db->where('id', $purchase_id)->getOne(T_BOOKING);
        if (!$booking) {
            echo json_encode(['status' => 404, 'message' => 'Plot not found']);
            exit;
        }
        
        // Check for conflicts
        $conflicts = [];
        if ($booking->status != '1') {
            $existing_helpers = $db->where('booking_id', $purchase_id)->where('status', '2')->get(T_BOOKING_HELPER);
            foreach ($existing_helpers as $helper) {
                $conflicts[] = [
                    'booking_id' => $helper->booking_id,
                    'file_id' => $helper->file_num,
                    'status' => $helper->status
                ];
            }
        }
        
        // Check file number conflicts
        if ($file_num) {
            $file_conflicts = $db->where('file_num', $file_num)->where('status', '2')->get(T_BOOKING_HELPER);
            foreach ($file_conflicts as $fc) {
                $conflicts[] = [
                    'booking_id' => $fc->booking_id,
                    'file_id' => $fc->file_num,
                    'status' => $fc->status
                ];
            }
        }
        
        $available = ($booking->status == '1' && empty($conflicts));
        $message = $available ? 'Plot is available' : 'Plot has active bookings or conflicts';
        
        echo json_encode([
            'status' => 200,
            'available' => $available,
            'message' => $message,
            'conflicts' => $conflicts
        ]);
        exit;
    }

    // Get purchase details for installment modal
    if ($s == 'get_purchase_details') {
        $purchase_id = isset($_GET['purchase_id']) ? (int)$_GET['purchase_id'] : 0;
        if (!$purchase_id) {
            echo json_encode(['status' => 400, 'message' => 'Invalid purchase ID']);
            exit;
        }

        $helper = $db->where('id', $purchase_id)->getOne(T_BOOKING_HELPER);
        if (!$helper) {
            echo json_encode(['status' => 404, 'message' => 'Purchase not found']);
            exit;
        }

        $booking = $db->where('id', $helper->booking_id)->getOne(T_BOOKING);
        $project = $db->where('slug', $booking->project)->getOne(T_PROJECTS);

        // Load saved schedule (if any)
        $scheduleRows = $db->where('purchase_id', $purchase_id)->orderBy('due_date','ASC')->get('installments');
        $schedule = [];
        foreach ($scheduleRows as $r) {
            $schedule[] = [
                'date' => date('Y-m-d', strtotime($r->due_date)),
                'amount' => (float)$r->amount,
                'adjustment' => (int)$r->adjustment,
                'status' => $r->status ?? 'unpaid'
            ];
        }

        $total_price = ($helper->per_katha * $booking->katha);

        echo json_encode([
            'status' => 200,
            'purchase_id' => $helper->id,
            'project_name' => $project->name ?? '',
            'project_slug' => $project->slug ?? '',
            'total_price' => $total_price,
            'booking_money' => (float)$helper->booking_money,
            'down_payment' => (float)$helper->down_payment,
            'default_installments' => $helper->installment ?? 12,
            'default_start_date' => date('Y-m-d', $helper->time ?? time()),
            'file_number' => $helper->file_num,
            'schedule' => $schedule
        ]);
        exit;
    }

    // Generate purchase report
    if ($s == 'generate_purchase_report') {
        $purchase_id = isset($_POST['purchase_id']) ? (int)$_POST['purchase_id'] : 0;
        $format = isset($_POST['format']) ? trim($_POST['format']) : 'pdf';

        if (!$purchase_id) {
            echo json_encode(['status' => 400, 'message' => 'Invalid purchase ID']);
            exit;
        }

        $helper = $db->where('id', $purchase_id)->getOne(T_BOOKING_HELPER);
        if (!$helper) {
            echo json_encode(['status' => 404, 'message' => 'Purchase not found']);
            exit;
        }

        // Generate report file (placeholder implementation)
        $filename = 'purchase_report_' . $purchase_id . '_' . date('Y-m-d') . '.' . $format;
        $download_url = '/downloads/' . $filename;

        logActivity('inventory', 'report', "Generated purchase report for purchase {$purchase_id}");

        echo json_encode([
            'status' => 200,
            'message' => 'Report generated successfully',
            'download_url' => $download_url,
            'filename' => $filename
        ]);
        exit;
    }

    // Export purchase data
    if ($s == 'export_purchase_data') {
        $purchase_id = isset($_POST['purchase_id']) ? (int)$_POST['purchase_id'] : 0;
        $format = isset($_POST['format']) ? trim($_POST['format']) : 'excel';

        if (!$purchase_id) {
            echo json_encode(['status' => 400, 'message' => 'Invalid purchase ID']);
            exit;
        }

        $helper = $db->where('id', $purchase_id)->getOne(T_BOOKING_HELPER);
        if (!$helper) {
            echo json_encode(['status' => 404, 'message' => 'Purchase not found']);
            exit;
        }

        // Generate export file (placeholder implementation)
        $filename = 'purchase_data_' . $purchase_id . '_' . date('Y-m-d') . '.' . $format;
        $download_url = '/downloads/' . $filename;

        logActivity('inventory', 'export', "Exported purchase data for purchase {$purchase_id} in {$format} format");

        echo json_encode([
            'status' => 200,
            'message' => 'Data exported successfully',
            'download_url' => $download_url,
            'filename' => $filename
        ]);
        exit;
    }

    // Get purchase for installment modal
    if ($s == 'get_purchase') {
        $purchase_id = isset($_GET['purchase_id']) ? (int)$_GET['purchase_id'] : 0;
        if (!$purchase_id) {
            echo json_encode(['error' => 'Invalid purchase id']);
            exit;
        }

        $helper = $db->where('id', $purchase_id)->getOne(T_BOOKING_HELPER);
        if (!$helper) {
            echo json_encode(['error' => 'Purchase not found']);
            exit;
        }

        $booking = $db->where('id', $helper->booking_id)->getOne(T_BOOKING);
        $project = $db->where('slug', $booking->project)->getOne(T_PROJECTS);

        // Load saved schedule (if any)
        $scheduleRows = $db->where('purchase_id', $purchase_id)->orderBy('due_date','ASC')->get('installments');
        $schedule = [];
        foreach ($scheduleRows as $r) {
            $schedule[] = [
                'date' => date('Y-m-d', strtotime($r->due_date)),
                'amount' => (float)$r->amount,
                'adjustment' => (int)$r->adjustment,
                'status' => $r->status ?? 'unpaid'
            ];
        }

        $total_price = ($helper->per_katha * $booking->katha);

        echo json_encode([
            'purchase_id' => $helper->id,
            'project_name' => $project->name ?? '',
            'project_slug' => $project->slug ?? '',
            'total_price' => $total_price,
            'booking_money' => (float)$helper->booking_money,
            'down_payment' => (float)$helper->down_payment,
            'default_installments' => $helper->installment ?? 12,
            'default_start_date' => date('Y-m-d', $helper->time ?? time()),
            'file_number' => $helper->file_num,
            'schedule' => $schedule
        ]);
        exit;
    }

    // Update installment schedule
    if ($s == 'update_installment') {
        $purchase_id = isset($_POST['purchase_id']) ? (int)$_POST['purchase_id'] : 0;
        $scheduleRaw = isset($_POST['schedule']) ? $_POST['schedule'] : '[]';
        
        if (!$purchase_id) {
            echo json_encode(['status' => 400, 'message' => 'Invalid purchase id']);
            exit;
        }
        
        $schedule = json_decode($scheduleRaw, true);
        if (!is_array($schedule)) {
            echo json_encode(['status' => 400, 'message' => 'Invalid schedule']);
            exit;
        }

        // Basic validation: amounts sum > 0
        $sum = 0; 
        foreach ($schedule as $r) {
            $sum += floatval($r['amount'] ?? 0);
        }
        if ($sum <= 0) {
            echo json_encode(['status' => 400, 'message' => 'Total schedule amount must be greater than zero']);
            exit;
        }

        $db->startTransaction();

        // Delete old schedule and insert new
        $db->where('purchase_id', $purchase_id)->delete('installments');

        foreach ($schedule as $r) {
            $row = [
                'purchase_id' => $purchase_id,
                'due_date' => $r['date'],
                'amount' => floatval($r['amount']),
                'adjustment' => isset($r['adjustment']) && $r['adjustment'] ? (int)$r['adjustment'] : 0,
                'status' => $r['status'] ?? 'unpaid',
                'created_at' => date('Y-m-d H:i:s')
            ];
            $db->insert('installments', $row);
        }

        $db->commit();

        logActivity('inventory', 'update', "Updated installment schedule for purchase {$purchase_id}");

        echo json_encode(['status' => 200, 'message' => 'Schedule saved']);
        exit;
    }

    // Change plot
    if ($s == 'change_plot') {
        $purchase_id = isset($_POST['purchase_id']) ? (int)$_POST['purchase_id'] : 0;
        $new_plot_id = isset($_POST['new_plot_id']) ? (int)$_POST['new_plot_id'] : 0;
        
        if (!$purchase_id || !$new_plot_id) {
            echo json_encode(['status' => 400, 'message' => 'Missing parameters']);
            exit;
        }

        $helper = $db->where('id', $purchase_id)->getOne(T_BOOKING_HELPER);
        $new_booking = $db->where('id', $new_plot_id)->getOne(T_BOOKING);
        
        if (!$helper || !$new_booking) {
            echo json_encode(['status' => 404, 'message' => 'Purchase or plot not found']);
            exit;
        }

        // Ensure new plot is available
        if ((int)$new_booking->status !== 1) {
            echo json_encode(['status' => 409, 'message' => 'Selected plot is not available']);
            exit;
        }

        $db->startTransaction();

        // Free old plot
        $old_booking = $db->where('id', $helper->booking_id)->getOne(T_BOOKING);
        if ($old_booking) {
            $db->where('id', $helper->booking_id)->update(T_BOOKING, [
                'status' => '1',
                'file_num' => null
            ]);
        }

        // Book new plot
        $db->where('id', $new_plot_id)->update(T_BOOKING, [
            'status' => '2',
            'file_num' => $helper->file_num
        ]);

        // Update helper
        $db->where('id', $purchase_id)->update(T_BOOKING_HELPER, [
            'booking_id' => $new_plot_id,
            'plot_changed_date' => time(),
            'plot_changed_by' => $wo['user']['user_id']
        ]);

        $db->commit();

        logActivity('inventory', 'change_plot', "Changed plot for purchase {$purchase_id} from {$old_booking->plot} to {$new_booking->plot}");

        echo json_encode(['status' => 200, 'message' => 'Plot changed successfully']);
        exit;
    }

    // Get purchase details for booking form
    if ($s == 'get_purchase_details') {
        $purchase_id = isset($_POST['purchase_id']) ? (int)$_POST['purchase_id'] : 0;
        if (!$purchase_id) {
            echo json_encode(['status' => 400, 'message' => 'Invalid purchase ID']);
            exit;
        }

        $helper = $db->where('id', $purchase_id)->getOne(T_BOOKING_HELPER);
        if (!$helper) {
            echo json_encode(['status' => 404, 'message' => 'Purchase not found']);
            exit;
        }

        $booking = $db->where('id', $helper->booking_id)->getOne(T_BOOKING);
        $project = $db->where('slug', $booking->project)->getOne(T_PROJECTS);
        $client = GetCustomerById($helper->client_id);
        $additional = GetAddiData_cId($helper->client_id);
        $nominees = get_nominees_by_customer_id($helper->client_id);
        
        // Get reference user
        $reference_user = null;
        if (!empty($additional['reference'])) {
            $reference_user = Wo_UserData($additional['reference']);
        }

        echo json_encode([
            'status' => 200,
            'data' => [
                'client' => $client,
                'booking' => [
                    'project' => $booking->project,
                    'block' => $booking->block,
                    'plot' => $booking->plot,
                    'katha' => $booking->katha,
                    'road' => $booking->road,
                    'facing' => $booking->facing,
                    'file_num' => $booking->file_num
                ],
                'project' => [
                    'id' => $project->id,
                    'name' => $project->name,
                    'slug' => $project->slug
                ],
                'additional' => $additional,
                'nominees' => $nominees,
                'reference_user' => $reference_user,
                'helper' => [
                    'per_katha' => $helper->per_katha,
                    'down_payment' => $helper->down_payment,
                    'booking_money' => $helper->booking_money
                ]
            ]
        ]);
        exit;
    }

    // Cancel all inventory (updated action)
    if ($s == 'cancel_all_inventory') {
        $inventory_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $project = isset($_POST['project']) ? trim($_POST['project']) : '';
        $client_id = isset($_POST['file_id']) ? (int)$_POST['file_id'] : 0;
        $cancel_date = isset($_POST['date']) ? $_POST['date'] : date('Y-m-d');

        if (!$inventory_id) {
            echo json_encode(['status' => 400, 'message' => 'Invalid inventory ID']);
            exit;
        }

        $booking = $db->where('id', $inventory_id)->getOne(T_BOOKING);
        if (!$booking) {
            echo json_encode(['status' => 404, 'message' => 'Inventory not found']);
            exit;
        }

        $db->startTransaction();

        // Cancel all active booking helpers for this inventory
        $helpers = $db->where('booking_id', $inventory_id)->where('status', '2')->get(T_BOOKING_HELPER);
        
        foreach ($helpers as $helper) {
            $db->where('id', $helper->id)->update(T_BOOKING_HELPER, [
                'status' => '4',
                'cancel_date' => strtotime($cancel_date),
                'cancelled_by' => $wo['user']['user_id']
            ]);
        }

        // Update booking status back to available
        $updated_booking = $db->where('id', $inventory_id)->update(T_BOOKING, [
            'status' => '1',
            'file_num' => null
        ]);

        if ($updated_booking) {
            $db->commit();
            
            logActivity('inventory', 'cancel_all', "Cancelled all bookings for inventory {$inventory_id}");
            
            echo json_encode(['status' => 200, 'message' => 'Inventory cancelled successfully']);
        } else {
            $db->rollback();
            echo json_encode(['status' => 500, 'message' => 'Failed to cancel inventory']);
        }
        exit;
    }

    // Edit inventory details
    if ($s == 'edit_inventory') {
        $inventory_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $project = isset($_POST['project']) ? trim($_POST['project']) : '';
        $block = isset($_POST['block']) ? trim($_POST['block']) : '';
        $facing = isset($_POST['facing']) ? trim($_POST['facing']) : '';
        $katha = isset($_POST['katha']) ? trim($_POST['katha']) : '';
        $road = isset($_POST['road']) ? trim($_POST['road']) : '';
        $plot_num = isset($_POST['plot_num']) ? trim($_POST['plot_num']) : '';

        if (!$inventory_id || !$project) {
            echo json_encode(['status' => 400, 'message' => 'Missing required fields']);
            exit;
        }

        $booking = $db->where('id', $inventory_id)->getOne(T_BOOKING);
        if (!$booking) {
            echo json_encode(['status' => 404, 'message' => 'Inventory not found']);
            exit;
        }

        $update_data = [];
        if ($block) $update_data['block'] = $block;
        if ($facing) $update_data['facing'] = $facing;
        if ($katha) $update_data['katha'] = $katha;
        if ($road) $update_data['road'] = $road;
        if ($plot_num) $update_data['plot'] = $plot_num;

        if (!empty($update_data)) {
            $updated = $db->where('id', $inventory_id)->update(T_BOOKING, $update_data);
            
            if ($updated) {
                logActivity('inventory', 'edit', "Updated inventory {$inventory_id}: " . json_encode($update_data));
                echo json_encode(['status' => 200, 'message' => 'Inventory updated successfully']);
            } else {
                echo json_encode(['status' => 500, 'message' => 'Failed to update inventory']);
            }
        } else {
            echo json_encode(['status' => 400, 'message' => 'No data to update']);
        }
        exit;
    }

    // Default response for unhandled actions
    echo json_encode(['status' => 404, 'message' => 'Action not found']);
    exit;
}