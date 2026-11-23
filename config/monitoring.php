<?php

use Dotenv\Dotenv;

// Keep the demo owner tied to the project file even if the shell defines the same key.
$settings = Dotenv::createArrayBacked(base_path(), '.env')->safeLoad();

return [
    'demo_owner_email' => $settings['DOKPANTAU_DEMO_OWNER_EMAIL'] ?? null,
];
