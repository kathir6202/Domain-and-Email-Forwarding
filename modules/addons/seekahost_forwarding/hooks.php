<?php
/**
 * SeekaHost Domain & Email Forwarding - hooks.
 * Every hook is wrapped so a module problem can never break WHMCS pages or cron.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/bootstrap.php';

use SeekaHost\Forwarding\Logger;
use SeekaHost\Forwarding\Schema;
use SeekaHost\Forwarding\Service;
use SeekaHost\Forwarding\Settings;
use WHMCS\Database\Capsule;

// Client area: add "Domain & Email Forwarding" to the domain sidebar (optionally replacing the native link).
add_hook('ClientAreaPrimarySidebar', 1, function ($primarySidebar) {
    try {
        $panel = $primarySidebar->getChild('Domain Details Management');
        $domain = class_exists('\Menu') ? \Menu::context('domain') : null;
        if (!$panel || !$domain || empty($domain->id)) {
            return;
        }
        $row = Service::domainRow($domain->id);
        if (!$row || !Service::visibleToClient($row)) {
            return;
        }
        if (Settings::on('replace_native') && $panel->getChild('Manage Email Forwarding')) {
            $panel->removeChild('Manage Email Forwarding');
        }
        $panel->addChild('seekahost_forwarding', [
            'label' => 'Domain & Email Forwarding',
            'uri' => 'index.php?m=seekahost_forwarding&id=' . (int) $domain->id,
            'order' => 65,
        ]);
    } catch (\Throwable $e) {
        // Never break the client area.
    }
});

// Client area: management box on the domain details page.
add_hook('ClientAreaDomainDetailsOutput', 1, function ($vars) {
    try {
        $id = 0;
        if (isset($vars['domain']) && is_object($vars['domain']) && !empty($vars['domain']->id)) {
            $id = (int) $vars['domain']->id;
        } elseif (!empty($_REQUEST['id'])) {
            $id = (int) $_REQUEST['id'];
        }
        $row = $id ? Service::domainRow($id) : null;
        if (!$row || !Service::visibleToClient($row)) {
            return '';
        }
        $fwd = Capsule::table(Schema::T_DOMAINS)->where('domain_id', $id)->first();
        $urls = $fwd ? Capsule::table(Schema::T_URL)->where('fwd_domain_id', $fwd->id)->count() : 0;
        $emails = $fwd ? Capsule::table(Schema::T_EMAIL)->where('fwd_domain_id', $fwd->id)->count() : 0;
        return '<div class="panel panel-default card shfwd-domain-box" style="margin:15px 0"><div class="panel-body card-body">'
            . '<h4 style="margin-top:0">Domain &amp; Email Forwarding</h4>'
            . '<p>Domain forwarders: <strong>' . $urls . '</strong> &middot; Email forwarders: <strong>' . $emails . '</strong></p>'
            . '<a class="btn btn-primary btn-sm" href="index.php?m=seekahost_forwarding&amp;id=' . $id . '">Manage Forwarding</a>'
            . '</div></div>';
    } catch (\Throwable $e) {
        return '';
    }
});

// Client area: send the native email forwarding page to this module.
add_hook('ClientAreaPageDomainEmailForwarding', 1, function ($vars) {
    try {
        if (!Settings::on('replace_native')) {
            return;
        }
        $id = 0;
        foreach (['domainid', 'id'] as $key) {
            if (!$id && !empty($vars[$key])) {
                $id = (int) $vars[$key];
            }
            if (!$id && !empty($_REQUEST[$key])) {
                $id = (int) $_REQUEST[$key];
            }
        }
        $row = $id ? Service::domainRow($id) : null;
        if ($row && Service::visibleToClient($row)) {
            header('Location: index.php?m=seekahost_forwarding&id=' . $id);
            exit;
        }
    } catch (\Throwable $e) {
        // Fall back to the native page.
    }
});

// Admin area: summary + link on the client's domain tab.
add_hook('AdminClientDomainsTabFields', 1, function ($vars) {
    try {
        $domainId = isset($vars['id']) ? (int) $vars['id'] : 0;
        if (!$domainId) {
            return [];
        }
        $fwd = Capsule::table(Schema::T_DOMAINS)->where('domain_id', $domainId)->first();
        $link = 'addonmodules.php?module=seekahost_forwarding&action=domain&domainid=' . $domainId;
        if ($fwd) {
            $urls = Capsule::table(Schema::T_URL)->where('fwd_domain_id', $fwd->id)->count();
            $emails = Capsule::table(Schema::T_EMAIL)->where('fwd_domain_id', $fwd->id)->count();
            $text = htmlspecialchars(ucfirst($fwd->status), ENT_QUOTES, 'UTF-8') . ($fwd->parked ? ' (parked)' : '')
                . ' &middot; ' . $urls . ' URL / ' . $emails . ' email forwarder(s)';
        } else {
            $text = 'Not used yet';
        }
        return ['Domain &amp; Email Forwarding' => $text . ' &nbsp; <a href="' . $link . '" class="btn btn-xs btn-sm btn-default">Manage</a>'];
    } catch (\Throwable $e) {
        return [];
    }
});

// Automatic forwarders / parking after registration or transfer (per-TLD settings).
$shfwdAutoSetup = function ($vars) {
    try {
        $domainId = isset($vars['params']['domainid']) ? (int) $vars['params']['domainid'] : 0;
        if ($domainId) {
            Service::autoSetup($domainId);
        }
    } catch (\Throwable $e) {
        Logger::log('auto_setup', 'Domain #' . (isset($domainId) ? $domainId : 0) . ': ' . $e->getMessage(), 'error', null, isset($domainId) ? $domainId : null, 'system');
    }
};
add_hook('AfterRegistrarRegistration', 1, $shfwdAutoSetup);
add_hook('AfterRegistrarTransfer', 1, $shfwdAutoSetup);

// A hosting account is about to be created for a forwarded domain: free the domain on the forwarding server.
add_hook('PreModuleCreate', 1, function ($vars) {
    try {
        $params = isset($vars['params']) ? $vars['params'] : [];
        if (!empty($params['domain']) && !empty($params['userid'])) {
            Service::releaseForHosting($params['domain'], $params['userid']);
        }
    } catch (\Throwable $e) {
        Logger::log('hosting_release', $e->getMessage(), 'error', null, null, 'system');
    }
});

// Daily: lifecycle (suspend/restore/unpark), drift check against the server, SSL retries.
add_hook('DailyCronJob', 1, function ($vars) {
    try {
        Service::cron();
    } catch (\Throwable $e) {
        Logger::log('cron', $e->getMessage(), 'error', null, null, 'system');
    }
});

// Domain deleted in WHMCS: clean up server and module data (cron retries if the server is unreachable).
add_hook('DomainDelete', 1, function ($vars) {
    try {
        $fwd = Capsule::table(Schema::T_DOMAINS)->where('domain_id', (int) $vars['domainid'])->first();
        if ($fwd) {
            Service::purge($fwd);
        }
    } catch (\Throwable $e) {
        Logger::log('domain_delete', $e->getMessage(), 'error', null, isset($vars['domainid']) ? (int) $vars['domainid'] : null, 'system');
    }
});
