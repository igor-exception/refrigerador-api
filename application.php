<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Factory\AppFactory;

require_once __DIR__ . '/measurements.php';

function jsonResponse(ResponseInterface $response, array $data, int $status): ResponseInterface
{
    $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));

    return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
}

function createApplication(string $token, callable $store, ?callable $list = null): App
{
    $app = AppFactory::create();
    $app->get('/health', static fn (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface =>
        jsonResponse($response, ['status' => 'ok'], 200));

    $app->get('/api/v1/temperaturas', static function (
        ServerRequestInterface $request,
        ResponseInterface $response,
    ) use ($list): ResponseInterface {
        $beforeId = null;
        $query = $request->getQueryParams();
        if (array_key_exists('before_id', $query)) {
            $cursor = $query['before_id'];
            if ((!is_string($cursor) && !is_int($cursor))
                || !preg_match('/^[1-9][0-9]*$/D', (string) $cursor)
                || ($beforeId = filter_var($cursor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) === false) {
                return jsonResponse($response, ['error' => 'before_id deve ser um ID inteiro positivo válido.'], 422);
            }
        }
        try {
            if ($list === null) {
                throw new RuntimeException('Consulta não configurada.');
            }
            $readings = $list($beforeId);
        } catch (PDOException | RuntimeException) {
            error_log('Falha ao consultar medições de temperatura.');
            return jsonResponse($response, ['error' => 'Serviço de consulta indisponível.'], 503);
        }

        return jsonResponse($response, ['data' => $readings], 200);
    });

    $app->post('/api/v1/temperaturas', static function (
        ServerRequestInterface $request,
        ResponseInterface $response,
    ) use ($token, $store): ResponseInterface {
        if ($token === '') {
            return jsonResponse($response, ['error' => 'Serviço de gravação indisponível.'], 503);
        }

        $authorization = $request->getHeaderLine('Authorization');
        if (!preg_match('/^Bearer ([^\s]+)$/iD', $authorization, $matches)
            || !hash_equals($token, $matches[1])) {
            return jsonResponse($response, ['error' => 'Não autorizado.'], 401)
                ->withHeader('WWW-Authenticate', 'Bearer');
        }

        try {
            $data = json_decode((string) $request->getBody(), false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return jsonResponse($response, ['error' => 'JSON inválido.'], 400);
        }

        if (!$data instanceof stdClass
            || !property_exists($data, 'sensor_id')
            || !property_exists($data, 'temperature')
            || !in_array($data->sensor_id, ['freezer', 'refrigerator'], true)
            || !(is_int($data->temperature) || is_float($data->temperature))
            || !is_finite((float) $data->temperature)) {
            return jsonResponse($response, ['error' => 'Informe sensor_id válido e temperature como número finito.'], 422);
        }

        try {
            $metadata = measurementMetadata($data);
        } catch (InvalidArgumentException $exception) {
            return jsonResponse($response, ['error' => $exception->getMessage()], 422);
        }

        try {
            $result = $store($data->sensor_id, $data->temperature, $metadata);
        } catch (DomainException) {
            return jsonResponse($response, ['error' => 'measurement_id já utilizado neste dispositivo com conteúdo diferente.'], 409);
        } catch (PDOException | RuntimeException) {
            // Não registrar a mensagem da exceção: ela pode conter credenciais ou SQL.
            error_log('Falha ao persistir medição de temperatura.');
            return jsonResponse($response, ['error' => 'Serviço de gravação indisponível.'], 503);
        }

        return jsonResponse($response, $result['reading'], $result['created'] ? 201 : 200);
    });

    $app->addRoutingMiddleware();
    $errors = $app->addErrorMiddleware(false, true, false);
    $errors->getDefaultErrorHandler()->forceContentType('application/json');

    return $app;
}
