<?php
if ($s == 'editor') {
	function normalize_addi_keys(array $rawAddi) {
		$clean = [];
		foreach ($rawAddi as $rawKey => $val) {
			$key = preg_replace("/^[\[\]']+|[\[\]']+$/", "", $rawKey);
			$clean[$key] = $val;
		}
		return $clean;
	}
	function extractCoordinatesFromGoogleMaps($url) {
		if (preg_match('/@([-0-9.]+),([-0-9.]+)/', $url, $matches)) {
			return $matches[1] . ',' . $matches[2];
		}
		return '';
	}
	// Save project changes
	if ($a === 'save_changes') {
		// 1) Grab raw POST values
		$raw_id           = $_POST['id']            ?? '';
		$raw_name         = $_POST['name']          ?? '';
		$raw_description  = $_POST['description']   ?? '';
		$raw_location     = $_POST['location_text'] ?? '';
		$raw_progress     = $_POST['progress']      ?? '';
		$raw_avatar       = $_POST['image']         ?? '';
		$raw_banner       = $_POST['banner_image']  ?? '';
		$raw_active       = $_POST['active']        ?? 0;

		// 2) Decode HTML entities then strip tags and Wo_Secure
		$id           = Wo_Secure(strip_tags(html_entity_decode($raw_id,          ENT_QUOTES|ENT_HTML5)));
		$name         = Wo_Secure(strip_tags(html_entity_decode($raw_name,        ENT_QUOTES|ENT_HTML5)));
		$description  = Wo_Secure(strip_tags(html_entity_decode($raw_description, ENT_QUOTES|ENT_HTML5)));
		$location     = Wo_Secure(strip_tags(html_entity_decode($raw_location,    ENT_QUOTES|ENT_HTML5))); //this is the project google map link
		$progress     = Wo_Secure(strip_tags(html_entity_decode($raw_progress,    ENT_QUOTES|ENT_HTML5)));
		$avatarUrl    = Wo_Secure(strip_tags(html_entity_decode($raw_avatar,      ENT_QUOTES|ENT_HTML5)));
		$bannerUrl    = Wo_Secure(strip_tags(html_entity_decode($raw_banner,      ENT_QUOTES|ENT_HTML5)));
		$active       = (int)$raw_active;

		// Required check
		if (empty($id) || empty($name)) {
			echo json_encode(['status'=>400,'message'=>'Missing required fields']);
			exit;
		}

		// 3) Load existing project & decode its JSON "additional"
		$project    = $db->where('id',$id)->getOne(T_PROJECTS);
		$additional = json_decode($project->additional, true) ?: [];

		// 4) Process any JSON‐encoded addi[] fields
		$rawAddi    = $_POST['addi'] ?? [];
		$jsonKeys   = ['pricing','additional_details', 'video', 'location_shapes'];
		foreach ($jsonKeys as $k) {
			if (isset($rawAddi[$k]) && is_string($rawAddi[$k])) {
				$d = json_decode($rawAddi[$k], true);
				if (json_last_error() === JSON_ERROR_NONE && is_array($d)) {
					$rawAddi[$k] = $d;
				}
			}
		}

		// 5) Merge into $additional, decoding entities on strings
		foreach ($rawAddi as $key => $val) {
			if (is_array($val)) {
				$additional[$key] = $val;
			} else {
				$additional[$key] = Wo_Secure(strip_tags(html_entity_decode($val, ENT_QUOTES|ENT_HTML5)));
			}
		}

		// 6) Force features to ints
		if (!empty($additional['features']) && is_array($additional['features'])) {
			foreach ($additional['features'] as $f => $cnt) {
				$additional['features'][$f] = (int)$cnt;
			}
		}

		// 7) Replace gallery exactly
		$g = json_decode($_POST['gallery'] ?? '[]', true);
		if (json_last_error()===JSON_ERROR_NONE && is_array($g)) {
			$additional['gallery'] = $g;
		}
		
		
		// 8) Replace attachments, decoding names
		$aArr = json_decode($_POST['attachments'] ?? '[]', true);
		if (json_last_error()===JSON_ERROR_NONE && is_array($aArr)) {
			$cleanA = [];
			foreach ($aArr as $att) {
				if (!empty($att['file_path'])) {
					$cleanA[] = [
						'name'      => html_entity_decode(strip_tags($att['name']      ?? ''), ENT_QUOTES|ENT_HTML5),
						'file_path' => Wo_Secure(strip_tags(html_entity_decode($att['file_path'] ?? '', ENT_QUOTES|ENT_HTML5)))
					];
				}
			}
			$additional['attachments'] = $cleanA;
		}
		// echo $additional['location_coordinates']; //this just getting like this 23.8787056,90.5025614
		
		// $additional['location_coordinates'] = extractCoordinatesFromGoogleMaps($additional['location_url']);

		// 9) Build update payload
		$update = [
			'name'        => $name,
			'description' => $description,
			'location'    => $location,
			'active'      => $active,
			'progress'    => $progress,
			'avatar'      => $avatarUrl,
			'banner'      => $bannerUrl,
			'additional'  => json_encode($additional)
		];

		// 10) Run update
		if ($db->where('id',$id)->update(T_PROJECTS, $update)) {
			echo json_encode(['status'=>200,'message'=>'Project saved successfully']);
		} else {
			echo json_encode(['status'=>500,'message'=>'Database update failed']);
		}
		exit;
	}

	// Upload file handler (images or attachments)
	function upload_files(string $key, array $config) {
		if (!isset($_FILES[$key])) {
			return [];
		}
		$files = $_FILES[$key];

		if (!is_array($files['tmp_name'])) {
			$files = [
				'name'     => [ $files['name'] ],
				'tmp_name' => [ $files['tmp_name'] ],
				'type'     => [ $files['type'] ],
				'size'     => [ $files['size'] ],
				'error'    => [ $files['error'] ],
			];
		}

		$results = [];
		foreach ($files['tmp_name'] as $i => $tmpPath) {
			if (empty($tmpPath) || !empty($files['error'][$i])) {
				continue;
			}
			if (is_uploaded_file($tmpPath)) {
				$info = [
					'file'  => $tmpPath,
					'name'  => $files['name'][$i],
					'size'  => $files['size'][$i],
					'type'  => $files['type'][$i],
					'types' => $config['types'],
					'crop'  => $config['crop'] ?? null,
				];
				$media = Wo_ShareFile($info);
				if (!empty($media['filename'])) {
					$results[] = [
						'name'      => $files['name'][$i],
						'file_path' => $media['filename'],
					];
				}
			}
		}
		return $results;
	}

	// Upload images
	if ($a == 'editor_upload_images') {
		$imgs = upload_files('images', [
			'types' => 'jpeg,jpg,png,bmp,gif',
			// 'crop'  => ['width'=>850, 'height'=>330]
		]);
		echo json_encode(!empty($imgs)
			? ['status'=>200, 'images'=>array_column($imgs, 'file_path')]
			: ['status'=>400, 'error'=>'No images uploaded']);
		exit;
	}

	// Delete images
	if ($a == 'editor_delete_images') {
		$toDelete = $_POST['images'] ?? [];
		$deleted   = [];
		$file_not_exist = false;
		foreach ((array)$toDelete as $url) {
			// build real path from web‑relative URL
			$relPath = ltrim($url, '/');
			$full    = $_SERVER['DOCUMENT_ROOT'] . '/' . $relPath;
			if (file_exists($full) && unlink($full)) {
				$deleted[] = $url;
			}
			if (empty($deleted) && !file_exists($full)) {
				$deleted[] = $url;
				$file_not_exist = true;
			}
		}
		if ($file_not_exist == true) {
			if (!empty($deleted)) {
				$jsonData = [
					'status'=>404,
					'deleted'=>'File not found, deleted from only database.'
				];
			}
		} else {			
			if (!empty($deleted)) {
				$jsonData = [
					'status'=>200,
					'deleted'=>$deleted
				];
			}
		}
		echo json_encode(!empty($deleted)
			? ['status'=>200, 'deleted'=>$deleted]
			: ['status'=>400, 'error'=>'No files deleted']
		);
		exit;
	}

	// Upload attachments
	if ($a === 'editor_upload_attachments') {
		$uploaded = upload_files('attachments', [
			'types' => 'pdf,doc,docx,xls,xlsx',
			'crop'  => null,
		]);

		$paths = array_column($uploaded, 'file_path');
		if (!empty($paths)) {
			echo json_encode([
				'status'      => 200,
				'attachments' => $paths
			]);
		} else {
			echo json_encode([
				'status' => 400,
				'error'  => 'No attachments uploaded'
			]);
		}
		exit;
	}

	// Delete attachments
	if ($a === 'editor_delete_attachments') {
		$toDelete = $_POST['attachments'] ?? [];
		$deleted   = [];
		foreach ((array)$toDelete as $url) {
			$relPath = ltrim($url, '/');
			$full    = $_SERVER['DOCUMENT_ROOT'] . '/' . $relPath;
			if (file_exists($full) && unlink($full)) {
				$deleted[] = $url;
			}
		}
		echo json_encode(!empty($deleted)
			? ['status'=>200,'deleted'=>$deleted]
			: ['status'=>400,'error'=>'No attachments deleted']
		);
		exit;
	}


} else {
	echo json_encode(['status'=>400, 'message'=>'Invalid request']);
	exit;
}
?>
