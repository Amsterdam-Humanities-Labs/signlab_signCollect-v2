<?php
/**
 * Live Signbank credentials — gitignored.
 * Edit dataset_id / dataset_acronym if your Signbank installation uses different ids.
 */
return [
    'base_url'         => 'https://signbank.cls.ru.nl',
    'api_key'          => 'UlerhGrfFpU03RD3',
    'auth_scheme'      => 'bearer', // 'bearer' (default per OpenAPI spec) or 'x-api-key'
    'dataset_id'       => '5',
    'dataset_acronym'  => 'NGT',
    'timeout_seconds'  => 30,
];
