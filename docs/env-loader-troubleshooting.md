# Encryption-key loading hotfix

## What this fixes

The loader previously treated an empty hosting-injected encryption/auth/setup
variable as an explicit override. A populated value under that same name in
`.env` was then ignored. Blank canonical variables could also block VP_ alias
resolution. Five regression cases reproduced failures before repair, including
the exact production `ENCRYPTION_KEY is not set` exception.

Only empty encryption/auth/setup variables may now be filled from populated
file values or aliases. Non-empty values keep their existing precedence. Empty
database passwords and other settings retain their old behaviour. No key is
automatically generated, no encrypted data is rewritten, and production still
refuses genuinely missing or invalid keys.

The parser also accepts an optional UTF-8 BOM at the beginning of `.env`.
UTF-16, escaped underscores, wrong filenames and missing/unreadable files still
need operator correction; the diagnostic reports those conditions.

## Install the small patch in cPanel

1. Back up `.env` and `application/core/Env.php`. Do not share the backups or
   place publicly downloadable archives of them in the document root.
2. Upload `env-loader-fix.zip` to the domain's actual document root, the folder
   containing the panel's `index.php`, and extract it there. Allow replacement
   of `application/core/Env.php`.
3. Do NOT replace `.env`, change your encryption key, import SQL or run migrations
   for this hotfix. The patch contains no secrets, configuration file or SQL.
4. Reload the panel. If the host disables PHP opcode timestamp checks, request
   an opcode-cache/PHP-worker restart from cPanel or your hosting support. Do
   not create a publicly accessible PHP cache-reset endpoint.
5. If the error remains, open `/env-check.php` on your domain. It returns only
   status flags and fixed troubleshooting messages, never key/password values,
   exact lengths, absolute server paths, or database connection results. The
   tool is read-only and does not bootstrap the application. Its minimum-length
   flag is not a key-strength check or proof that the full application works.
6. Share that diagnostic output if assistance is needed. Never share `.env`.
7. Delete `env-check.php` and the uploaded ZIP after troubleshooting.

This changes the repository files only until you install the patch on your host.
The hosted server's root cause has not yet been confirmed. A file uploaded into
the wrong directory, named `.env.txt`, unreadable by PHP, or containing a blank
key will still require correction. Do not weaken permissions to 0777 or disable
the production encryption-key requirement.

The optional standalone diagnostic is intentionally not added to the normal
full deployment archive. The small patch includes it for temporary use.

## Validation

- Actual loader/production key resolution: 9 tests, 17 assertions passed.
- Read-only/redacted diagnostics: 11 tests, 50 assertions passed.
- Full offline suite: 2,007 tests, 21,830 assertions, zero failures, one native
  file-locking probe skipped under WASM.
- PHP syntax and deployment archive checks passed. This is not live hosting
  acceptance, native PHP/MySQL proof, or confirmation of the remote root cause.
