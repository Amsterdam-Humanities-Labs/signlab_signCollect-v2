<?php
/**
 * Copy this file to config.php and fill in real values.
 * config.php is gitignored.
 */
return [
    'base_url'         => 'https://signbank.cls.ru.nl',
    'api_key'          => 'YOUR_BEARER_TOKEN_HERE',
    'dataset_id'       => '5',   // numeric primary key of the dataset (path segment)
    'dataset_acronym'  => 'NGT', // sent in body as "Dataset"
    'timeout_seconds'  => 30,
];
