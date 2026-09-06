<?php

namespace WHMCS\Module\Server\Gameap;

/**
 * Templates for the two names the module has to invent: the panel login for a
 * brand-new customer account, and the server name.
 */
class Naming
{
    private const LOGIN_MAX_LENGTH = 40;
    private const SERVER_NAME_MAX_LENGTH = 128;

    /** Panel policy is 12; a generated secret has no reason to sit at the floor. */
    private const PASSWORD_LENGTH = 24;

    /**
     * A deterministic login keyed on the WHMCS client id. Deterministic beats
     * random here: the same customer ordering a second service resolves to the
     * same account, and an administrator can match a panel user to a billing
     * client by eye.
     *
     * @param array<string,mixed> $client
     */
    public static function login(string $template, array $client): string
    {
        if (trim($template) === '') {
            $template = 'whmcs{client_id}';
        }

        $login = self::render($template, [
            '{client_id}' => (string) ($client['id'] ?? $client['userid'] ?? '0'),
            '{first_name}' => self::slug((string) ($client['firstname'] ?? '')),
            '{last_name}' => self::slug((string) ($client['lastname'] ?? '')),
        ]);

        $login = strtolower(preg_replace('/[^A-Za-z0-9._-]/', '', $login) ?? '');

        if ($login === '') {
            $login = 'whmcs' . (string) ($client['id'] ?? '0');
        }

        return substr($login, 0, self::LOGIN_MAX_LENGTH);
    }

    /**
     * @param array<string,string> $placeholders
     */
    public static function serverName(string $template, array $placeholders): string
    {
        if (trim($template) === '') {
            $template = '{game} #{service_id}';
        }

        $name = self::render($template, $placeholders);

        // A placeholder the template asked for but nobody supplied is dropped
        // rather than left in place: a typo in the product settings should not
        // put literal braces in the name the customer sees.
        $name = preg_replace('/\{[a-z_]+\}/i', '', $name) ?? $name;

        // Control characters would travel into config files on the node.
        $name = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '');
        $name = trim(preg_replace('/\s{2,}/u', ' ', $name) ?? $name);

        if ($name === '') {
            $name = 'Server #' . ($placeholders['{service_id}'] ?? '');
        }

        return mb_substr($name, 0, self::SERVER_NAME_MAX_LENGTH);
    }

    /**
     * Generated with the CSPRNG and shaped to satisfy any reasonable policy:
     * mixed case, digits and punctuation, well past the 12-character minimum.
     */
    public static function password(): string
    {
        $alphabets = [
            'abcdefghijkmnopqrstuvwxyz',
            'ABCDEFGHJKLMNPQRSTUVWXYZ',
            '23456789',
            '!@#%^*_-+=',
        ];

        $characters = [];

        // One from each class first, so the result cannot accidentally miss one.
        foreach ($alphabets as $alphabet) {
            $characters[] = $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        $pool = implode('', $alphabets);
        for ($i = count($characters); $i < self::PASSWORD_LENGTH; $i++) {
            $characters[] = $pool[random_int(0, strlen($pool) - 1)];
        }

        // Fisher-Yates with the CSPRNG: shuffle() is not cryptographically
        // random, and the first four positions would otherwise be predictable.
        for ($i = count($characters) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$characters[$i], $characters[$j]] = [$characters[$j], $characters[$i]];
        }

        return implode('', $characters);
    }

    /**
     * @param array<string,string> $replacements
     */
    private static function render(string $template, array $replacements): string
    {
        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    private static function slug(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9]/', '', $value) ?? '';
    }
}
