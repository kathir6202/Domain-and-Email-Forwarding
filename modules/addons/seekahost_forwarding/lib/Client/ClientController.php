<?php

namespace SeekaHost\Forwarding\Client;

use SeekaHost\Forwarding\DnsCheck;
use SeekaHost\Forwarding\Input;
use SeekaHost\Forwarding\Service;
use SeekaHost\Forwarding\Settings;
use SeekaHost\Forwarding\Validator;
use WHMCS\Database\Capsule;

class ClientController
{
    const SSL_THROTTLE_SECONDS = 3600;

    private $vars;
    private $link;

    public function __construct(array $vars)
    {
        $this->vars = $vars;
        $this->link = $vars['modulelink'];
    }

    private static function clientId()
    {
        if (class_exists('\WHMCS\Authentication\CurrentUser')) {
            $client = (new \WHMCS\Authentication\CurrentUser())->client();
            if ($client) {
                return (int) $client->id;
            }
        }
        return isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : 0;
    }

    /** WHMCS user/contact permission to manage domains (account owners always pass). */
    private static function canManageDomains()
    {
        if (!function_exists('checkContactPermission') && defined('ROOTDIR')) {
            foreach (['/includes/clientfunctions.php', '/includes/clientareafunctions.php'] as $file) {
                if (!function_exists('checkContactPermission') && is_file(ROOTDIR . $file)) {
                    require_once ROOTDIR . $file;
                }
            }
        }
        if (function_exists('checkContactPermission')) {
            return (bool) checkContactPermission('managedomains', true);
        }
        // Fallback for WHMCS versions without checkContactPermission(): the account owner is always allowed.
        try {
            if (class_exists('\WHMCS\Authentication\CurrentUser')) {
                $current = new \WHMCS\Authentication\CurrentUser();
                $user = $current->user();
                $client = $current->client();
                if ($user && $client && method_exists($client, 'owner')) {
                    $owner = $client->owner();
                    if ($owner && (int) $owner->id === (int) $user->id) {
                        return true;
                    }
                }
            }
        } catch (\Throwable $e) {
            // fall through to deny
        }
        \SeekaHost\Forwarding\Logger::log('permission', 'Domain permission could not be confirmed for this user; access denied', 'warning');
        return false;
    }

    private function page($title, array $vars, $breadcrumb = [])
    {
        $flash = isset($_SESSION['shfwd_flash']) ? $_SESSION['shfwd_flash'] : null;
        unset($_SESSION['shfwd_flash']);

        return [
            'pagetitle' => $title,
            'breadcrumb' => array_merge(['clientarea.php?action=domains' => 'My Domains'], $breadcrumb),
            'templatefile' => 'templates/clientarea',
            'requirelogin' => true,
            'forcessl' => true,
            'vars' => array_merge([
                'modulelink' => $this->link,
                'flash' => $flash,
                'mode' => 'manage',
                'error' => null,
                'addonsLink' => null,
                'domainId' => null,
            ], $vars),
        ];
    }

    private function redirect($domainId, $type, $message, $extra = '')
    {
        $_SESSION['shfwd_flash'] = ['type' => $type, 'message' => $message];
        header('Location: ' . $this->link . '&id=' . (int) $domainId . $extra);
        exit;
    }

    public function dispatch()
    {
        $clientId = self::clientId();
        if (!$clientId) {
            return $this->page('Domain & Email Forwarding', ['mode' => 'error']);
        }
        if (!self::canManageDomains()) {
            return $this->page('Domain & Email Forwarding', ['mode' => 'error', 'error' => 'You do not have permission to manage domains on this account.']);
        }

        $domainId = isset($_REQUEST['id']) ? (int) $_REQUEST['id'] : 0;
        if (!$domainId) {
            return $this->listDomains($clientId);
        }

        $domainRow = Service::domainRow($domainId);
        if (!$domainRow || (int) $domainRow->userid !== $clientId) {
            return $this->page('Domain & Email Forwarding', ['mode' => 'error', 'error' => 'Domain not found.']);
        }

        list($ok, $reason, $code) = Service::access($domainRow);
        if (!$ok) {
            return $this->page('Domain & Email Forwarding', [
                'mode' => 'error',
                'error' => $reason,
                'domain' => Validator::toUnicode($domainRow->domain),
                'domainId' => $domainId,
                'addonsLink' => $code === 'addon' ? 'clientarea.php?action=domainaddons&id=' . $domainId : null,
            ], ['clientarea.php?action=domaindetails&id=' . $domainId => $domainRow->domain]);
        }

        try {
            $fwd = Service::fwdDomain($domainRow);
        } catch (\Throwable $e) {
            return $this->page('Domain & Email Forwarding', [
                'mode' => 'error',
                'error' => $e instanceof \InvalidArgumentException ? $e->getMessage() : 'Forwarding for this domain is temporarily unavailable. Please try again later.',
                'domainId' => $domainId,
            ]);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handlePost($fwd, $domainId);
        }

        return $this->manage($fwd);
    }

