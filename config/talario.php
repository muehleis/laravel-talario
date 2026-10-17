<?php

return [
    'api_key' => env('TALARIO_API_KEY'),
    'endpoint' => env('TALARIO_ENDPOINT', 'https://talario.eu/api/v1'),
    'timeout' => (float) env('TALARIO_TIMEOUT', 60),
    'connect_timeout' => (float) env('TALARIO_CONNECT_TIMEOUT', 10),
];
