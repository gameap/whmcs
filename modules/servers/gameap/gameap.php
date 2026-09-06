<?php

/**
 * GameAP provisioning module for WHMCS.
 *
 * Only the gameap_* entry points live in the global namespace; everything else
 * is under WHMCS\Module\Server\Gameap. That is deliberate — server modules
 * share one global namespace inside WHMCS, and a helper declared globally is
 * how two panel modules end up unable to coexist on the same install.
 *
 * Requires GameAP 4.5.0 or newer: the token abilities for user, node and game
 * access and the single sign-on endpoints do not exist in earlier panels.
 *
 * @see docs/INSTALL.md
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/ModuleException.php';
require_once __DIR__ . '/lib/HttpClient.php';
require_once __DIR__ . '/lib/PanelClient.php';
require_once __DIR__ . '/lib/Config.php';
require_once __DIR__ . '/lib/Naming.php';
require_once __DIR__ . '/lib/Placement.php';
require_once __DIR__ . '/lib/ServiceState.php';
require_once __DIR__ . '/lib/Provisioner.php';
require_once __DIR__ . '/lib/Diagnostics.php';

use WHMCS\Module\Server\Gameap\Config;
use WHMCS\Module\Server\Gameap\Diagnostics;
use WHMCS\Module\Server\Gameap\HttpClient;
use WHMCS\Module\Server\Gameap\ModuleException;
use WHMCS\Module\Server\Gameap\PanelClient;
use WHMCS\Module\Server\Gameap\Provisioner;
use WHMCS\Module\Server\Gameap\ServiceState;

const GAMEAP_MODULE_VERSION = '1.0.0';

/**
 * The client area renders on every visit to the service page; a panel that
 * is up but slow must not hold the page for the full provisioning timeout.
 */
const GAMEAP_CLIENT_AREA_TIMEOUT = 8;

/**
 * @return array<string,mixed>
 */
function gameap_MetaData(): array
{
    return [
        'DisplayName' => 'GameAP',
        'APIVersion' => '1.1',
        'RequiresServer' => true,
        'DefaultSSLPort' => '443',
        'DefaultNonSSLPort' => '80',
        'ServiceSingleSignOnLabel' => 'Log in to the game panel',
    ];
}

/**
 * @return array<string,array<string,string>>
 */
function gameap_ConfigOptions(): array
{
    return Config::OPTIONS;
}

/**
 * @param array<string,mixed> $params
 *
 * @return array{success:bool,error:string}
 */
function gameap_TestConnection(array $params): array
{
    try {
        $http = gameap_http($params);
        $report = (new Diagnostics($http, new PanelClient($http)))->run();
    } catch (ModuleException $exception) {
        return ['success' => false, 'error' => $exception->getMessage()];
    } catch (Throwable $throwable) {
        return ['success' => false, 'error' => 'Unexpected error: ' . $throwable->getMessage()];
    }

    if (!$report['ok']) {
        return ['success' => false, 'error' => implode(' ', $report['problems'])];
    }

    return ['success' => true, 'error' => implode(' ', $report['notes'])];
}

/**
 * @param array<string,mixed> $params
 */
function gameap_CreateAccount(array $params): string
{
    return gameap_run($params, static function (Provisioner $provisioner): void {
        $provisioner->create();
    });
}

/**
 * @param array<string,mixed> $params
 */
function gameap_SuspendAccount(array $params): string
{
    return gameap_run($params, static function (Provisioner $provisioner): void {
        $provisioner->suspend();
    });
}

/**
 * @param array<string,mixed> $params
 */
function gameap_UnsuspendAccount(array $params): string
{
    return gameap_run($params, static function (Provisioner $provisioner): void {
        $provisioner->unsuspend();
    });
}

/**
 * @param array<string,mixed> $params
 */
function gameap_TerminateAccount(array $params): string
{
    return gameap_run($params, static function (Provisioner $provisioner): void {
        $provisioner->terminate();
    });
}

/**
 * @param array<string,mixed> $params
 */
function gameap_ChangePackage(array $params): string
{
    return gameap_run($params, static function (Provisioner $provisioner): void {
        $provisioner->changePackage();
    });
}

// There is deliberately no gameap_ChangePassword: the panel refuses password
// changes made through a personal access token, so the password of an
// existing account can only be changed in the panel itself.

