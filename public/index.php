<?php

declare(strict_types=1);

namespace App;

use Dotenv\Dotenv;
use Gotenberg\Gotenberg;
use Gotenberg\Modules\ChromiumCookie;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Factory\AppFactory;
use Slim\Psr7\Request;
use Valitron\Validator;

require __DIR__ . '/../vendor/autoload.php';

$app = AppFactory::create();

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();

$logger = new Logger('pdf-generator');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));

$app->get('/', function (Request $request, Response $response) use ($logger) {
    $logger->info(
        message: 'PDF generation request received',
        context: $request->getQueryParams(),
    );

    $validator = new Validator($request->getQueryParams());
    $validator->rules([
        'required' => ['pageUrl','fileName','id'],
        'url' => ['pageUrl']
    ]);

    [
        'pageUrl' => $pageUrl,
        'fileName' => $fileName,
        'id' => $id
    ] = $request->getQueryParams();

    if(! $validator->validate()) {
        $response->getBody()->write(json_encode($validator->errors(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        return $response->withStatus(422);
    }

    $folder = '/tmp';

    $file = Gotenberg::save(
        request: Gotenberg::chromium($_ENV['GOTENBERG_API_URL'])
            ->pdf()
            ->landscape()
            ->printBackground()
            ->preferCssPageSize()
            ->scale(0.799)
            ->skipNetworkIdleEvent(false)
            ->waitForExpression('document.documentElement && !document.documentElement.classList.contains("loading")')
            ->waitDelay('5s')
            ->emulateScreenMediaType()
            ->paperSize(
                width: 8.27,
                height: 11.7,
            )
            ->margins(
                top: 0,
                bottom: 0,
                left: 0,
                right: 0,
            )
            ->outputFilename($fileName)
            ->cookies([
                new ChromiumCookie(
                    name: 'PHPSESSID',
                    value: $id,
                    domain: parse_url($pageUrl, PHP_URL_HOST),
                )
            ])
            ->url($pageUrl . '&pdf=true'),
        dirPath: $folder
    );

    $filePath = $folder . '/' . $file;

    $response = $response->withHeader('Content-Type', 'application/pdf');
    $response = $response->withHeader('Content-Disposition', 'attachment; filename="' . $fileName . '"');
    $response = $response->withHeader('Content-Length', (string) filesize($filePath));
    $response->getBody()->write(file_get_contents($filePath));

    register_shutdown_function(function() use ($filePath) {
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    });

    return $response;
});

$app->run();
