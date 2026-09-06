<?php

namespace WHMCS\Module\Server\Gameap;

/**
 * Everything the module keeps on the WHMCS side of a service.
 *
 * The link between a WHMCS service and a GameAP server lives in service
 * properties — WHMCS's per-service key/value store for modules, which creates
 * the backing custom field itself — so a merchant does not have to hand-create
 * fields on every product before ordering works. Custom fields with the same
 * names are still read when present, so an administrator who prefers seeing
 * the ids on the service page can add them and they take precedence for
 * reading.
 */
class ServiceState
{
    public const FIELD_SERVER_ID = 'GameAP Server ID';
    public const FIELD_USER_ID = 'GameAP User ID';
    public const FIELD_NODE = 'GameAP Node';
    public const FIELD_SNAPSHOT = 'GameAP Status';

    /** @var array<string,mixed> */
    private array $params;

    /**
     * @param array<string,mixed> $params
     */
    public function __construct(array $params)
    {
        $this->params = $params;
    }

    public function serviceId(): int
    {
        return (int) ($this->params['serviceid'] ?? 0);
    }

    public function serverId(): int
    {
        return (int) $this->read(self::FIELD_SERVER_ID);
    }

    public function userId(): int
    {
        return (int) $this->read(self::FIELD_USER_ID);
    }

    /**
     * Persists the link. Losing it silently is the worst outcome — WHMCS would
     * retry setup and provision a second server — so when nothing could be
     * written the failure is raised, with the ids in the message so an
     * administrator can link the service by hand. Cosmetic writes (the status
     * snapshot) pass $required = false and are allowed to fail quietly.
     *
     * @param array<string,scalar> $values
     */
    public function store(array $values, bool $required = true): void
    {
        $failure = 'The service model is not available.';

        $model = $this->params['model'] ?? null;
        if (is_object($model) && isset($model->serviceProperties)) {
            try {
                $model->serviceProperties->save($values);

                return;
            } catch (\Throwable $exception) {
                // Fall through to custom fields: losing the link is worse than
                // a slower write path.
                $failure = $exception->getMessage();
            }
        }

        if ($this->storeInCustomFields($values)) {
            return;
        }

        if (!$required) {
            return;
        }

        $pairs = [];
        foreach ($values as $name => $value) {
            $pairs[] = $name . ' = ' . (string) $value;
        }

        throw ModuleException::state(
            'Could not record the GameAP link on WHMCS service #' . $this->serviceId() . ' (' . implode(', ', $pairs)
            . '). ' . $failure . ' Add these values to the service by hand before retrying.'
        );
    }

    /**
     * Writes the connect address into the built-in Dedicated IP column, which
     * is what WHMCS shows on the service page and in emails. There is no API
     * for that column, hence the direct query — scoped to both the service id
     * and its owner so a wrong id cannot touch another client's service.
     */
    public function setConnectAddress(string $address): void
    {
        if (!class_exists('\WHMCS\Database\Capsule')) {
            return;
        }

        try {
            \WHMCS\Database\Capsule::table('tblhosting')
                ->where('id', $this->serviceId())
                ->where('userid', (int) ($this->params['userid'] ?? 0))
                ->update(['dedicatedip' => $address]);
        } catch (\Throwable $exception) {
            // Cosmetic field; never fail provisioning over it.
        }
    }

    /**
     * Stores the panel credentials on the service so the customer can see
     * them. Only ever called when the module created the account: overwriting
     * them later would replace a password the customer has since changed.
     */
    public function setCredentials(string $login, string $password): void
    {
        if (!function_exists('localAPI')) {
            return;
        }

        localAPI('UpdateClientProduct', [
            'serviceid' => $this->serviceId(),
            'serviceusername' => $login,
            'servicepassword' => $password,
        ]);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function snapshot(): ?array
    {
        $raw = $this->read(self::FIELD_SNAPSHOT);
        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function storeSnapshot(array $data): void
    {
        $encoded = json_encode(['at' => time(), 'data' => $data], JSON_UNESCAPED_UNICODE);

        if ($encoded !== false) {
            $this->store([self::FIELD_SNAPSHOT => $encoded], false);
        }
    }

    /**
     * The allow-list that may be logged. $params itself must never reach the
     * module log: it carries the panel token and the client's personal
     * details.
     *
     * @return array<string,mixed>
     */
    public function safeContext(): array
    {
        return [
            'serviceid' => $this->serviceId(),
            'userid' => $this->params['userid'] ?? null,
            'pid' => $this->params['pid'] ?? null,
            'serverid' => $this->params['serverid'] ?? null,
            'serverhostname' => $this->params['serverhostname'] ?? null,
            'panel_server_id' => $this->serverId(),
            'panel_user_id' => $this->userId(),
        ];
    }

    private function read(string $field): string
    {
        $customFields = $this->params['customfields'] ?? null;
        if (is_array($customFields) && isset($customFields[$field]) && $customFields[$field] !== '') {
            return (string) $customFields[$field];
        }

        $model = $this->params['model'] ?? null;
        if (is_object($model) && isset($model->serviceProperties)) {
            try {
                $value = $model->serviceProperties->get($field);
                if ($value !== null && $value !== '') {
                    return (string) $value;
                }
            } catch (\Throwable $exception) {
                // Treated as "not recorded".
            }
        }

        return '';
    }

    /**
     * The UpdateClientProduct API takes custom fields keyed by field ID, not
     * by name, so the product's field definitions are looked up first. A
     * field's stored name may carry a "|Display name" suffix, which is
     * stripped before comparing.
     *
     * @param array<string,scalar> $values
     */
    private function storeInCustomFields(array $values): bool
    {
        if (!function_exists('localAPI') || !class_exists('\WHMCS\Database\Capsule')) {
            return false;
        }

        try {
            $fields = \WHMCS\Database\Capsule::table('tblcustomfields')
                ->where('type', 'product')
                ->where('relid', (int) ($this->params['pid'] ?? 0))
                ->get(['id', 'fieldname']);

            $byName = [];
            foreach ($fields as $field) {
                $name = trim((string) explode('|', (string) $field->fieldname, 2)[0]);
                $byName[$name] = (int) $field->id;
            }

            $writable = [];
            foreach ($values as $name => $value) {
                if (isset($byName[$name])) {
                    $writable[$byName[$name]] = (string) $value;
                }
            }

            if ($writable === []) {
                return false;
            }

            $result = localAPI('UpdateClientProduct', [
                'serviceid' => $this->serviceId(),
                'customfields' => base64_encode(serialize($writable)),
            ]);

            return is_array($result) && ($result['result'] ?? '') === 'success';
        } catch (\Throwable $exception) {
            return false;
        }
    }
}
