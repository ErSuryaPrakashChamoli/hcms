# Production security boundaries (closure)

The exact boundaries introduced or confirmed in the production readiness closure. Each is enforced in
code at the request layer, not in the UI, and covered by the tests named below.

## 1. Inbound: signed BGV callback

`POST /api/v1/bgv/cases/{reference}/checks`. In order, before the body is read or applied:

| Step | Rule | Failure |
|---|---|---|
| 1 | API key with scope `bgv.write` (binds the tenant; suspended tenants refused) | 401 / 403 |
| 2 | An Integration Hub system of kind `bgv`, in the key's tenant, **bound to that exact key** | 403 `integration_required` (audited) |
| 3 | System active | 409 `integration_inactive` |
| 4 | `X-PeopleOS-Signature: sha256=HMAC-SHA256(system secret, "<X-PeopleOS-Timestamp>.<raw body>")`, compared with `hash_equals` | 401 `invalid_signature` (audited; also for a missing signature or a modified body) |
| 5 | Timestamp inside the system's window (default ±300 s) | 401 `stale_timestamp` (audited) |
| 6 | Payload shape (`checks[]` with known type / status, notes ≤ 2000) | 422; nothing recorded |
| 7 | Stored once per `Idempotency-Key`, else `X-PeopleOS-Event-Id`, else `sha256(timestamp.body)` | A replay or duplicate returns the stored outcome (`Idempotent-Replayed: true`); same key with a different body → 409 |
| 8 | Applied by the `bgv.results` handler inside the hub transaction; case looked up only in the tenant | 404 for another tenant's case |

The payload is stored encrypted and audit rows carry metadata only. Neither signatures nor secrets are
logged. **Pending:** provider-specific signature schemes. No vendor contract is available, so a
provider must sign with this scheme, or an adapter must be added when its contract is known.

Tests: `tests/Feature/Bgv/BgvCallbackSecurityTest.php`, `tests/MySql/ClosureConcurrencyTest.php`.

## 2. Outbound: SSRF guard

Applies to every request to a tenant-configured destination:

- webhook endpoints (`Webhooks::attempt`);
- workflow webhook nodes (`SendWebhook`);
- SSO token and userinfo endpoints (`Sso::identity`).

The AI provider endpoint is operator-configured (env) and outside tenant control.

`App\Support\Http\OutboundUrlGuard` + `SafeHttp`:

1. **URL:** https only (http only with `PEOPLEOS_OUTBOUND_ALLOW_HTTP=true`); no credentials; port in `PEOPLEOS_OUTBOUND_ALLOWED_PORTS` (default 443, 80, 8443, 8080).
2. **Host name:** refused if it is:
   - `localhost`, `*.localhost`, `*.local`, `*.internal`, `*.home.arpa` or `*.localdomain`;
   - a metadata name (`metadata`, `metadata.google.internal`, `instance-data`);
   - a single-label name;
   - an ambiguous numeric form (`127.1`, `2130706433`, `0x7f.0.0.1`).
3. **After DNS:** the host must resolve, and **every** A / AAAA answer must be public. Refused ranges:
   - **IPv4:** 0/8, 10/8, 100.64/10, 127/8, 169.254/16 (incl. 169.254.169.254), 172.16/12, 192.0.0/24, 192.0.2/24, 192.88.99/24, 192.168/16, 198.18/15, 198.51.100/24, 203.0.113/24, 224/4, 240/4, 255.255.255.255.
   - **IPv6:** ::, ::1, 100::/64, 2001::/23, 2001:db8::/32, fc00::/7 (incl. fd00:ec2::254), fe80::/10, fec0::/10, ff00::/8.
   - **IPv4 embedded in IPv6** (mapped, compatible, NAT64 64:ff9b::/96, 6to4 2002::/16, Teredo) is checked as IPv4.
4. **Rebinding:** the connection is pinned to the validated address with `CURLOPT_RESOLVE`; the name is resolved once per request.
5. **Redirects:** never followed (`allow_redirects=false`); a 3xx is a failed delivery.
6. **Saving:** endpoint URLs and SSO token / userinfo URLs are checked without DNS (model hooks and form rules). The full check runs on every request.
7. **Exemptions:** only operators, by exact host name (`PEOPLEOS_OUTBOUND_ALLOWED_HOSTS`), for example an on-premises receiver. Tenants cannot exempt anything.
8. **Egress proxy:** if one is configured, the proxy resolves names and **must enforce the same policy**. Network egress filtering (no route from application nodes to metadata or private ranges) remains recommended as defence in depth.

A blocked webhook is recorded on the delivery (`Blocked: …`) and audited as
`OUTBOUND_DESTINATION_BLOCKED`, with the reason and the redacted destination only. A blocked
workflow node records `webhook.blocked` and is not retried. A blocked SSO endpoint fails the login.

Tests: `tests/Feature/Security/OutboundSsrfTest.php`, covering:
- localhost, 127/8, ::1, private IPv4 and IPv6, link-local, metadata, unspecified, mapped forms, numeric hosts, schemes, credentials, ports;
- a public address (allowed);
- a host resolving to a private address, and a mixed answer;
- rebinding pinning;
- a redirect to the metadata address;
- the webhook / workflow / SSO paths;
- secret safety.

## 3. Secret safety on outbound paths

- **Webhook delivery records:** the receiver's response excerpt, or a transport error with the configured URL reduced to scheme and host. Never the endpoint secret or the signature.
- **Workflow action logs:** scheme and host only; never the configured path or query, headers or body.
- **`SendWebhook` jobs** are `ShouldBeEncrypted`, so the queued payload (URL, headers including any `Authorization`, body) is encrypted in the `jobs` / `failed_jobs` tables.
- **Logs:** the redaction tap masks secrets, tokens, `Authorization`, signatures and identifiers on every channel. No outbound path logs request bodies or headers.
- **Audit:** metadata only (event, reason, redacted destination).
