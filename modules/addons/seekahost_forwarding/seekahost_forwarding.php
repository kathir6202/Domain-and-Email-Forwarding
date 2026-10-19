<?php
/**
 * SeekaHost Domain & Email Forwarding - WHMCS Addon Module
 *
 * Self-contained addon: no WHMCS core files or templates are modified.
 * All data lives in tables prefixed "mod_seekahost_forwarding_".
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/bootstrap.php';

use SeekaHost\Forwarding\Admin\AdminController;
use SeekaHost\Forwarding\Client\ClientController;
use SeekaHost\Forwarding\Schema;

function seekahost_forwarding_config()
{
    return [
        'name' => 'SeekaHost Domain & Email Forwarding',
        'description' => 'Domain (URL) and email forwarding for domains registered with you, '
            . 'executed on cPanel/WHM, DirectAdmin or Plesk servers.',
        'author' => 'SeekaHost',
        'language' => 'english',
        'version' => SEEKAHOST_FWD_VERSION,
        'fields' => [
            'access_mode' => [
                'FriendlyName' => 'Access Mode',
                'Type' => 'dropdown',
                'Options' => [
                    'addon' => 'Domain addon required (WHMCS "Email Forwarding" domain addon)',
                    'all' => 'All active domains (free)',
                ],
                'Default' => 'addon',
                'Description' => 'Who can use forwarding.',
            ],
            'hide_if_hosting' => [
                'FriendlyName' => 'Hide When Hosting Exists',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'Hide forwarding for domains that have an active/suspended hosting service in WHMCS.',
            ],
            'replace_native' => [
                'FriendlyName' => 'Replace Native Email Forwarding Page',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'Redirect the native WHMCS "Email Forwarding" page and sidebar link to this module.',
            ],
            'url_limit' => [
                'FriendlyName' => 'Default URL Forwarders Limit',
                'Type' => 'text',
                'Size' => '5',
                'Default' => '10',
                'Description' => 'Per domain (0 = unlimited). Can be overridden per domain.',
            ],
            'email_limit' => [
                'FriendlyName' => 'Default Email Forwarders Limit',
                'Type' => 'text',
                'Size' => '5',
                'Default' => '25',
                'Description' => 'Per domain (0 = unlimited). Can be overridden per domain.',
            ],
            'max_destinations' => [
                'FriendlyName' => 'Max Destinations per Email Alias',
                'Type' => 'text',
                'Size' => '5',
                'Default' => '5',
            ],
            'allow_302' => ['FriendlyName' => 'Allow 302 (Temporary)', 'Type' => 'yesno', 'Default' => 'on'],
            'allow_303' => ['FriendlyName' => 'Allow 303 (See Other)', 'Type' => 'yesno', 'Default' => 'on'],
            'allow_masked' => ['FriendlyName' => 'Allow Masked (Frame) Forwarding', 'Type' => 'yesno', 'Default' => 'on'],
            'allow_path' => ['FriendlyName' => 'Allow Path Forwarding', 'Type' => 'yesno', 'Default' => 'on'],
            'allow_wildcard' => ['FriendlyName' => 'Allow Wildcard Forwarding', 'Type' => 'yesno', 'Default' => 'on'],
            'allow_catchall' => ['FriendlyName' => 'Allow Catch-All Email', 'Type' => 'yesno', 'Default' => 'on'],
            'allow_domain_email' => [
                'FriendlyName' => 'Allow Domain Email Forwarding',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'Forward all mail of a domain to the same address at another domain (cPanel servers only).',
            ],
            'auto_forwarders' => [
                'FriendlyName' => 'Automatic Forwarders',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'Run the per-TLD automatic action (park / create forwarder) after registration or transfer.',
            ],
            'parking_html' => [
                'FriendlyName' => 'Parking Page HTML',
                'Type' => 'textarea',
                'Rows' => '4',
                'Description' => 'Shown for parked domains without URL forwarders. {domain} is replaced. Empty = built-in page.',
            ],
            'auto_ssl' => [
                'FriendlyName' => 'Auto Request SSL (Let\'s Encrypt)',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'Request a certificate on activation and retry daily until issued.',
            ],
            'le_email' => [
                'FriendlyName' => 'Let\'s Encrypt Email (Plesk)',
                'Type' => 'text',
                'Size' => '40',
            ],
            'show_dns' => [
                'FriendlyName' => 'Show DNS Instructions',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'Show required A/MX records and their live status to the client.',
            ],
            'notify_client' => ['FriendlyName' => 'Email Client on Changes', 'Type' => 'yesno', 'Default' => 'on'],
            'notify_admin_errors' => ['FriendlyName' => 'Email Admins on Errors', 'Type' => 'yesno', 'Default' => 'on'],
            'api_enabled' => [
                'FriendlyName' => 'Enable API',
                'Type' => 'yesno',
                'Description' => 'Allow token-authenticated API access (manage tokens under Addons &gt; this module &gt; API).',
            ],
            'delete_on_deactivate' => [
                'FriendlyName' => 'Delete Data on Deactivate',
                'Type' => 'yesno',
                'Description' => 'Drop module tables and email template when the module is deactivated. Domains are NOT removed from the servers.',
            ],
        ],
    ];
}

function seekahost_forwarding_activate()
{
    try {
        Schema::install();
        return ['status' => 'success', 'description' => 'SeekaHost Domain & Email Forwarding activated.'];
    } catch (\Throwable $e) {
        return ['status' => 'error', 'description' => 'Activation failed: ' . $e->getMessage()];
    }
}

function seekahost_forwarding_deactivate()
{
    try {
        $settings = \SeekaHost\Forwarding\Settings::all();
        if (!empty($settings['delete_on_deactivate'])) {
            Schema::uninstall();
        }
        return ['status' => 'success', 'description' => 'SeekaHost Domain & Email Forwarding deactivated.'];
    } catch (\Throwable $e) {
        return ['status' => 'error', 'description' => 'Deactivation failed: ' . $e->getMessage()];
    }
}

function seekahost_forwarding_upgrade($vars)
{
    try {
        Schema::install();
    } catch (\Throwable $e) {
        \SeekaHost\Forwarding\Logger::log('upgrade', 'Schema upgrade failed: ' . $e->getMessage(), 'error', null, null, 'system');
    }
}

function seekahost_forwarding_output($vars)
{
    (new AdminController($vars))->dispatch();
}

function seekahost_forwarding_clientarea($vars)
{
    return (new ClientController($vars))->dispatch();
}
