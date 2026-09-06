<?php

/**
 * Daily reconciliation between WHMCS and GameAP.
 *
 * Provisioning calls can fail after WHMCS has already changed a service's
 * state — the panel restarts mid-request, the network blips — and nothing in
 * WHMCS retries them. Without a sweep, a service suspended for non-payment can
 * stay fully usable indefinitely, which is the failure mode that costs a
 * merchant money.
 *
 * The sweep is deliberately one-directional and minimal: it reads WHMCS,
 * corrects the panel, and never writes back to WHMCS. It only mirrors the
 * product's suspend mode and lifts blocks it placed itself — it never
 * creates, deletes, or starts anything, so a transient billing state cannot
 * destroy a customer's server. Delete this file to turn the behaviour off.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

// Hook files load on every page; the module file only when WHMCS calls the
// module. Either order must work.
if (!function_exists('gameap_MetaData')) {
    require_once __DIR__ . '/gameap.php';
}

use WHMCS\Database\Capsule;
use WHMCS\Module\Server\Gameap\Config;
use WHMCS\Module\Server\Gameap\ModuleException;
use WHMCS\Module\Server\Gameap\Provisioner;
use WHMCS\Module\Server\Gameap\ServiceState;

/** Bounds one cron run on a large install; the rest are picked up tomorrow. */
const GAMEAP_RECONCILE_LIMIT = 500;

add_hook('DailyCronJob', 1, static function (): void {
    if (!class_exists(Capsule::class)) {
        return;
    }

    try {
        $services = gameap_reconcileCandidates();
    } catch (Throwable $throwable) {
        gameap_reconcileLog('discovery failed', $throwable->getMessage());

        return;
    }

    $fixed = 0;
    $skipped = 0;
    $problems = [];
    $unreachable = [];

    foreach ($services as $service) {
        $panelServer = (int) $service->serverid;

        // A panel that is down answers every service on it with the same
        // connect timeout, and 500 of those would outlast the cron run.
        if (isset($unreachable[$panelServer])) {
            $skipped++;

            continue;
        }

        try {
            $note = gameap_reconcileService($service);
        } catch (Throwable $throwable) {
            if ($throwable instanceof ModuleException && $throwable->errorCode() === ModuleException::CODE_TRANSPORT) {
                $unreachable[$panelServer] = true;
            }

            $problems[] = 'service #' . $service->serviceid . ': ' . $throwable->getMessage();

            continue;
        }

        if ($note !== null) {
            $fixed++;
            $problems[] = 'service #' . $service->serviceid . ': ' . $note;
        }
    }

    if ($problems !== []) {
        gameap_reconcileLog(
            'checked ' . (count($services) - $skipped) . ' services, corrected ' . $fixed
                . ($skipped > 0 ? ', skipped ' . $skipped . ' on unreachable servers' : ''),
            implode('; ', array_slice($problems, 0, 50))
        );
    }
});

/**
 * @return array<int,object>
 */
function gameap_reconcileCandidates(): array
{
    return Capsule::table('tblhosting')
        ->join('tblproducts', 'tblproducts.id', '=', 'tblhosting.packageid')
        ->join('tblservers', 'tblservers.id', '=', 'tblhosting.server')
        ->where('tblproducts.servertype', 'gameap')
        ->whereIn('tblhosting.domainstatus', ['Active', 'Suspended'])
        ->orderBy('tblhosting.id')
        ->limit(GAMEAP_RECONCILE_LIMIT)
        ->get([
            'tblhosting.id as serviceid',
            'tblhosting.userid',
            'tblhosting.packageid',
            'tblhosting.server as serverid',
            'tblhosting.domainstatus',
            'tblservers.hostname',
            'tblservers.secure',
            'tblservers.port',
            'tblservers.password',
            'tblservers.accesshash',
        ])
        ->all();
}

function gameap_reconcileService(object $service): ?string
{
    $params = gameap_moduleParams((int) $service->serviceid) ?? gameap_fallbackParams($service);

    $state = new ServiceState($params);
    $provisioner = new Provisioner(
        gameap_panel($params),
        new Config($params),
        $state,
        $params
    );

    return $provisioner->reconcile((string) $service->domainstatus);
}

/**
 * The same $params WHMCS hands to a module call, built by WHMCS itself —
 * server credentials decrypted, product options, custom fields, the service
 * model. Undocumented but what WHMCS core uses for the same job.
 *
 * @return array<string,mixed>|null
 */
function gameap_moduleParams(int $serviceId): ?array
{
    if (!class_exists('\WHMCS\Module\Server')) {
        return null;
    }

    try {
        $module = new \WHMCS\Module\Server();
        if (!$module->loadByServiceID($serviceId)) {
            return null;
        }

        $params = $module->buildParams();

        return is_array($params) && $params !== [] ? $params : null;
    } catch (Throwable $throwable) {
        return null;
    }
}

/**
 * Just enough of $params to run one check, for a WHMCS that lacks the
 * module loader. Product options are read straight from tblproducts.
 *
 * @return array<string,mixed>
 */
function gameap_fallbackParams(object $service): array
{
    return [
        'serviceid' => (int) $service->serviceid,
        'userid' => (int) $service->userid,
        'pid' => (int) $service->packageid,
        'serverhostname' => (string) $service->hostname,
        'serversecure' => (bool) $service->secure,
        'serverport' => (int) $service->port,
        // The password column is encrypted; the access hash is stored as is.
        'serverpassword' => gameap_decryptServerPassword((string) $service->password),
        'serveraccesshash' => (string) $service->accesshash,
        'model' => gameap_serviceModel((int) $service->serviceid),
    ] + gameap_productOptions((int) $service->packageid);
}

/**
 * @return array<string,string>
 */
function gameap_productOptions(int $productId): array
{
    $product = Capsule::table('tblproducts')->where('id', $productId)->first();
    if ($product === null) {
        return [];
    }

    $options = [];
    for ($index = 1; $index <= count(Config::OPTIONS); $index++) {
        $key = 'configoption' . $index;
        $options[$key] = (string) ($product->{$key} ?? '');
    }

    return $options;
}

function gameap_serviceModel(int $serviceId): ?object
{
    if (!class_exists('\WHMCS\Service\Service')) {
        return null;
    }

    try {
        return \WHMCS\Service\Service::find($serviceId);
    } catch (Throwable $throwable) {
        return null;
    }
}

function gameap_decryptServerPassword(string $encrypted): string
{
    if ($encrypted === '' || !function_exists('localAPI')) {
        return '';
    }

    $result = localAPI('DecryptPassword', ['password2' => $encrypted]);

    return (string) ($result['password'] ?? '');
}

function gameap_reconcileLog(string $action, string $detail): void
{
    if (function_exists('logModuleCall')) {
        logModuleCall('gameap', 'reconcile: ' . $action, [], $detail);
    }
}
