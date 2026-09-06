# Installing the GameAP module for WHMCS

Requires GameAP **4.5.0 or newer**. Earlier panels have neither the token
abilities for user, node and game access nor the single sign-on endpoints, so
the module cannot work against them.

## 1. Create the panel token

In GameAP, signed in as an **administrator**: **Profile → API tokens → Create**.
The token must belong to an administrator account — the panel checks that
before it looks at the token's abilities, and refuses every provisioning call
otherwise. Give it these abilities and no others:

| Ability | Used for |
|-------------------------|--------------------------------------------------|
| `admin:server:create` | Creating, updating and deleting game servers |
| `server:list` | Reading server state for the client area |
| `server:start` | Starting a server |
| `server:stop` | Stopping a server on suspend |
| `server:restart` | The client-area restart button |
| `server:settings-manage`| Writing mod variables such as the slot count |
| `admin:user:read` | Finding the customer's panel account |
| `admin:user:manage` | Creating the account, attaching servers to it and granting permissions |
| `admin:node:read` | Listing nodes, their addresses and busy ports |
| `admin:game:read` | Resolving the game and its mods |
| `admin:user:sso` | Issuing single sign-on tickets |

The token belongs to an administrator, but it is not equivalent to one: it
cannot delete accounts, cannot change nodes or games, cannot change any
password, cannot touch administrator accounts, cannot grant administrative
roles, and cannot mint a sign-in ticket for another administrator.

Copy the token — the panel shows it once.

## 2. Install the files

Unpack the release archive over the WHMCS root:

```
modules/servers/gameap/
├── gameap.php
├── hooks.php
├── clientarea.tpl
├── lang/
├── lib/
└── whmcs.json
```

`hooks.php` is the daily reconciliation sweep. Delete it if you do not want it.
WHMCS registers a module's hook file when the module is activated; if the
sweep does not run after an upgrade, open the product's Module Settings tab and
press Save Changes once.

To show a logo next to the module in the WHMCS admin, drop a square PNG into
the module directory as `logo.png` and add `"logo": {"filename": "logo.png"}`
to `whmcs.json`.

## 3. Add the server in WHMCS

**System Settings → Products/Services → Servers → Add New Server**

| Field | Value |
|-------------|-------------------------------------------------|
| Name | Anything, for example "GameAP" |
| Hostname | The panel's hostname, e.g. `panel.example.com` |
| Server Type | GameAP |
| Password | The token from step 1 |
| Username | leave empty |
| Access Hash | leave empty |
| Secure | tick, unless the panel is served over plain HTTP |

The token goes in **Password** because WHMCS stores that column encrypted;
Access Hash is stored in clear text. The module still reads Access Hash when
Password is empty, so an installation configured the old way keeps working —
move the token when convenient.

Press **Test Connection**. On success it reports how many nodes are available.
On failure it names the exact ability that is missing, says that the token's
owner is not an administrator, or says the panel could not be reached — it does
not just say "failed".

## 4. Create a product

**System Settings → Products/Services → Create a New Product**, module GameAP.

Minimum viable configuration:

- **Game code** — the panel's code, e.g. `cs2`
- **Port range** — e.g. `27000-28000`
- **Slots**, **RAM limit**, **CPU limit** — the tier you are selling. RAM is
  in MB, CPU in percent of one core (100 = one full core, 200 = two); the
  module converts both to the panel's units
- **Mod variables** — e.g. `maxplayers={slots}`
- **Server name** — e.g. `{game} #{service_id}`

WHMCS gives server modules no hook at product-save time, so the game code and
mod name are checked at the first order: an unknown game or mod fails that
order with a message that lists the panel's available mods.

### Selling upgrades

Slots, RAM limit, CPU limit, disk limit, the game mod and the server name can
be overridden per service. Create a WHMCS configurable option whose name
matches the setting's label — "Slots", "RAM limit (MB)" — and its value wins
over the product-level one. The same works for custom fields. The other
settings (nodes, system user, permissions, suspend and terminate behaviour)
are deliberately not overridable: a custom field is something a customer may
fill in at checkout.

## 5. Optional: single sign-on

Turn on **Panel single sign-on** in the product. The client area then shows a
button that opens the panel with the customer already signed in, and the same
button appears on the service page in the WHMCS admin. The ticket behind it is
single-use, expires in a minute, is never valid for another administrator, and
still requires the second factor if the customer has 2FA enabled.

## What the module does not do

- **Change passwords.** The panel refuses password changes made through a
  token, so there is no Change Password action in WHMCS. The customer changes
  the password in the panel profile or with the panel's password reset. The
  password generated when the module creates an account is stored on the
  WHMCS service, so the customer sees it in the client area.
- **Guarantee unique ports under concurrent orders.** The panel does not
  check that an address:port pair is unused. The module scans the node's busy
  ports before creating a server, which is enough while WHMCS processes orders
  one at a time; two servers created for the same port at the same instant
  would both be accepted by the panel.

## Multiple panel instances

If the panel runs as several instances behind a load balancer, they must share
a cache (Redis). Sign-in tickets live in the cache, so with per-instance memory
caches roughly half the sign-in attempts would fail.

## Troubleshooting

Turn on **System Settings → System Logs → Module Log**. Every panel request the
module makes is recorded there, with the token masked. If provisioning fails,
the module log entry shows the exact request and the panel's answer.

If an order fails with "the panel account is an administrator", the customer's
e-mail address belongs to an administrator account in the panel; the module
cannot attach servers to administrators through a token.
