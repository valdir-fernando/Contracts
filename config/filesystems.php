<?php

return [
    'default' => 'local',
    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'throw' => true,
        ],
        'contract_documents' => [
            'driver' => 'local',
            'root' => storage_path('app/private/contract-documents'),
            'visibility' => 'private',
            'throw' => true,
        ],
    ],
];
