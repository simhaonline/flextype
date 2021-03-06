<?php

declare(strict_types=1);

/**
 * Flextype (http://flextype.org)
 * Founded by Sergey Romanenko and maintained by Flextype Community.
 */

namespace Flextype;

use Flextype\Component\Filesystem\Filesystem;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use function array_replace_recursive;
use function count;

/**
 * API sys messages
 */
$api_sys_messages['AccessTokenInvalid'] = ['sys' => ['type' => 'Error', 'id' => 'AccessTokenInvalid'], 'message' => 'The access token you sent could not be found or is invalid.'];
$api_sys_messages['NotFound'] = ['sys' => ['type' => 'Error', 'id' => 'NotFound'], 'message' => 'The resource could not be found.'];

/**
 * Validate management config token
 */
function validate_management_config_token($token) : bool
{
    return Filesystem::has(PATH['project'] . '/tokens/management/config/' . $token . '/token.yaml');
}

/**
 * Fetch item in the config
 *
 * endpoint: GET /api/delivery/config
 *
 * Query:
 * key    - [REQUIRED] - Unique identifier of the config item.
 * config - [REQUIRED] - Unique identifier of the config namespace.
 * token  - [REQUIRED] - Valid Content Delivery API token for Config.
 *
 * Returns:
 * An array of config item objects.
 */
$app->get('/api/management/config', function (Request $request, Response $response) use ($flextype, $api_sys_messages) {

    // Get Query Params
    $query = $request->getQueryParams();

    // Set variables
    $key      = $query['key'];
    $config   = $query['config'];
    $token    = $query['token'];

    if ($flextype['registry']->get('flextype.settings.api.management.config.enabled')) {

        // Validate delivery token
        if (validate_delivery_config_token($token)) {
            $delivery_config_token_file_path = PATH['project'] . '/tokens/management/config/' . $token . '/token.yaml';

            // Set delivery token file
            if ($delivery_config_token_file_data = $flextype['serializer']->decode(Filesystem::read($delivery_config_token_file_path), 'yaml')) {
                if ($delivery_config_token_file_data['state'] === 'disabled' ||
                    ($delivery_config_token_file_data['limit_calls'] !== 0 && $delivery_config_token_file_data['calls'] >= $delivery_config_token_file_data['limit_calls'])) {
                    return $response->withJson($api_sys_messages['AccessTokenInvalid'], 401);
                }

                // Fetch config
                if ($flextype['config']->has($config, $key)) {
                    $response_data['data']['key']   = $key;
                    $response_data['data']['value'] = $flextype['config']->get($config, $key);

                    // Set response code
                    $response_code = 200;

                } else {
                    $response_data = [];
                    $response_code = 404;
                }

                // Update calls counter
                Filesystem::write($delivery_config_token_file_path, $flextype['serializer']->encode(array_replace_recursive($delivery_config_token_file_data, ['calls' => $delivery_config_token_file_data['calls'] + 1]), 'yaml'));

                if ($response_code == 404) {

                    // Return response
                    return $response
                           ->withJson($api_sys_messages['NotFound'], $response_code);
                }

                // Return response
                return $response
                       ->withJson($response_data, $response_code);
            }

            return $response
                   ->withJson($api_sys_messages['AccessTokenInvalid'], 401);
        }

        return $response
               ->withJson($api_sys_messages['AccessTokenInvalid'], 401);
    }

    return $response
           ->withJson($api_sys_messages['AccessTokenInvalid'], 401);
});

/**
 * Create new item in the config
 *
 * endpoint: POST /api/management/config
 *
 * Body:
 * config        - [REQUIRED] - Unique identifier of the config namespace.
 * token         - [REQUIRED] - Valid Content Management API token for Config.
 * access_token  - [REQUIRED] - Valid Access token.
 * data          - [REQUIRED] - Data to store for the config.
 *
 * Returns:
 * Returns the config item object for the config item that was just created.
 */
