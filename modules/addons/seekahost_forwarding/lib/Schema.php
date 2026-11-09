<?php

namespace SeekaHost\Forwarding;

use WHMCS\Database\Capsule;

class Schema
{
    const T_SERVERS = 'mod_seekahost_forwarding_servers';
    const T_DOMAINS = 'mod_seekahost_forwarding_domains';
    const T_URL = 'mod_seekahost_forwarding_url';
    const T_EMAIL = 'mod_seekahost_forwarding_email';
    const T_LOGS = 'mod_seekahost_forwarding_logs';
    const T_TLDS = 'mod_seekahost_forwarding_tlds';
    const T_TOKENS = 'mod_seekahost_forwarding_api_tokens';
    const T_MIGRATION = 'mod_seekahost_forwarding_migration';

    const EMAIL_TEMPLATE = 'SeekaHost Forwarding Notification';

    /**
     * Idempotent: safe to run on activate and on every upgrade.
     */
    public static function install()
    {
        $schema = Capsule::schema();

        if (!$schema->hasTable(self::T_SERVERS)) {
            $schema->create(self::T_SERVERS, function ($t) {
                $t->increments('id');
                $t->string('name', 100);
                $t->string('type', 20);
                $t->string('hostname', 255);
                $t->integer('port')->nullable();
                $t->boolean('secure')->default(1);
                $t->boolean('verify_ssl')->default(0);
                $t->string('username', 100)->default('');
                $t->text('password')->nullable();
                $t->text('api_token')->nullable();
                $t->string('account', 255)->default('');
                $t->string('web_ip', 64)->default('');
                $t->string('mail_host', 255)->default('');
                $t->string('ftp_host', 255)->default('');
                $t->integer('ftp_port')->nullable();
                $t->string('ftp_user', 100)->default('');
                $t->text('ftp_pass')->nullable();
                $t->boolean('ftp_ssl')->default(0);
                $t->boolean('is_default')->default(0);
                $t->boolean('active')->default(1);
                $t->text('meta')->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
            });
        }

        if (!$schema->hasTable(self::T_DOMAINS)) {
            $schema->create(self::T_DOMAINS, function ($t) {
                $t->increments('id');
                $t->unsignedInteger('domain_id')->unique();
                $t->unsignedInteger('client_id')->index();
                $t->string('domain', 191)->index();
                $t->unsignedInteger('server_id')->nullable()->index();
                $t->string('status', 20)->default('pending')->index();
                $t->boolean('parked')->default(0);
                $t->text('remote_ref')->nullable();
                $t->string('ssl_status', 20)->nullable();
                $t->dateTime('ssl_requested_at')->nullable();
                $t->integer('url_limit')->nullable();
                $t->integer('email_limit')->nullable();
                $t->string('catchall', 255)->nullable();
                $t->string('domain_forward', 191)->nullable();
                $t->text('last_error')->nullable();
                $t->dateTime('last_sync')->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
            });
        }

        if (!$schema->hasTable(self::T_URL)) {
            $schema->create(self::T_URL, function ($t) {
                $t->increments('id');
                $t->unsignedInteger('fwd_domain_id')->index();
                $t->string('source_path', 191)->default('/');
                $t->text('destination');
                $t->string('type', 10)->default('301');
                $t->string('www_mode', 10)->default('both');
                $t->boolean('wildcard')->default(0);
                $t->boolean('preserve_query')->default(1);
                $t->boolean('auto')->default(0);
                $t->string('title', 255)->nullable();
                $t->text('meta_description')->nullable();
                $t->text('meta_keywords')->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->unique(['fwd_domain_id', 'source_path'], 'shfwd_url_unique');
            });
        }

        if (!$schema->hasTable(self::T_EMAIL)) {
            $schema->create(self::T_EMAIL, function ($t) {
                $t->increments('id');
                $t->unsignedInteger('fwd_domain_id')->index();
                $t->string('local_part', 64);
                $t->text('destinations');
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->unique(['fwd_domain_id', 'local_part'], 'shfwd_email_unique');
            });
        }

        if (!$schema->hasTable(self::T_LOGS)) {
            $schema->create(self::T_LOGS, function ($t) {
                $t->increments('id');
                $t->unsignedInteger('client_id')->nullable()->index();
                $t->unsignedInteger('domain_id')->nullable()->index();
                $t->string('actor', 64)->default('system');
                $t->string('level', 10)->default('info')->index();
                $t->string('action', 64);
                $t->text('message');
                $t->dateTime('created_at')->nullable();
            });
        }

        if (!$schema->hasTable(self::T_TLDS)) {
            $schema->create(self::T_TLDS, function ($t) {
                $t->increments('id');
                $t->string('tld', 64)->unique();
                $t->boolean('allow_url')->default(1);
                $t->boolean('allow_path')->default(1);
                $t->boolean('allow_email')->default(1);
                $t->boolean('allow_domain_email')->default(1);
                $t->boolean('allow_catchall')->default(1);
                $t->boolean('park_only')->default(0);
                $t->string('auto_action', 10)->default('none');
                $t->text('auto_url')->nullable();
                $t->string('auto_type', 10)->default('301');
                $t->dateTime('updated_at')->nullable();
            });
        }

        if (!$schema->hasTable(self::T_TOKENS)) {
            $schema->create(self::T_TOKENS, function ($t) {
                $t->increments('id');
                $t->string('name', 100);
                $t->string('token_hash', 64)->unique();
                $t->string('token_hint', 12)->default('');
                $t->text('allowed_ips')->nullable();
                $t->boolean('active')->default(1);
                $t->dateTime('last_used_at')->nullable();
                $t->string('last_ip', 64)->nullable();
                $t->dateTime('created_at')->nullable();
            });
        }

        if (!$schema->hasTable(self::T_MIGRATION)) {
            $schema->create(self::T_MIGRATION, function ($t) {
                $t->increments('id');
                $t->unsignedInteger('server_id')->index();
                $t->string('domain', 191)->unique();
                $t->string('kind', 10)->default('parked');
                $t->unsignedInteger('domain_id')->nullable();
                $t->unsignedInteger('client_id')->nullable();
                $t->string('state', 20)->default('discovered')->index();
                $t->boolean('blocking')->default(0);
                $t->text('issues')->nullable();
                $t->longText('snapshot')->nullable();
                $t->longText('mapping')->nullable();
                $t->longText('result')->nullable();
                $t->text('message')->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
            });
        }

        self::migrate();
        self::installEmailTemplate();
    }

