<?php

use Slim\App;
use Slim\Http\Request;
use Slim\Http\Response;

use bunq\Context\ApiContext;
use bunq\Context\BunqContext;
use bunq\Model\Generated\Endpoint\AttachmentPublicContent;
use bunq\Model\Generated\Endpoint\MonetaryAccountBank;
use bunq\Model\Generated\Endpoint\Payment;
use bunq\Util\BunqEnumApiEnvironmentType;

use GuzzleHttp\Client;

return function (App $app) {
    $container = $app->getContainer();
    $bunq_settings = $container->get('settings')['bunq'];

    $redirect_if_auth_mw = function (Request $request, Response $response, $next) use ($container) {
        if (isset($_SESSION['api_context'])) {
            return $response->withRedirect($container->get('router')->pathFor('dashboard'));
        }

        return $next($request, $response);
    };

    $redirect_if_no_auth_mw = function (Request $request, Response $response, $next) use ($container) {
        if (!isset($_SESSION['api_context'])) {
            return $response->withRedirect($container->get('router')->pathFor('login'));
        }

        return $next($request, $response);
    };

    $app->get('/', function (Request $request, Response $response, array $args) use ($container) {
        // Sample log message
        $container->get('logger')->info("Slim-Skeleton '/' route");

        // Render index view
        return $container->get('renderer')->render($response, 'index.html.twig', $args);
    })->add($redirect_if_auth_mw);

    $app->get('/dashboard', function (Request $request, Response $response, array $args) use ($container) {
        // recreate context from session
        $api_context_serialized = $_SESSION['api_context'];
        $apiContext = ApiContext::fromJson($api_context_serialized);
        BunqContext::loadApiContext($apiContext);

        // fetch primary account details 
        $primaryAccountId = BunqContext::getUserContext()->getMainMonetaryAccountId();
        $primaryAccount = MonetaryAccountBank::get($primaryAccountId)->getValue();

        // fetch avatar contents
        $avatarImage = $primaryAccount->getAvatar()->getImage()[0];
        $avatarId = $avatarImage->getAttachmentPublicUuid();
        $avatarContents = AttachmentPublicContent::listing($avatarId)->getValue();
        $avatarUrl = 'data:image/' . $avatarImage->getContentType() . ';base64,' . base64_encode($avatarContents);

        return $container->get('renderer')->render($response, 'dashboard.html.twig', [
            'account' => json_decode(json_encode($primaryAccount), true),
            'avatarUrl' => $avatarUrl
        ]);
    })->setName('dashboard')->add($redirect_if_no_auth_mw);;

    $app->get('/transactions', function (Request $request, Response $response, array $args) use ($container) {
        // recreate context from session
        $api_context_serialized = $_SESSION['api_context'];
        $apiContext = ApiContext::fromJson($api_context_serialized);
        BunqContext::loadApiContext($apiContext);

        // fetch all transactions for primary account
        $primaryAccountId = BunqContext::getUserContext()->getMainMonetaryAccountId();
        $transactions = Payment::listing($primaryAccountId)->getValue();

        return $container->get('renderer')->render($response, 'transactions.html.twig', [
            'transactions' => json_decode(json_encode($transactions), true)
        ]);
    })->add($redirect_if_no_auth_mw);

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
    })->setName('login');

    $app->get('/auth-callback', function (Request $request, Response $response, array $args) use ($container, $bunq_settings) {
        $code = $request->getQueryParam('code');
        $state = $request->getQueryParam('state');

        $base_url = $request->getUri()->getBaseUrl();
        $callback_url = $base_url . $container->get('router')->pathFor('auth-callback');

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