/**
 * @param array<string,mixed> $params
 *
 * @return array<string,mixed>
 */
function gameap_ClientArea(array $params): array
{
    $vars = [
        'serviceid' => (int) ($params['serviceid'] ?? 0),
        'sso_enabled' => (new Config($params))->bool('sso_enabled'),
        'panel_url' => '',
        'panel_login' => (string) ($params['username'] ?? ''),
        'lang' => gameap_clientStrings(),
        'status' => [],
        'stale' => false,
        'error' => '',
        // Initialised up front: the template reads it, and the block below
        // can fail before it would otherwise be set.
        'server_id' => 0,
    ];

    try {
        $vars['panel_url'] = gameap_baseUrl($params);

        [$provisioner, $state] = gameap_provisioner($params, GAMEAP_CLIENT_AREA_TIMEOUT);
        $status = $provisioner->status();

        $vars['status'] = $status['data'];
        $vars['stale'] = (bool) ($status['stale'] ?? false);
        $vars['error'] = (string) ($status['error'] ?? '');
        $vars['server_id'] = $state->serverId();
    } catch (Throwable $throwable) {
        // The service page must render even when the panel is down.
        $vars['error'] = $throwable->getMessage();
        $vars['stale'] = true;
    }

    return [
        'templatefile' => 'clientarea',
        'vars' => $vars,
    ];
}

/**
 * @return array<string,string>
 */
function gameap_ClientAreaCustomButtonArray(): array
{
    return [
        'Start server' => 'startServer',
        'Stop server' => 'stopServer',
        'Restart server' => 'restartServer',
    ];
}

/**
 * @param array<string,mixed> $params
 */
function gameap_startServer(array $params): string
{
    return gameap_power($params, 'start');
}

/**
 * @param array<string,mixed> $params
 */
function gameap_stopServer(array $params): string
{
    return gameap_power($params, 'stop');
}

/**
 * @param array<string,mixed> $params
 */
function gameap_restartServer(array $params): string
{
    return gameap_power($params, 'restart');
}

/**
 * @return array<string,string>
 */
function gameap_AdminCustomButtonArray(): array
{
    return [
        'Refresh from panel' => 'resync',
    ];
}

/**
 * @param array<string,mixed> $params
 */
function gameap_resync(array $params): string
{
    return gameap_run($params, static function (Provisioner $provisioner): void {
        $provisioner->status(true);
    });
}

/**
 * @param array<string,mixed> $params
 *
 * @return array<string,string>
 */
function gameap_AdminServicesTabFields(array $params): array
{
    $state = new ServiceState($params);

    $fields = [
        'GameAP server' => $state->serverId() > 0 ? '#' . $state->serverId() : 'not linked',
        'GameAP user' => $state->userId() > 0 ? '#' . $state->userId() : 'not linked',
    ];

    $snapshot = $state->snapshot();
    if ($snapshot === null) {
        return $fields;
    }

    $data = is_array($snapshot['data'] ?? null) ? $snapshot['data'] : [];
    $age = time() - (int) ($snapshot['at'] ?? 0);

    $fields['Address'] = htmlspecialchars((string) ($data['address'] ?? ''), ENT_QUOTES, 'UTF-8');
    $fields['State'] = ($data['blocked'] ?? false) ? 'blocked' : (($data['online'] ?? false) ? 'online' : 'offline');
    $fields['Last checked'] = $age . 's ago';

    return $fields;
}

/**
 * Serves both the client-area button and the admin's "log in" button on the
 * service page (WHMCS prefers this over LoginLink when both exist, so there
 * is no LoginLink).
 *
 * @param array<string,mixed> $params
 *
 * @return array<string,mixed>
 */
function gameap_ServiceSingleSignOn(array $params): array
{
    $config = new Config($params);

    if (!$config->bool('sso_enabled')) {
        return ['success' => false, 'errorMsg' => 'Single sign-on is disabled for this product.'];
    }

    try {
        $state = new ServiceState($params);
        $userId = $state->userId();

        if ($userId === 0) {
            return ['success' => false, 'errorMsg' => 'This service is not linked to a GameAP account yet.'];
        }

        $serverId = $state->serverId();
        $redirect = $serverId > 0 ? '/servers/' . $serverId : '/';

        $ticket = gameap_panel($params)->issueSsoTicket($userId, $redirect);

        // The ticket goes in the fragment: fragments are not sent to the
        // server, so it stays out of the panel's access logs and out of the
        // Referer header. The panel's /sso page reads it from there.
        $url = gameap_baseUrl($params) . '/sso#t=' . rawurlencode((string) ($ticket['ticket'] ?? ''));

        return ['success' => true, 'redirectTo' => $url];
    } catch (Throwable $throwable) {
        return ['success' => false, 'errorMsg' => 'Could not open the panel session. Please try again.'];
    }
}

