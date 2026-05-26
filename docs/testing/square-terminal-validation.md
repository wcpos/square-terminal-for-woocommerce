# Square Terminal validation evidence

## Local wcpos.local

Status: pending local WordPress installation discovery.

Checklist:
- [ ] Build scoped vendor.
- [ ] Install plugin on wcpos.local.
- [ ] Validate gateway settings and missing credential messages.
- [ ] Validate Device Code UI and exact webhook URL display.
- [ ] Validate order-pay UI and Payment Log.
- [ ] Verify AJAX rejects order-id-only mutations.
- [ ] Verify webhook simulation is debug-only/admin-only and shares processing path.

## Hosted dev-pro.wcpos.com

Status: blocked until Square Sandbox credentials and SSH deployment credentials are available.

## demo.wcpos.com

Status: final smoke only, not run.

## Evidence recorded 2026-05-26

Local discovery command:

```bash
find /Users/kilbot -maxdepth 4 -name wp-config.php
```

Found multiple `.wp-env` WordPress roots, including `/Users/kilbot/.wp-env/wp-env-tmp-wcpos-wp-env-58913-98de6a17/WordPress/wp-config.php`.

`curl -I http://wcpos.local` returned `HTTP/1.1 200 OK` with PHP `8.2.23`, proving the local hostname responds.

A plugin copy was attempted to `/Users/kilbot/.wp-env/wp-env-tmp-wcpos-wp-env-58913-98de6a17/WordPress/wp-content/plugins/square-terminal-for-woocommerce`, excluding unscoped `vendor/`, tests, build cache, and git metadata. Host-side PHP activation was blocked because the wp-env DB host `mysql` is only resolvable inside the container network:

```text
php_network_getaddresses: getaddrinfo for mysql failed
Error establishing a database connection
```

`wp` CLI is not installed on the host. Further wcpos.local activation/checklist validation needs the correct running container/CLI entrypoint for this local environment.
