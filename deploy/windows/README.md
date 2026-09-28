# Windows native deployment

Source: `D:/x1/x2/YXHK`, branch `7.x`.
Fixed releases: `E:/pxy-deploy/YXHK/instances/<tenant>/releases/<version>`.
Private data: `E:/pxy-runtime/YXHK/tenants/<tenant>`.
Runtime tools: `E:/pxy-deploy/YXHK/tools`.

The workstation uses PHP 8.3 NTS with Caddy/FastCGI. Docker is not required.
Every customer needs a separate MySQL database and database account, runtime
directory, configuration, Windows service identity and scheduled tasks. Native
Mautic roles within one database are not a tenant isolation boundary.

`Publish-Yxhk.ps1 -TenantId owner -Preview` reports exact paths and commit.
Without `-Preview`, it exports that commit and builds production dependencies
and assets. It writes `prepared.json`; it does not activate a service or migrate
an existing database. A failed build leaves the previous instance unchanged.

Production provisioning must protect customer configuration with NTFS ACLs,
bind PHP and HTTP to loopback, block source/configuration paths in Caddy, and
check application and database health before switching the active release.
Each release redirects Mautic local configuration to the private customer
directory through generated `config/paths_local.php`; uploaded images/files
persist through release changes. Cache, temporary files, sessions and imports
must also use that customer's runtime directory in local configuration.

The initial installation reuses the workstation MySQL service. Database
administrator credentials must be entered locally, never in chat, process
arguments or Git. Email delivery is deferred; use `null://null` until configured.
Public domain, PXYLH account handoff and subscriptions are separate integration
steps. This deployment entry does not implement billing or single sign-on.

Before activation, back up the customer database and record the prior release.
A code rollback cannot reverse a database migration. Preserve runtime data and
customer configuration during cleanup.
