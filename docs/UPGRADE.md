# Upgrading

Unpack the new release over `modules/servers/gameap/`. The module keeps no
database schema of its own, and the link between a WHMCS service and its panel
server is stored in WHMCS service properties.

## Upgrading from 1.0.0

1.0.0 was not usable against GameAP 4.5.0 (see [AUDIT.md](AUDIT.md)); the
release that follows it changes a few visible things:

- **Token location.** The token is now read from the server's Password field
  first, Access Hash second. Nothing breaks if it stays in Access Hash, but
  that field is stored in clear text by WHMCS — move it.
- **Install on create** became a yes/no dropdown. Existing products show
  "yes", which is what the old checkbox effectively did.
- **CPU and RAM limits** are now sent in the panel's units (millicores and
  bytes). Open each existing service once and use **Change Package**, or the
  admin **Refresh from panel** button followed by Change Package, so the panel
  receives the corrected values; a limit sent by 1.0.0 was 10× too small for
  CPU and a million times too small for RAM.
- **Change Password** is gone: the panel refuses it for tokens.
- **Terminate** in delete mode now removes the server's files on the node.
- Per-service overrides are limited to slots, RAM, CPU, disk, game mod and
  server name.

After upgrading, open a product's Module Settings tab and press Save Changes
once so WHMCS re-registers the module's hook file.

## The one thing that never changes

`Config::OPTIONS` in `lib/Config.php` defines the product settings, and WHMCS
stores them positionally as `configoption1..24`. The order of that array is part
of the on-disk format: reordering or removing an entry silently reassigns every
existing product's settings — a customer's RAM limit becomes whatever now
occupies that slot.

New settings are appended, never inserted. Slots 20–24 are free; there are 19 in
use.

## Verifying an upgrade

1. Open an existing product and confirm its settings still read correctly.
2. Press **Test Connection** on the server entry — a new release may require an
   ability the token does not have yet, and this names it.
3. Open a client-area service page.
