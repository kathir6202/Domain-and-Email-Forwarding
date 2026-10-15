<?php

namespace SeekaHost\Forwarding\Client;

use SeekaHost\Forwarding\DnsCheck;
use SeekaHost\Forwarding\Service;
use SeekaHost\Forwarding\Settings;
use WHMCS\Database\Capsule;

class ClientController
{
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
            ], $vars),
        ];
    }

    private function redirect($domainId, $type, $message)
    {
        $_SESSION['shfwd_flash'] = ['type' => $type, 'message' => $message];
        header('Location: ' . $this->link . '&id=' . (int) $domainId);
        exit;
    }

    public function dispatch()
    {
        $clientId = self::clientId();
        if (!$clientId) {
            return $this->page('Domain & Email Forwarding', ['mode' => 'error']);
        }

        $domainId = isset($_REQUEST['id']) ? (int) $_REQUEST['id'] : 0;
        if (!$domainId) {
            return $this->listDomains($clientId);
        }

        $domainRow = Service::domainRow($domainId);
        if (!$domainRow || (int) $domainRow->userid !== $clientId) {
            return $this->page('Domain & Email Forwarding', ['mode' => 'error', 'error' => 'Domain not found.']);
        }

        list($eligible, $reason) = Service::eligibility($domainRow);
        if ($eligible && Settings::on('hide_if_hosting') && Service::hasHosting($domainRow)) {
            $eligible = false;
            $reason = 'This domain has a hosting service with us. Please manage forwarding from your hosting control panel.';
        }
        if (!$eligible) {
            return $this->page('Domain & Email Forwarding', [
                'mode' => 'error',
                'error' => $reason,
                'domain' => $domainRow->domain,
                'domainId' => $domainId,
                'addonsLink' => 'clientarea.php?action=domainaddons&id=' . $domainId,
            ], ['clientarea.php?action=domaindetails&id=' . $domainId => $domainRow->domain]);
        }

        $fwd = Service::fwdDomain($domainRow);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handlePost($fwd, $domainId);
        }

        return $this->manage($fwd, $domainRow);
    }

    private function handlePost($fwd, $domainId)
    {
        check_token('WHMCS.default');
        $action = isset($_POST['a']) ? $_POST['a'] : '';
        $id = isset($_POST['fid']) ? (int) $_POST['fid'] : null;
        try {
            switch ($action) {
                case 'save_url':
                    Service::saveUrl($fwd, $_POST, $id);
                    $msg = 'URL forwarder saved.';
                    break;
                case 'delete_url':
                    Service::deleteUrl($fwd, $id);
                    $msg = 'URL forwarder deleted.';
                    break;
                case 'save_email':
                    Service::saveEmail($fwd, $_POST, $id);
                    $msg = 'Email forwarder saved.';
                    break;
                case 'delete_email':
                    Service::deleteEmail($fwd, $id);
                    $msg = 'Email forwarder deleted.';
                    break;
                case 'catchall':
                    Service::setCatchAll($fwd, isset($_POST['catchall']) ? $_POST['catchall'] : '');
                    $msg = 'Catch-all updated.';
                    break;
                case 'ssl':
                    $fwd = Service::ensureProvisioned($fwd);
                    Service::requestSsl($fwd);
                    $msg = 'SSL certificate requested. It can take a few minutes to become active.';
                    break;
                default:
                    $msg = null;
            }
        } catch (\InvalidArgumentException $e) {
            $this->redirect($domainId, 'error', $e->getMessage());
        } catch (\Throwable $e) {
            $this->redirect($domainId, 'error', $action === 'ssl'
                ? 'The SSL certificate could not be issued yet. Please make sure the DNS records below point to our server and try again later.'
                : 'We could not apply this change right now. Our team has been notified; please try again later.');
        }
        if ($msg) {
            $this->redirect($domainId, 'success', $msg);
        }
    }

    private function manage($fwd, $domainRow)
    {
        $urls = Service::urls($fwd);
        $emails = Service::emails($fwd);
        foreach ($emails as $e) {
            $e->dest_list = Service::splitDestinations($e->destinations);
            $e->dest_text = implode(", ", $e->dest_list);
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
        if (!Settings::on('allow_302')) {
            unset($types['302']);
        }
        if (!Settings::on('allow_masked')) {
            unset($types['masked']);
        }

        $server = Service::server($fwd);
        $limits = Service::limits($fwd);

        return $this->page('Domain & Email Forwarding - ' . $fwd->domain, [
            'domain' => $fwd->domain,
            'domainId' => $fwd->domain_id,
            'status' => $fwd->status,
            'sslStatus' => $fwd->ssl_status,
            'urls' => $urls,
            'emails' => $emails,
            'catchall' => $fwd->catchall,
            'urlLimit' => $limits['url'],
            'emailLimit' => $limits['email'],
            'urlCount' => count($urls),
            'emailCount' => count($emails),
            'types' => $types,
            'allowPath' => Settings::on('allow_path'),
            'allowWildcard' => Settings::on('allow_wildcard'),
            'allowCatchall' => Settings::on('allow_catchall'),
            'maxDestinations' => Settings::int('max_destinations'),
            'dns' => Settings::on('show_dns') ? DnsCheck::records($fwd->domain, $server) : [],
            'serverReady' => (bool) $server,
            'editUrl' => $editUrl,
            'editEmail' => $editEmail,
        ], [
            'clientarea.php?action=domaindetails&id=' . $fwd->domain_id => $fwd->domain,
            $this->link . '&id=' . $fwd->domain_id => 'Forwarding',
        ]);
    }

    private function listDomains($clientId)
    {
        $rows = Capsule::table('tbldomains')->where('userid', $clientId)->where('status', 'Active')->orderBy('domain')->get()->all();
        $domains = [];
        foreach ($rows as $row) {
            if (Service::visibleToClient($row)) {
                $domains[] = ['id' => $row->id, 'domain' => $row->domain];
            }
        }
        return $this->page('Domain & Email Forwarding', ['mode' => 'list', 'domains' => $domains]);
    }
}
