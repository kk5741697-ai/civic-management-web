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

    // ===============================
    //  🔍 SEARCH PURCHASES (for Select2)
    // ===============================
    if ($s == 'search_purchases') {
        $query = trim($_POST['q'] ?? $_GET['q'] ?? '');
        $page = max(1, (int)($_POST['page'] ?? $_GET['page'] ?? 1));
        $perPage = min(50, max(10, (int)($_POST['per_page'] ?? $_GET['per_page'] ?? 30)));
        $projectId = trim($_POST['project_id'] ?? $_GET['project_id'] ?? '');
        $availableOnly = !empty($_POST['available_only'] ?? $_GET['available_only'] ?? false);
        
        $offset = ($page - 1) * $perPage;
        
        if (!$projectId) {
            echo json_encode(['status' => 400, 'message' => 'Project ID required']);
            exit;
        }
        
        // Build search conditions
        $searchConditions = "b.project = ?";
        $params = [$projectId];
        
        if ($availableOnly) {
            $searchConditions .= " AND b.status IN ('0', '1')";
        }
        
        if ($query) {
            $searchConditions .= " AND (b.plot LIKE ? OR b.block LIKE ? OR b.katha LIKE ? OR b.road LIKE ?)";
            $likeQuery = '%' . $query . '%';
            $params = array_merge($params, [$likeQuery, $likeQuery, $likeQuery, $likeQuery]);
        }
        
        $sql = "
            SELECT b.id, b.project, b.block, b.plot, b.katha, b.road, b.facing, b.status,
                   CASE 
                       WHEN b.status = '0' OR b.status = '1' THEN 1
                       ELSE 0
                   END as available,
                   CASE 
                       WHEN b.status = '0' OR b.status = '1' THEN 'Available'
                       WHEN b.status = '2' THEN 'Sold'
                       WHEN b.status = '3' THEN 'Complete'
                       WHEN b.status = '4' THEN 'Cancelled'
                       ELSE 'Unknown'
                   END as status_label
            FROM " . T_BOOKING . " b
            WHERE {$searchConditions}
            ORDER BY b.block ASC, CAST(b.plot AS UNSIGNED) ASC
            LIMIT {$offset}, {$perPage}
        ";
        
        try {
            $results = $db->rawQuery($sql, $params);
            $data = [];
            
            foreach ($results as $row) {
                $data[] = [
                    'id' => $row->id,
                    'project' => $row->project,
                    'block' => $row->block,
                    'plot' => $row->plot,
                    'katha' => $row->katha,
                    'road' => $row->road,
                    'facing' => $row->facing,
                    'status' => $row->status,
                    'status_label' => $row->status_label,
                    'available' => (bool)$row->available
                ];
            }
            
            echo json_encode([
                'status' => 200,
                'data' => $data,
                'more' => count($data) >= $perPage
            ]);
            
        } catch (Exception $e) {
            echo json_encode(['status' => 500, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // ===============================
    //  📄 DOWNLOAD SCHEDULE PDF
    // ===============================
    if ($s == 'download_schedule_pdf') {
        $purchaseId = $_POST['purchase_id'] ?? '';
        $scheduleJson = $_POST['schedule'] ?? '';
        $clientDataJson = $_POST['client_data'] ?? '';
        $printDataJson = $_POST['print_data'] ?? '';
        
        if (!$purchaseId || !$scheduleJson) {
            echo json_encode(['status' => 400, 'message' => 'Missing required data']);
            exit;
        }
        
        try {
            $schedule = json_decode($scheduleJson, true);
            $clientData = json_decode($clientDataJson, true);
            $printData = json_decode($printDataJson, true);
            
            if (!$schedule || !is_array($schedule)) {
                echo json_encode(['status' => 400, 'message' => 'Invalid schedule data']);
                exit;
            }
            
            // Generate PDF content
            $filename = 'payment_schedule_' . $purchaseId . '_' . date('Y-m-d') . '.pdf';
            $downloadUrl = generateSchedulePDF($purchaseId, $schedule, $clientData, $printData, $filename);
            
            if ($downloadUrl) {
                echo json_encode([
                    'status' => 200,
                    'download_url' => $downloadUrl,
                    'filename' => $filename,
                    'message' => 'PDF generated successfully'
                ]);
            } else {
                echo json_encode(['status' => 500, 'message' => 'Failed to generate PDF']);
            }
            
        } catch (Exception $e) {
            echo json_encode(['status' => 500, 'message' => 'Error generating PDF: ' . $e->getMessage()]);
        }
        exit;
    }

    // ===============================
    //  💾 SAVE SCHEDULE XLSX
    // ===============================
    if ($s == 'save_schedule_xlsx') {
        $purchaseId = $_POST['purchase_id'] ?? '';
        $scheduleJson = $_POST['schedule'] ?? '';
        $clientDataJson = $_POST['client_data'] ?? '';
        $printDataJson = $_POST['print_data'] ?? '';
        $format = $_POST['format'] ?? 'xlsx';
        
        if (!$purchaseId || !$scheduleJson) {
            echo json_encode(['status' => 400, 'message' => 'Missing required data']);
            exit;
        }
        
        try {
            $schedule = json_decode($scheduleJson, true);
            $clientData = json_decode($clientDataJson, true);
            $printData = json_decode($printDataJson, true);
            
            if (!$schedule || !is_array($schedule)) {
                echo json_encode(['status' => 400, 'message' => 'Invalid schedule data']);
                exit;
            }
            
            // Generate Excel file
            $filename = 'payment_schedule_' . $purchaseId . '_' . date('Y-m-d') . '.' . $format;
            $result = generateScheduleExcel($purchaseId, $schedule, $clientData, $printData, $filename, $format);
            
            if ($result && isset($result['file_path'])) {
                echo json_encode([
                    'status' => 200,
                    'file_path' => $result['file_path'],
                    'download_url' => $result['download_url'],
                    'filename' => $filename,
                    'message' => 'Excel file generated successfully'
                ]);
            } else {
                echo json_encode(['status' => 500, 'message' => 'Failed to generate Excel file']);
            }
            
        } catch (Exception $e) {
            echo json_encode(['status' => 500, 'message' => 'Error generating Excel: ' . $e->getMessage()]);
        }
        exit;
    }

    // ===============================
    //  🔄 CHANGE PLOT
    // ===============================
    if ($s == 'change_plot') {
        $purchaseId = $_POST['purchase_id'] ?? '';
        $newPlotId = $_POST['new_plot_id'] ?? '';
        $reason = trim($_POST['reason'] ?? '');
        
        if (!$purchaseId || !$newPlotId || !$reason) {
            echo json_encode(['status' => 400, 'message' => 'Missing required fields']);
            exit;
        }
        
        if (strlen($reason) < 10) {
            echo json_encode(['status' => 400, 'message' => 'Reason must be at least 10 characters long']);
            exit;
        }
        
        try {
            $db->startTransaction();
            
            // Get current purchase details
            $currentPurchase = $db->where('id', $purchaseId)->getOne(T_BOOKING_HELPER);
            if (!$currentPurchase) {
                $db->rollback();
                echo json_encode(['status' => 404, 'message' => 'Purchase not found']);
                exit;
            }
            
            // Get new plot details
            $newPlot = $db->where('id', $newPlotId)->getOne(T_BOOKING);
            if (!$newPlot) {
                $db->rollback();
                echo json_encode(['status' => 404, 'message' => 'New plot not found']);
                exit;
            }
            
            // Check if new plot is available
            if ($newPlot->status != '0' && $newPlot->status != '1') {
                $db->rollback();
                echo json_encode(['status' => 400, 'message' => 'Selected plot is not available']);
                exit;
            }
            
            // Update booking_helper to point to new booking
            $db->where('id', $purchaseId)->update(T_BOOKING_HELPER, [
                'booking_id' => $newPlotId,
                'time' => time()
            ]);
            
            // Update old plot status to available
            if ($currentPurchase->booking_id) {
                $db->where('id', $currentPurchase->booking_id)->update(T_BOOKING, [
                    'status' => '1',
                    'file_num' => null
                ]);
            }
            
            // Update new plot status to sold
            $db->where('id', $newPlotId)->update(T_BOOKING, [
                'status' => '2',
                'file_num' => $currentPurchase->file_num
            ]);
            
            // Log the change
            $clientData = GetCustomerById($currentPurchase->client_id);
            $clientName = $clientData['name'] ?? 'Unknown';
            
            logActivity('inventory', 'change_plot', 
                "Plot changed for {$clientName}: from booking_id {$currentPurchase->booking_id} to {$newPlotId}. Reason: {$reason}");
            
            $db->commit();
            
            echo json_encode([
                'status' => 200,
                'message' => 'Plot changed successfully'
            ]);
            
        } catch (Exception $e) {
            $db->rollback();
            echo json_encode(['status' => 500, 'message' => 'Error changing plot: ' . $e->getMessage()]);
        }
        exit;
    }

    // ===============================
    //  📋 GET PURCHASE DETAILS
    // ===============================
    if ($s == 'get_purchase_details') {
        $purchaseId = $_POST['purchase_id'] ?? $_GET['id'] ?? '';
        
        if (!$purchaseId) {
            echo json_encode(['status' => 400, 'message' => 'Purchase ID required']);
            exit;
        }
        
        try {
            // Get purchase details with booking info
            $sql = "
                SELECT bh.*, b.project, b.block, b.plot, b.katha, b.road, b.facing,
                       c.name as client_name, c.phone as client_phone, c.address as client_address
                FROM " . T_BOOKING_HELPER . " bh
                LEFT JOIN " . T_BOOKING . " b ON b.id = bh.booking_id
                LEFT JOIN " . T_CUSTOMERS . " c ON c.id = bh.client_id
                WHERE bh.id = ?
            ";
            
            $purchase = $db->rawQueryOne($sql, [$purchaseId]);
            
            if (!$purchase) {
                echo json_encode(['status' => 404, 'message' => 'Purchase not found']);
                exit;
            }
            
            // Get existing schedule if any
            $schedule = [];
            // Add logic to fetch existing payment schedule from database
            
            echo json_encode([
                'status' => 200,
                'purchase' => $purchase,
                'schedule' => $schedule,
                'project_name' => ucwords(str_replace('-', ' ', $purchase->project ?? '')),
                'total_price' => (float)($purchase->per_katha ?? 0) * (float)($purchase->katha ?? 0),
                'down_payment' => (float)($purchase->down_payment ?? 0),
                'booking_money' => (float)($purchase->booking_money ?? 0),
                'default_installments' => 12,
                'default_start_date' => date('Y-m-d')
            ]);
            
        } catch (Exception $e) {
            echo json_encode(['status' => 500, 'message' => 'Error fetching purchase details: ' . $e->getMessage()]);
        }
        exit;
    }

    // ===============================
    //  💰 UPDATE INSTALLMENT SCHEDULE
    // ===============================
    if ($s == 'update_installment') {
        $purchaseId = $_POST['purchase_id'] ?? '';
        $scheduleJson = $_POST['schedule'] ?? '';
        
        if (!$purchaseId || !$scheduleJson) {
            echo json_encode(['status' => 400, 'message' => 'Missing required data']);
            exit;
        }
        
        try {
            $schedule = json_decode($scheduleJson, true);
            
            if (!$schedule || !is_array($schedule)) {
                echo json_encode(['status' => 400, 'message' => 'Invalid schedule data']);
                exit;
            }
            
            // Save schedule to database
            $scheduleData = [
                'purchase_id' => $purchaseId,
                'schedule_data' => $scheduleJson,
                'updated_at' => time(),
                'updated_by' => $wo['user']['user_id']
            ];
            
            // Check if schedule exists
            $existingSchedule = $db->where('purchase_id', $purchaseId)->getOne('payment_schedules');
            
            if ($existingSchedule) {
                $db->where('purchase_id', $purchaseId)->update('payment_schedules', $scheduleData);
            } else {
                $scheduleData['created_at'] = time();
                $scheduleData['created_by'] = $wo['user']['user_id'];
                $db->insert('payment_schedules', $scheduleData);
            }
            
            // Log the activity
            logActivity('inventory', 'update_schedule', "Updated payment schedule for purchase ID: {$purchaseId}");
            
            echo json_encode([
                'status' => 200,
                'message' => 'Payment schedule saved successfully'
            ]);
            
        } catch (Exception $e) {
            echo json_encode(['status' => 500, 'message' => 'Error saving schedule: ' . $e->getMessage()]);
        }
        exit;
    }

    // ===============================
    //  ❌ CANCEL PURCHASE
    // ===============================
    if ($s == 'cancel_purchase') {
        $bookingHelperId = $_POST['booking_helper_id'] ?? '';
        $cancelDate = $_POST['cancel_date'] ?? date('Y-m-d');
        
        if (!$bookingHelperId) {
            echo json_encode(['status' => 400, 'message' => 'Booking helper ID required']);
            exit;
        }
        
        try {
            $db->startTransaction();
            
            // Get booking helper details
            $bookingHelper = $db->where('id', $bookingHelperId)->getOne(T_BOOKING_HELPER);
            if (!$bookingHelper) {
                $db->rollback();
                echo json_encode(['status' => 404, 'message' => 'Purchase not found']);
                exit;
            }
            
            // Update booking helper status to cancelled
            $db->where('id', $bookingHelperId)->update(T_BOOKING_HELPER, [
                'status' => '4',
                'cancel_date' => strtotime($cancelDate)
            ]);
            
            // Update booking status to available
            if ($bookingHelper->booking_id) {
                $db->where('id', $bookingHelper->booking_id)->update(T_BOOKING, [
                    'status' => '1',
                    'file_num' => null
                ]);
            }
            
            // Log the cancellation
            $clientData = GetCustomerById($bookingHelper->client_id);
            $clientName = $clientData['name'] ?? 'Unknown';
            
            logActivity('inventory', 'cancel_purchase', 
                "Purchase cancelled for {$clientName} on {$cancelDate}. Booking Helper ID: {$bookingHelperId}");
            
            $db->commit();
            
            echo json_encode([
                'status' => 200,
                'message' => 'Purchase cancelled successfully'
            ]);
            
        } catch (Exception $e) {
            $db->rollback();
            echo json_encode(['status' => 500, 'message' => 'Error cancelling purchase: ' . $e->getMessage()]);
        }
        exit;
    }

    // ===============================
    //  📊 REGISTER PURCHASE
    // ===============================
    if ($s == 'register_purchase') {
        $clientId = $_POST['client_id'] ?? '';
        $projectId = $_POST['project_id'] ?? '';
        $purchaseId = $_POST['purchase_id'] ?? '';
        $fileNum = trim($_POST['file_num'] ?? '');
        $perKatha = (float)($_POST['per_katha'] ?? 0);
        $downPayment = (float)($_POST['down_payment'] ?? 0);
        $bookingMoney = (float)($_POST['booking_money'] ?? 0);
        $purchaseDate = $_POST['purchase_date'] ?? date('Y-m-d');
        $nomineeIds = $_POST['nominee_ids'] ?? [];
        $force = !empty($_POST['force']);
        
        // Validation
        if (!$clientId || !$projectId || !$purchaseId || !$fileNum || $perKatha <= 0) {
            echo json_encode(['status' => 400, 'message' => 'Missing or invalid required fields']);
            exit;
        }
        
        try {
            $db->startTransaction();
            
            // Check if plot is available (unless forcing)
            $plot = $db->where('id', $purchaseId)->getOne(T_BOOKING);
            if (!$plot) {
                $db->rollback();
                echo json_encode(['status' => 404, 'message' => 'Plot not found']);
                exit;
            }
            
            if (!$force && $plot->status != '0' && $plot->status != '1') {
                $db->rollback();
                echo json_encode([
                    'status' => 409,
                    'message' => 'Plot is not available. Current status: ' . getStatusLabel($plot->status)
                ]);
                exit;
            }
            
            // Create booking helper entry
            $bookingHelperData = [
                'booking_id' => $purchaseId,
                'client_id' => $clientId,
                'file_num' => $fileNum,
                'status' => '2', // Sold
                'time' => strtotime($purchaseDate),
                'per_katha' => $perKatha,
                'down_payment' => $downPayment,
                'booking_money' => $bookingMoney
            ];
            
            if (!empty($nomineeIds) && is_array($nomineeIds)) {
                $bookingHelperData['nominee_ids'] = json_encode(array_map('intval', $nomineeIds));
            }
            
            $bookingHelperId = $db->insert(T_BOOKING_HELPER, $bookingHelperData);
            
            if (!$bookingHelperId) {
                $db->rollback();
                echo json_encode(['status' => 500, 'message' => 'Failed to create purchase record']);
                exit;
            }
            
            // Update plot status
            $db->where('id', $purchaseId)->update(T_BOOKING, [
                'status' => '2',
                'file_num' => $fileNum
            ]);
            
            // Log the activity
            $clientData = GetCustomerById($clientId);
            $clientName = $clientData['name'] ?? 'Unknown';
            
            logActivity('inventory', 'register_purchase', 
                "New purchase registered for {$clientName}. Plot ID: {$purchaseId}, File: {$fileNum}");
            
            $db->commit();
            
            echo json_encode([
                'status' => 200,
                'message' => 'Purchase registered successfully',
                'booking_helper_id' => $bookingHelperId
            ]);
            
        } catch (Exception $e) {
            $db->rollback();
            echo json_encode(['status' => 500, 'message' => 'Error registering purchase: ' . $e->getMessage()]);
        }
        exit;
    }
}

// ===============================
//  🛠️ HELPER FUNCTIONS
// ===============================

function getStatusLabel($status) {
    switch ((string)$status) {
        case '0':
        case '1':
            return 'Available';
        case '2':
            return 'Sold';
        case '3':
            return 'Complete';
        case '4':
            return 'Cancelled';
        default:
            return 'Unknown';
    }
}

function generateSchedulePDF($purchaseId, $schedule, $clientData, $printData, $filename) {
    // This is a placeholder implementation
    // In a real implementation, you would use a PDF library like TCPDF or FPDF
    
    try {
        // Create downloads directory if it doesn't exist
        $downloadsDir = 'downloads/schedules/';
        if (!is_dir($downloadsDir)) {
            mkdir($downloadsDir, 0755, true);
        }
        
        // Generate PDF content (simplified HTML to PDF conversion)
        $html = generateScheduleHTML($schedule, $clientData, $printData);
        
        // For now, save as HTML file (in production, convert to PDF)
        $filePath = $downloadsDir . str_replace('.pdf', '.html', $filename);
        file_put_contents($filePath, $html);
        
        // Return download URL
        global $wo;
        return $wo['config']['site_url'] . '/' . $filePath;
        
    } catch (Exception $e) {
        error_log("PDF generation error: " . $e->getMessage());
        return false;
    }
}

function generateScheduleExcel($purchaseId, $schedule, $clientData, $printData, $filename, $format) {
    // This is a placeholder implementation
    // In a real implementation, you would use PhpSpreadsheet or similar library
    
    try {
        // Create downloads directory if it doesn't exist
        $downloadsDir = 'downloads/schedules/';
        if (!is_dir($downloadsDir)) {
            mkdir($downloadsDir, 0755, true);
        }
        
        // Generate CSV content (simplified Excel alternative)
        $csv = generateScheduleCSV($schedule, $clientData, $printData);
        
        $filePath = $downloadsDir . str_replace('.xlsx', '.csv', $filename);
        file_put_contents($filePath, $csv);
        
        // Return file info
        global $wo;
        return [
            'file_path' => $filePath,
            'download_url' => $wo['config']['site_url'] . '/' . $filePath
        ];
        
    } catch (Exception $e) {
        error_log("Excel generation error: " . $e->getMessage());
        return false;
    }
}

function generateScheduleHTML($schedule, $clientData, $printData) {
    $html = '<!DOCTYPE html><html><head><title>Payment Schedule</title>';
    $html .= '<style>body{font-family:Arial,sans-serif;margin:20px;} table{width:100%;border-collapse:collapse;} th,td{border:1px solid #ddd;padding:8px;text-align:left;} th{background-color:#f2f2f2;}</style>';
    $html .= '</head><body>';
    
    $html .= '<h1>Payment Schedule</h1>';
    
    if ($printData) {
        $html .= '<h2>Client: ' . htmlspecialchars($printData['client_name'] ?? '') . '</h2>';
        $html .= '<p>Project: ' . htmlspecialchars($printData['project_name'] ?? '') . '</p>';
        $html .= '<p>Plot: ' . htmlspecialchars($printData['plot_info'] ?? '') . '</p>';
        $html .= '<p>File Number: ' . htmlspecialchars($printData['file_number'] ?? '') . '</p>';
    }
    
    $html .= '<table><thead><tr><th>#</th><th>Due Date</th><th>Amount</th><th>Status</th></tr></thead><tbody>';
    
    foreach ($schedule as $index => $item) {
        $html .= '<tr>';
        $html .= '<td>' . ($index + 1) . '</td>';
        $html .= '<td>' . htmlspecialchars($item['date'] ?? '') . '</td>';
        $html .= '<td>৳' . number_format($item['amount'] ?? 0, 2) . '</td>';
        $html .= '<td>' . ($item['paid'] ? 'Paid' : 'Unpaid') . '</td>';
        $html .= '</tr>';
    }
    
    $html .= '</tbody></table>';
    $html .= '<p>Generated on: ' . date('Y-m-d H:i:s') . '</p>';
    $html .= '</body></html>';
    
    return $html;
}

function generateScheduleCSV($schedule, $clientData, $printData) {
    $csv = "Payment Schedule\n\n";
    
    if ($printData) {
        $csv .= "Client," . ($printData['client_name'] ?? '') . "\n";
        $csv .= "Project," . ($printData['project_name'] ?? '') . "\n";
        $csv .= "Plot," . ($printData['plot_info'] ?? '') . "\n";
        $csv .= "File Number," . ($printData['file_number'] ?? '') . "\n\n";
    }
    
    $csv .= "#,Due Date,Amount,Status\n";
    
    foreach ($schedule as $index => $item) {
        $csv .= ($index + 1) . ",";
        $csv .= ($item['date'] ?? '') . ",";
        $csv .= "৳" . number_format($item['amount'] ?? 0, 2) . ",";
        $csv .= ($item['paid'] ? 'Paid' : 'Unpaid') . "\n";
    }
    
    $csv .= "\nGenerated on," . date('Y-m-d H:i:s') . "\n";
    
    return $csv;
}

header("Content-type: application/json");
echo json_encode($data ?? ['status' => 404, 'message' => 'Invalid request']);
exit();
?>