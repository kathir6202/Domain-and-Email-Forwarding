<?php

namespace SeekaHost\Forwarding\Panels;

class PanelFactory
{
    const TYPES = [
        'cpanel' => 'cPanel / WHM',
        'directadmin' => 'DirectAdmin',
        'plesk' => 'Plesk',
    ];

    /** @return PanelInterface */
    public static function make($server)
    {
        switch ($server->type) {
            case 'cpanel':
                return new Cpanel($server);
            case 'directadmin':
                return new DirectAdmin($server);
            case 'plesk':
                return new Plesk($server);
        }
        throw new \RuntimeException('Unsupported server type: ' . $server->type);
    }
}
