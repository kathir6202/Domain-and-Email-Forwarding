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
            $text = ucfirst($fwd->status) . ' &middot; ' . $urls . ' URL / ' . $emails . ' email forwarder(s)';
        } else {
            $text = 'Not used yet';
        }
        return ['Domain &amp; Email Forwarding' => $text . ' &nbsp; <a href="' . $link . '" class="btn btn-xs btn-sm btn-default">Manage</a>'];
    } catch (\Throwable $e) {
        return [];
    }
});

// Daily: remove ineligible domains from servers, restore re-enabled ones, retry SSL.
add_hook('DailyCronJob', 1, function ($vars) {
    try {
        Service::cron();
    } catch (\Throwable $e) {
        Logger::log('cron', $e->getMessage(), 'error', null, null, 'system');
    }
});

// Domain deleted in WHMCS: clean up server and module data.
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
