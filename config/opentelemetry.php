<?php

return [
    'service_name' => env('OTEL_SERVICE_NAME', 'laravel_local'),
    'traces_endpoint' => env('OTEL_EXPORTER_OTLP_TRACES_ENDPOINT', 'http://localhost/v1/traces'),
    'traces_headers' => env('OTEL_EXPORTER_OTLP_TRACES_HEADERS', ''),
];