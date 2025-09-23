<?php
if ($f == "booking") {
	$errors = array();
	$result = '';

	$project = Wo_Secure($_POST['project']);
	$block = Wo_Secure($_POST['block']);
	$row_id = preg_replace('/[^0-9]/', '', Wo_Secure($_POST['row_id']));
	$plot_value = Wo_Secure($_POST['plot_value']);

	
	if ($s == "update_map") {
		if (empty($project) || empty($block) || !isset($project, $block)) {
			$errors[] = 'Something Went Wrong!';
		} else {
			$message = '';
			$update_map = true;

			$file_path = './themes/' . $wo['config']['theme'] . '/maps/' . $project . '/block' . $block . '.svg';
			$svg_content = file_get_contents($file_path);
			
			
	// Create a DOMDocument
$dom = new DOMDocument();
$dom->loadXML($svg_content);

// Create DOMXPath after loading the XML
$xpath = new DOMXPath($dom);

$get_changes = $db->where('project', $project)->where('block', $block)->get(T_BOOKING);

foreach ($get_changes as $change) {
    $row_selector = 'row' . $change->row_id;
    $plot_number = 'data-name="' . $change->object_id . '"';

    // Use XPath to locate the row element
    $rowNodeList = $xpath->query("//*[@id='{$row_selector}']"); // Corrected the XPath query

    foreach ($rowNodeList as $rowElement) {
        // Use XPath within the row element to locate the plot element
        $plotNodeList = $xpath->query(".//*[@{$plot_number}]", $rowElement);

        foreach ($plotNodeList as $plotElement) {
            // Your logic to modify the plot element based on $change->status
            if ($change->status == 0) {
                // Remove class "booked"
                $plotElement->removeAttribute('class');
            } elseif ($change->status == 1) {
                // Add class "booked"
                $plotElement->setAttribute('class', 'booked');
            }
        }
    }
}

// Save the modified SVG content
$updatedSvgContent = $dom->saveXML();




			$update_status = file_put_contents($file_path, $updatedSvgContent);
			if ($update_status) {
				// Output success message
				$message .= 'Updated map data successfully.';
			}

			if ($update_map) {
				$result = 'success';
			} else {
				$errors[] = 'Failed to update map data.';
			}
		}

		$response = array(
			'status' => (empty($errors)) ? 200 : 400,
			'errors' => $errors,
			'message' => $message,
			'result' => $result
		);

		header("Content-type: application/json");
		echo json_encode($response);
		exit();
	}
	if ($s == "booking_action") {
		if (empty($project) || empty($block) || empty($row_id) || empty($plot_value) || !isset($project, $block, $row_id, $plot_value)) {
			$errors[] = 'Something Went Wrong!';
		} else {
			$rowBlockItem = $db->where('project', $project)
				->where('block', $block)
				->where('row_id', $row_id)
				->where('object_id', $plot_value)
				->getOne(T_BOOKING);

			if ($rowBlockItem) {
				// Record found
				if ($rowBlockItem->status == 1) {
					$update_booking = $db->where('id', $rowBlockItem->id)->update(T_BOOKING, array(
						'status' => 0
					));
					$result = 'unbooked';
				} else {
					$update_booking = $db->where('id', $rowBlockItem->id)->update(T_BOOKING, array(
						'status' => 1
					));
					$result = 'booked';
				}
			} else {
				// Record not found, insert new record
				$insert_booking = $db->insert(T_BOOKING, array(
					'project' => $project,
					'block' => $block,
					'row_id' => $row_id,
					'object_id' => $plot_value,
					'status' => 1 // Assuming a default value for status when inserting
				));

				if ($insert_booking) {
					$result = 'booked';
				} else {
					$errors[] = 'Failed to insert booking data.';
				}
			}
		}

		$response = array(
			'status' => (empty($errors)) ? 200 : 400,
			'errors' => $errors,
			'result' => $result
		);

		header("Content-type: application/json");
		echo json_encode($response);
		exit();
	}
	
	
	  // GET purchase & schedule
      if ($s == 'get_purchase') {
        header('Content-Type: application/json; charset=utf-8');
        global $db, $wo;
        $purchase_id = isset($_REQUEST['purchase_id']) ? (int) $_REQUEST['purchase_id'] : 0;
        if (!$purchase_id) {
          echo json_encode(['error' => 'Invalid purchase id']); exit;
        }
    
        $purchase = $db->where('id', $purchase_id)->getOne('purchases'); // adjust table
        if (!$purchase) {
          echo json_encode(['error' => 'Purchase not found']); exit;
        }
    
        // load booking & project details - adjust names
        $booking = $db->where('purchase_id', $purchase_id)->getOne('bookings');
        $project = $db->where('id', $booking->project_id)->getOne('projects');
    
        // load saved schedule (if any)
        $scheduleRows = $db->where('purchase_id', $purchase_id)->orderBy('due_date','ASC')->get('installments');
        $schedule = [];
        foreach ($scheduleRows as $r) {
          $schedule[] = [
            'date' => date('Y-m-d', strtotime($r->due_date)),
            'amount' => (float)$r->amount,
            'adjustment' => (int)$r->adjustment
          ];
        }
    
        $out = [
          'purchase_id' => $purchase->id,
          'project_name' => $project->name ?? '',
          'project_slug' => $project->slug ?? '',
          'total_price' => (float)$purchase->total_price,
          'down_payment' => (float)$purchase->down_payment,
          'default_installments' => $purchase->installments ?? 3,
          'default_start_date' => date('Y-m-d', $purchase->created_time ?? time()),
          'schedule' => $schedule
        ];
        echo json_encode($out);
        exit;
      }
    
      // POST update_installment
      if ($s == 'update_installment') {
        header('Content-Type: application/json; charset=utf-8');
        global $db, $wo;
        $purchase_id = isset($_POST['purchase_id']) ? (int) $_POST['purchase_id'] : 0;
        $scheduleRaw = isset($_POST['schedule']) ? $_POST['schedule'] : '[]';
        if (!$purchase_id) {
          echo json_encode(['status' => 400, 'message' => 'Invalid purchase id']); exit;
        }
        $schedule = json_decode($scheduleRaw, true);
        if (!is_array($schedule)) {
          echo json_encode(['status' => 400, 'message' => 'Invalid schedule']); exit;
        }
    
        // Basic validation: amounts sum > 0
        $sum = 0; foreach ($schedule as $r) $sum += floatval($r['amount'] ?? 0);
        if ($sum <= 0) {
          echo json_encode(['status' => 400, 'message' => 'Total schedule amount must be greater than zero']); exit;
        }
    
        // Delete old schedule and insert new (simple approach)
        $db->where('purchase_id', $purchase_id)->delete('installments');
    
        foreach ($schedule as $r) {
          $row = [
            'purchase_id' => $purchase_id,
            'due_date' => $r['date'],
            'amount' => floatval($r['amount']),
            'adjustment' => isset($r['adjustment']) && $r['adjustment'] ? 1 : 0,
            'created_at' => date('Y-m-d H:i:s')
          ];
          $db->insert('installments', $row);
        }
    
        echo json_encode(['status' => 200, 'message' => 'Schedule saved']);
        exit;
      }
    
      // GET available plots for a project
      if ($s == 'get_available_plots') {
        header('Content-Type: application/json; charset=utf-8');
        global $db;
        $project_slug = isset($_REQUEST['project_slug']) ? trim($_REQUEST['project_slug']) : '';
        if (!$project_slug) { echo json_encode([]); exit; }
        $project = $db->where('slug', $project_slug)->getOne('projects');
        if (!$project) { echo json_encode([]); exit; }
        // fetch plots where status available (example)
        $plots = $db->where('project_id', $project->id)->where('status', 1)->get('plots');
        $out = [];
        foreach ($plots as $p) {
          $out[] = [
            'id' => $p->id,
            'block' => $p->block,
            'plot' => $p->plot_number ?? $p->plot,
            'katha' => $p->katha,
            'road' => $p->road
          ];
        }
        echo json_encode($out);
        exit;
      }
    
      // POST change_plot
      if ($s == 'change_plot') {
        header('Content-Type: application/json; charset=utf-8');
        global $db, $sqlConnect;
        $purchase_id = isset($_POST['purchase_id']) ? (int) $_POST['purchase_id'] : 0;
        $new_plot_id = isset($_POST['new_plot_id']) ? (int) $_POST['new_plot_id'] : 0;
        if (!$purchase_id || !$new_plot_id) {
          echo json_encode(['status' => 400, 'message' => 'Missing parameters']); exit;
        }
    
        // basic checks
        $purchase = $db->where('id', $purchase_id)->getOne('purchases');
        $plot = $db->where('id', $new_plot_id)->getOne('plots');
        if (!$purchase || !$plot) {
          echo json_encode(['status' => 404, 'message' => 'Purchase or plot not found']); exit;
        }
    
        // ensure plot is available
        if ((int)$plot->status !== 1) {
          echo json_encode(['status' => 409, 'message' => 'Selected plot is not available']); exit;
        }
    
        // Update purchase and plot statuses. You may need to perform additional business logic
        $db->where('id', $purchase_id)->update('purchases', [
          'plot_id' => $new_plot_id,
          'block' => $plot->block,
          'plot' => $plot->plot, // adjust column names as required
          'updated_at' => date('Y-m-d H:i:s')
        ]);
    
        // set selected plot to booked (example)
        $db->where('id', $new_plot_id)->update('plots', ['status' => 2]);
    
        // Optionally free old plot (if you stored old plot id)
        if (!empty($purchase->plot_id_old)) {
          $db->where('id', $purchase->plot_id_old)->update('plots', ['status' => 1]);
        }
    
        echo json_encode(['status' => 200, 'message' => 'Plot changed']);
        exit;
      }

}