    /** Columns/indexes added after 1.0.0. */
    private static function migrate()
    {
        $schema = Capsule::schema();
        $add = function ($table, $column, callable $definition) use ($schema) {
            if (!$schema->hasColumn($table, $column)) {
                $schema->table($table, $definition);
            }
        };
        $add(self::T_SERVERS, 'verify_ssl', function ($t) {
            $t->boolean('verify_ssl')->default(0);
        });
        $add(self::T_DOMAINS, 'parked', function ($t) {
            $t->boolean('parked')->default(0);
        });
        $add(self::T_DOMAINS, 'ssl_requested_at', function ($t) {
            $t->dateTime('ssl_requested_at')->nullable();
        });
        $add(self::T_DOMAINS, 'domain_forward', function ($t) {
            $t->string('domain_forward', 191)->nullable();
        });
        $add(self::T_URL, 'www_mode', function ($t) {
            $t->string('www_mode', 10)->default('both');
        });
        $add(self::T_URL, 'auto', function ($t) {
            $t->boolean('auto')->default(0);
        });

        foreach ([[self::T_DOMAINS, 'domain'], [self::T_DOMAINS, 'status'], [self::T_DOMAINS, 'server_id'], [self::T_LOGS, 'level']] as $idx) {
            self::ensureIndex($idx[0], $idx[1]);
        }
    }

    private static function ensureIndex($table, $column)
    {
        try {
            $prefix = Capsule::connection()->getTablePrefix();
            $exists = Capsule::select('SHOW INDEX FROM `' . $prefix . $table . '` WHERE Column_name = ?', [$column]);
            if (!$exists) {
                Capsule::schema()->table($table, function ($t) use ($column) {
                    $t->index($column);
                });
            }
        } catch (\Throwable $e) {
            // Index is an optimisation only.
        }
    }

    /**
     * Drops module data. Forwarded domains are NOT removed from the panel servers;
     * use "Remove from Server" first if that is wanted.
     */
    public static function uninstall()
    {
        $schema = Capsule::schema();
        foreach ([self::T_MIGRATION, self::T_TOKENS, self::T_TLDS, self::T_LOGS, self::T_EMAIL, self::T_URL, self::T_DOMAINS, self::T_SERVERS] as $table) {
            $schema->dropIfExists($table);
        }
        Capsule::table('tblemailtemplates')->where('name', self::EMAIL_TEMPLATE)->delete();
    }

    private static function installEmailTemplate()
    {
        if (Capsule::table('tblemailtemplates')->where('name', self::EMAIL_TEMPLATE)->exists()) {
            return;
        }
        $message = '<p>Dear {$client_name},</p>'
            . '<p>The forwarding settings for your domain <strong>{$domain_name}</strong> have been updated.</p>'
            . '<p><strong>{$fwd_action}</strong><br />{$fwd_details}</p>'
            . '<p>If you did not make this change, please contact our support team.</p>'
            . '<p>{$signature}</p>';

        $subject = 'Forwarding updated for {$domain_name}';
        try {
            $template = new \WHMCS\Mail\Template();
            $template->type = 'domain';
            $template->name = self::EMAIL_TEMPLATE;
            $template->subject = $subject;
            $template->message = $message;
            $template->custom = true;
            $template->save();
        } catch (\Throwable $e) {
            Capsule::table('tblemailtemplates')->insert([
                'type' => 'domain',
                'name' => self::EMAIL_TEMPLATE,
                'subject' => $subject,
                'message' => $message,
                'attachments' => '',
                'fromname' => '',
                'fromemail' => '',
                'disabled' => 0,
                'custom' => 1,
                'language' => '',
                'copyto' => '',
                'blind_copy_to' => '',
                'plaintext' => 0,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }
}
