<?php
declare(strict_types=1);

/*
 * Square Invoice Forecast configuration template.
 *
 * Replace REPLACE_WITH_YOUR_SQUARE_ACCESS_TOKEN with the production access
 * token from your Square Developer Dashboard after copying this file to
 * config.php. Never commit the populated config.php file.
 */

return [
    'square_access_token' => 'REPLACE_WITH_YOUR_SQUARE_ACCESS_TOKEN',
    'square_environment' => 'production',
    'square_api_version' => '2026-07-15',

    // Optional. Leave empty to include every active Square location.
    // For specific locations: ['LOCATION_ID_1', 'LOCATION_ID_2']
    'square_location_ids' => [],

    'business_timezone' => 'America/Chicago',
];
