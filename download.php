<?php
require_once('assets/init.php');
require_once 'assets/libraries/word/vendor/autoload.php';
require_once 'assets/libraries/phpSpreadsheet/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\TemplateProcessor;

if (isset($_GET['download_lead']) && ($_GET['download_lead'] == 'c3661f719c494s85' || $_GET['download_lead'] == '9pRzMYUxxNKj')) {
    
    
    
    include('leads.php');
    
    
    
    exit();
}
if (isset($_GET['token']) && Wo_CheckMainSession($_GET['token']) === true) {
	//Default
    $type = isset($_GET['dl_type']) ? Wo_Secure($_GET['dl_type']) : '';
	
    // Check the file type based on the extension
    if ($type == 'leave') {
		$id = isset($_GET['id']) ? Wo_Secure($_GET['id']) : '';
		$documentData = $db->where('id', $id)->getOne(T_LEAVES);
		$user_data = Wo_UserData($documentData->user_id);
		$count_end = date('d-m-Y', strtotime('-1 day', $documentData->leave_from));
		$c1 = $c2 = $c3 = $c4 = '';
		$c_used = $c_balance = $c_duration = $cc_balance = $s_used = $s_balance = $s_duration = $sc_balance = $e_used = $e_balance = $e_duration = $ec_balance = '0';
		
        // set image
        $signaturePath = $user_data['signature']; // change path logic as needed


        $raw_balance = remaining_leave($user_data['user_id'], 'casual', $count_end, true, true);
        $c_balance = $balance = ($raw_balance === null || $raw_balance === 0) ? '0' : (string) $raw_balance;
		if (!$c_used) {$c_used = '0';}
		$c_duration = ($documentData->type == 'Casual' || $documentData->type == 'casual') ? $documentData->days : '0';
		$raw_used = calculateTotalLeaves($documentData->user_id) - $c_balance;
        $c_used = $used = ($raw_used === 0) ? '0' : $raw_used;

		$cc_balance = ($documentData->type == 'Casual' || $documentData->type == 'casual') ? $balance - $documentData->days : $balance;
		
		
        $s_used = remaining_leave($user_data['user_id'], 'sick', $count_end, true, true);
        $s_used = ($s_used === null || $s_used === '') ? '0' : (string)$s_used;
		$s_balance = '0';
        $s_duration = ($documentData->type === 'Sick' && !empty($documentData->days)) ? (string)$documentData->days : '0';
		$sc_balance = '0';
		
		if ($documentData->type == 'Casual') {
			$c1 = '✔';
			
		} else if ($documentData->type == 'Sick') {
			$c2 = '✔';
		} else if ($documentData->type == 'Earned') {
			$c3 = '✔';
			$e_used = '0';
			$e_balance = '0';
			$e_duration = '0';
			$ec_balance = '0';
		} else if ($documentData->type == 'Maternity') {
			$c4 = '✔';
		}

		$setData = array(
			'id' => $documentData->id,
			'name' => $user_data['name'],
			'designation' => $user_data['designation'],
			'department' => $user_data['department'],
			'join_d' => $user_data['joining_date'],
			'app_d' => date('d-m-Y', $documentData->posted),
			'from_d' => date('d-m-Y', $documentData->leave_from),
			'to_d' => date('d-m-Y', $documentData->leave_to),
			'resume_on' => getAdjustedResumeDate($documentData->leave_to),
			'duration' => $documentData->days,
			'reason' => $documentData->reason,
			'c1' => $c1,
			'c2' => $c2,
			'c3' => $c3,
			'c4' => $c4,
			'while_leave' => '',
			'total_L' => (string) max(0, (int) calculateTotalLeaves($documentData->user_id)),
			'balance' => $balance,
			//casual leave
			'c_used' => $c_used,
			'c_balance' => $c_balance,
			'c_duration' => $c_duration,
			'cc_balance' => $cc_balance,
			//sick leave
			's_used' => $s_used,
			's_balance' => $s_balance,
			's_duration' => $s_duration,
			'sc_balance' => $sc_balance,
			//earned leave
			'e_used' => $e_used,
			'e_balance' => $e_balance,
			'e_duration' => $e_duration,
			'ec_balance' => $ec_balance,
		);
		
        $file_path = './themes/' . $wo['config']['theme'] . '/file_template/leave_form.docx';
        $filename = $user_data['name'] . '-Leave Form.docx';
    } else if ($type == 'leave_report') {
		$user_id = isset($_GET['user_id']) ? Wo_Secure($_GET['user_id']) : '';
		$date_start = isset($_GET['date_start']) ? Wo_Secure($_GET['date_start']) : '';
		$date_end = isset($_GET['date_end']) ? Wo_Secure($_GET['date_end']) : '';
		
		$setData = array();
		$rowsToInsert = [];
		$ordinalcount = 1;
		$count = 5;

		$start_timestamp = strtotime($date_start);
		$end_timestamp = strtotime($date_end);
		
		$leaves = $db->where('is_approved', 1)->where('leave_from', $start_timestamp, '>=')->where('leave_to', $end_timestamp, '<=')->get(T_LEAVES);
		
		// $get_users = $db->orderBy('department', 'ASC')->where('active', '1')->get(T_USERS);
		
			
		$setData['A1'] = 'Leave Report';
		
		$setData['A2'] = $date_start . ' to ' . $date_end;
		// $setData['A3'] = html_entity_decode($user['designation'] . ', ' . $user['department']);
		
		
		$casual_paid = 0;
		$sick_paid = 0;
		$earned_paid = 0;
		$maternity_paid = 0;
		$casual_unpaid = 0;
		$sick_unpaid = 0;
		$earned_unpaid = 0;
		$maternity_unpaid = 0;
		$total_paid = 0;
		$total_unpaid = 0;
		$grand_total = 0;
		$overused = 0;
		
		$avillable_users = [];
		$leave_data = [];
		$leave_data['paid'] = 0;
		$leave_data['unpaid'] = 0;
		
		foreach ($leaves as $leave) {
			$rowsToInsert[] = $count + 1;
			$setData['A' . $count] = $ordinalcount;
			
			$paid_status = '';
			if ($leave->is_paid == 0) {
				$paid_status = 'Unpaid';
			} else if ($leave->is_paid == 1) {
				$paid_status = 'Paid';
			}
			
			$setData['B' . $count] = Wo_UserData($leave->user_id)['name'];
			
			$avillable_users[] = $leave->user_id;
			
			$setData['C' . $count] = date('d M Y', $leave->posted);
			$setData['D' . $count] = date('d M Y', $leave->leave_from);
			$setData['E' . $count] = date('d M Y', $leave->leave_to);
			$setData['F' . $count] = $leave->type;
			$setData['G' . $count] = $paid_status;
			
			if ($leave->is_paid == 1) {
				if ($leave->type == 'Casual') {
					$casual_paid += $leave->days;
				} elseif ($leave->type == 'Sick') {
					$sick_paid += $leave->days;
				} elseif ($leave->type == 'Earned') {
					$earned_paid += $leave->days;
				} elseif ($leave->type == 'Maternity') {
					$maternity_paid += $leave->days;
				}
				
				$leave_data[$leave->user_id]['paid'] += $leave->days;
			} else if ($leave->is_paid == 0) {
				if ($leave->type == 'Casual') {
					$casual_unpaid += $leave->days;
				} elseif ($leave->type == 'Sick') {
					$sick_unpaid += $leave->days;
				} elseif ($leave->type == 'Earned') {
					$earned_unpaid += $leave->days;
				} elseif ($leave->type == 'Maternity') {
					$maternity_unpaid += $leave->days;
				}
				
				$leave_data[$leave->user_id]['unpaid'] += $leave->days;
			}
			
			$count++;
			$ordinalcount++;
		}
		
		$unique_userId = array_unique($avillable_users);
		
		$ordinalcount2 = 1;
		$count2 = $count + 4;
		$total_paid = 0;
		$total_unpaid = 0;
		
		foreach ($unique_userId as $unique_id) {
			if ($ordinalcount2 != count($unique_userId)) {				
				$rowsToInsert[] = $count2 + 1;
			}
			$paid = $leave_data[$unique_id]['paid'] ?? 0;
			$unpaid = $leave_data[$unique_id]['unpaid'] ?? 0;
			
			$setData['A' . $count2] = $ordinalcount2;
			$setData['B' . $count2] = Wo_UserData($unique_id)['name'];
			
			$setData['C' . $count2] = $paid;
			$setData['D' . $count2] = $unpaid;
			$setData['E' . $count2] = calculateTotalLeaves($unique_id);
			$setData['F' . $count2] = remaining_leave($unique_id);
			
			$total_paid += $paid;
			$total_unpaid += $unpaid;
		
			$count2++;
			$ordinalcount2++;
		}
		$setData['C' . $count2] = $total_paid;
		$setData['D' . $count2] = $total_unpaid;
		$setData['C' . $count2 + 1] = $total_paid + $total_unpaid;
		
        $file_path = './themes/' . $wo['config']['theme'] . '/file_template/leave_report.xlsx';
		$filename = 'Leave Report.xlsx';
    } else if ($type == 'rent_report') {
		$user_id = isset($_GET['user_id']) ? Wo_Secure($_GET['user_id']) : '';
		$date_start = isset($_GET['date_start']) ? Wo_Secure($_GET['date_start']) : '';
		$date_end = isset($_GET['date_end']) ? Wo_Secure($_GET['date_end']) : '';
		$setData = array();
		$rowsToInsert = [];
		$ordinalcount = 1;
		$count = 4;


		if (empty($date_end)) {
			// If $date_end is empty, set it to the end of the selected day
			$date_end = $date_start . ' 23:59:59';
			$date_start = $date_start . ' 00:00:00';
		} else {
			// If both dates are provided, set timestamps for the entire days
			$date_start = $date_start . ' 00:00:00';
			$date_end = $date_end . ' 23:59:59';
		}
		
		$start_timestamp = strtotime($date_start);
		$end_timestamp = strtotime($date_end);

		$query = $db->where('status', 1)->where('visit_date', $start_timestamp, '>=')->where('visit_date', $end_timestamp, '<=');

		if ($user_id != 999) {
			$query->where('user_id', $user_id);
		}

		$rents = $query->get(T_RENT_REPORT);

		
		$setData['A1'] = 'Vehicle Rent Report';
		$setData['A2'] = 'Date :' . date('d-m-Y' , $start_timestamp) . ' to ' . date('d-m-Y' , $end_timestamp);
		
		$rent_group = [];
		$total_rent = 0;
		$total_payment = 0;
		
		if (!empty($rents)) {
				foreach ($rents as $rent) {
					$user = Wo_UserData($rent->user_id);

					// Initialize user group if not already set
					if (!isset($rent_group[$rent->user_id])) {
						$rent_group[$rent->user_id] = [
							'name' => $user['name'],
							'total_rent' => 0,
							'payment' => 0
						];
					}

					// Aggregate total rents and payments
					$rent_group[$rent->user_id]['total_rent'] += 1;
					if ($rent->vendor == 'Office') {
						$rent_group[$rent->user_id]['payment'] += 0;
					} else {				
						$rent_group[$rent->user_id]['payment'] += $rent->payment;
					}
				}

				// Prepare data for output
				foreach ($rent_group as $key => $rg) {
					$rowsToInsert[] = $count + 1;
					$setData['B' . $count] = $count; // Serial number
					$setData['C' . $count] = $rg['name']; // User name
					$setData['D' . $count] = $rg['total_rent']; // Total rent
					$setData['E' . $count] = number_format($rg['payment'], 2) . '/-'; // Payment
					$total_rent += $rg['total_rent'];
					$total_payment += $rg['payment'];
					$count++;
				}

				// Add grand total
				$count++;
				$setData['C' . $count] = 'Grand Total';
				$setData['D' . $count] = $total_rent;
				$setData['E' . $count] = number_format($total_payment, 2) . '/-';

		} else {
			$setData['C' . $count+1] = 'Grand Total';
			$setData['D' . $count+1] = $total_rent;
			$setData['E' . $count+1] = number_format($total_payment, 2) . '/-';
		}
		
		
        $file_path = './themes/' . $wo['config']['theme'] . '/file_template/rent_report.xlsx';
		$filename = 'Vehicle rent report.xlsx';
    } else {
        // Invalid document type
        echo 'Invalid document type';
        exit();
    }

    $extension = pathinfo($file_path, PATHINFO_EXTENSION);

    if ($extension == 'docx') {
        $content_type = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
		
        // Load the Word document template
        $templateProcessor = new TemplateProcessor($file_path);
        
        if ($type == 'leave') {
    		if (file_exists($signaturePath)) {
                $templateProcessor->setImageValue('signature', array(
                    'path' => $signaturePath,
                    'width' => 200,
                    'height' => 60,
                    'ratio' => true
                ));
            } else {
                $templateProcessor->setValue('signature', '(No Signature)');
            }
        }
        
        // Replace placeholders
        foreach ($setData as $key => $data) {
            // if it’s exactly int 0 or string '0', make it the string '0'
            if ($data === '0') {
                $data = '0' . "\xC2\xA0";
            }
            $templateProcessor->setValue($key, (string)$data);
        }
		
        // Save the modified file
        $modifiedFilePath = './themes/' . $wo['config']['theme'] . '/modified_document_' . time() . '.docx';
        $templateProcessor->saveAs($modifiedFilePath);
		
    } else if ($extension == 'xlsx') {
        $content_type = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        
        // Load the existing Excel file
        $spreadsheet = IOFactory::load($file_path);
        // Modify the Excel file
        $sheet = $spreadsheet->getActiveSheet();
		// Insert new rows before setting cell values
		foreach ($rowsToInsert as $row) {
			$sheet->insertNewRowBefore($row, 1); // Insert a new row before the specified row
		}
		
        // Replace placeholders
		foreach ($setData as $key => $data) {
			if ($key != 'NR') {
				$sheet->setCellValue($key, $data); // Set the cell value
			}
		}
	
		
        // Save the modified file
        $modifiedFilePath = './themes/' . $wo['config']['theme'] . '/modified_document_' . time() . '.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($modifiedFilePath);
    } else {
        // Invalid file format
        echo 'File format invalid';
        exit();
    }

    // Set appropriate headers for file download
    ob_end_clean();
    header('Content-Description: File Transfer');
    header('Content-Type:' . $content_type); // Change content type if needed
    header('Content-Disposition: attachment; filename=' . $filename);
    header('Content-Transfer-Encoding: binary');
    header('Expires: 0');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    header('Content-Length: ' . filesize($modifiedFilePath));

    ob_clean();
    flush();
    readfile($modifiedFilePath);

    unlink($modifiedFilePath);
    exit();
} else {
    // Invalid or missing token, handle accordingly
    echo 'Invalid token';
}
