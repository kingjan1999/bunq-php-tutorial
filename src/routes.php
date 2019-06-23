<?php

use Slim\App;
use Slim\Http\Request;
use Slim\Http\Response;

use bunq\Util\BunqEnumApiEnvironmentType;
use bunq\Context\ApiContext;

use GuzzleHttp\Client;

return function (App $app) {
    $container = $app->getContainer();
    $bunq_settings = $container->get('settings')['bunq'];

    $app->get('/', function (Request $request, Response $response, array $args) use ($container) {
        // Sample log message
        $container->get('logger')->info("Slim-Skeleton '/' route");

        // Render index view
        return $container->get('renderer')->render($response, 'index.html.twig', $args);
    });

    $app->get('/login', function (Request $request, Response $response, array $args) use ($container, $bunq_settings) {
        $base_url = $request->getUri()->getBaseUrl();
        $callback_url = $container->get('router')->pathFor('auth-callback');

        $state = generateRandomString();

        $target_url = $bunq_settings['auth_url'] . '?';
        $target_url .= http_build_query([
            'response_type' => 'code',
            'client_id' => $bunq_settings['client_id'],
            'redirect_uri' => $base_url . $callback_url,
            'state' => $state
        ]);

        $_SESSION['auth_state'] = $state;

        return $response->withRedirect($target_url);
    });

    $app->get('/auth-callback', function (Request $request, Response $response, array $args) use ($container, $bunq_settings) {
        $code = $request->getQueryParam('code');
        $state = $request->getQueryParam('state');

        $base_url = $request->getUri()->getBaseUrl();
        $callback_url = $base_url. $container->get('router')->pathFor('auth-callback');

        if (empty($state) || empty($code)) {
            $response->getBody()->write("Need to pass state and code!");
            return $response;
        }

        $old_state = $_SESSION['auth_state'];
        if (empty($old_state) || strcmp($state, $old_state) !== 0) {
            $response->getBody()->write("Invalid state passed!");
            return $response;
        }

        $http_client = new Client();
        $token_url = $bunq_settings['token_url'];
        $token_response = $http_client->request('POST', $token_url, [
            'query' => [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $callback_url,
                'client_id' => $bunq_settings['client_id'],
                'client_secret' => $bunq_settings['client_secret']
            ],
            'headers' => [
                'X-Bunq-Client-Request-Id' => generateRandomString(16)
            ]
        ]);

        $bodyDecoded = json_decode($token_response->getBody()->getContents());
        $access_token = $bodyDecoded->access_token;
        $apiContext = generateAPIContext($access_token);

        $_SESSION['access_token'] = $access_token;
        $_SESSION['api_context'] = $apiContext->toJson();

        return $response->withRedirect('/');
    })->setName('auth-callback');
};


function generateAPIContext($apiKey)
{
    $environmentType = BunqEnumApiEnvironmentType::SANDBOX();
    $deviceDescription = 'bunq Webapp Tutorial';
    $permittedIps = [];

    $apiContext = ApiContext::create(
        $environmentType,
        $apiKey,
        $deviceDescription,
        $permittedIps
    );

    return $apiContext;
}

/**
 * Generates a (non-secure) string of arbitrary length
 * @param int $length Length of the string to generate
 * 
 * @return string
 * 
 * stolen from https://stackoverflow.com/a/13212994 
 */
function generateRandomString($length = 10)
{
    return substr(str_shuffle(str_repeat($x = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', ceil($length / strlen($x)))), 1, $length);
}
