{* SeekaHost Domain & Email Forwarding - client area (Bootstrap markup, Lagom compatible) *}
<style>
.shfwd .shfwd-box{border:1px solid rgba(0,0,0,.1);border-radius:6px;padding:20px;margin-bottom:24px;background:#fff}
.shfwd .shfwd-box h3{margin-top:0;font-size:18px}
.shfwd .shfwd-muted{color:#888;font-size:12px}
.shfwd .shfwd-badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;background:#eee}
.shfwd .shfwd-ok{background:#dff0d8;color:#2b6a2b}
.shfwd .shfwd-bad{background:#f2dede;color:#8a2a2a}
.shfwd table td,.shfwd table th{vertical-align:middle!important}
.shfwd .shfwd-inline{display:inline}
.shfwd .shfwd-masked{display:none}
</style>

<div class="shfwd">

{if $flash}
    <div class="alert alert-{if $flash.type eq 'success'}success{else}danger{/if}">{$flash.message|escape}</div>
{/if}

{if $mode eq 'error'}
    <div class="alert alert-warning">{if $error}{$error|escape}{else}Please log in to continue.{/if}</div>
    {if $addonsLink}<a href="{$addonsLink}" class="btn btn-primary">View Domain Addons</a>{/if}
    {if $domainId}<a href="clientarea.php?action=domaindetails&id={$domainId}" class="btn btn-default">Back to Domain</a>{/if}

{elseif $mode eq 'list'}
    <div class="shfwd-box">
        <h3>Domain &amp; Email Forwarding</h3>
        {if $domains}
            <table class="table table-striped">
                <thead><tr><th>Domain</th><th class="text-right"></th></tr></thead>
                <tbody>
                {foreach $domains as $d}
                    <tr><td>{$d.domain|escape}</td><td class="text-right"><a class="btn btn-primary btn-sm" href="{$modulelink}&id={$d.id}">Manage Forwarding</a></td></tr>
                {/foreach}
                </tbody>
            </table>
        {else}
            <p>None of your domains have Domain &amp; Email Forwarding enabled. You can add it from a domain's addons page.</p>
        {/if}
    </div>

{else}
    {if !$serverReady}
        <div class="alert alert-warning">Forwarding is being set up for our platform. Please try again later.</div>
    {/if}

    {* ---------------------------------------------------------------- URL *}
    <div class="shfwd-box">
        <h3>Domain (URL) Forwarding <span class="shfwd-muted">{$urlCount}{if $urlLimit} / {$urlLimit}{/if} used</span></h3>

        {if $urls}
        <div class="table-responsive">
        <table class="table table-striped">
            <thead><tr><th>Source</th><th>Destination</th><th>Type</th><th>Options</th><th class="text-right"></th></tr></thead>
            <tbody>
            {foreach $urls as $u}
                <tr>
                    <td>{$domain|escape}{$u->source_path|escape}</td>
                    <td style="word-break:break-all">{$u->destination|escape}</td>
                    <td>{$types[$u->type]|default:$u->type|escape}</td>
                    <td>
                        {if $u->wildcard}<span class="shfwd-badge">Wildcard</span>{/if}
                        {if $u->preserve_query}<span class="shfwd-badge">Keep query</span>{/if}
                    </td>
                    <td class="text-right" style="white-space:nowrap">
                        <a class="btn btn-default btn-sm" href="{$modulelink}&id={$domainId}&edit_url={$u->id}#shfwd-url-form">Edit</a>
                        <form method="post" action="{$modulelink}&id={$domainId}" class="shfwd-inline" onsubmit="return confirm('Delete this forwarder?');">
                            <input type="hidden" name="token" value="{$token}">
                            <input type="hidden" name="a" value="delete_url">
                            <input type="hidden" name="fid" value="{$u->id}">
                            <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                        </form>
                    </td>
                </tr>
            {/foreach}
            </tbody>
        </table>
        </div>
        {else}
            <p class="shfwd-muted">No URL forwarders yet.</p>
        {/if}

        {if $editUrl || !$urlLimit || $urlCount < $urlLimit}
        <h4 id="shfwd-url-form">{if $editUrl}Edit URL Forwarder{else}Add URL Forwarder{/if}</h4>
        <form method="post" action="{$modulelink}&id={$domainId}">
            <input type="hidden" name="token" value="{$token}">
            <input type="hidden" name="a" value="save_url">
            {if $editUrl}<input type="hidden" name="fid" value="{$editUrl->id}">{/if}
            <div class="row">
                <div class="col-sm-4 form-group">
                    <label>Source</label>
                    <div class="input-group">
                        <span class="input-group-addon input-group-text">{$domain|escape}</span>
                        <input type="text" name="source_path" class="form-control" value="{if $editUrl}{$editUrl->source_path|escape}{else}/{/if}" {if !$allowPath}readonly{/if} placeholder="/">
                    </div>
                    <span class="shfwd-muted">Use "/" for the whole domain{if $allowPath}, or a path such as /blog{/if}.</span>
                </div>
                <div class="col-sm-5 form-group">
                    <label>Destination URL</label>
                    <input type="text" name="destination" class="form-control" required value="{if $editUrl}{$editUrl->destination|escape}{/if}" placeholder="https://www.example.com/">
                </div>
                <div class="col-sm-3 form-group">
                    <label>Type</label>
                    <select name="type" class="form-control" id="shfwd-type">
                        {foreach $types as $k => $label}
                            <option value="{$k}" {if $editUrl && $editUrl->type eq $k}selected{/if}>{$label}</option>
                        {/foreach}
                    </select>
                </div>
            </div>
            <div class="form-group">
                {if $allowWildcard}
                <label class="checkbox-inline" style="margin-right:20px">
                    <input type="checkbox" name="wildcard" value="1" {if $editUrl && $editUrl->wildcard}checked{/if}>
                    Wildcard (forward sub-paths, e.g. /page &rarr; destination/page)
                </label>
                {/if}
                <label class="checkbox-inline">
                    <input type="checkbox" name="preserve_query" value="1" {if !$editUrl || $editUrl->preserve_query}checked{/if}>
                    Keep query string
                </label>
            </div>
            <div class="shfwd-masked" id="shfwd-masked">
                <div class="row">
                    <div class="col-sm-4 form-group"><label>Page Title</label><input type="text" name="title" class="form-control" maxlength="255" value="{if $editUrl}{$editUrl->title|escape}{/if}"></div>
                    <div class="col-sm-4 form-group"><label>Meta Description</label><input type="text" name="meta_description" class="form-control" value="{if $editUrl}{$editUrl->meta_description|escape}{/if}"></div>
                    <div class="col-sm-4 form-group"><label>Meta Keywords</label><input type="text" name="meta_keywords" class="form-control" value="{if $editUrl}{$editUrl->meta_keywords|escape}{/if}"></div>
                </div>
                <p class="shfwd-muted">Masked forwarding keeps your domain in the address bar. Some sites refuse to be displayed in a frame.</p>
            </div>
            <button type="submit" class="btn btn-primary">{if $editUrl}Save Changes{else}Add Forwarder{/if}</button>
            {if $editUrl}<a href="{$modulelink}&id={$domainId}" class="btn btn-default">Cancel</a>{/if}
        </form>
        {else}
            <p class="shfwd-muted">You have reached the URL forwarder limit for this domain.</p>
        {/if}
    </div>

    {* -------------------------------------------------------------- Email *}
    <div class="shfwd-box">
        <h3>Email Forwarding <span class="shfwd-muted">{$emailCount}{if $emailLimit} / {$emailLimit}{/if} used</span></h3>

        {if $emails}
        <div class="table-responsive">
        <table class="table table-striped">
            <thead><tr><th>Address</th><th>Forwards To</th><th class="text-right"></th></tr></thead>
            <tbody>
            {foreach $emails as $e}
                <tr>
                    <td>{$e->local_part|escape}@{$domain|escape}</td>
                    <td>{foreach $e->dest_list as $dest}{$dest|escape}<br>{/foreach}</td>
                    <td class="text-right" style="white-space:nowrap">
                        <a class="btn btn-default btn-sm" href="{$modulelink}&id={$domainId}&edit_email={$e->id}#shfwd-email-form">Edit</a>
                        <form method="post" action="{$modulelink}&id={$domainId}" class="shfwd-inline" onsubmit="return confirm('Delete this email forwarder?');">
                            <input type="hidden" name="token" value="{$token}">
                            <input type="hidden" name="a" value="delete_email">
                            <input type="hidden" name="fid" value="{$e->id}">
                            <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                        </form>
                    </td>
                </tr>
            {/foreach}
            </tbody>
        </table>
        </div>
        {else}
            <p class="shfwd-muted">No email forwarders yet.</p>
        {/if}

        {if $editEmail || !$emailLimit || $emailCount < $emailLimit}
        <h4 id="shfwd-email-form">{if $editEmail}Edit Email Forwarder{else}Add Email Forwarder{/if}</h4>
        <form method="post" action="{$modulelink}&id={$domainId}">
            <input type="hidden" name="token" value="{$token}">
            <input type="hidden" name="a" value="save_email">
            {if $editEmail}<input type="hidden" name="fid" value="{$editEmail->id}">{/if}
            <div class="row">
                <div class="col-sm-5 form-group">
                    <label>Alias</label>
                    <div class="input-group">
                        <input type="text" name="local_part" class="form-control" required value="{if $editEmail}{$editEmail->local_part|escape}{/if}" {if $editEmail}readonly{/if} placeholder="info">
                        <span class="input-group-addon input-group-text">@{$domain|escape}</span>
                    </div>
                </div>
                <div class="col-sm-7 form-group">
                    <label>Forward To</label>
                    <input type="text" name="destinations" class="form-control" required value="{if $editEmail}{$editEmail->dest_text|escape}{/if}" placeholder="you@gmail.com, team@example.com">
                    <span class="shfwd-muted">Separate multiple addresses with commas (max {$maxDestinations}).</span>
                </div>
            </div>
            <button type="submit" class="btn btn-primary">{if $editEmail}Save Changes{else}Add Forwarder{/if}</button>
            {if $editEmail}<a href="{$modulelink}&id={$domainId}" class="btn btn-default">Cancel</a>{/if}
        </form>
        {else}
            <p class="shfwd-muted">You have reached the email forwarder limit for this domain.</p>
        {/if}

        {if $allowCatchall}
        <hr>
        <h4>Catch-All</h4>
        <p class="shfwd-muted">Forward email sent to any other address at {$domain|escape}. Leave empty to reject unknown addresses.</p>
        <form method="post" action="{$modulelink}&id={$domainId}" class="form-inline">
            <input type="hidden" name="token" value="{$token}">
            <input type="hidden" name="a" value="catchall">
            <input type="email" name="catchall" class="form-control" style="min-width:280px" value="{$catchall|escape}" placeholder="you@example.com">
            <button type="submit" class="btn btn-primary">Save</button>
        </form>
        {/if}
    </div>

    {* ---------------------------------------------------------- DNS / SSL *}
    {if $dns}
    <div class="shfwd-box">
        <h3>DNS Records</h3>
        <p class="shfwd-muted">For forwarding to work, your domain must have these records. Update them from DNS Management if needed. Changes can take a few hours to propagate.</p>
        <table class="table">
            <thead><tr><th>Type</th><th>Host</th><th>Value</th><th>Status</th></tr></thead>
            <tbody>
            {foreach $dns as $r}
                <tr>
                    <td>{$r.type}</td><td>{$r.host}</td><td>{$r.value|escape}</td>
                    <td>{if $r.ok}<span class="shfwd-badge shfwd-ok">OK</span>{else}<span class="shfwd-badge shfwd-bad">Not detected</span>{/if}</td>
                </tr>
            {/foreach}
            </tbody>
        </table>
    </div>
    {/if}

    {if $status eq 'active'}
    <div class="shfwd-box">
        <h3>HTTPS</h3>
        <p>
            SSL status:
            {if $sslStatus eq 'issued'}<span class="shfwd-badge shfwd-ok">Issued</span>
            {elseif $sslStatus eq 'requested'}<span class="shfwd-badge shfwd-ok">Requested</span>
            {elseif $sslStatus eq 'failed'}<span class="shfwd-badge shfwd-bad">Pending DNS</span>
            {else}<span class="shfwd-badge">Not requested</span>{/if}
        </p>
        <form method="post" action="{$modulelink}&id={$domainId}">
            <input type="hidden" name="token" value="{$token}">
            <input type="hidden" name="a" value="ssl">
            <button type="submit" class="btn btn-default">Request SSL Certificate</button>
        </form>
    </div>
    {/if}

    <a href="clientarea.php?action=domaindetails&id={$domainId}" class="btn btn-default">Back to Domain</a>

    <script>
    (function () {
        var sel = document.getElementById('shfwd-type'), box = document.getElementById('shfwd-masked');
        if (!sel || !box) return;
        function toggle() { box.style.display = sel.value === 'masked' ? 'block' : 'none'; }
        sel.addEventListener('change', toggle); toggle();
    })();
    </script>
{/if}
</div>
