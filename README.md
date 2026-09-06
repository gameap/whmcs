# GameAP module for WHMCS

Provisions game servers in the [GameAP](https://gameap.com) control panel from
WHMCS. A paid order creates the server, an unpaid one closes access to it, and a
cancelled one removes it — without an administrator touching the panel.

Русская версия: [README_RU.md](README_RU.md) · Deutsche Version: [README_DE.md](README_DE.md) · Versión en español: [README_ES.md](README_ES.md)

## What it does

| WHMCS event | What happens in GameAP |
|-----------------|----------------------------------------------------------------|
| Create | Finds or creates the customer's panel account, picks a node, allocates a free port block, creates the server, queues the installation, attaches it to the account and grants the customer rights on it |
| Suspend | Stops the server and blocks it, so the customer loses control of it |
| Unsuspend | Unblocks and, if configured, starts the server again |
| Terminate | Detaches the server from the account and removes it, files included (or only disables it, if configured) |
| Change package | Applies new limits and mod variables. Never changes the game |
| Client area | Server state, connect address and a one-click sign-in to the panel |

A daily cron sweep corrects drift in one direction only — WHMCS is read, the
panel is corrected — so a transient billing state can never destroy a server.
It lifts only blocks the module placed itself; a server an administrator
blocked in the panel stays blocked.

Passwords of panel accounts are not managed from WHMCS: the panel does not let
an API token change an existing account's password. The customer changes it in
the panel profile, or uses the panel's password reset.

## Requirements

- WHMCS 8.9 or newer, running PHP 8.1 or newer
- GameAP **4.5.0 or newer** — the token abilities for user, node and game
  access and the single sign-on endpoints were introduced in 4.5.0

## Install

1. Download the release archive and unpack it over the WHMCS root, so the files
   land in `modules/servers/gameap/`.
2. In the panel, signed in as an **administrator**, create a personal access
   token (Profile → API tokens) with the abilities listed in
   [docs/INSTALL.md](docs/INSTALL.md). The token must belong to an
   administrator: the panel refuses provisioning calls from other accounts.
3. In WHMCS, go to **System Settings → Products/Services → Servers**, add a
   server:
   - **Hostname** — the panel's hostname
   - **Password** — the token (WHMCS stores this field encrypted)
   - **Username / Access Hash** — leave empty
   - **Secure** — on, unless the panel is plain HTTP
   - **Type** — GameAP
4. Press **Test Connection**. It checks every ability the module needs and names
   any that is missing.
5. Create a product of type GameAP and fill in the module settings.

Full walkthrough: [docs/INSTALL.md](docs/INSTALL.md).

## Product settings

The important ones. Slots, RAM, CPU, disk, the game mod and the server name can
be overridden per service with a WHMCS configurable option or custom field of
the same name; the remaining settings are read from the product only.

| Setting | Meaning |
|--------------------|-------------------------------------------------------------|
| Game code | Panel game code, for example `cs2` |
| Game mod | Mod name. Blank uses the game's first mod (alphabetically) |
| Nodes | Comma-separated node names. Blank means any enabled node |
| Slots, RAM, CPU | Resources. RAM in MB, CPU in percent of a core (100 = one core); the module converts to the panel's units. Slots go into the mod variable you name below |
| Port range | Range to allocate from, for example `27000-28000` |
| Mod variables | `key=value` per line. `{slots}` is substituted |
| Client permissions | One per line. Blank grants the panel's full per-server set |
| Install on create | Whether to queue the game installation right away |
| On suspend | Stop and block, block only, or stop only |
| On terminate | Delete the server and its files, or only disable it |

## Security

The module holds one secret: the panel token, in the server's Password field,
which WHMCS keeps encrypted at rest. It is never written to the module log —
every request goes through a single logging point that masks it — and
`$params` is never logged, because it carries both the token and the
customer's personal data.

The token should be scoped to the abilities in
[docs/INSTALL.md](docs/INSTALL.md) and nothing more. The panel refuses to mint a
sign-in ticket for another administrator, to change any password, and to touch
administrator accounts through a token, so a leaked token cannot become panel
access. See [docs/SECURITY.md](docs/SECURITY.md).

## Development

```bash
composer install
composer test    # phpunit
composer lint    # PSR-12
```

The provisioning logic lives in `modules/servers/gameap/lib/` and is plain PHP
with no WHMCS dependency, so it is covered by ordinary unit tests. The WHMCS
entry points in `gameap.php` only translate `$params` into calls on it.

The audit that shaped the current release, with every panel and WHMCS
behaviour it was checked against, is in [docs/AUDIT.md](docs/AUDIT.md).

## License

MIT
