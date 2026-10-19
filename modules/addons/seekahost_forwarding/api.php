<?php
/**
 * SeekaHost Domain & Email Forwarding - JSON API endpoint.
 *
 *   GET/POST https://your-whmcs/modules/addons/seekahost_forwarding/api.php?action=redirects&domain=example.com
 *   Header:  X-API-Token: shfwd_...
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/lib/bootstrap.php';

use SeekaHost\Forwarding\Api;
use SeekaHost\Forwarding\ApiException;
use SeekaHost\Forwarding\Input;
use SeekaHost\Forwarding\Logger;
use WHMCS\Database\Capsule;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$respond = function ($code, array $body) {
    http_response_code($code);
    echo json_encode($body);
    exit;
};

try {
    $active = Capsule::table('tbladdonmodules')->where('module', SEEKAHOST_FWD_MODULE)->where('setting', 'version')->exists();
    if (!$active) {
        throw new ApiException('Module is not active.', 404);
    }
    $params = Input::decode(array_merge($_GET, $_POST));
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    $token = isset($_SERVER['HTTP_X_API_TOKEN']) ? $_SERVER['HTTP_X_API_TOKEN'] : (isset($params['token']) ? $params['token'] : '');
    $tokenRow = Api::authenticate($token, $ip);
    unset($params['token']);

    Logger::$actor = 'api:' . substr($tokenRow->name, 0, 16);
    $action = isset($params['action']) ? (string) $params['action'] : '';
    $result = Api::handle($action, $params);
    Logger::log('api_call', 'API ' . $action . ' from ' . $ip);
    $respond(200, ['result' => 'success'] + $result);
} catch (ApiException $e) {
    $respond($e->getCode() ?: 400, ['result' => 'error', 'message' => $e->getMessage()]);
} catch (\InvalidArgumentException $e) {
    $respond(422, ['result' => 'error', 'message' => $e->getMessage()]);
} catch (\Throwable $e) {
    $respond(500, ['result' => 'error', 'message' => 'The forwarding server could not apply the change: ' . $e->getMessage()]);
}