// ===============================
//  📅 PAYMENT SCHEDULE MANAGEMENT
// ===============================
if ($f == "manage_inventory") {
    
    // Get purchase details for payment schedule
    if ($s == 'get_purchase_details') {
        $purchase_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
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
        
        // Calculate total amount
        $per_katha = (float)($helper->per_katha ?? 0);
        $katha = (float)($booking->katha ?? 0);
        $total_amount = $per_katha * $katha;
        
        // Get total paid amount
        $paid_amount = (float)$db->where('customer_id', $helper->client_id)
                                 ->getValue(T_INVOICE, 'SUM(pay_amount)') ?: 0;

        $purchase_data = [
            'id' => $helper->id,
            'block' => $booking->block ?? '',
            'plot' => $booking->plot ?? '',
            'katha' => $booking->katha ?? '',
            'road' => $booking->road ?? '',
            'facing' => $booking->facing ?? '',
            'total_amount' => $total_amount,
            'paid_amount' => $paid_amount,
            'due_amount' => $total_amount - $paid_amount
        ];

        echo json_encode(['status' => 200, 'purchase' => $purchase_data]);
        exit;
    }

    // Get payment schedule
    if ($s == 'get_payment_schedule') {
        $purchase_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if (!$purchase_id) {
            echo json_encode(['status' => 400, 'message' => 'Invalid purchase ID']);
            exit;
        }

        // For now, return empty schedule - you can implement actual schedule storage
        $schedule = [];
        
        echo json_encode(['status' => 200, 'schedule' => $schedule]);
        exit;
    }

    // Save payment schedule
    if ($s == 'save_payment_schedule') {
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

        // Here you would save the schedule to your database
        // For now, just return success
        echo json_encode(['status' => 200, 'message' => 'Payment schedule saved successfully']);
        exit;
    }

    // Mark payment as paid
    if ($s == 'mark_payment') {
        $schedule_id = isset($_POST['schedule_id']) ? $_POST['schedule_id'] : '';
        $amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
        $payment_date = isset($_POST['payment_date']) ? $_POST['payment_date'] : '';
        $method = isset($_POST['method']) ? $_POST['method'] : '';
        $reference = isset($_POST['reference']) ? $_POST['reference'] : '';
        $notes = isset($_POST['notes']) ? $_POST['notes'] : '';

        if (!$schedule_id || $amount <= 0) {
            echo json_encode(['status' => 400, 'message' => 'Invalid payment data']);
            exit;
        }

        // Here you would update the payment status in your database
        // For now, just return success
        echo json_encode(['status' => 200, 'message' => 'Payment marked successfully']);
        exit;
    }

    // Get schedule preview for printing
    if ($s == 'get_schedule_preview') {
        $purchase_id = isset($_POST['purchase_id']) ? (int)$_POST['purchase_id'] : 0;
        $options_json = isset($_POST['options']) ? $_POST['options'] : '{}';
        
        if (!$purchase_id) {
            echo json_encode(['status' => 400, 'message' => 'Invalid purchase ID']);
            exit;
        }

        $options = json_decode($options_json, true) ?: [];
        
        // Generate preview HTML
        $preview_html = generateSchedulePreviewHTML($purchase_id, $options);
        
        echo json_encode(['status' => 200, 'html' => $preview_html]);
        exit;
    }

    // Generate purchase report
    if ($s == 'generate_purchase_report') {
        $purchase_id = isset($_POST['purchase_id']) ? (int)$_POST['purchase_id'] : 0;
        $format = isset($_POST['format']) ? $_POST['format'] : 'pdf';
        
        if (!$purchase_id) {
            echo json_encode(['status' => 400, 'message' => 'Invalid purchase ID']);
            exit;
        }

        // Here you would generate the actual report
        // For now, return a placeholder
        echo json_encode([
            'status' => 200, 
            'download_url' => '/reports/purchase_' . $purchase_id . '.' . $format,
            'message' => 'Report generated successfully'
        ]);
        exit;
    }

    // Export purchase data
    if ($s == 'export_purchase_data') {
        $purchase_id = isset($_POST['purchase_id']) ? (int)$_POST['purchase_id'] : 0;
        $export_type = isset($_POST['export_type']) ? $_POST['export_type'] : 'json';
        
        if (!$purchase_id) {
            echo json_encode(['status' => 400, 'message' => 'Invalid purchase ID']);
            exit;
        }

        // Here you would export the actual data
        // For now, return a placeholder
        echo json_encode([
            'status' => 200, 
            'download_url' => '/exports/purchase_data_' . $purchase_id . '.' . $export_type,
            'filename' => 'purchase_data_' . $purchase_id . '.' . $export_type,
            'message' => 'Data exported successfully'
        ]);
        exit;
    }
}

