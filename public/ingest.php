<?php
declare(strict_types=1);

// Endpoint lead masuk: POST /ingest.php?t=<tenant-slug>&s=<source-slug>
require dirname(__DIR__) . '/lib/bootstrap.php';

set_exception_handler(function (Throwable $e) {
    app_log('ingest.log', 'request error: ' . get_class($e) . ': ' . $e->getMessage());
    json_response(['error' => 'server_error'], 500);
});

ingest_handle_request();
