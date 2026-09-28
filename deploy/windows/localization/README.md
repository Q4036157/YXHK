# YXHK Chinese localization

These versioned `zh_CN` resources are loaded after Mautic's bundled, downloaded,
and tenant override translations. English fallback resources remain unchanged.
Downloaded official language packs are not modified by this overlay.

Protocol names, URLs, brands, search operators, HTML markup, and runtime tokens
must retain their original spelling. User-created content is not translated.

The workstation release contains this overlay and activates it with the code.
Translation services are used only when preparing source text; the deployed
application has no dependency on an external translation service.

Run `php deploy/windows/localization/verify.php` to check all bundled keys and
runtime placeholders. The two `localize-system-*.php` commands rename only
unchanged installation defaults; backups are written to the tenant runtime.
They are idempotent and do not change role permissions or field identifiers.

The display dictionary also covers built-in package descriptions and PHP info
labels. Diagnostic configuration names, package identifiers, brands, and values
remain suitable for copying into support tools.
