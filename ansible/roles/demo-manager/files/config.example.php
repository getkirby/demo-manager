<?php

return [
    'activityPath'   => 'content',
    'custom'         => [
        'cdn-key' => '...'
    ],
    'indexResponse'  => 'https://example.com/try',
    'statusResponse' => 'https://example.com/try/{{ type }}:{{ status }}',
    'templateUrl'    => 'https://github.com/ghost/example-repo/archive/main.zip#example-repo-main',
    'webhookOrigins' => ['ghost/example-repo#refs/heads/main'],
    'webhookSecret'  => '...',

    // only enable for debugging!!
    // 'maxInstancesPerClient' => 0,
];
