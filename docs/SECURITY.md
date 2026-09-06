# Security notes

## The one secret

The module stores a single credential: the GameAP personal access token, in the
WHMCS server's **Password** field, which WHMCS keeps encrypted at rest. The
**Access Hash** field is read as a fallback for installations set up before
this was the documented place, but note that WHMCS stores that field in clear
text — move the token to Password.

It never reaches the module log. Every request goes through one logging call in
`lib/HttpClient.php`, which strips the `Authorization` header from the logged
request and passes the token to WHMCS as a replacement variable, so it stays
masked even if it appears inside a response body. `$params` is never logged: it
carries both the token and the customer's personal details.

## What a leaked token can and cannot do

Scoped as documented in [INSTALL.md](INSTALL.md), the token can create, change
and delete game servers, and create panel users and attach servers to them. It
cannot:

- delete panel accounts
- change nodes, games or game mods
- change any account's password — the panel refuses password changes made
  through a token, which is also why the module has no Change Password action
- modify an administrator account in any way
- grant administrative roles — the panel refuses role changes that would make a
  user an administrator when the request is authenticated by a token
- issue a sign-in ticket for another administrator — refused when the ticket is
  minted and again when it is redeemed

So the worst case is disruption of the servers the module manages and access as
a customer, not control of the panel. Rotate the token by creating a new one,
pasting it into the server entry, and deleting the old one.

## What a customer can and cannot change

Only the settings that describe the tier a customer bought — slots, RAM, CPU,
disk, game mod, server name — can come from a WHMCS configurable option or
custom field. Which node a server lands on, which system user runs it, which
permissions the customer gets and what happens on suspend or terminate are
read from the product only.

## Single sign-on

The sign-in link is a ticket, not a session:

- 48 characters from the panel's CSPRNG; only its SHA-256 is stored
- valid for 60 seconds and consumed on first use, before it is validated, so a
  replay loses the race deterministically
- carried in the URL **fragment**, which browsers do not send to servers and do
  not put in the `Referer` header, and which the panel scrubs from the address
  bar before anything else happens
- unusable as a credential anywhere else: the panel's authentication does not
  recognise its prefix, so presenting it as a bearer token, a query parameter or
  a cookie is rejected
- not a way around two-factor authentication — an account with 2FA still has to
  enter a code

## Transport

TLS certificate verification is on and cannot be turned off from the module
settings. Redirects are not followed: a 3xx is treated as an error rather than
replayed against another host with the token attached.
