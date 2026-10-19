<?php

namespace SeekaHost\Forwarding\Panels;

class PanelFactory
{
    const TYPES = [
        'cpanel' => 'cPanel / WHM',
        'directadmin' => 'DirectAdmin',
        'plesk' => 'Plesk',
    ];

    /** @var callable|null test hook: fn($server) => PanelInterface */
    public static $factory;

    /** @return PanelInterface */
    public static function make($server)
    {
        if (self::$factory !== null) {
            return call_user_func(self::$factory, $server);
        }
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
