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

/*
 | Start OpenTelemetry setup
 */
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Contrib\OtlpHttp\Exporter as OtlpHttpExporter;
use OpenTelemetry\SDK\Common\Time\ClockFactory;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\SpanExporter\ConsoleSpanExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SemConv\TraceAttributes;

$httpClient = new Client();
$httpFactory = new HttpFactory();

// The OtlpHttp\Exporter will look in $_ENV, so we need to work around the configuration mechanism
// $_ENV['OTEL_SERVICE_NAME'] = "laravel_local";
// $_ENV['OTEL_EXPORTER_OTLP_TRACES_ENDPOINT'] = "https://ga-otlp.lumigo-tracer-edge.golumigo.com/v1/traces";
// $_ENV['OTEL_EXPORTER_OTLP_TRACES_HEADERS'] = "Authorization=LumigoToken <PUT_LUMIGO_TOKEN_HERE>";

$tracerProvider = new TracerProvider(
    [
        new BatchSpanProcessor(
            OtlpHttpExporter::fromConnectionString(),
            ClockFactory::getDefault(),
        ),
        new SimpleSpanProcessor(
            new ConsoleSpanExporter(),
        ),
    ],
    new AlwaysOnSampler(),
);

$tracer = $tracerProvider->getTracer('Laravel');
/*
 | End OpenTelemetry setup
 */

 try {
    $kernel = $app->make(Kernel::class);

    $request = Request::capture();
    $span = $tracer->spanBuilder($request->method() . " " . $request->url())
        ->setSpanKind(SpanKind::KIND_SERVER)
        ->setAttribute(TraceAttributes::HTTP_METHOD, strtoupper($request->method()))
        ->setAttribute(TraceAttributes::HTTP_USER_AGENT, $request->header("user-agent"))
        // TODO http.request_content_length
        // TODO http.response_content_length
        ->setAttribute(TraceAttributes::HTTP_SCHEME, strtok($request->schemeAndHttpHost(), ":"))
        ->setAttribute(TraceAttributes::HTTP_TARGET, $request->path())
        // ->setAttribute(OpenTelemetry\SemConv\TraceAttributes::HTTP_ROUTE, ???) // This requires hooking deeper in controllers
        ->startSpan();

    foreach ($request->header() as $headerName => $headerValues) {
        $span->setAttribute("http.request.header." . str_replace("-", "_", strtolower($headerName)), $request->header($headerName));
    }

    $spanScope = $span->activate();
    try {
        $response = $kernel->handle($request)->send();

        $span->setAttribute(TraceAttributes::HTTP_STATUS_CODE, $response->status());
        foreach ($response->headers as $headerName => $headerValues) {
            $span->setAttribute("http.response.header." . str_replace("-", "_", strtolower($headerName)), $response->headers->get($headerName));
        }

        $kernel->terminate($request, $response);

        $span->setStatus(StatusCode::STATUS_OK);
    } catch (Exception $e) {
        $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
        $span->recordException($e, [TraceAttributes::EXCEPTION_ESCAPED => true]);
        throw $e;
    } finally {
        $span->end();
        $spanScope->detach();
    }
} finally {
    $tracerProvider->forceFlush();
}
