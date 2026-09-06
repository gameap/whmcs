{* GameAP service panel. Rendered from a cached snapshot: the module talks to
   the panel at most once a minute and with a short timeout, so a slow or
   unreachable panel never makes this page hang. Strings come from the
   module's lang/ folder via $lang; every use carries a default so the page
   stays readable without one. *}

{if $sso_enabled && $server_id}
    <div class="row">
        <div class="col-sm-12">
            <a href="clientarea.php?action=productdetails&id={$serviceid}&dosinglesignon=1"
               target="_blank" rel="noopener" class="btn btn-primary btn-block">
                {$lang.open_panel|default:'Open the game panel'}
            </a>
            <p class="text-muted small text-center" style="margin-top:6px">
                {$lang.open_panel_hint|default:'Opens GameAP in a new tab, already signed in.'}
            </p>
        </div>
    </div>
{/if}

{if !$server_id}
    <div class="alert alert-info">
        {$lang.not_ready|default:'The server is not ready yet. This page will show its details once setup finishes.'}
    </div>
{else}
    {if $stale}
        <div class="alert alert-warning">
            {$lang.stale|default:'Showing the last known state — the panel could not be reached just now.'}
        </div>
    {/if}

    <table class="table table-condensed">
        <tbody>
        <tr>
            <th style="width:35%">{$lang.address|default:'Address'}</th>
            <td><code>{$status.address|escape}</code></td>
        </tr>
        <tr>
            <th>{$lang.game|default:'Game'}</th>
            <td>{$status.game|escape}</td>
        </tr>
        <tr>
            <th>{$lang.state|default:'State'}</th>
            <td>
                {* installed: 0 = not installed, 1 = installed, 2 = installation running *}
                {if $status.blocked}
                    <span class="label label-warning">{$lang.suspended|default:'Suspended'}</span>
                {elseif $status.installed == 2}
                    <span class="label label-info">{$lang.installing|default:'Installing'}</span>
                {elseif $status.installed != 1}
                    <span class="label label-default">{$lang.not_installed|default:'Not installed'}</span>
                {elseif $status.online}
                    <span class="label label-success">{$lang.online|default:'Online'}</span>
                {else}
                    <span class="label label-default">{$lang.offline|default:'Offline'}</span>
                {/if}
            </td>
        </tr>
        {if $status.ram_limit}
            <tr>
                <th>{$lang.ram|default:'RAM'}</th>
                <td>{$status.ram_limit} MB</td>
            </tr>
        {/if}
        {if $status.cpu_limit}
            <tr>
                <th>{$lang.cpu|default:'CPU'}</th>
                <td>{$status.cpu_limit}%</td>
            </tr>
        {/if}
        {if $panel_login}
            <tr>
                <th>{$lang.panel_login|default:'Panel login'}</th>
                <td>{$panel_login|escape}</td>
            </tr>
        {/if}
        </tbody>
    </table>
{/if}
