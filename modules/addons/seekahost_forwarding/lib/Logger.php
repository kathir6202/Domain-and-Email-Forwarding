<?php

namespace SeekaHost\Forwarding;

use WHMCS\Database\Capsule;

class Logger
{
    /** Overrides the detected actor (e.g. "api:token-name"). */
    public static $actor;

    public static function log($action, $message, $level = 'info', $clientId = null, $domainId = null, $actor = null)
    {
        if ($actor === null && self::$actor !== null) {
            $actor = self::$actor;
        }
        if ($actor === null) {
            $actor = defined('ADMINAREA') ? 'admin' : (defined('CLIENTAREA') ? 'client' : 'system');
        }
        try {
            Capsule::table(Schema::T_LOGS)->insert([
                'client_id' => $clientId,
                'domain_id' => $domainId,
                'actor' => substr($actor, 0, 20),
                'level' => $level,
                'action' => substr($action, 0, 64),
                'message' => $message,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Logging must never break the request.
        }
    }

    public static function error($action, $message, $clientId = null, $domainId = null, $notify = true)
    {
        self::log($action, $message, 'error', $clientId, $domainId);
        if ($notify) {
            Notifier::adminError($action, $message, $domainId);
        }
    }
}
