// Activate Service Worker Instantly
self.addEventListener('install', (evt) => {
    self.skipWaiting(); // Activate the worker immediately
});

self.addEventListener('activate', (evt) => {
    evt.waitUntil(self.clients.claim()); // Take control of uncontrolled clients
});

// Listen for Notification Click
self.addEventListener('notificationclick', (event) => {
    const static_data = event.notification.data; // Get the notification data
    event.notification.close(); // Close the notification

    event.waitUntil(
        clients.matchAll({ type: 'window' }).then((clientList) => {
            let isPwaOpen = false;

            // Check if any of the open windows matches the PWA app's URL (with full URL or relative)
            for (let client of clientList) {
                const clientUrl = new URL(client.url); // Parse the URL of the client

                // Match the client with the URL of your PWA (e.g., https://example.com/management)
                if (clientUrl.pathname === '/management' && 'focus' in client) {
                    isPwaOpen = true;
                    return client.focus(); // Focus on the PWA window if it's already open
                }
            }

            // If the PWA is not open, open it in a new window (standalone)
            if (!isPwaOpen && clients.openWindow) {
                return clients.openWindow(static_data.onclic_url); // Open the PWA at the entry point
            }
        }).catch((error) => {
            console.error('[ServiceWorker] Error handling notification click:', error);
        })
    );
});



// Listen for Push Notifications
self.addEventListener('push', (event) => {
    if (!event.data) {
        return;
    }

    let static_data;
    try {
        static_data = event.data.json(); // Parse the received push payload
    } catch (error) {
        return;
    }

    const options = {
        body: static_data.body || 'No message body provided',
        icon: static_data.icon || 'https://civicgroupbd.com/manage/assets/images/logo-icon-2.png', // Default icon
        image: static_data.image || null,
        badge: static_data.badge || null,
        data: static_data, // Pass the entire payload for use in click events
    };

    // Show the notification
    event.waitUntil(
        self.registration
            .showNotification(static_data.title || 'No title provided', options)
            .catch((error) => console.error('Error showing notification:', error))
    );
});
