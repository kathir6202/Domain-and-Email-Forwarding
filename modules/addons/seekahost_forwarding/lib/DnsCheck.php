<?php

namespace SeekaHost\Forwarding;

class DnsCheck
{
    /**
     * Records the client must have for forwarding to work, with live status.
     * @return array[] each: type, host, value, ok
     */
    public static function records($domain, $server)
    {
        if (!$server) {
            return [];
        }
        $rows = [];
        if ($server->web_ip !== '') {
            foreach ([$domain, 'www.' . $domain] as $host) {
                $found = [];
                foreach ((array) @dns_get_record($host, DNS_A) as $rec) {
                    if (isset($rec['ip'])) {
                        $found[] = $rec['ip'];
                    }
                }
                $rows[] = [
                    'type' => 'A',
                    'host' => $host === $domain ? '@' : 'www',
                    'value' => $server->web_ip,
                    'ok' => in_array($server->web_ip, $found, true),
                ];
            }
        }
        if ($server->mail_host !== '') {
            $found = [];
            foreach ((array) @dns_get_record($domain, DNS_MX) as $rec) {
                if (isset($rec['target'])) {
                    $found[] = strtolower(rtrim($rec['target'], '.'));
                }
            }
            $rows[] = [
                'type' => 'MX (priority 10)',
                'host' => '@',
                'value' => $server->mail_host,
                'ok' => in_array(strtolower($server->mail_host), $found, true),
            ];
        }
        return $rows;
    }
}
