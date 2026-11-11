<?php

/*
|--------------------------------------------------------------------------
| Staff Position Configuration
|--------------------------------------------------------------------------
| Facility staff accounts share the global "staff" role. The position on
| the tenant_users pivot specialises the staff member. When a position is
| set, its permission list REPLACES the staff role baseline (whitelist
| semantics) so every facility can rely on consistent staff capabilities.
|
| manager         - Runs the facility alongside the owner.
| front_desk      - Handles bookings, walk-ins, payments and check-ins.
| court_attendant - Handles courts and on-site check-ins.
| scorer          - Records and verifies match scores.
*/

return [

    'positions' => [

        'manager' => [
            'label' => 'Manager',
            'permissions' => [
                'bookings.view',
                'bookings.manage',
                'bookings.cancel',
                'bookings.check_in',
                'courts.view',
                'payments.view',
                'payments.verify',
                'open_play.manage',
                'scores.submit',
                'scores.verify',
                'staff.view',
                'announcements.view',
                'announcements.create',
                'announcements.update',
                'announcements.delete',
                'announcements.publish',
            ],
        ],

        'front_desk' => [
            'label' => 'Front Desk',
            'permissions' => [
                'bookings.view',
                'bookings.manage',
                'bookings.check_in',
                'courts.view',
                'payments.view',
                'payments.verify',
                'announcements.view',
            ],
        ],

        'court_attendant' => [
            'label' => 'Court Attendant',
            'permissions' => [
                'bookings.view',
                'bookings.check_in',
                'courts.view',
                'announcements.view',
            ],
        ],

        'scorer' => [
            'label' => 'Scorer',
            'permissions' => [
                'bookings.view',
                'scores.submit',
                'scores.verify',
                'announcements.view',
            ],
        ],

    ],
];
