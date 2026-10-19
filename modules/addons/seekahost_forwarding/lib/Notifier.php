<?php

namespace SeekaHost\Forwarding;

class Notifier
{
    public static function client($domainId, $action, $details)
    {
        if (!Settings::on('notify_client')) {
            return;
        }
        try {
            $result = localAPI('SendEmail', [
                'messagename' => Schema::EMAIL_TEMPLATE,
                'id' => (int) $domainId,
                'customvars' => base64_encode(serialize([
                    'fwd_action' => $action,
                    'fwd_details' => nl2br(htmlspecialchars($details, ENT_QUOTES, 'UTF-8')),
                ])),
            ]);
            if (isset($result['result']) && $result['result'] !== 'success') {
                Logger::log('notify', 'Client email failed: ' . (isset($result['message']) ? $result['message'] : 'unknown'), 'error', null, $domainId);
            }
        } catch (\Throwable $e) {
            Logger::log('notify', 'Client email failed: ' . $e->getMessage(), 'error', null, $domainId);
        }
    }

    public static function adminError($action, $message, $domainId = null)
    {
        if (!Settings::on('notify_admin_errors')) {
            return;
        }
        try {
            localAPI('SendAdminEmail', [
                'customsubject' => 'SeekaHost Forwarding error: ' . $action,
                'custommessage' => '<p>Domain ID: ' . (int) $domainId . '</p><p>'
                    . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>',
                'type' => 'system',
            ]);
        } catch (\Throwable $e) {
            // ignore
        }
    }
}