    private function handlePost($fwd, $domainId)
    {
        check_token('WHMCS.default');
        $in = Input::all();
        $action = isset($in['a']) ? $in['a'] : '';
        $id = !empty($in['fid']) ? (int) $in['fid'] : null;
        $back = '';
        if ($id && $action === 'save_url') {
            $back = '&edit_url=' . $id . '#shfwd-url-form';
        } elseif ($id && $action === 'save_email') {
            $back = '&edit_email=' . $id . '#shfwd-email-form';
        }
        $msg = null;
        try {
            switch ($action) {
                case 'save_url':
                    Service::saveUrl($fwd, $in, $id);
                    $msg = 'URL forwarder saved.';
                    break;
                case 'delete_url':
                    Service::deleteUrl($fwd, $id);
                    $msg = 'URL forwarder deleted.';
                    break;
                case 'save_email':
                    Service::saveEmail($fwd, $in, $id);
                    $msg = 'Email forwarder saved.';
                    break;
                case 'delete_email':
                    Service::deleteEmail($fwd, $id);
                    $msg = 'Email forwarder deleted.';
                    break;
                case 'catchall':
                    Service::setCatchAll($fwd, isset($in['catchall']) ? $in['catchall'] : '');
                    $msg = 'Catch-all updated.';
                    break;
                case 'domain_forward':
                    Service::setDomainForwarder($fwd, isset($in['domain_forward']) ? $in['domain_forward'] : '');
                    $msg = 'Domain email forwarding updated.';
                    break;
                case 'ssl':
                    if ($fwd->ssl_requested_at && time() - strtotime($fwd->ssl_requested_at) < self::SSL_THROTTLE_SECONDS) {
                        Validator::fail('An SSL certificate was requested recently. Please wait an hour before trying again.');
                    }
                    $fwd = Service::ensureProvisioned($fwd);
                    Service::requestSsl($fwd, true);
                    $msg = 'SSL certificate requested. It can take a few minutes to become active.';
                    break;
            }
        } catch (\InvalidArgumentException $e) {
            $this->redirect($domainId, 'error', $e->getMessage(), $back);
        } catch (\Throwable $e) {
            $this->redirect($domainId, 'error', $action === 'ssl'
                ? 'The SSL certificate could not be issued yet. Please make sure the DNS records below point to our server and try again later.'
                : 'We could not apply this change right now. Our team has been notified; please try again later.', $back);
        }
        if ($msg) {
            $this->redirect($domainId, 'success', $msg);
        }
    }

    private function manage($fwd)
    {
        $policy = Service::policy($fwd);
        $urls = Service::urls($fwd);
        $emails = Service::emails($fwd);
        foreach ($emails as $e) {
            $e->dest_list = Service::splitDestinations($e->destinations);
            $e->dest_text = implode(', ', $e->dest_list);
        }

        $editUrl = null;
        $editEmail = null;
        if (!empty($_GET['edit_url'])) {
            foreach ($urls as $u) {
                if ((int) $u->id === (int) $_GET['edit_url']) {
                    $editUrl = $u;
                }
            }
        }
        if (!empty($_GET['edit_email'])) {
            foreach ($emails as $e) {
                if ((int) $e->id === (int) $_GET['edit_email']) {
                    $editEmail = $e;
                }
            }
        }

        $types = Service::URL_TYPES;
        foreach (['302' => 'allow_302', '303' => 'allow_303', 'masked' => 'allow_masked'] as $type => $setting) {
            if (!Settings::on($setting)) {
                unset($types[$type]);
            }
        }

        $server = Service::server($fwd, $fwd->status !== 'active');
        $limits = Service::limits($fwd);
        $display = Validator::toUnicode($fwd->domain);

        return $this->page('Domain & Email Forwarding - ' . $display, [
            'domain' => $display,
            'domainId' => $fwd->domain_id,
            'status' => $fwd->status,
            'sslStatus' => $fwd->ssl_status,
            'urls' => $urls,
            'emails' => $emails,
            'catchall' => $fwd->catchall,
            'domainForward' => $fwd->domain_forward,
            'urlLimit' => $limits['url'],
            'emailLimit' => $limits['email'],
            'urlCount' => count($urls),
            'emailCount' => count($emails),
            'types' => $types,
            'wwwModes' => Service::WWW_MODES,
            'allowUrl' => $policy['url'],
            'allowPath' => $policy['path'],
            'allowWildcard' => $policy['wildcard'],
            'allowEmail' => $policy['email'],
            'allowCatchall' => $policy['catchall'],
            'allowDomainEmail' => $policy['domain_email'] && $server && Service::supportsDomainEmail($fwd),
            'parkOnly' => $policy['park_only'],
            'disabled' => in_array($fwd->status, ['disabled', 'migrating'], true),
            'migrating' => $fwd->status === 'migrating',
            'maxDestinations' => Settings::int('max_destinations'),
            'dns' => Settings::on('show_dns') ? DnsCheck::records($fwd->domain, $server) : [],
            'nameservers' => Settings::on('show_dns') ? DnsCheck::nameservers($fwd->domain, $server) : [],
            'serverReady' => (bool) $server,
            'editUrl' => $editUrl,
            'editEmail' => $editEmail,
        ], [
            'clientarea.php?action=domaindetails&id=' . $fwd->domain_id => $display,
            $this->link . '&id=' . $fwd->domain_id => 'Forwarding',
        ]);
    }

    private function listDomains($clientId)
    {
        $rows = Capsule::table('tbldomains')->where('userid', $clientId)->where('status', 'Active')->orderBy('domain')->get()->all();
        $domains = [];
        foreach ($rows as $row) {
            if (Service::visibleToClient($row)) {
                $domains[] = ['id' => $row->id, 'domain' => Validator::toUnicode($row->domain)];
            }
        }
        return $this->page('Domain & Email Forwarding', ['mode' => 'list', 'domains' => $domains]);
    }
}