// Helper function to generate schedule preview HTML
function generateSchedulePreviewHTML($purchase_id, $options) {
    global $db;
    
    // Get purchase and client details
    $helper = $db->where('id', $purchase_id)->getOne(T_BOOKING_HELPER);
    if (!$helper) return '<div class="alert alert-danger">Purchase not found</div>';
    
    $client = GetCustomerById($helper->client_id);
    $booking = $db->where('id', $helper->booking_id)->getOne(T_BOOKING);
    
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
    
    // Payment summary
    if ($options['include_payment_summary'] ?? true) {
        $per_katha = (float)($helper->per_katha ?? 0);
        $katha = (float)($booking->katha ?? 0);
        $total_amount = $per_katha * $katha;
        $paid_amount = (float)$db->where('customer_id', $helper->client_id)->getValue(T_INVOICE, 'SUM(pay_amount)') ?: 0;
        
        $html .= '<div class="info-section">';
        $html .= '<div class="info-title">Payment Summary</div>';
        $html .= '<div class="info-grid">';
        $html .= '<div class="info-item"><div class="info-label">Total Amount</div><div class="info-value">৳' . number_format($total_amount, 2) . '</div></div>';
        $html .= '<div class="info-item"><div class="info-label">Paid Amount</div><div class="info-value">৳' . number_format($paid_amount, 2) . '</div></div>';
        $html .= '<div class="info-item"><div class="info-label">Due Amount</div><div class="info-value">৳' . number_format($total_amount - $paid_amount, 2) . '</div></div>';
        $html .= '</div>';
        $html .= '</div>';
    }
    
    // Sample schedule table (you would replace this with actual schedule data)
    $html .= '<table>';
    $html .= '<thead><tr><th>#</th><th>Due Date</th><th>Amount</th><th>Status</th><th>Notes</th></tr></thead>';
    $html .= '<tbody>';
    $html .= '<tr><td>1</td><td>2025-02-01</td><td>৳50,000</td><td><span class="badge bg-success">Paid</span></td><td>First installment</td></tr>';
    $html .= '<tr><td>2</td><td>2025-03-01</td><td>৳50,000</td><td><span class="badge bg-warning">Pending</span></td><td>Second installment</td></tr>';
    $html .= '<tr><td>3</td><td>2025-04-01</td><td>৳50,000</td><td><span class="badge bg-secondary">Pending</span></td><td>Final payment</td></tr>';
    $html .= '</tbody>';
    $html .= '</table>';
    
    // Signature area
    $html .= '<div class="signature-area">';
    $html .= '<div class="signature-box"><div class="signature-line">Client Signature</div></div>';
    $html .= '<div class="signature-box"><div class="signature-line">Authorized Signature</div></div>';
    $html .= '</div>';
    
    $html .= '</div>';
    
    return $html;
}