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