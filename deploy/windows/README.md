# Windows native deployment

Source: `D:/x1/x2/YXHK`, branch `7.x`.
Fixed releases: `E:/pxy-deploy/YXHK/instances/<tenant>/releases/<version>`.
Private data: `E:/pxy-runtime/YXHK/tenants/<tenant>`.
Runtime tools: `E:/pxy-deploy/YXHK/tools`.

The workstation uses PHP 8.3 NTS with Caddy/FastCGI. Docker is not required.
The generated local bundle uses the Windows Sass launcher and passes only the
small environment needed by Sass, avoiding Windows environment block limits.
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

After preparation, `Initialize-YxhkDatabase.ps1 -AdminEmail <email>` opens a
local MySQL credential prompt, creates a fresh customer database/account, and
installs the schema. Existing customer databases/configuration are never reused
or overwritten. The generated initial login is saved only in the protected
customer configuration directory; the database administrator password is not
saved. If creation fails partway, inspect the new database/account before retrying.

The private OPS repository provides `Install-YxhkServices.ps1`, which needs
administrator privileges for first installation. It assigns a dedicated Windows
account to PHP, HTTP and scheduled maintenance. HTTP defaults to loopback 3034,
FastCGI to loopback 9004. It validates Caddy and requires a login page health
check; it does not configure public ingress. Email workers and campaign execution
remain disabled. Future activation/rollback for an already installed service is
not implemented by this first-install operation.

Before activation, back up the customer database and record the prior release.
A code rollback cannot reverse a database migration. Preserve runtime data and
customer configuration during cleanup.
