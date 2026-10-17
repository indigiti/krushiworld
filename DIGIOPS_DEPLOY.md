# KrushiWorld — DigiOps release deployment

The `.github/workflows/digiops-release.yml` pipeline runs on changes to **main** and on manual `workflow_dispatch`. Pull requests validate code but do not publish deployment artifacts. Once tests and packaging pass, GitHub Actions uploads an artifact **exactly** named `digiops-release`. DigiOps can select that successful run/artifact after **Check update**.

## DigiOps application configuration

| Setting | Expected value |
| --- | --- |
| Repository | `indigiti/krushiworld` |
| Branch | `main` |
| Artifact | `digiops-release` |
| Public URL | `/krushiworld/` |
| Public target | `public_html/krushiworld/` |
| Private target | `private_html/krushiworld/` |

Artifact contract:

```
release/
  RELEASE.json
  public/
    index.php              # browser route + read API
    api.php                # first-prototype API compatibility route
    .htaccess
    build/                 # KrushiPulse assets
  private/
    app/                   # PHP services and templates
    bin/server-refresh.sh
    config/                # source configuration, no credentials
    collector/             # Python standard-library source collector
    VERSION
    build/release.json
```

`public/index.php` explicitly resolves `private_html/krushiworld/app/bootstrap.php` from the sibling Cloudways private folder. This layout assumes both folders are underneath the **same Cloudways application root**. This is distinct from the original single-folder development layout, which remains supported in GitHub.

## Persistent storage — important

The release payload **does not contain `storage/`**. Do not delete or overwrite `private_html/krushiworld/storage/` during deployments or rollbacks. Provision a writable `storage/` directory once; the Python collector creates its subfolders. Ensure the runtime PHP/Python user can write it. Back it up independently from release snapshots. If your DigiOps/agent version replaces the entire private target directory rather than merging selected code subdirectories while preserving `storage/`, **do not deploy until the target's persistent-path behavior is confirmed**. An artifact that omits `storage/` is not by itself proof of state preservation.

## Hosting configuration

1. Set a strong `APMC_ADMIN_TOKEN` and `APMC_PUBLIC_ORIGIN=https://YOURDOMAIN` through your hosting environment (never commit credentials).
2. Ensure PHP 8.1+, Python 3.10+, writable private storage, and HTTPS access to official APMC sources.
3. Configure the Cloudways scheduler using an absolute path to `private_html/krushiworld/bin/server-refresh.sh` (every five minutes). DigiOps does **not** install cron automatically.
4. In DigiOps, click **Check update**, inspect a successful workflow run with the exact GitHub `main` commit and `digiops-release` artifact, then select **Deploy**.
5. After deployment, open `https://YOURDOMAIN/krushiworld/` and `https://YOURDOMAIN/krushiworld/?api=health`. Confirm frontend assets, both languages, admin login, and current rate source dates/units. Check that private paths (`/krushiworld/config/`, etc.) return 404/403.

**Never label stale snapshots as current.** Confirm Pune APMC feed changes against the publisher before relying on market quotations.

## Local validation

```sh
python -m unittest discover -s apmc-rates/tests -p 'test_*.py' -v
find apmc-rates tools -type f -name '*.php' -print0 | xargs -0 -n1 php -l
php tools/package-digiops-release.php
php tools/check-digiops-release.php
```