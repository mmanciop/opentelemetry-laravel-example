<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

define('LARAVEL_START', microtime(true));

/*
|--------------------------------------------------------------------------
| Check If The Application Is Under Maintenance
|--------------------------------------------------------------------------
|
| If the application is in maintenance / demo mode via the "down" command
| we will load this file so that any pre-rendered content can be shown
| instead of starting the framework, which could cause an exception.
|
*/

if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

/*
|--------------------------------------------------------------------------
| Register The Auto Loader
|--------------------------------------------------------------------------
|
| Composer provides a convenient, automatically generated class loader for
| this application. We just need to utilize it! We'll simply require it
| into the script here so we don't need to manually load our classes.
|
*/

require __DIR__.'/../vendor/autoload.php';

/*
 | Start OpenTelemetry setup
 */
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Contrib\Jaeger\Exporter as JaegerExporter;
use OpenTelemetry\SDK\Common\Time\ClockFactory;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\SpanExporter\ConsoleSpanExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\Contrib\OtlpHttp\Exporter as OtlpHttpExporter;

$httpClient = new Client();
$httpFactory = new HttpFactory();

$tracerProvider = new TracerProvider(
    [
        new SimpleSpanProcessor(
            JaegerExporter::fromConnectionString('http://jaeger:9412/api/v2/spans', 'Laravel')
        ),
        new SimpleSpanProcessor(
            new ConsoleSpanExporter()
        ),
    ],
    new AlwaysOnSampler(),
);

$tracer = $tracerProvider->getTracer('Laravel');
/*
 | End OpenTelemetry setup
 */

/*
|--------------------------------------------------------------------------
| Run The Application
|--------------------------------------------------------------------------
|
| Once we have the application, we can handle the incoming request using
| the application's HTTP kernel. Then, we will send the response back
| to this client's browser, allowing them to enjoy our application.
|
*/

$app = require_once __DIR__.'/../bootstrap/app.php';

try {
    $kernel = $app->make(Kernel::class);

    $request = Request::capture();
    $span = $tracer->spanBuilder($request->url())
        ->setSpanKind(SpanKind::KIND_SERVER)
        ->startSpan();
    $spanScope = $span->activate();
    try {
        $response = $kernel->handle($request)->send();
        $kernel->terminate($request, $response);
        // TODO ReportException on exception
    } finally {
        $span->end();
        $spanScope->detach();
    }
} finally {
    $tracerProvider->forceFlush();
}
