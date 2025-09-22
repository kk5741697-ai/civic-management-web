<?php 
if ($f == 'upload_image') {
	if ($s == 'upload') {
		if (isset($_FILES['image']['name'])) {
			$fileInfo = array(
				'file' => $_FILES["image"]["tmp_name"],
				'name' => $_FILES['image']['name'],
				'size' => $_FILES["image"]["size"],
				'type' => $_FILES["image"]["type"]
			);
			$media    = Wo_ShareFile($fileInfo);
			if (!empty($media)) {
				$mediaFilename    = $media['filename'];
				$mediaName        = $media['name'];
				$_SESSION['file'] = $mediaFilename;
				$data             = array(
					'status' => 200,
					'image' => Wo_GetMedia($mediaFilename),
					'image_src' => $mediaFilename
				);
			}
		}
	}
	if ($s == 'upload_v2') {
		if (isset($_FILES['file']['name'])) {
			$fileInfo = array(
				'file' => $_FILES["file"]["tmp_name"],
				'name' => $_FILES['file']['name'],
				'size' => $_FILES["file"]["size"],
				'type' => $_FILES["file"]["type"]
			);
			$media    = Wo_ShareFile($fileInfo);
			if (!empty($media)) {
				$mediaFilename    = $media['filename'];
				$mediaName        = $media['name'];
				$_SESSION['file'] = $mediaFilename;
				$data             = array(
					'status' => 200,
					'location' => Wo_GetMedia($mediaFilename)
				);
			}
		} else {
			echo json_encode(['error' => 'No file received']);
		}
	}
	
	header("Content-type: application/json");
	echo json_encode($data);
	exit();
}
