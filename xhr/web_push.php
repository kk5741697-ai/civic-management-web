<?php
// web_push.php

ob_start(); // Start output buffering
if ($f == 'web_push') {
    if ($s == 'save_subscription') {
        // Get the user ID (replace this with your authentication mechanism if necessary)
        $user_id = isset($wo['user']['user_id']) ? $wo['user']['user_id'] : null;

        if (!$user_id) {
            header("Content-Type: application/json");
            echo json_encode(['status' => 400, 'message' => 'User ID is required']);
            exit();
        }

        // Read the raw POST data from the request
        $rawData = $_POST['sub'];
        $subscription = json_decode($rawData, true);

        // Validate the subscription object
        if (!isset($subscription['endpoint']) || 
            !isset($subscription['keys']['p256dh']) || 
            !isset($subscription['keys']['auth'])) {
            header("Content-Type: application/json");
            echo json_encode(['status' => 400, 'message' => 'Invalid subscription data']);
            exit();
        }

        // File path where subscriptions will be saved
        $filePath = 'subscriptions.json';

        // Load existing subscriptions from the file (if it exists)
        $subscriptions = [];
        if (file_exists($filePath)) {
            $subscriptions = json_decode(file_get_contents($filePath), true) ?? [];
        }

        // Check if the user already has subscriptions
        if (!isset($subscriptions[$user_id])) {
            $subscriptions[$user_id] = []; // Initialize an empty array for the user
        }

        // Check if the subscription already exists
        $existingSubscription = false;
        foreach ($subscriptions[$user_id] as $sub) {
            if ($sub['endpoint'] === $subscription['endpoint']) {
                $existingSubscription = true;
                break;
            }
        }

        // Add the new subscription only if it doesn't already exist
        if (!$existingSubscription) {
            $subscriptions[$user_id][] = $subscription; // Add to the user's subscriptions array
        }

        // Save subscriptions back to the file
        if (file_put_contents($filePath, json_encode($subscriptions, JSON_PRETTY_PRINT))) {
            // Respond to the client with success
            header("Content-Type: application/json");
            echo json_encode(['status' => 200, 'message' => 'Subscription saved successfully']);
        } else {
            // Handle file write errors
            header("Content-Type: application/json");
            echo json_encode(['status' => 500, 'message' => 'Failed to save subscription']);
        }
        exit();
    }
}
