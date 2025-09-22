<?php
return [
    'menus' => [
        [
            'id' => 0,
            'name' => 'Home',
            'url' => '/',
            'parent_id' => null,
        ],
		[
			'id' => 1,
			'name' => 'Corporate',
			'url' => '/about-us/company-profile',
			'parent_id' => null,
			'children' => [
				[
					'id' => 2,
					'name' => 'Company Profile',
					'url' => '/about-us/company-profile',
					'parent_id' => 1,
				],
				[
					'id' => 3,
					'name' => 'Mission & Vision',
					'url' => '/about-us/mission-and-vision',
					'parent_id' => 1,
				],
				[
					'id' => 4,
					'name' => 'Certifications',
					'url' => '/about-us/certifications',
					'parent_id' => 1,
				],
				[
					'id' => 5,
					'name' => 'Awards',
					'url' => '/about-us/awards',
					'parent_id' => 1,
				],
				[
					'id' => 6,
					'name' => 'Amenities',
					'url' => '/about-us/amenities',
					'parent_id' => 1,
				],
				[
					'id' => 7,
					'name' => 'Clients Review',
					'url' => '/about-us/clients-review',
					'parent_id' => 1,
				],
				[
					'id' => 8,
					'name' => 'Hours & Location',
					'url' => '/about-us/hours-and-location',
					'parent_id' => 1,
				],
			],
		],
		[
			'id' => 9,
			'name' => 'Land Projects',
			'url' => '/civic-moonhill',
			'parent_id' => null,
			'children' => [
				[
					'id' => 10,
					'name' => 'Civic Moonhill',
					'url' => '/civic-moonhill',
					'parent_id' => 9,
					'children' => [
						[
							'id' => 11,
							'name' => 'Overview',
							'url' => '/civic-moonhill',
							'parent_id' => 10,
						],
						[
							'id' => 12,
							'name' => 'Layout',
							'url' => '/civic-moonhill/layout',
							'parent_id' => 10,
						],
						[
							'id' => 13,
							'name' => 'Features & Amenities',
							'url' => '/civic-moonhill/features-amenities',
							'parent_id' => 10,
						],
						[
							'id' => 14,
							'name' => 'Key Locations',
							'url' => '/civic-moonhill/key-locations',
							'parent_id' => 10,
						],
						[
							'id' => 15,
							'name' => 'Photo & Video Gallery',
							'url' => '/civic-moonhill/gallery',
							'parent_id' => 10,
						],
						[
							'id' => 16,
							'name' => 'Certifications',
							'url' => '/civic-moonhill/certifications',
							'parent_id' => 10,
						],
						[
							'id' => 17,
							'name' => 'Terms & Conditions',
							'url' => '/civic-moonhill/terms-and-conditions',
							'parent_id' => 10,
						],
						[
							'id' => 18,
							'name' => 'Apply Online',
							'url' => '/civic-moonhill/apply-online',
							'parent_id' => 10,
						],
					],
				],
			],
		],
		[
			'id' => 19,
			'name' => 'Apartments',
			'url' => '/apartments',
			'parent_id' => null,
			'children' => [
				[
					'id' => 20,
					'name' => 'Civic Design & Development Ltd',
					'url' => '/apartments/civic_design_and_development_ltd',
					'parent_id' => 19,
				],
				[
					'id' => 21,
					'name' => 'Civic Abedin',
					'url' => '/apartments/civic_abedin',
					'parent_id' => 19,
				],
			],
		],
		[
			'id' => 22,
			'name' => 'Gallery',
			'url' => '/gallery',
			'parent_id' => null,
		],
	],
    'pages' => [
        // Example page
        ['id' => 1, 'title' => 'Home Page', 'content' => 'Welcome to our website!', 'slug' => 'home'],
    ],
];
?>
