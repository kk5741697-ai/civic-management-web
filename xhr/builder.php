<?php

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

header('Content-Type: application/json');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Only POST method allowed']);
    exit;
}

// Parse JSON input
$data = json_decode(file_get_contents('php://input'), true);

// Validate input
if (
    empty($data['project_id']) ||
    !is_numeric($data['project_id']) ||
    empty($data['fields']) ||
    !is_array($data['fields'])
) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input']);
    exit;
}

$project_id = (int)$data['project_id'];
$fields = $data['fields'];

// Optional: Clear old entries
$db->where('project_id', $project_id);
$db->delete('field_positions');

// Insert new field positions
foreach ($fields as $f) {
    if (empty($f['name']) || !isset($f['style']) || !is_array($f['style'])) {
        continue;
    }

    $style_json = [
        'top'           => $f['style']['top'] ?? '0px',
        'left'          => $f['style']['left'] ?? '0px',
        'width'         => $f['style']['width'] ?? null,
        'height'        => $f['style']['height'] ?? null,
        'letterSpacing' => $f['style']['letterSpacing'] ?? null,
        'textAlign'     => $f['style']['textAlign'] ?? null
    ];

    $insert_data = [
        'project_id'  => $project_id,
        'field_name'  => $f['name'],
        'style_json'  => json_encode($style_json)
    ];

    $db->insert('field_positions', $insert_data);
}

echo json_encode(['success' => true, 'message' => 'Field positions saved successfully']);

// Get field positions for a project
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['s']) && $_POST['s'] === 'get_positions') {
    $project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
    $project_slug = isset($_POST['project_slug']) ? trim($_POST['project_slug']) : '';
    
    if ($project_id) {
        $positions = $db->where('project_id', $project_id)->get('field_positions');
    } elseif ($project_slug) {
        // Get project ID from slug
        $project = $db->where('slug', $project_slug)->getOne(T_PROJECTS);
        if ($project) {
            $positions = $db->where('project_id', $project->id)->get('field_positions');
        } else {
            echo json_encode(['success' => false, 'message' => 'Project not found']);
            exit;
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Project ID or slug required']);
        exit;
    }
    
    $result = [];
    foreach ($positions as $pos) {
        $result[] = [
            'name' => $pos->field_name,
            'style' => json_decode($pos->style_json, true)
        ];
    }
    
    echo json_encode(['success' => true, 'positions' => $result]);
    exit;
}