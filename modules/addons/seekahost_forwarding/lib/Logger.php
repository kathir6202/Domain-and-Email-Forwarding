<?php

namespace SeekaHost\Forwarding;

use WHMCS\Database\Capsule;

class Logger
{
    public static function log($action, $message, $level = 'info', $clientId = null, $domainId = null, $actor = null)
    {
        if ($actor === null) {
            $actor = defined('ADMINAREA') ? 'admin' : (defined('CLIENTAREA') ? 'client' : 'system');
        }
        try {
            Capsule::table(Schema::T_LOGS)->insert([
                'client_id' => $clientId,
                'domain_id' => $domainId,
                'actor' => $actor,
                'level' => $level,
                'action' => substr($action, 0, 64),
                'message' => $message,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Logging must never break the request.
        }
    }

    public static function error($action, $message, $clientId = null, $domainId = null)
    {
        self::log($action, $message, 'error', $clientId, $domainId);
        Notifier::adminError($action, $message, $domainId);
    }
}
