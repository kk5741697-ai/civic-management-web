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
			'url' => '/projects/1_Civic+Moon+hill',
			'parent_id' => null,
			'children' => [
				[
					'id' => 10,
					'name' => 'Civic Moonhill',
					'url' => '/projects/1_Civic+Moon+hill',
					'parent_id' => 9,
					'children' => [
						[
							'id' => 11,
							'name' => 'Overview',
							'url' => '/projects/1_Civic+Moon+hill#overview',
							'parent_id' => 10,
						],
						[
							'id' => 12,
							'name' => 'Price List',
							'url' => '/projects/1_Civic+Moon+hill#price_list',
							'parent_id' => 10,
						],
						[
							'id' => 13,
							'name' => 'Features & Amenities',
							'url' => '/projects/1_Civic+Moon+hill#features',
							'parent_id' => 10,
						],
						[
							'id' => 14,
							'name' => 'Google Location',
							'url' => '/projects/1_Civic+Moon+hill#google_location',
							'parent_id' => 10,
						]
					],
				],
				[
					'id' => 23,
					'name' => 'Civic Hill Town',
					'url' => '/projects/2_Civic+Hill+Town',
					'parent_id' => 9,
					'children' => [
						[
							'id' => 11,
							'name' => 'Overview',
							'url' => '/projects/2_Civic+Hill+Town#overview',
							'parent_id' => 10,
						],
						[
							'id' => 12,
							'name' => 'Layout',
							'url' => '/projects/2_Civic+Hill+Town#layout',
							'parent_id' => 10,
						],
					],
				],
			],
		],
		[
			'id' => 19,
			'name' => 'Apartments',
			'url' => '/projects/3_Civic+Abedin',
			'parent_id' => null,
			'children' => [
				[
					'id' => 20,
					'name' => 'Civic Abedin',
					'url' => '/projects/3_Civic+Abedin',
					'parent_id' => 19,
				],
				[
					'id' => 21,
					'name' => 'Civic Ashridge',
					'url' => '/projects/4_Civic+Ashridge',
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
