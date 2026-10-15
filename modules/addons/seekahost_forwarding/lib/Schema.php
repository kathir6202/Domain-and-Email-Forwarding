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
                $t->string('domain', 255);
                $t->unsignedInteger('server_id')->nullable();
                $t->string('status', 20)->default('pending');
                $t->text('remote_ref')->nullable();
                $t->string('ssl_status', 20)->nullable();
                $t->integer('url_limit')->nullable();
                $t->integer('email_limit')->nullable();
                $t->string('catchall', 255)->nullable();
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
                $t->boolean('wildcard')->default(0);
                $t->boolean('preserve_query')->default(1);
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
                $t->string('actor', 20)->default('system');
                $t->string('level', 10)->default('info');
                $t->string('action', 64);
                $t->text('message');
                $t->dateTime('created_at')->nullable();
            });
        }

        self::installEmailTemplate();
    }

    public static function uninstall()
    {
        $schema = Capsule::schema();
        foreach ([self::T_LOGS, self::T_EMAIL, self::T_URL, self::T_DOMAINS, self::T_SERVERS] as $table) {
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
