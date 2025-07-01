<?php
$capabilities = [
    'local/chiefdoctor_panel:createslots' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'chiefdoctor' => CAP_ALLOW
        ]
    ]
];