$app->post('/api/management/config', function (Request $request, Response $response) use ($flextype, $api_sys_messages) {

    // Get Post Data
    $post_data = $request->getParsedBody();

    // Set variables
    $token        = $post_data['token'];
    $access_token = $post_data['access_token'];
    $config       = $post_data['config'];
    $data         = $post_data['data'];

    if ($flextype['registry']->get('flextype.settings.api.management.config.enabled')) {

        // Validate management and access token
        if (validate_management_config_token($token) && validate_access_token($access_token)) {
            $management_config_token_file_path = PATH['project'] . '/tokens/management/config/' . $token . '/token.yaml';
            $access_token_file_path = PATH['project'] . '/tokens/access/' . $access_token . '/token.yaml';

            // Set management and access token file
            if (($management_config_token_file_data = $flextype['serializer']->decode(Filesystem::read($management_config_token_file_path), 'yaml')) &&
                ($access_token_file_data = $flextype['serializer']->decode(Filesystem::read($access_token_file_path), 'yaml'))) {

                if ($management_config_token_file_data['state'] === 'disabled' ||
                    ($management_config_token_file_data['limit_calls'] !== 0 && $management_config_token_file_data['calls'] >= $management_config_token_file_data['limit_calls'])) {
                    return $response->withJson($api_sys_messages['AccessTokenInvalid'], 401);
                }

                if ($access_token_file_data['state'] === 'disabled' ||
                    ($access_token_file_data['limit_calls'] !== 0 && $access_token_file_data['calls'] >= $access_token_file_data['limit_calls'])) {
                    return $response->withJson($api_sys_messages['AccessTokenInvalid'], 401);
                }

                // Create config
                $create_config = $flextype['config']->create($config, $data['key'], $data['value']);

                if ($create_config) {
                    $response_data['data']['key']   = $data['key'];
                    $response_data['data']['value'] = $flextype['config']->get($config, $data['key']);;

                    // Set response code
                    $response_code = 200;
                } else {
                    $response_data = [];
                    $response_code = 404;
                }

                // Set response code
                $response_code = ($create_config) ? 200 : 404;

                // Update calls counter
                Filesystem::write($management_config_token_file_path, $flextype['serializer']->encode(array_replace_recursive($management_config_token_file_data, ['calls' => $management_config_token_file_data['calls'] + 1]), 'yaml'));

                if ($response_code == 404) {

                    // Return response
                    return $response
                           ->withJson($api_sys_messages['NotFound'], $response_code);
                }

                // Return response
                return $response
                       ->withJson($response_data, $response_code);
            }

            return $response
                   ->withJson($api_sys_messages['AccessTokenInvalid'], 401);
        }

        return $response
               ->withJson($api_sys_messages['AccessTokenInvalid'], 401);
    }

    return $response
           ->withJson($api_sys_messages['AccessTokenInvalid'], 401);
});

/**
 * Update config item
 *
 * endpoint: POST /api/management/config
 *
 * Body:
 * config        - [REQUIRED] - Unique identifier of the config namespace.
 * token         - [REQUIRED] - Valid Content Management API token for Config.
 * access_token  - [REQUIRED] - Valid Access token.
 * data          - [REQUIRED] - Data to store for the config.
 *
 * Returns:
 * Returns the config item object for the config item that was just created.
 */
$app->patch('/api/management/config', function (Request $request, Response $response) use ($flextype, $api_sys_messages) {

    // Get Post Data
    $post_data = $request->getParsedBody();

    // Set variables
    $token        = $post_data['token'];
    $access_token = $post_data['access_token'];
    $data         = $post_data['data'];
    $config       = $post_data['config'];

    if ($flextype['registry']->get('flextype.settings.api.management.config.enabled')) {

        // Validate management and access token
        if (validate_management_config_token($token) && validate_access_token($access_token)) {

            $management_config_token_file_path = PATH['project'] . '/tokens/management/config/' . $token . '/token.yaml';
            $access_token_file_path = PATH['project'] . '/tokens/access/' . $access_token . '/token.yaml';

            // Set management and access token file
            if (($management_config_token_file_data = $flextype['serializer']->decode(Filesystem::read($management_config_token_file_path), 'yaml')) &&
                ($access_token_file_data = $flextype['serializer']->decode(Filesystem::read($access_token_file_path), 'yaml'))) {

                if ($management_config_token_file_data['state'] === 'disabled' ||
                    ($management_config_token_file_data['limit_calls'] !== 0 && $management_config_token_file_data['calls'] >= $management_config_token_file_data['limit_calls'])) {
                    return $response->withJson($api_sys_messages['AccessTokenInvalid'], 401);
                }

                if ($access_token_file_data['state'] === 'disabled' ||
                    ($access_token_file_data['limit_calls'] !== 0 && $access_token_file_data['calls'] >= $access_token_file_data['limit_calls'])) {
                    return $response->withJson($api_sys_messages['AccessTokenInvalid'], 401);
                }

                // Update config
                $update_config = $flextype['config']->update($config, $data['key'], $data['value']);

                if ($update_config) {
                    $response_data['data']['key']   = $data['key'];
                    $response_data['data']['value'] = $flextype['config']->get($config, $data['key']);

                    // Set response code
                    $response_code = 200;
                } else {
                    $response_data = [];
                    $response_code = 404;
                }

                // Set response code
                $response_code = ($update_config) ? 200 : 404;

                // Update calls counter
                Filesystem::write($management_config_token_file_path, $flextype['serializer']->encode(array_replace_recursive($management_config_token_file_data, ['calls' => $management_config_token_file_data['calls'] + 1]), 'yaml'));

                if ($response_code == 404) {

                    // Return response
                    return $response
                           ->withJson($api_sys_messages['NotFound'], $response_code);
                }

                // Return response
                return $response
                       ->withJson($response_data, $response_code);
            }

            return $response
                   ->withJson($api_sys_messages['AccessTokenInvalid'], 401);
        }

        return $response
               ->withJson($api_sys_messages['AccessTokenInvalid'], 401);

    }

    return $response
           ->withJson($api_sys_messages['AccessTokenInvalid'], 401);
});


