<?php
declare(strict_types=1);

/*
 * Square Invoice Forecast configuration template.
 *
 * Copy this file to config.php and replace the placeholder access token.
 * Never commit the populated config.php file.
 */

return [
    // Use 'private' for restricted installations.
    // Change to 'public' when the report is intentionally publicly accessible.
    'installation_mode' => 'private',

    'square_access_token' => 'REPLACE_WITH_YOUR_SQUARE_ACCESS_TOKEN',
    'square_environment' => 'production',
    'square_api_version' => '2026-07-15',

    // Leave empty to include every active Square location.
    // For specific locations: ['LOCATION_ID_1', 'LOCATION_ID_2']
    'square_location_ids' => [],

    'business_timezone' => 'America/Chicago',
];