// ---------------------------------------------------------------------
// Internals
// ---------------------------------------------------------------------

/**
 * Runs one provisioning step and translates the outcome into what WHMCS
 * expects: the literal string 'success', or a message shown to the
 * administrator.
 *
 * @param array<string,mixed>     $params
 * @param callable(Provisioner):void $step
 */
function gameap_run(array $params, callable $step): string
{
    try {
        [$provisioner] = gameap_provisioner($params);
        $step($provisioner);

        return 'success';
    } catch (ModuleException $exception) {
        return $exception->getMessage();
    } catch (Throwable $throwable) {
        return 'Unexpected error: ' . $throwable->getMessage();
    }
}

/**
 * @param array<string,mixed> $params
 */
function gameap_power(array $params, string $action): string
{
    return gameap_run($params, static function (Provisioner $provisioner) use ($action): void {
        $provisioner->power($action);
    });
}

/**
 * @param array<string,mixed> $params
 *
 * @return array{0:Provisioner,1:ServiceState}
 */
function gameap_provisioner(array $params, ?int $timeout = null): array
{
    $state = new ServiceState($params);
    $provisioner = new Provisioner(gameap_panel($params, $timeout), new Config($params), $state, $params);

    return [$provisioner, $state];
}

/**
 * @param array<string,mixed> $params
 */
function gameap_panel(array $params, ?int $timeout = null): PanelClient
{
    return new PanelClient(gameap_http($params, $timeout));
}

/**
 * The token is read from the server's Password field first — WHMCS stores
 * that column encrypted — and from Access Hash as a fallback for installs
 * configured before that was the documented place.
 *
 * @param array<string,mixed> $params
 */
function gameap_http(array $params, ?int $timeout = null): HttpClient
{
    $token = trim((string) ($params['serverpassword'] ?? ''));

    if ($token === '') {
        $token = trim((string) ($params['serveraccesshash'] ?? ''));
    }

    if ($token === '') {
        throw ModuleException::config(
            'No GameAP API token. Put a panel personal access token in the server\'s Password field.'
        );
    }

    return new HttpClient(gameap_baseUrl($params), $token, 'gameap', $timeout);
}

/**
 * @param array<string,mixed> $params
 */
function gameap_baseUrl(array $params): string
{
    $hostname = trim((string) ($params['serverhostname'] ?? ''));

    if ($hostname === '') {
        $hostname = trim((string) ($params['serverip'] ?? ''));
    }

    if ($hostname === '') {
        throw ModuleException::config('The server has no hostname. Set it in Setup > Products/Services > Servers.');
    }

    $scheme = ($params['serversecure'] ?? false) ? 'https' : 'http';
    $url = $scheme . '://' . $hostname;

    $port = (int) ($params['serverport'] ?? 0);
    if ($port > 0 && $port !== 80 && $port !== 443) {
        $url .= ':' . $port;
    }

    return rtrim($url, '/');
}

/**
 * Strings for clientarea.tpl in the visitor's language. WHMCS loads lang/
 * folders for addon modules only, so a server module has to read its own:
 * the file for the session language is used when it exists, English
 * otherwise, and the template carries defaults for a missing file.
 *
 * @return array<string,string>
 */
function gameap_clientStrings(): array
{
    $language = strtolower(preg_replace('/[^a-z]/i', '', (string) ($_SESSION['Language'] ?? '')) ?? '');

    foreach (array_unique([$language, 'english']) as $candidate) {
        if ($candidate === '') {
            continue;
        }

        $file = __DIR__ . '/lang/' . $candidate . '.php';
        if (!is_file($file)) {
            continue;
        }

        $_LANG = [];
        include $file;

        if (is_array($_LANG) && $_LANG !== []) {
            return $_LANG;
        }
    }

    return [];
}
