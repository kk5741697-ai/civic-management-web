<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

Global $road_array, $project_mapping;

// ---------- KATHA NORMALIZER ----------
function normalizeKatha($value) {
    if ($value === null || $value === '') return null;
    $num = floatval($value);
    // If decimal part is .00 â†’ remove
    if (fmod($num, 1.0) == 0.0) {
        return (string)intval($num);
    }
    // Otherwise keep decimal
    return (string)$num;
}

if ($f == 'manage_inventory') {

    // Get all clients (for transfer purchase modal)
    if ($s === 'get_all_clients') {
        header('Content-Type: application/json; charset=utf-8');
        
        try {
            $clients = $db->orderBy('name', 'ASC')->get(T_CUSTOMERS, null, ['id', 'name', 'phone', 'address']);
            
            $result = [];
            if (!empty($clients)) {
                foreach ($clients as $client) {
                    $result[] = [
                        'id' => $client->id,
                        'name' => $client->name,
                        'phone' => $client->phone ?? '',
                        'address' => $client->address ?? ''
                    ];
                }
            }
            
            echo json_encode($result);
        } catch (Exception $e) {
            echo json_encode(['status' => 500, 'message' => 'Failed to load clients: ' . $e->getMessage()]);
        }
        exit;
    }

    // Get purchase by ID (for Select2 preselection)
    if ($s === 'get_purchase_by_id') {
        header('Content-Type: application/json; charset=utf-8');
        
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        $project_id = isset($_GET['project_id']) ? trim($_GET['project_id']) : '';
        
        if ($id <= 0) {
            echo json_encode(['status' => 400, 'message' => 'Invalid ID']);
            exit;
        }
        
        try {
            $booking = $db->where('id', $id)->getOne(T_BOOKING);
            
            if (!$booking) {
                echo json_encode(['status' => 404, 'message' => 'Purchase not found']);
                exit;
            }
            
            // Build normalized item
            $labelParts = [];
            if (!empty($booking->block)) $labelParts[] = ucwords($booking->block);
            $labelParts[] = 'Plot ' . $booking->plot;
            if (!empty($booking->katha)) $labelParts[] = $booking->katha . ' katha';
            if (!empty($booking->road)) $labelParts[] = 'Road ' . $booking->road;
            if (!empty($booking->facing)) $labelParts[] = 'Facing ' . $booking->facing;
            $label = implode(' â€¢ ', array_filter($labelParts));
            
            $status = (string)($booking->status ?? '0');
            $status_label = 'Available';
            if ($status === '2') $status_label = 'Sold';
            elseif ($status === '4') $status_label = 'Cancelled';
            elseif ($status === '3') $status_label = 'Complete';
            
            $item = [
                'id' => $booking->id,
                'text' => $label,
                'plot' => $booking->plot,
                'block' => $booking->block,
                'katha' => $booking->katha,
                'road' => $booking->road,
                'facing' => $booking->facing,
                'status' => $status,
                'status_label' => $status_label,
                'available' => in_array($status, ['0', '1']) ? 1 : 0
            ];
            
            echo json_encode(['status' => 200, 'item' => $item]);
        } catch (Exception $e) {
            echo json_encode(['status' => 500, 'message' => 'Server error: ' . $e->getMessage()]);
        }
        exit;
    }

    // Transfer purchase to another client
    if ($s === 'transfer_purchase') {
        header('Content-Type: application/json; charset=utf-8');
        
        $purchase_id = isset($_POST['purchase_id']) ? (int)$_POST['purchase_id'] : 0;
        $target_client_id = isset($_POST['target_client_id']) ? (int)$_POST['target_client_id'] : 0;
        $transfer_date = isset($_POST['transfer_date']) ? trim($_POST['transfer_date']) : '';
        $reason = isset($_POST['reason']) ? trim($_POST['reason']) : '';
        
        if ($purchase_id <= 0 || $target_client_id <= 0) {
            echo json_encode(['status' => 400, 'message' => 'Invalid parameters']);
            exit;
        }
        
        if (empty($reason)) {
            echo json_encode(['status' => 400, 'message' => 'Transfer reason is required']);
            exit;
        }
        
        try {
            // Verify source purchase exists
            $helper = $db->where('id', $purchase_id)->getOne(T_BOOKING_HELPER);
            if (!$helper) {
                echo json_encode(['status' => 404, 'message' => 'Purchase not found']);
                exit;
            }
            
            // Verify target client exists
            $target_client = $db->where('id', $target_client_id)->getOne(T_CUSTOMERS);
            if (!$target_client) {
                echo json_encode(['status' => 404, 'message' => 'Target client not found']);
                exit;
            }
            
            $transfer_timestamp = !empty($transfer_date) ? strtotime($transfer_date) : time();
            
            $db->startTransaction();
            
            // Update the helper with new client
            $update_result = $db->where('id', $purchase_id)->update(T_BOOKING_HELPER, [
                'client_id' => (string)$target_client_id,
                'time' => $transfer_timestamp
            ]);
            
            if ($update_result === false) {
                $db->rollback();
                echo json_encode(['status' => 500, 'message' => 'Failed to transfer purchase']);
                exit;
            }
            
            // Log the transfer
            $old_client = GetCustomerById($helper->client_id);
            $old_client_name = $old_client['name'] ?? 'Unknown';
            $new_client_name = $target_client->name ?? 'Unknown';
            
            logActivity('purchase', 'transfer', "Purchase #{$purchase_id} transferred from {$old_client_name} to {$new_client_name}. Reason: {$reason}");
            
            $db->commit();
            
            echo json_encode([
                'status' => 200, 
                'message' => 'Purchase transferred successfully',
                'transfer_id' => $purchase_id
            ]);
            
        } catch (Exception $e) {
            if (isset($db) && method_exists($db, 'rollback')) {
                $db->rollback();
            }
            echo json_encode(['status' => 500, 'message' => 'Server error: ' . $e->getMessage()]);
        }
        exit;
    }

    // Suspend purchase
    if ($s === 'suspend_purchase') {
        header('Content-Type: application/json; charset=utf-8');
        
        $purchase_id = isset($_POST['purchase_id']) ? (int)$_POST['purchase_id'] : 0;
        $suspend_date = isset($_POST['suspend_date']) ? trim($_POST['suspend_date']) : '';
        $duration_days = isset($_POST['duration_days']) ? (int)$_POST['duration_days'] : 30;
        $reason = isset($_POST['reason']) ? trim($_POST['reason']) : '';
        
        if ($purchase_id <= 0) {
            echo json_encode(['status' => 400, 'message' => 'Invalid purchase ID']);
            exit;
        }
        
        if (empty($reason)) {
            echo json_encode(['status' => 400, 'message' => 'Suspension reason is required']);
            exit;
        }
        
        if ($duration_days <= 0) {
            echo json_encode(['status' => 400, 'message' => 'Invalid suspension duration']);
            exit;
        }
        
        try {
            // Verify purchase exists
            $helper = $db->where('id', $purchase_id)->getOne(T_BOOKING_HELPER);
            if (!$helper) {
                echo json_encode(['status' => 404, 'message' => 'Purchase not found']);
                exit;
            }
            
            $suspend_timestamp = !empty($suspend_date) ? strtotime($suspend_date) : time();
            $resume_timestamp = $suspend_timestamp + ($duration_days * 24 * 60 * 60);
            
            $db->startTransaction();
            
            // Update helper with suspension info
            $suspension_data = [
                'suspended' => 1,
                'suspend_date' => $suspend_timestamp,
                'suspend_reason' => $reason,
                'suspend_duration' => $duration_days,
                'resume_date' => $resume_timestamp
            ];
            
            $update_result = $db->where('id', $purchase_id)->update(T_BOOKING_HELPER, $suspension_data);
            
            if ($update_result === false) {
                $db->rollback();
                echo json_encode(['status' => 500, 'message' => 'Failed to suspend purchase']);
                exit;
            }
            
            // Log the suspension
            $client = GetCustomerById($helper->client_id);
            $client_name = $client['name'] ?? 'Unknown';
            
            logActivity('purchase', 'suspend', "Purchase #{$purchase_id} for {$client_name} suspended for {$duration_days} days. Reason: {$reason}");
            
            $db->commit();
            
            echo json_encode([
                'status' => 200, 
                'message' => 'Purchase suspended successfully',
                'resume_date' => date('Y-m-d', $resume_timestamp)
            ]);
            
        } catch (Exception $e) {
            if (isset($db) && method_exists($db, 'rollback')) {
                $db->rollback();
            }
            echo json_encode(['status' => 500, 'message' => 'Server error: ' . $e->getMessage()]);
        }
        exit;
    }

    // Get purchase history
    if ($s === 'get_purchase_history') {
        header('Content-Type: application/json; charset=utf-8');
        
        $purchase_id = isset($_GET['purchase_id']) ? (int)$_GET['purchase_id'] : 0;
        
        if ($purchase_id <= 0) {
            echo json_encode(['status' => 400, 'message' => 'Invalid purchase ID']);
            exit;
        }
        
        try {
            // Get purchase details
            $helper = $db->where('id', $purchase_id)->getOne(T_BOOKING_HELPER);
            if (!$helper) {
                echo json_encode(['status' => 404, 'message' => 'Purchase not found']);
                exit;
            }
            
            $booking = $db->where('id', $helper->booking_id)->getOne(T_BOOKING);
            $client = GetCustomerById($helper->client_id);
            
            // Build history timeline
            $history = [];
            
            // Purchase creation
            $history[] = [
                'type' => 'created',
                'title' => 'Purchase Created',
                'description' => 'Initial purchase booking created',
                'date' => date('d M Y H:i', $helper->time ?? time())
            ];
            
            // Get related invoices/payments
            $invoices = $db->where('customer_id', $helper->client_id)
                           ->where('purchase_id', $purchase_id)
                           ->orderBy('time', 'DESC')
                           ->get(T_INVOICE);
            
            if ($invoices) {
                foreach ($invoices as $invoice) {
                    $history[] = [
                        'type' => 'payment',
                        'title' => 'Payment Received',
                        'description' => 'Amount: à§³' . number_format($invoice->pay_amount ?? 0),
                        'date' => date('d M Y H:i', $invoice->time ?? time())
                    ];
                }
            }
            
            // Check for status changes
            if (!empty($helper->cancel_date)) {
                $history[] = [
                    'type' => 'cancelled',
                    'title' => 'Purchase Cancelled',
                    'description' => 'Purchase was cancelled',
                    'date' => date('d M Y H:i', $helper->cancel_date)
                ];
            }
            
            if (!empty($helper->suspend_date)) {
                $history[] = [
                    'type' => 'suspended',
                    'title' => 'Purchase Suspended',
                    'description' => 'Reason: ' . ($helper->suspend_reason ?? 'Not specified'),
                    'date' => date('d M Y H:i', $helper->suspend_date)
                ];
            }
            
            // Sort by date (newest first)
            usort($history, function($a, $b) {
                return strtotime($b['date']) - strtotime($a['date']);
            });
            
            echo json_encode([
                'status' => 200,
                'history' => $history,
                'purchase_info' => [
                    'id' => $purchase_id,
                    'client_name' => $client['name'] ?? 'Unknown',
                    'project' => $booking->project ?? 'Unknown',
                    'plot' => $booking->plot ?? 'Unknown',
                    'file_num' => $helper->file_num ?? 'Unknown'
                ]
            ]);
            
        } catch (Exception $e) {
            echo json_encode(['status' => 500, 'message' => 'Server error: ' . $e->getMessage()]);
        }
        exit;
    }

    // Generate purchase report
    if ($s === 'generate_purchase_report') {
        header('Content-Type: application/json; charset=utf-8');
        
        $purchase_id = isset($_POST['purchase_id']) ? (int)$_POST['purchase_id'] : 0;
        $format = isset($_POST['format']) ? strtolower(trim($_POST['format'])) : 'pdf';
        
        if ($purchase_id <= 0) {
            echo json_encode(['status' => 400, 'message' => 'Invalid purchase ID']);
            exit;
        }
        
        if (!in_array($format, ['pdf', 'excel', 'csv'])) {
            echo json_encode(['status' => 400, 'message' => 'Invalid format. Use pdf, excel, or csv']);
            exit;
        }
        
        try {
            // Get purchase details
            $helper = $db->where('id', $purchase_id)->getOne(T_BOOKING_HELPER);
            if (!$helper) {
                echo json_encode(['status' => 404, 'message' => 'Purchase not found']);
                exit;
            }
            
            $booking = $db->where('id', $helper->booking_id)->getOne(T_BOOKING);
            $client = GetCustomerById($helper->client_id);
            
            // Get payment schedule
            $schedule = [];
            if (!empty($helper->installment)) {
                $schedule = json_decode($helper->installment, true) ?: [];
            }
            
            // Get invoices
            $invoices = $db->where('customer_id', $helper->client_id)
                           ->where('purchase_id', $purchase_id)
                           ->orderBy('time', 'DESC')
                           ->get(T_INVOICE);
            
            // Calculate totals
            $total_price = ($helper->per_katha ?? 0) * ($booking->katha ?? 0);
            $total_paid = 0;
            if ($invoices) {
                foreach ($invoices as $inv) {
                    $total_paid += ($inv->pay_amount ?? 0);
                }
            }
            
            // Create report data
            $report_data = [
                'purchase_id' => $purchase_id,
                'client' => $client,
                'booking' => (array)$booking,
                'helper' => (array)$helper,
                'schedule' => $schedule,
                'invoices' => $invoices ? array_map(function($inv) { return (array)$inv; }, $invoices) : [],
                'totals' => [
                    'total_price' => $total_price,
                    'total_paid' => $total_paid,
                    'due_amount' => $total_price - $total_paid
                ],
                'generated_at' => date('Y-m-d H:i:s'),
                'generated_by' => $wo['user']['name'] ?? 'System'
            ];
            
            // For this example, we'll create a simple JSON report
            // In a real implementation, you'd generate actual PDF/Excel files
            $filename = "purchase_report_{$purchase_id}_" . date('Y-m-d_H-i-s') . ".$format";
            $report_json = json_encode($report_data, JSON_PRETTY_PRINT);
            
            // In production, you would:
            // 1. Generate the actual file based on format (PDF/Excel/CSV)
            // 2. Save it to a temporary directory
            // 3. Return the download URL
            
            // For now, return success with mock URL
            echo json_encode([
                'status' => 200,
                'message' => 'Report generated successfully',
                'filename' => $filename,
                'download_url' => '/downloads/reports/' . $filename, // Mock URL
                'format' => $format,
                'size' => strlen($report_json) . ' bytes'
            ]);
            
        } catch (Exception $e) {
            echo json_encode(['status' => 500, 'message' => 'Server error: ' . $e->getMessage()]);
        }
        exit;
    }

    // Export purchase data
    if ($s === 'export_purchase_data') {
        header('Content-Type: application/json; charset=utf-8');
        
        $purchase_id = isset($_POST['purchase_id']) ? (int)$_POST['purchase_id'] : 0;
        $format = isset($_POST['format']) ? strtolower(trim($_POST['format'])) : 'excel';
        
        if ($purchase_id <= 0) {
            echo json_encode(['status' => 400, 'message' => 'Invalid purchase ID']);
            exit;
        }
        
        if (!in_array($format, ['excel', 'csv', 'pdf'])) {
            echo json_encode(['status' => 400, 'message' => 'Invalid format. Use excel, csv, or pdf']);
            exit;
        }
        
        try {
            // Get all purchase data
            $helper = $db->where('id', $purchase_id)->getOne(T_BOOKING_HELPER);
            if (!$helper) {
                echo json_encode(['status' => 404, 'message' => 'Purchase not found']);
                exit;
            }
            
            $booking = $db->where('id', $helper->booking_id)->getOne(T_BOOKING);
            $client = GetCustomerById($helper->client_id);
            
            // Export data structure
            $export_data = [
                'Purchase ID' => $purchase_id,
                'Client Name' => $client['name'] ?? '',
                'Client Phone' => $client['phone'] ?? '',
                'Client Address' => $client['address'] ?? '',
                'Project' => $booking->project ?? '',
                'Block' => $booking->block ?? '',
                'Plot' => $booking->plot ?? '',
                'Katha' => $booking->katha ?? '',
                'Road' => $booking->road ?? '',
                'Facing' => $booking->facing ?? '',
                'File Number' => $helper->file_num ?? '',
                'Per Katha Price' => $helper->per_katha ?? 0,
                'Down Payment' => $helper->down_payment ?? 0,
                'Booking Money' => $helper->booking_money ?? 0,
                'Status' => $helper->status ?? '',
                'Purchase Date' => date('Y-m-d', $helper->time ?? time()),
                'Total Price' => ($helper->per_katha ?? 0) * ($booking->katha ?? 0)
            ];
            
            // Generate filename
            $client_name_safe = preg_replace('/[^a-zA-Z0-9_-]/', '', str_replace(' ', '_', $client['name'] ?? 'client'));
            $filename = "purchase_data_{$client_name_safe}_{$purchase_id}_" . date('Y-m-d') . ".$format";
            
            // In production, you would generate the actual file here
            // For this example, we'll return success with the structured data
            
            echo json_encode([
                'status' => 200,
                'message' => 'Data exported successfully',
                'filename' => $filename,
                'download_url' => '/downloads/exports/' . $filename, // Mock URL
                'format' => $format,
                'data' => $export_data // In production, remove this - file would be generated
            ]);
            
        } catch (Exception $e) {
            echo json_encode(['status' => 500, 'message' => 'Server error: ' . $e->getMessage()]);
        }
        exit;
    }

    // Export payment schedule
    if ($s === 'export_payment_schedule') {
        header('Content-Type: application/json; charset=utf-8');
        
        $purchase_id = isset($_POST['purchase_id']) ? (int)$_POST['purchase_id'] : 0;
        $format = isset($_POST['format']) ? strtolower(trim($_POST['format'])) : 'xlsx';
        
        if ($purchase_id <= 0) {
            echo json_encode(['status' => 400, 'message' => 'Invalid purchase ID']);
            exit;
        }
        
        try {
            // Get purchase details
            $helper = $db->where('id', $purchase_id)->getOne(T_BOOKING_HELPER);
            if (!$helper) {
                echo json_encode(['status' => 404, 'message' => 'Purchase not found']);
                exit;
            }
            
            $booking = $db->where('id', $helper->booking_id)->getOne(T_BOOKING);
            $client = GetCustomerById($helper->client_id);
            
            // Get payment schedule
            $schedule = [];
            if (!empty($helper->installment)) {
                $schedule = json_decode($helper->installment, true) ?: [];
            }
            
            if (empty($schedule)) {
                echo json_encode(['status' => 404, 'message' => 'No payment schedule found']);
                exit;
            }
            
            // Generate filename
            $client_name_safe = preg_replace('/[^a-zA-Z0-9_-]/', '', str_replace(' ', '_', $client['name'] ?? 'client'));
            $filename = "payment_schedule_{$client_name_safe}_{$purchase_id}_" . date('Y-m-d') . ".$format";
            
            // In production, you would generate the actual Excel/PDF file here
            // For this example, return success with structured data
            
            echo json_encode([
                'status' => 200,
                'message' => 'Payment schedule exported successfully',
                'filename' => $filename,
                'download_url' => '/downloads/schedules/' . $filename, // Mock URL
                'format' => $format,
                'schedule_count' => count($schedule)
            ]);
            
        } catch (Exception $e) {
            echo json_encode(['status' => 500, 'message' => 'Server error: ' . $e->getMessage()]);
        }
        exit;
    }


    // ===============================
    //  ðŸ“… GET PURCHASE DETAILS FOR PAYMENT SCHEDULE
    // ===============================
    if ($s == 'get_purchase_details') {
        $purchase_id = isset($_GET['purchase_id']) ? (int)$_GET['purchase_id'] : (isset($_POST['purchase_id']) ? (int)$_POST['purchase_id'] : 0);
        
        if (!$purchase_id) {
            echo json_encode(['status' => 400, 'message' => 'Invalid purchase ID']);
            exit;
        }

        // Get purchase details from booking_helper
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

        // Calculate amounts
        $per_katha = (float)($helper->per_katha ?? 0);
        $katha = (float)($booking->katha ?? 0);
        $down_payment = (float)($helper->down_payment ?? 0);
        $booking_money = (float)($helper->booking_money ?? 0);
        
        $total_price = $per_katha * $katha;
        $remaining_after_advance = $total_price - ($down_payment + $booking_money);
        
        // Get project name
        $project_name = 'Unknown Project';
        if ($booking->project == 'moon-hill') {
            $project_name = 'Civic Moon Hill';
        } elseif ($booking->project == 'hill-town') {
            $project_name = 'Civic Hill Town';
        }

        // Get existing schedule if any
        $existing_schedule = [];
        if (!empty($helper->installment)) {
            $schedule_data = json_decode($helper->installment, true);
            if (is_array($schedule_data)) {
                $existing_schedule = $schedule_data;
            }
        }

        $response = [
            'status' => 200,
            'purchase_id' => $helper->id,
            'client_name' => $client['name'],
            'project_name' => $project_name,
            'project_slug' => $booking->project,
            'total_price' => $total_price,
            'down_payment' => $down_payment,
            'booking_money' => $booking_money,
            'remaining_amount' => $remaining_after_advance,
            'per_katha' => $per_katha,
            'file_num' => $booking->file_num ?? '',
            'plot' => $booking->plot ?? '',
            'katha' => $katha,
            'block' => $booking->block ?? '',
            'road' => $booking->road ?? '',
            'facing' => $booking->facing ?? '',
            'default_start_date' => date('Y-m-d'),
            'default_installments' => 12,
            'schedule' => $existing_schedule
        ];

        echo json_encode($response);
        exit;
    }

    // ===============================
    //  ðŸ’¾ UPDATE INSTALLMENT SCHEDULE
    // ===============================
    if ($s == 'update_installment') {
        $purchase_id = isset($_POST['purchase_id']) ? (int)$_POST['purchase_id'] : 0;
        $schedule_json = isset($_POST['schedule']) ? $_POST['schedule'] : '[]';
        
        if (!$purchase_id) {
            echo json_encode(['status' => 400, 'message' => 'Invalid purchase ID']);
            exit;
        }

        $schedule = json_decode($schedule_json, true);
        if (!is_array($schedule)) {
            echo json_encode(['status' => 400, 'message' => 'Invalid schedule data']);
            exit;
        }

        // Get helper record
        $helper = $db->where('id', $purchase_id)->getOne(T_BOOKING_HELPER);
        if (!$helper) {
            echo json_encode(['status' => 404, 'message' => 'Purchase not found']);
            exit;
        }

        // Get booking for calculations
        $booking = $db->where('id', $helper->booking_id)->getOne(T_BOOKING);
        if (!$booking) {
            echo json_encode(['status' => 404, 'message' => 'Booking not found']);
            exit;
        }

        // Calculate expected total
        $per_katha = (float)($helper->per_katha ?? 0);
        $katha = (float)($booking->katha ?? 0);
        $down_payment = (float)($helper->down_payment ?? 0);
        $booking_money = (float)($helper->booking_money ?? 0);
        
        $total_price = $per_katha * $katha;
        $expected_schedule_total = $total_price - ($down_payment + $booking_money);

        // Calculate actual schedule total
        $actual_schedule_total = 0;
        foreach ($schedule as $item) {
            $actual_schedule_total += (float)($item['amount'] ?? 0);
        }

        // Allow small rounding differences (within 1 taka)
        $difference = abs($expected_schedule_total - $actual_schedule_total);
        if ($difference > 1) {
            echo json_encode([
                'status' => 400, 
                'message' => 'Schedule total mismatch. Expected: à§³' . number_format($expected_schedule_total, 2) . 
                           ', Got: à§³' . number_format($actual_schedule_total, 2) . 
                           ', Difference: à§³' . number_format($difference, 2)
            ]);
            exit;
        }

        // Update the installment field
        $update_result = $db->where('id', $purchase_id)->update(T_BOOKING_HELPER, [
            'installment' => json_encode($schedule, JSON_UNESCAPED_UNICODE)
        ]);

        if ($update_result) {
            logActivity('clients', 'update', "Updated payment schedule for purchase ID: {$purchase_id}");
            echo json_encode(['status' => 200, 'message' => 'Payment schedule saved successfully']);
        } else {
            echo json_encode(['status' => 500, 'message' => 'Failed to save schedule']);
        }
        exit;
    }

    // Change plot functionality
    if ($s === 'change_plot') {
        $purchase_id = isset($_POST['purchase_id']) ? (int) $_POST['purchase_id'] : 0;
        $new_plot_id = isset($_POST['new_plot_id']) ? (int) $_POST['new_plot_id'] : 0;
        
        if ($purchase_id <= 0 || $new_plot_id <= 0) {
            echo json_encode(['status' => 400, 'message' => 'Invalid parameters']);
            exit;
        }
        
        global $db;
        
        // Get current purchase
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
        
        // Check if new plot is available
        $existing_helper = $db->where('booking_id', $new_plot_id)
                             ->where('status', ['0', '1', '4'], 'NOT IN')
                             ->getOne(T_BOOKING_HELPER);
        
        if ($existing_helper) {
            echo json_encode(['status' => 400, 'message' => 'Selected plot is not available']);
            exit;
        }
        
        $db->startTransaction();
        
        try {
            // Reset old booking status
            $db->where('id', $helper->booking_id)->update(T_BOOKING, [
                'status' => 0,
                'file_num' => ''
            ]);
            
            // Update helper with new booking
            $db->where('id', $purchase_id)->update(T_BOOKING_HELPER, [
                'booking_id' => $new_plot_id
            ]);
            
            // Update new booking status
            $db->where('id', $new_plot_id)->update(T_BOOKING, [
                'status' => 2,
                'file_num' => $helper->file_num
            ]);
            
            $db->commit();
            
            echo json_encode(['status' => 200, 'message' => 'Plot changed successfully']);
            
        } catch (Exception $e) {
            $db->rollback();
            echo json_encode(['status' => 500, 'message' => 'Failed to change plot: ' . $e->getMessage()]);
        }
        
        exit;
    }

    // Get available plots for change plot modal
    if ($s === 'get_available_plots') {
        $project_slug = isset($_GET['project_slug']) ? trim($_GET['project_slug']) : '';
        
        if (empty($project_slug)) {
            echo json_encode([]);
            exit;
        }
        
        global $db;
        
        // Get available plots (status 0 or 1, or no active helpers)
        $plots = $db->where('project', $project_slug)
                   ->where('status', ['0', '1'], 'IN')
                   ->get(T_BOOKING);
        
        $available = [];
        foreach ($plots as $plot) {
            // Check if plot has active helpers
            $active_helper = $db->where('booking_id', $plot->id)
                               ->where('status', ['0', '1', '4'], 'NOT IN')
                               ->getOne(T_BOOKING_HELPER);
            
            if (!$active_helper) {
                $available[] = [
                    'id' => $plot->id,
                    'block' => $plot->block ?? '',
                    'plot' => $plot->plot ?? '',
                    'katha' => $plot->katha ?? '',
                    'road' => $plot->road ?? '',
                    'plot_number' => $plot->plot ?? ''
                ];
            }
        }
        
        echo json_encode($available);
        exit;
    }

    // ------------------ CANCEL ALL INVENTORY (cancel booking + all helpers + clear file_num) ------------------
    if ($s === 'cancel_all_inventory') {
    
        // accept booking id via POST 'id' or 'booking_id', or GET fallback
        $booking_id = isset($_POST['id']) ? (int) $_POST['id']
                    : (isset($_POST['booking_id']) ? (int) $_POST['booking_id']
                    : (isset($_GET['id']) ? (int) $_GET['id']
                    : (isset($_GET['booking_id']) ? (int) $_GET['booking_id'] : 0)));
    
        // accept cancel date via POST 'cancel_date' or 'date', or GET fallback
        $raw_date = isset($_POST['cancel_date']) ? trim((string) $_POST['cancel_date'])
                  : (isset($_POST['date']) ? trim((string) $_POST['date'])
                  : (isset($_GET['cancel_date']) ? trim((string) $_GET['cancel_date'])
                  : (isset($_GET['date']) ? trim((string) $_GET['date']) : '')));
    
        // Normalize cancel_date:
        // - $cancel_date_ts will be integer timestamp or null
        // - $cancel_date_short will be 'Y-m-d' string or null
        $cancel_date_ts = null;
        $cancel_date_short = null;
        if ($raw_date !== '') {
            // accept YYYY-MM-DD or general human formats (via strtotime)
            $d = DateTime::createFromFormat('Y-m-d', $raw_date);
            if ($d && $d->format('Y-m-d') === $raw_date) {
                $cancel_date_short = $raw_date;
                $cancel_date_ts = (int) $d->getTimestamp();
            } else {
                $ts = strtotime($raw_date);
                if ($ts !== false) {
                    $cancel_date_ts = (int) $ts;
                    $cancel_date_short = date('Y-m-d', $cancel_date_ts);
                } else {
                    // invalid date string â€” treat as not provided (optional: return 400 instead)
                    $cancel_date_ts = null;
                    $cancel_date_short = null;
                }
            }
        }
    
        if ($booking_id <= 0) {
            http_response_code(400);
            echo json_encode(['status' => 400, 'message' => 'Missing or invalid booking id.']);
            exit;
        }
    
        // fetch booking
        $booking = $db->where('id', $booking_id)->getOne(T_BOOKING);
        if (!$booking) {
            http_response_code(404);
            echo json_encode(['status' => 404, 'message' => 'Booking not found.']);
            exit;
        }
    
        // begin transaction
        $db->startTransaction();
    
        $now = time();
    
        // 1) update booking: set status = 4 (Cancelled) and clear file_num
        $bookingUpdate = [
            'status'   => 4,
            'file_num' => ''
        ];

        
        $okBooking = $db->where('id', $booking_id)->update(T_BOOKING, $bookingUpdate);
        if ($okBooking === false) {
            $db->rollback();
            http_response_code(500);
            echo json_encode(['status' => 500, 'message' => 'Failed to update booking status/file number.']);
            exit;
        }
    
        // 2) fetch all existing helpers for this booking (to collect IDs for response/logging)
        $helpersBefore = $db->where('booking_id', $booking_id)->get(T_BOOKING_HELPER);
        $helperIds = [];
        if ($helpersBefore && is_array($helpersBefore)) {
            foreach ($helpersBefore as $h) {
                $hid = isset($h->id) ? (int)$h->id : 0;
                if ($hid > 0) $helperIds[] = $hid;
            }
        }
    
        // 3) update all helpers for this booking -> set status = 4 (Cancelled) and update time
        $helperUpdate = [
            'status' => 4
        ];
        // include cancel_date_ts if provided (store numeric timestamp)
        if (!is_null($cancel_date_ts)) {
            $helperUpdate['cancel_date'] = $cancel_date_ts;
        }
    
        $okHelpers = $db->where('booking_id', $booking_id)->update(T_BOOKING_HELPER, $helperUpdate);
        if ($okHelpers === false) {
            $db->rollback();
            http_response_code(500);
            echo json_encode(['status' => 500, 'message' => 'Failed to update booking helpers.']);
            exit;
        }
    
        // commit transaction
        $db->commit();
    
        // optional logging
        $logUser = 'User #' . ($wo['user']['id'] ?? 'unknown');
        logActivity('booking', 'cancel_all', "{$logUser} cancelled booking #{$booking_id} and helpers [" . implode(',', $helperIds) . "]");
    
        // success response (return both timestamp and short date if available)
        echo json_encode([
            'status' => 200,
            'message' => 'Booking and all helpers cancelled successfully.',
            'booking_id' => $booking_id,
            'cancel_date' => $cancel_date_short,
            'cancel_date_ts' => $cancel_date_ts,
            'helpers_cancelled' => $helperIds,
            'booking_update_result' => $okBooking,
            'helpers_update_result' => $okHelpers
        ]);
        exit;
    }

    // ------------------ CANCEL PURCHASE (set helper status = 4 = Cancelled) ------------------
    if ($s === 'cancel_purchase') {
    
        // accept via POST or GET
        $booking_helper_id = isset($_POST['booking_helper_id']) ? (int) $_POST['booking_helper_id'] : (isset($_GET['booking_helper_id']) ? (int) $_GET['booking_helper_id'] : 0);
        $cancel_date_raw = isset($_POST['cancel_date']) ? $_POST['cancel_date'] : (isset($_GET['cancel_date']) ? $_GET['cancel_date'] : '');
    
        if ($booking_helper_id <= 0) {
            http_response_code(400);
            echo json_encode(['status' => 400, 'message' => 'Missing or invalid booking_helper_id.']);
            exit;
        }
    
        // find helper
        $helper = $db->where('id', $booking_helper_id)->getOne(T_BOOKING_HELPER);
        if (!$helper) {
            http_response_code(404);
            echo json_encode(['status' => 404, 'message' => 'Booking helper not found.']);
            exit;
        }
        
        // Convert date to timestamp
        $cancel_date_ts = null;
        if (!empty($cancel_date_raw)) {
            $cancel_date_ts = strtotime($cancel_date_raw);
            if ($cancel_date_ts === false) {
                $cancel_date_ts = time(); // fallback to current time
            }
        } else {
            $cancel_date_ts = time();
        }
        
        // booking id exists?
        $booking_id = isset($helper->booking_id) ? (int)$helper->booking_id : 0;
        if ($booking_id <= 0) {
            http_response_code(400);
            echo json_encode(['status' => 400, 'message' => 'Booking id not found for this helper.']);
            exit;
        }
    
        // start transaction
        $db->startTransaction();
    
        // update helper -> set status = 4 (cancelled) and update time
        $updateData = ['status' => 4, 'cancel_date' => $cancel_date_ts];
        $ok = $db->where('id', $booking_helper_id)->update(T_BOOKING_HELPER, $updateData);

        if (!$ok) {
            $db->rollback();
            http_response_code(500);
            echo json_encode(['status' => 500, 'message' => 'Failed to cancel purchase (helper update).']);
            exit;
        }
    
        // Fetch other helpers for same booking (excluding the cancelled one)
        $otherHelpers = $db->where('booking_id', $booking_id)
                           ->where('id', $booking_helper_id, '!=')
                           ->get(T_BOOKING_HELPER);
    
        // Normalize "free" statuses (strings/numbers). Adjust list if your app uses different status values.
        $free_statuses = ['0','1','4','available','cancelled','canceled'];
    
        $hasNonFree = false;
        $otherWithFileNum = false;
        $otherFileNumCandidate = null;
    
        if (!empty($otherHelpers)) {
            foreach ($otherHelpers as $oh) {
                $hstatus_raw = isset($oh->status) ? (string)$oh->status : '';
                $hstatus = strtolower(trim($hstatus_raw));
                // treat empty as free as well
                if ($hstatus !== '' && !in_array($hstatus, $free_statuses, true)) {
                    $hasNonFree = true;
                }
                // candidate file_num from other helpers (prefer non-empty)
                $oh_fn = isset($oh->file_num) ? trim((string)$oh->file_num) : '';
                if ($oh_fn !== '') {
                    $otherWithFileNum = true;
                    if ($otherFileNumCandidate === null) $otherFileNumCandidate = $oh_fn;
                }
                // if we already found a non-free and a file_num candidate, we can stop early
                if ($hasNonFree && $otherFileNumCandidate !== null) break;
            }
        }
    
        // fetch current booking row (we'll need to compare file_num and update)
        $booking = $db->where('id', $booking_id)->getOne(T_BOOKING);
        if (!$booking) {
            // weird: helper had booking_id but booking row missing. Rollback and error.
            $db->rollback();
            http_response_code(500);
            echo json_encode(['status' => 500, 'message' => 'Booking record not found for this helper.']);
            exit;
        }
    
        $booking_file_num = isset($booking->file_num) ? trim((string)$booking->file_num) : '';
    
        // If no other non-free helper exists => cancel booking and clear file_num
        if (!$hasNonFree) {
            $bkUpdate = ['status' => 4, 'file_num' => '']; // set file_num to NULL (no owner)
            $ok2 = $db->where('id', $booking_id)->update(T_BOOKING, $bkUpdate);
            if ($ok2 === false) {
                $db->rollback();
                http_response_code(500);
                echo json_encode(['status' => 500, 'message' => 'Helper cancelled but failed to update booking status.']);
                exit;
            }
        } else {
            // There are other non-free helpers -> booking remains active.
            // But if booking.file_num equals the file_num of the cancelled helper, we should replace it with another helper's file_num (if available), or clear it.
            $cancelled_file_num = isset($helper->file_num) ? trim((string)$helper->file_num) : '';
    
            if ($cancelled_file_num !== '' && $booking_file_num === $cancelled_file_num) {
                // prefer a non-empty file_num from other helpers
                $newFileNum = null;
                if ($otherFileNumCandidate !== null) {
                    $newFileNum = $otherFileNumCandidate;
                } else {
                    // no candidate, clear booking file_num
                    $newFileNum = null;
                }
    
                $bkUpd = ['file_num' => $newFileNum];
                $ok3 = $db->where('id', $booking_id)->update(T_BOOKING, $bkUpd);
                if ($ok3 === false) {
                    $db->rollback();
                    http_response_code(500);
                    echo json_encode(['status' => 500, 'message' => 'Helper cancelled but failed to update booking file number.']);
                    exit;
                }
            }
            // else: booking.file_num is not owned by this helper -> leave as-is
        }
    
        // commit transaction
        $db->commit();
    
        // optional: log
        $logUser = 'User #' . ($wo['user']['id'] ?? 'unknown');
        logActivity('booking', 'cancel', "{$logUser} cancelled booking helper #{$booking_helper_id} for booking #{$booking_id}");
    
        echo json_encode([
            'status' => 200,
            'message' => 'Purchase cancelled successfully.',
            'booking_helper_id' => $booking_helper_id,
            'booking_id' => $booking_id
        ]);
        exit;
    }

    // ------------------ NEW: Get available purchases (for Select2) ------------------
    if ($s === 'get_available_purchases') {
        header('Content-Type: application/json; charset=utf-8');
    
        $project_id = isset($_GET['project_id']) ? $_GET['project_id'] : (isset($_POST['project_id']) ? $_POST['project_id'] : '');
    
        if (!$project_id) {
            echo json_encode([]);
            exit;
        }
    
        $free_statuses = ['0', '1', '4', 'available','cancelled','canceled'];
    
        // Fetch bookings for project
        $bookings = $db->where('project', $project_id)->get(T_BOOKING);
    
        if (empty($bookings)) {
            echo json_encode([]);
            exit;
        }
    
        // Pre-fetch helpers grouped by booking_id
        $bookingIds = array_column($bookings, 'id');
        $helpers = [];
        if (!empty($bookingIds)) {
            $rawHelpers = $db->where('booking_id', $bookingIds, 'IN')->objectbuilder()->get(T_BOOKING_HELPER);
            foreach ($rawHelpers as $h) {
                $helpers[$h->booking_id][] = $h;
            }
        }
    
        $results = [];
        foreach ($bookings as $b) {
            // normalize booking status to string
            $bstatus = strtolower(trim((string)($b->status ?? '')));
    
            // collect helper statuses and detect conflicts
            $helperList = [];
            $hasNonFreeHelper = false;
            if (!empty($helpers[$b->id])) {
                foreach ($helpers[$b->id] as $h) {
                    $hstatus = strtolower(trim((string)($h->status ?? '')));
                    $helperList[] = [
                        'id' => isset($h->id) ? $h->id : null,
                        'booking_id' => $h->booking_id ?? null,
                        'file_id' => $h->file_id ?? null,
                        'status' => $hstatus,
                        'raw' => $h
                    ];
                    if ($hstatus !== '' && !in_array($hstatus, $free_statuses, true)) {
                        $hasNonFreeHelper = true;
                    }
                }
            }
    
            // Decide combined status: prefer a non-free helper status if present; otherwise booking.status
            $combinedStatus = $bstatus;
            if ($hasNonFreeHelper) {
                // pick first non-free helper status for clarity (could be customized)
                foreach ($helperList as $hl) {
                    if ($hl['status'] !== '' && !in_array($hl['status'], $free_statuses, true)) {
                        $combinedStatus = $hl['status'];
                        break;
                    }
                }
            }
    
            // Determine availability based on combinedStatus
            $available = ($combinedStatus === '' || in_array($combinedStatus, $free_statuses, true));
    
            // Produce a friendly status label (always a string; respects '0')
            if ($combinedStatus === '0' || $combinedStatus === '1' || $combinedStatus === 'available') {
                $status_label = 'Available';
            } elseif ($combinedStatus === '2' || $combinedStatus === 'sold' || $combinedStatus === 'booked') {
                $status_label = 'Sold';
            } elseif ($combinedStatus === '4' || $combinedStatus === 'cancelled' || $combinedStatus === 'canceled') {
                $status_label = 'Cancelled';
            } elseif ($combinedStatus === '') {
                $status_label = '';
            } else {
                // fallback: ucfirst raw status
                $status_label = ucfirst($combinedStatus);
            }
    
            // disabled flag: true when NOT available (frontend can choose to honor or ignore)
            $disabled = !$available;
    
            // Build label for Select2 display
            $labelParts = [];
            if (!empty($b->block)) $labelParts[] = ucwords($b->block);
            $labelParts[] = 'Plot ' . $b->plot;
            if (!empty($b->katha)) $labelParts[] = $b->katha . ' katha';
            if (!empty($b->road)) $labelParts[] = 'Road ' . $b->road;
            $label = implode(' â€¢ ', array_filter($labelParts));
    
            // summary of helper conflicts (if any non-free helpers exist)
            $conflicts = [];
            if (!empty($helperList)) {
                foreach ($helperList as $hl) {
                    if ($hl['status'] !== '' && !in_array($hl['status'], $free_statuses, true)) {
                        $conflicts[] = [
                            'helper_id' => $hl['id'],
                            'booking_id' => $hl['booking_id'],
                            'file_id' => $hl['file_id'],
                            'status' => $hl['status']
                        ];
                    }
                }
            }
    
            $results[] = [
                'id'            => $b->id,
                'text'          => $label ?: ('Plot ' . $b->plot),
                'katha'         => $b->katha,
                'plot'          => $b->plot,
                'block'         => $b->block,
                'road'          => $b->road,
                'facing'        => $b->facing,
                'status'        => $combinedStatus,      // raw/computed status (may be '0', 'sold', 'booked', etc.)
                'status_label'  => $status_label,        // human readable label (string; "0" will become "Available")
                'available'     => $available ? 1 : 0,   // helpful for client-side quick checks
                'disabled'      => $disabled ? 1 : 0,    // UI may use this to visually disable selection
                'helpers'       => $helperList,          // raw helper entries (if you want to inspect)
                'conflicts'     => $conflicts            // simplified conflict summary (only non-free helpers)
            ];
        }
    
        echo json_encode($results);
        exit;
    }
        
    if ($s === 'search_purchases') {
        header('Content-Type: application/json; charset=utf-8');
    
        $q = isset($_GET['q']) ? trim($_GET['q']) : (isset($_POST['q']) ? trim($_POST['q']) : '');
        $page = isset($_GET['page']) ? (int)$_GET['page'] : (isset($_POST['page']) ? (int)$_POST['page'] : 1);
        $per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : (isset($_POST['per_page']) ? (int)$_POST['per_page'] : 30);
        $project_id = isset($_GET['project_id']) ? $_GET['project_id'] : (isset($_POST['project_id']) ? $_POST['project_id'] : '');
    
        if (!$project_id) {
            echo json_encode(['results'=>[], 'more'=>false]);
            exit;
        }
    
        if ($page < 1) $page = 1;
        if ($per_page < 1) $per_page = 30;
        $offset = ($page - 1) * $per_page;
        $limit = $per_page + 1; // request one extra to detect "more"
    
        // Tokenize the query (whitespace split)
        $tokens = [];
        if ($q !== '') {
            $rawTokens = preg_split('/\s+/', $q);
            foreach ($rawTokens as $t) {
                $tTrim = trim($t);
                if ($tTrim !== '') $tokens[] = $tTrim;
            }
        }
    
        // Base WHERE and params
        $whereParts = ["`project` = ?"];
        $params = [$project_id];
    
        // Process each token and append a single OR-group per token.
        foreach ($tokens as $token) {
            $tok = trim($token);
            if ($tok === '') continue;
            $lower = mb_strtolower($tok, 'UTF-8');
    
            $groupSql = [];     // OR clauses for this token
            $groupParams = [];  // params for this token (kept contiguous)
    
            // 1) explicit KATHA forms: "k5", "5k", "k 5"
            if (preg_match('/^(?:k(?:atha)?)[:\-\s]*([0-9]+(?:\.\d+)?)$/i', $tok, $m) ||
                preg_match('/^([0-9]+(?:\.\d+)?)[:\-\s]*k(?:atha)?$/i', $tok, $m)) {
                $num = $m[1];
                $groupSql[] = "CAST(`katha` AS DECIMAL(10,3)) = ?";
                $groupParams[] = $num;
                $groupSql[] = "LOWER(`katha`) LIKE ?";
                $groupParams[] = '%' . $lower . '%';
            }
            // 2) explicit PLOT prefix: "p1543", "plot1543", "1543p"
            elseif (preg_match('/^(?:p(?:lot)?)[:\-\s]*([A-Za-z0-9\-_\/]+)$/i', $tok, $m) ||
                    preg_match('/^([A-Za-z0-9\-_\/]+)[:\-\s]*p(?:lot)?$/i', $tok, $m)) {
                $val = mb_strtolower($m[1], 'UTF-8');
                $groupSql[] = "LOWER(`plot`) = ?";
                $groupParams[] = $val;
                $groupSql[] = "LOWER(`plot`) LIKE ?";
                $groupParams[] = '%' . $val . '%';
                $groupSql[] = "LOWER(CONCAT_WS(' ', `block`, `plot`)) LIKE ?";
                $groupParams[] = '%' . $lower . '%';
            }
            // 3) explicit ROAD prefix: "r2", "rd30", "road 4", "2r"
            elseif (preg_match('/^(?:r|rd|road)[:\-\s]*([0-9]+)$/i', $tok, $m) ||
                    preg_match('/^([0-9]+)[:\-\s]*(?:r|rd|road)$/i', $tok, $m)) {
                $num = (int)$m[1];
                $groupSql[] = "`road` RLIKE ?";
                $groupParams[] = '[[:<:]]' . $num . '[[:>:]]';
                $groupSql[] = "CAST(REGEXP_REPLACE(`road`, '[^0-9\\-]', '') AS SIGNED) = ?";
                $groupParams[] = $num;
                $groupSql[] = "LOWER(`road`) LIKE ?";
                $groupParams[] = '%' . $lower . '%';
            }
            // 4) explicit BLOCK forms: "bA", "blockA", "block A", "A block", "BA"
            // IMPORTANT: block matching uses only equality checks as you requested.
            elseif (preg_match('/^(?:b|block)[:\-\s]*([A-Za-z0-9\-\_\/]{1,8})$/i', $tok, $m) ||
                    preg_match('/^([A-Za-z0-9\-\_\/]{1,8})[:\-\s]*(?:block|b)$/i', $tok, $m)) {
                $val = mb_strtolower($m[1], 'UTF-8');
    
                // If token is a single character (like "a" or "B"), do a single equality check:
                if (mb_strlen($val, 'UTF-8') === 1) {
                    $groupSql[] = "LOWER(`block`) = ?";
                    $groupParams[] = $val;
                } else {
                    // Multi-letter token: split into characters and create equality ORs:
                    // (LOWER(block) = ? OR LOWER(block) = ? ...)
                    $chars = preg_split('//u', $val, -1, PREG_SPLIT_NO_EMPTY);
                    $chars = array_values(array_unique($chars));
                    foreach ($chars as $ch) {
                        $groupSql[] = "LOWER(`block`) = ?";
                        $groupParams[] = $ch;
                    }
                    // Note: we do NOT add LIKE or IN; we only use equality checks as requested.
                }
            }
            // 5) mixed letters+digits e.g. "p12a", "r12b" -> leading-letter heuristics
            elseif (preg_match('/[A-Za-z]/', $tok) && preg_match('/\d/', $tok)) {
                $first = strtolower($tok[0]);
                if ($first === 'p') {
                    if (preg_match('/^p[:\-\s]*([A-Za-z0-9\-_\/]+)$/i', $tok, $m)) $val = mb_strtolower($m[1], 'UTF-8');
                    else $val = mb_strtolower($tok, 'UTF-8');
                    $groupSql[] = "LOWER(`plot`) = ?"; $groupParams[] = $val;
                    $groupSql[] = "LOWER(`plot`) LIKE ?"; $groupParams[] = '%' . $val . '%';
                    $groupSql[] = "LOWER(CONCAT_WS(' ', `block`, `plot`)) LIKE ?"; $groupParams[] = '%' . $lower . '%';
                } elseif ($first === 'r') {
                    if (preg_match('/^r[:\-\s]*([0-9]+)$/i', $tok, $m)) $num = (int)$m[1];
                    elseif (preg_match('/^([0-9]+)r$/i', $tok, $m)) $num = (int)$m[1];
                    else { preg_match('/([0-9]+)/', $tok, $md); $num = isset($md[1]) ? (int)$md[1] : 0; }
                    if ($num > 0) {
                        $groupSql[] = "`road` RLIKE ?"; $groupParams[] = '[[:<:]]' . $num . '[[:>:]]';
                        $groupSql[] = "CAST(REGEXP_REPLACE(`road`, '[^0-9\\-]', '') AS SIGNED) = ?"; $groupParams[] = $num;
                        $groupSql[] = "LOWER(`road`) LIKE ?"; $groupParams[] = '%' . $lower . '%';
                    } else {
                        $groupSql[] = "LOWER(CONCAT_WS(' ', `block`, `plot`, `katha`, `road`)) LIKE ?";
                        $groupParams[] = '%' . $lower . '%';
                    }
                } elseif ($first === 'k') {
                    if (preg_match('/k[:\-\s]*([0-9]+(?:\.\d+)?)/i', $tok, $m) || preg_match('/([0-9]+(?:\.\d+)?)k/i', $tok, $m)) {
                        $num = $m[1];
                        $groupSql[] = "CAST(`katha` AS DECIMAL(10,3)) = ?"; $groupParams[] = $num;
                        $groupSql[] = "LOWER(`katha`) LIKE ?"; $groupParams[] = '%' . $lower . '%';
                    } else {
                        $groupSql[] = "LOWER(CONCAT_WS(' ', `block`, `plot`, `katha`, `road`)) LIKE ?";
                        $groupParams[] = '%' . $lower . '%';
                    }
                } else {
                    $groupSql[] = "LOWER(CONCAT_WS(' ', `block`, `plot`, `katha`, `road`)) LIKE ?";
                    $groupParams[] = '%' . $lower . '%';
                }
            }
            // 6) short alphabetic tokens (A / B / BA / ABC) -> treat as block codes via equality only
            elseif (preg_match('/^[A-Za-z]{1,4}$/', $tok)) {
                $val = mb_strtolower($tok, 'UTF-8');
                if (mb_strlen($val, 'UTF-8') === 1) {
                    $groupSql[] = "LOWER(`block`) = ?";
                    $groupParams[] = $val;
                } else {
                    // multi-letter: generate equality ORs for each character (no LIKE)
                    $chars = preg_split('//u', $val, -1, PREG_SPLIT_NO_EMPTY);
                    $chars = array_values(array_unique($chars));
                    foreach ($chars as $ch) {
                        $groupSql[] = "LOWER(`block`) = ?";
                        $groupParams[] = $ch;
                    }
                }
            }
            // 7) status words
            elseif (preg_match('/\b(sold|cancelled|canceled|available|booked|complete|completed|pending)\b/i', $tok, $m)) {
                $val = mb_strtolower($m[1], 'UTF-8');
                $groupSql[] = "LOWER(`status`) LIKE ?";
                $groupParams[] = '%' . $val . '%';
                $groupSql[] = "LOWER(`status_label`) LIKE ?";
                $groupParams[] = '%' . $val . '%';
            }
            // 8) bare numeric token: treat heuristically
            elseif (preg_match('/^[0-9]+(?:\.[0-9]+)?$/', $tok)) {
                $numInt = (int)$tok;
                if ($numInt <= 999) {
                    $groupSql[] = "`road` RLIKE ?";
                    $groupParams[] = '[[:<:]]' . $numInt . '[[:>:]]';
                    $groupSql[] = "CAST(REGEXP_REPLACE(`road`, '[^0-9\\-]', '') AS SIGNED) = ?";
                    $groupParams[] = $numInt;
                    $groupSql[] = "LOWER(`road`) LIKE ?";
                    $groupParams[] = '%' . $lower . '%';
                } else {
                    $groupSql[] = "CAST(`katha` AS DECIMAL(10,3)) = ?";
                    $groupParams[] = $tok;
                    $groupSql[] = "LOWER(`katha`) LIKE ?";
                    $groupParams[] = '%' . $lower . '%';
                }
            }
            // 9) fallback: generic LIKE across common fields (not block)
            else {
                $groupSql[] = "LOWER(`plot`) LIKE ?";  $groupParams[] = '%' . $lower . '%';
                $groupSql[] = "LOWER(`katha`) LIKE ?"; $groupParams[] = '%' . $lower . '%';
                $groupSql[] = "LOWER(`road`) LIKE ?";  $groupParams[] = '%' . $lower . '%';
                $groupSql[] = "LOWER(`status`) LIKE ?"; $groupParams[] = '%' . $lower . '%';
                $groupSql[] = "LOWER(CONCAT_WS(' ', `block`,`plot`,`katha`,`road`)) LIKE ?"; $groupParams[] = '%' . $lower . '%';
            }
    
            // Append this token's grouped OR clause and that group's params (contiguous)
            if (!empty($groupSql)) {
                $whereParts[] = '(' . implode(' OR ', $groupSql) . ')';
                foreach ($groupParams as $p) $params[] = $p;
            }
        } // end foreach tokens
    
        // Final SQL and parameter append for offset/limit
        $whereSql = implode(' AND ', $whereParts);
        $sql = "SELECT SQL_CALC_FOUND_ROWS * FROM `" . T_BOOKING . "` WHERE " . $whereSql . " ORDER BY `id` ASC LIMIT ?, ?";
        $params[] = (int)$offset;
        $params[] = (int)$limit;
    
        try {
            $rows = $db->rawQuery($sql, $params);
        } catch (Exception $ex) {
            error_log('search_purchases rawQuery error: ' . $ex->getMessage());
            echo json_encode(['results'=>[], 'more'=>false]);
            exit;
        }
    
        // Determine "more"
        $fetchedCount = is_array($rows) ? count($rows) : 0;
        $more = false;
        if ($fetchedCount > $per_page) {
            $more = true;
            $rows = array_slice($rows, 0, $per_page);
        }
    
        if (empty($rows)) {
            echo json_encode(['results'=>[], 'more'=>$more]);
            exit;
        }
    
        // Pre-fetch helpers for bookings
        $bookingIds = array_column($rows, 'id');
        $helpers = [];
        if (!empty($bookingIds)) {
            $rawHelpers = $db->where('booking_id', $bookingIds, 'IN')->objectbuilder()->get(T_BOOKING_HELPER);
            if ($rawHelpers) {
                foreach ($rawHelpers as $h) {
                    $helpers[$h->booking_id][] = $h;
                }
            }
        }
    
        // free statuses set
        $free_statuses = ['0', '1', '4', 'available', 'cancelled', 'canceled'];
    
        // Build final results (keeps your original logic)
        $results = [];
        foreach ($rows as $b) {
            $bstatus = strtolower(trim((string)($b->status ?? '')));
    
            $helperList = [];
            $hasNonFreeHelper = false;
            if (!empty($helpers[$b->id])) {
                foreach ($helpers[$b->id] as $h) {
                    $hstatus = strtolower(trim((string)($h->status ?? '')));
                    $helperList[] = [
                        'id' => isset($h->id) ? $h->id : null,
                        'booking_id' => $h->booking_id ?? null,
                        'file_id' => $h->file_id ?? null,
                        'status' => $hstatus,
                        'raw' => $h
                    ];
                    if ($hstatus !== '' && !in_array($hstatus, $free_statuses, true)) {
                        $hasNonFreeHelper = true;
                    }
                }
            }
    
            $combinedStatus = $bstatus;
            if ($hasNonFreeHelper) {
                foreach ($helperList as $hl) {
                    if ($hl['status'] !== '' && !in_array($hl['status'], $free_statuses, true)) {
                        $combinedStatus = $hl['status'];
                        break;
                    }
                }
            }
    
            $available = ($combinedStatus === '' || in_array($combinedStatus, $free_statuses, true));
    
            if ($combinedStatus === '0' || $combinedStatus === '1' || $combinedStatus === 'available') {
                $status_label = 'Available';
            } elseif ($combinedStatus === '2' || $combinedStatus === 'sold' || $combinedStatus === 'booked') {
                $status_label = 'Sold';
            } elseif ($combinedStatus === '4' || $combinedStatus === 'cancelled' || $combinedStatus === 'canceled') {
                $status_label = 'Cancelled';
            } elseif ($combinedStatus === '') {
                $status_label = '';
            } else {
                $status_label = ucfirst($combinedStatus);
            }
    
            $disabled = !$available;
    
            // Select2 label
            $labelParts = [];
            if (!empty($b->block)) $labelParts[] = ucwords($b->block);
            $labelParts[] = 'Plot ' . $b->plot;
            if (!empty($b->katha)) $labelParts[] = $b->katha . ' katha';
            if (!empty($b->road)) $labelParts[] = 'Road ' . $b->road;
            if (!empty($b->facing)) $labelParts[] = 'Facing ' . $b->facing;
            $label = implode(' â€¢ ', array_filter($labelParts));
            
            // simplified conflicts (non-free helpers)
            $conflicts = [];
            if (!empty($helperList)) {
                foreach ($helperList as $hl) {
                    if ($hl['status'] !== '' && !in_array($hl['status'], $free_statuses, true)) {
                        $conflicts[] = [
                            'helper_id' => $hl['id'],
                            'booking_id' => $hl['booking_id'],
                            'file_id' => $hl['file_id'],
                            'status' => $hl['status']
                        ];
                    }
                }
            }
    
            $results[] = [
                'id'            => $b->id,
                'text'          => $label ?: ('Plot ' . $b->plot),
                'katha'         => $b->katha,
                'plot'          => $b->plot,
                'facing'        => $b->facing,
                'block'         => $b->block,
                'road'          => $b->road,
                'status'        => $combinedStatus,
                'status_label'  => $status_label,
                'available'     => $available ? 1 : 0,
                'disabled'      => $disabled ? 1 : 0,
                'helpers'       => $helperList,
                'conflicts'     => $conflicts
            ];
        }
    
        echo json_encode(['results' => $results, 'more' => $more]);
        exit;
    }

    // ===============================
    //  📄 GET SCHEDULE PREVIEW FOR PRINTING
    // ===============================
    if ($s == 'get_schedule_preview') {
        $purchase_id = isset($_POST['purchase_id']) ? (int)$_POST['purchase_id'] : 0;
        $options_json = isset($_POST['options']) ? $_POST['options'] : '{}';
        
        if (!$purchase_id) {
            echo json_encode(['status' => 400, 'message' => 'Invalid purchase ID']);
            exit;
        }

        $options = json_decode($options_json, true) ?: [];
        
        // Get purchase details
        $helper = $db->where('id', $purchase_id)->getOne(T_BOOKING_HELPER);
        if (!$helper) {
            echo json_encode(['status' => 404, 'message' => 'Purchase not found']);
            exit;
        }

        $booking = $db->where('id', $helper->booking_id)->getOne(T_BOOKING);
        $client = GetCustomerById($helper->client_id);

        // Generate preview HTML
        $html = '<div class="schedule-print-template">';
        
        // Company header
        $html .= '<div class="company-header">';
        $html .= '<div class="company-name">Civic Real Estate Ltd.</div>';
        $html .= '<div class="document-title">Payment Schedule</div>';
        $html .= '<div class="text-muted">Generated on ' . date('F j, Y') . '</div>';
        $html .= '</div>';
        
        // Client info
        if ($options['include_client_info'] ?? true) {
            $html .= '<div class="info-section">';
            $html .= '<div class="info-title">Client Information</div>';
            $html .= '<div class="info-grid">';
            $html .= '<div class="info-item"><div class="info-label">Name</div><div class="info-value">' . htmlspecialchars($client['name'] ?? '') . '</div></div>';
            $html .= '<div class="info-item"><div class="info-label">Phone</div><div class="info-value">' . htmlspecialchars($client['phone'] ?? '') . '</div></div>';
            $html .= '<div class="info-item"><div class="info-label">Address</div><div class="info-value">' . htmlspecialchars($client['address'] ?? '') . '</div></div>';
            $html .= '<div class="info-item"><div class="info-label">File Number</div><div class="info-value">' . htmlspecialchars($helper->file_num ?? '') . '</div></div>';
            $html .= '</div>';
            $html .= '</div>';
        }
        
        // Plot details
        if ($options['include_plot_details'] ?? true) {
            $html .= '<div class="info-section">';
            $html .= '<div class="info-title">Plot Details</div>';
            $html .= '<div class="info-grid">';
            $html .= '<div class="info-item"><div class="info-label">Block</div><div class="info-value">' . htmlspecialchars($booking->block ?? '') . '</div></div>';
            $html .= '<div class="info-item"><div class="info-label">Plot</div><div class="info-value">' . htmlspecialchars($booking->plot ?? '') . '</div></div>';
            $html .= '<div class="info-item"><div class="info-label">Katha</div><div class="info-value">' . htmlspecialchars($booking->katha ?? '') . '</div></div>';
            $html .= '<div class="info-item"><div class="info-label">Road</div><div class="info-value">' . htmlspecialchars($booking->road ?? '') . '</div></div>';
            $html .= '</div>';
            $html .= '</div>';
        }

        // Payment schedule table
        $schedule = [];
        if (!empty($helper->installment)) {
            $schedule_data = json_decode($helper->installment, true);
            if (is_array($schedule_data)) {
                $schedule = $schedule_data;
            }
        }

        if (!empty($schedule)) {
            $html .= '<div class="info-section">';
            $html .= '<div class="info-title">Payment Schedule</div>';
            $html .= '<table class="table table-bordered">';
            $html .= '<thead><tr><th>#</th><th>Due Date</th><th>Amount</th><th>Status</th><th>Type</th></tr></thead>';
            $html .= '<tbody>';
            
            $total_amount = 0;
            $paid_amount = 0;
            
            foreach ($schedule as $index => $item) {
                $amount = (float)($item['amount'] ?? 0);
                $total_amount += $amount;
                
                $status_badge = ($item['paid'] ?? false) ? 
                    '<span class="badge bg-success">Paid</span>' : 
                    '<span class="badge bg-warning">Pending</span>';
                
                if ($item['paid'] ?? false) {
                    $paid_amount += $amount;
                }
                
                $type_badge = ($item['adjustment'] ?? false) ? 
                    '<span class="badge bg-info">Yearly</span>' : 
                    '<span class="badge bg-secondary">Monthly</span>';
                
                // Apply filters
                $show_row = true;
                if (($options['show_paid_only'] ?? false) && !($item['paid'] ?? false)) {
                    $show_row = false;
                }
                if (($options['show_pending_only'] ?? false) && ($item['paid'] ?? false)) {
                    $show_row = false;
                }
                
                if ($show_row) {
                    $html .= '<tr>';
                    $html .= '<td>' . ($index + 1) . '</td>';
                    $html .= '<td>' . htmlspecialchars($item['date'] ?? '') . '</td>';
                    $html .= '<td>৳' . number_format($amount, 2) . '</td>';
                    $html .= '<td>' . $status_badge . '</td>';
                    $html .= '<td>' . $type_badge . '</td>';
                    $html .= '</tr>';
                }
            }
            
            $html .= '</tbody>';
            $html .= '<tfoot>';
            $html .= '<tr class="table-info"><td colspan="2"><strong>Total</strong></td><td><strong>৳' . number_format($total_amount, 2) . '</strong></td><td colspan="2"></td></tr>';
            $html .= '<tr class="table-success"><td colspan="2"><strong>Paid</strong></td><td><strong>৳' . number_format($paid_amount, 2) . '</strong></td><td colspan="2"></td></tr>';
            $html .= '<tr class="table-warning"><td colspan="2"><strong>Due</strong></td><td><strong>৳' . number_format($total_amount - $paid_amount, 2) . '</strong></td><td colspan="2"></td></tr>';
            $html .= '</tfoot>';
            $html .= '</table>';
            $html .= '</div>';
        }

        // Payment summary
        if ($options['include_payment_summary'] ?? true) {
            $per_katha = (float)($helper->per_katha ?? 0);
            $katha = (float)($booking->katha ?? 0);
            $total_price = $per_katha * $katha;
            $paid_amount = (float)$db->where('customer_id', $helper->client_id)->getValue(T_INVOICE, 'SUM(pay_amount)') ?: 0;
            
            $html .= '<div class="info-section">';
            $html .= '<div class="info-title">Payment Summary</div>';
            $html .= '<div class="info-grid">';
            $html .= '<div class="info-item"><div class="info-label">Total Price</div><div class="info-value">৳' . number_format($total_price, 2) . '</div></div>';
            $html .= '<div class="info-item"><div class="info-label">Down Payment</div><div class="info-value">৳' . number_format($helper->down_payment ?? 0, 2) . '</div></div>';
            $html .= '<div class="info-item"><div class="info-label">Booking Money</div><div class="info-value">৳' . number_format($helper->booking_money ?? 0, 2) . '</div></div>';
            $html .= '<div class="info-item"><div class="info-label">Invoice Paid</div><div class="info-value">৳' . number_format($paid_amount, 2) . '</div></div>';
            $html .= '</div>';
            $html .= '</div>';
        }
        
        // Signature area
        $html .= '<div class="signature-area">';
        $html .= '<div class="signature-box"><div class="signature-line">Client Signature</div></div>';
        $html .= '<div class="signature-box"><div class="signature-line">Authorized Signature</div></div>';
        $html .= '</div>';
        
        $html .= '</div>';

        echo json_encode(['status' => 200, 'html' => $html]);
        exit;
    }

    // ===============================
    //  📊 EXPORT SCHEDULE
    // ===============================
    if ($s == 'export_schedule') {
        $purchase_id = isset($_POST['purchase_id']) ? (int)$_POST['purchase_id'] : 0;
        $schedule_json = isset($_POST['schedule']) ? $_POST['schedule'] : '[]';
        $format = isset($_POST['format']) ? $_POST['format'] : 'excel';
        
        if (!$purchase_id) {
            echo json_encode(['status' => 400, 'message' => 'Invalid purchase ID']);
            exit;
        }

        // For now, return a placeholder download URL
        $filename = 'payment_schedule_' . $purchase_id . '_' . date('Y-m-d') . '.xlsx';
        
        echo json_encode([
            'status' => 200, 
            'download_url' => '/exports/' . $filename,
            'filename' => $filename,
            'message' => 'Schedule exported successfully'
        ]);
        exit;
    }

    // ===============================
    //  🔍 GET AVAILABLE PLOTS
    // ===============================
    if ($s == 'get_available_plots') {
        $project_slug = isset($_GET['project_slug']) ? trim($_GET['project_slug']) : '';
        
        if (!$project_slug) {
            echo json_encode([]);
            exit;
        }

        // Get available plots for the project
        $plots = $db->where('project', $project_slug)
                   ->where('status', '1')
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
            // If booking already marked sold but the same client has helper, allow update â€” otherwise count as conflict.
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

    // ------------------ EDIT INVENTORY ------------------
    if ($s === 'edit_inventory') {
        $id        = $_POST['id']     ?? null;
        $project   = $_POST['project'] ?? null;
        $block     = isset($_POST['block']) ? strtolower(trim($_POST['block'])) : null;
        $facing    = isset($_POST['facing']) ? strtolower(trim($_POST['facing'])) : null;
        $katha     = isset($_POST['katha']) ? normalizeKatha($_POST['katha']) : null;
        $road      = $_POST['road']      ?? null;
        $plot_num  = $_POST['plot_num']  ?? null;
    
        if (empty($id)) {
            echo json_encode(['status' => 400, 'message' => 'Invalid booking ID.']); exit;
        }
    
        $booking = $db->where('id', $id)->getOne(T_BOOKING);
        if (!$booking) {
            echo json_encode(['status' => 404, 'message' => 'Booking not found.']); exit;
        }
    
        $updateData = [];
        $logChanges = [];
    
        // --- Check each field ---
        if (!is_null($project) && $project != $booking->project) {
            $updateData['project'] = $project;
            $logChanges[] = "project changed from '{$booking->project}' to '{$project}'";
        }
        if (!is_null($block) && $block != $booking->block) {
            $updateData['block'] = $block;
            $logChanges[] = "block changed from '{$booking->block}' to '{$block}'";
        }
        if (!empty($facing) && $facing != $booking->facing) {
            $updateData['facing'] = $facing;
            $logChanges[] = "facing changed from '{$booking->facing}' to '{$facing}'";
        }
        if (!is_null($katha) && $katha != $booking->katha) {
            $updateData['katha'] = $katha;
            $logChanges[] = "katha changed from '{$booking->katha}' to '{$katha}'";
        }
        if (!is_null($road) && $road != $booking->road) {
            $updateData['road'] = $road;
            $logChanges[] = "road changed from '{$booking->road}' to '{$road}'";
        }
        if (!is_null($plot_num) && $plot_num != $booking->plot) {
            $updateData['plot'] = $plot_num; // assuming DB column = plot
            $logChanges[] = "plot changed from '{$booking->plot}' to '{$plot_num}'";
        }
    
        if (empty($updateData)) {
            echo json_encode(['status' => 400, 'message' => 'Nothing to update.']); exit;
        }
    
        // --- Check duplicate ---
        $db->where('id', $id, '!=')
           ->where('project', $updateData['project'] ?? $booking->project)
           ->where('katha', $updateData['katha'] ?? $booking->katha)
           ->where('plot', $updateData['plot'] ?? $booking->plot)
           ->where('road', $updateData['road'] ?? $booking->road);
    
        if (array_key_exists('block', $updateData)) {
            $db->where('block', $updateData['block']);
        } else {
            $db->where('block', $booking->block);
        }
        if (array_key_exists('facing', $updateData)) {
            $db->where('facing', $updateData['facing']);
        } else {
            $db->where('facing', $booking->facing);
        }
    
        $exist = $db->getOne(T_BOOKING);
        if ($exist) {
            echo json_encode([
                'status' => 400,
                'message' => 'Another booking with the same project, block, plot, road, katha & facing already exists!'
            ]); exit;
        }
    
        // --- Perform update ---
        $update = $db->where('id', $id)->update(T_BOOKING, $updateData);
    
        if ($update) {
            // --- Logging ---
            $logUser    = 'User #' . $wo['user']['id']; // adjust to your user system
            $logDate    = date('Y-m-d H:i:s');
            $logDetails = "Booking ID #{$id} ({$booking->project}, Plot {$booking->plot}, Katha {$booking->katha})";
            $logMessage = implode('; ', $logChanges);
            logActivity('booking', 'update', "{$logUser} updated {$logDetails}: {$logMessage}");
    
            echo json_encode(['status' => 200, 'message' => 'Booking updated successfully!']);
        } else {
            echo json_encode(['status' => 500, 'message' => 'Failed to update booking.']);
        }
        exit;
    }

    // ------------------ SUBMIT NEW BOOKING ------------------
    if ($s == 'submit') {
        $project   = isset($_POST['project']) ? strtolower(trim($_POST['project'])) : '';
        $block     = isset($_POST['block']) ? strtolower(trim($_POST['block'])) : '';
        $katha     = isset($_POST['katha']) ? normalizeKatha($_POST['katha']) : '';
        $plot_num  = isset($_POST['plot_num']) ? trim($_POST['plot_num']) : '';
        $facing    = isset($_POST['facing']) ? strtolower(trim($_POST['facing'])) : '';
        $road      = isset($_POST['road']) ? trim($_POST['road']) : '';
        $file_num  = isset($_POST['file_num']) ? strtolower(trim($_POST['file_num'])) : null;

        if ($project == 'moon-hill') {
            if (empty($project) || empty($katha) || empty($plot_num) || empty($facing) || empty($road)) {
                $data = ['status'=>400,'message'=>'All fields except file number are required!'];
            }
            $is_exist = $db->where('project', $project)
                           ->where('katha', $katha)
                           ->where('plot', $plot_num)
                           ->where('facing', $facing)
                           ->where('road', $road)
                           ->getOne(T_BOOKING);
        } else {
            if (empty($project) || empty($katha) || empty($plot_num) || empty($facing) || empty($road)) {
                $data = ['status'=>400,'message'=>'All fields except file number are required!'];
            }
            $is_exist = $db->where('project', $project)
                           ->where('katha', $katha)
                           ->where('plot', $plot_num)
                           ->where('facing', $facing)
                           ->where('road', $road)
                           ->getOne(T_BOOKING);
        }

        if ($is_exist) {
            $data = ['status'=>400,'message'=>'Entry already exists!'];
        } else {
            $data_array = ['project'=>$project,'katha'=>$katha,'plot'=>$plot_num,'facing'=>$facing,'road'=>$road];
            if ($project != 'moon-hill') $data_array['block']=$block;
            if (!empty($file_num)) $data_array['file_num']=$file_num;

            $insert = $db->insert(T_BOOKING,$data_array);
            if ($insert) {
                $data = ['status'=>200,'message'=>'Added successfully!'];
                // Logging
                $logUser    = 'User #' . $wo['user']['id'];
                $logDate    = date('Y-m-d H:i:s');
                $logDetails = "Booking ID #{$insert} ({$project}, Plot {$plot_num}, Katha {$katha})";
                logActivity('booking', 'create', "{$logUser} added new booking {$logDetails}");
            } else {
                $data = ['status'=>400,'message'=>'Something went wrong!'];
            }
        }
    }

    // ------------------ EDIT MODAL ------------------
    if ($s == 'edit_modal') {
        $id = isset($_POST['id']) ? $_POST['id'] : '';
        if (empty($id)) {
            $data = ['status'=>400,'message'=>'Something went wrong!'];
        } else {
            $inventory = $db->where('id', $id)->getOne(T_BOOKING);
            $data = ['status'=>200,'result'=>Wo_LoadManagePage('inventory/edit')];
        }
    }

    // ------------------ UPDATE STATUS ------------------
    if ($s === 'update_status') {
        $id       = !empty($_POST['id']) ? $_POST['id'] : null;
        $file_id  = !empty($_POST['file_id']) ? $_POST['file_id'] : null;
        $file_id2 = !empty($_POST['file_id2']) ? $_POST['file_id2'] : null;
        $status   = isset($_POST['status']) ? $_POST['status'] : '0';
        $date     = !empty($_POST['date']) ? $_POST['date'] : '';

        if (empty($id)) { echo json_encode(['status'=>400,'message'=>'Invalid booking ID.']); exit; }
        if (empty($file_id) && empty($file_id2)) { echo json_encode(['status'=>400,'message'=>'Client/File ID is required!']); exit; }
        if (empty($file_id)) $file_id=$file_id2;
        $timestamp = ($date && strtotime($date)!==false) ? strtotime($date) : time();

        $is_exist = $db->where('booking_id',$id)->where('file_num',$file_id)->getOne(T_BOOKING_HELPER);
        $updateData = ['status'=>$status,'time'=>$timestamp];

        if ($is_exist) {
            $update = $db->where('booking_id',$id)->where('file_num',$file_id)->update(T_BOOKING_HELPER,$updateData);
            $data = $update ? ['status'=>200,'message'=>'Record updated successfully!'] : ['status'=>500,'message'=>'Failed to update record!'];
        } else {
            $lastEntry = $db->where('booking_id',$id)->orderBy('time','DESC')->getOne(T_BOOKING_HELPER);
            if ($lastEntry) {
                $db->where('booking_id',$id)->where('id',$lastEntry->id,'!=')->update(T_BOOKING_HELPER,['status'=>4]);
                $db->where('id',$lastEntry->id)->update(T_BOOKING_HELPER,['status'=>4,'time'=>$timestamp]);
            }
            $insertData = ['booking_id'=>$id,'status'=>$status,'time'=>$timestamp,'file_num'=>$file_id];
            $insert = $db->insert(T_BOOKING_HELPER,$insertData);
            $data = $insert ? ['status'=>200,'message'=>'Record inserted successfully!'] : ['status'=>500,'message'=>'Failed to insert record!'];
        }
        if ($data['status']===200) $db->where('id',$id)->update(T_BOOKING,['status'=>$status,'file_num'=>$file_id]);
    }

    // ------------------ FETCH DATA ------------------
    if ($s == 'fetch') {
        $page_num = isset($_POST['start']) ? $_POST['start']/$_POST['length']+1 : 1;
        $searchValue = isset($_POST['search']['value']) ? $_POST['search']['value'] : '';
        $project = isset($_POST['project']) ? $_POST['project'] : '';
        $block   = isset($_POST['block']) ? $_POST['block'] : '';
        $katha   = isset($_POST['katha']) ? normalizeKatha($_POST['katha']) : '';
        $road    = isset($_POST['road']) ? $_POST['road'] : '';
        $facing  = isset($_POST['facing']) ? $_POST['facing'] : '';
        $plot_num= isset($_POST['plot_num']) ? $_POST['plot_num'] : '';

        if (!empty($searchValue)) {
            $db->where(is_numeric($searchValue)?'file_id':'name','%'.$searchValue.'%','LIKE');
        }
        if (!empty($project)) $db->where('project',$project);
        if (!empty($block) && $block!='Select Block...') $db->where('block',$block);
        if (!empty($katha) && $katha!='Select Katha...') $db->where('katha',$katha);
        if (!empty($road) && $road!='Select Road...') $db->where('road',$road);
        if (!empty($facing) && $facing!='Select Facing...') $db->where('facing',$facing);
        if (!empty($plot_num)) $db->where('plot','%'.$plot_num.'%','LIKE');

        $orderColumn = isset($_POST['order'][0]['column']) ? $_POST['order'][0]['column'] : null;
        $orderDirection = isset($_POST['order'][0]['dir']) ? $_POST['order'][0]['dir'] : null;
        if ($orderColumn!==null && $orderColumn==3) $db->orderBy('plot',$orderDirection=='asc'?'ASC':'DESC');
        else $db->orderBy('plot','DESC');

        $db->pageLimit = $_POST['length'];
        $inventory = $db->objectbuilder()->paginate(T_BOOKING,$page_num);

        $outputData = [];
        if ($inventory) {
            foreach ($inventory as $value) {
                $client = GetCustomerById($value->file_num);

                $status_raw = $value->status;
                if ($status_raw == '1') $status = '<span class="badge bg-info"> Available </span>';
                else if ($status_raw == '2') $status = '<span class="badge bg-success"> Sold </span>';
                else if ($status_raw == '3') $status = '<span class="badge bg-success"> Complete </span>';
                else if ($status_raw == '4') $status = '<span class="badge bg-danger"> Canceled </span>';
                else $status = '<span class="badge bg-info">Available</span>';

                $facingDisplay = (strpos($value->facing,'-')!==false) ? ucwords($value->facing,'-') : ucfirst($value->facing);

                $outputData[] = [
                    'id'      => ucwords($value->id),
                    'block'   => ucwords($value->block),
                    'road'    => ucwords($value->road),
                    'plot'    => 'Plot ' . $value->plot,
                    'katha'   => $value->katha . ' katha',
                    'facing'  => $facingDisplay,
                    'status'  => $status,
                    'file_num'=> $client['file_id']
                ];
            }
        }

        $data = [
            "draw" => intval($_POST['draw']),
            "recordsTotal" => $db->totalPages * $_POST['length'],
            "recordsFiltered" => $db->totalPages * $_POST['length'],
            "data" => $outputData
        ];
    }

    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}