/**
 * Delete config item
 *
 * endpoint: DELETE /api/management/config
 *
 * Body:
 * config        - [REQUIRED] - Unique identifier of the config namespace.
 * token         - [REQUIRED] - Valid Content Management API token for Config.
 * access_token  - [REQUIRED] - Valid Access token.
 * data          - [REQUIRED] - Data to store for the config.
 *
 * Returns:
 * Returns an empty body with HTTP status 204
 */
$app->delete('/api/management/config', function (Request $request, Response $response) use ($flextype) {

    // Get Post Data
    $post_data = $request->getParsedBody();

    // Set variables
    $token        = $post_data['token'];
    $access_token = $post_data['access_token'];
    $data         = $post_data['data'];
    $config       = $post_data['config'];

    if ($flextype['registry']->get('flextype.settings.api.management.config.enabled')) {

        // Validate management and access token
        if (validate_management_config_token($token) && validate_access_token($access_token)) {
            $management_config_token_file_path = PATH['project'] . '/tokens/management/config/' . $token . '/token.yaml';
            $access_token_file_path = PATH['project'] . '/tokens/access/' . $access_token . '/token.yaml';

            // Set management and access token file
            if (($management_config_token_file_data = $flextype['serializer']->decode(Filesystem::read($management_config_token_file_path), 'yaml')) &&
                ($access_token_file_data = $flextype['serializer']->decode(Filesystem::read($access_token_file_path), 'yaml'))) {

                if ($management_config_token_file_data['state'] === 'disabled' ||
                    ($management_config_token_file_data['limit_calls'] !== 0 && $management_config_token_file_data['calls'] >= $management_config_token_file_data['limit_calls'])) {
                    return $response->withJson($api_sys_messages['AccessTokenInvalid'], 401);
                }

                if ($access_token_file_data['state'] === 'disabled' ||
                    ($access_token_file_data['limit_calls'] !== 0 && $access_token_file_data['calls'] >= $access_token_file_data['limit_calls'])) {
                    return $response->withJson($api_sys_messages['AccessTokenInvalid'], 401);
                }

                // Delete entry
                $delete_config = $flextype['config']->delete($config, $data['key']);

                // Set response code
                $response_code = ($delete_config) ? 204 : 404;

                // Update calls counter
                Filesystem::write($management_config_token_file_path, $flextype['serializer']->encode(array_replace_recursive($management_config_token_file_data, ['calls' => $management_config_token_file_data['calls'] + 1]), 'yaml'));

                if ($response_code == 404) {

                    // Return response
                    return $response
                           ->withJson($api_sys_messages['NotFound'], $response_code);
                }

                // Return response
                return $response
                       ->withJson($delete_config, $response_code);
            }

            return $response
                   ->withJson($api_sys_messages['AccessTokenInvalid'], 401);
        }

        return $response
               ->withJson($api_sys_messages['AccessTokenInvalid'], 401);
    }

    return $response
           ->withJson($api_sys_messages['AccessTokenInvalid'], 401);
});
