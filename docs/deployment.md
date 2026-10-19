# Local demo and database state

KhetSaathi is currently configured as a local, shared-workspace academic prototype. Run the existing `Start-KhetSaathi.ps1` script and open `http://127.0.0.1:8000/`. The development server, startup script, scheduled task, port, and network exposure settings are intentionally unchanged.

## Current database state

The live database retains the account and ownership additions made by the previous multi-user migration. The application no longer implements authentication, account management, or ownership-based access checks, and all operational records are shown in the shared workspace. The schema and existing records have not been rolled back or rewritten as part of restoring the application code.

The migrated schema makes `owner_user_id` mandatory on operational rows. New farmer, field, planting, and support-request inserts therefore use the first existing administrator account ID only as a schema-compatibility foreign-key value. This value is not an authorization control. If the account table or administrator row is missing, those inserts fail with the normal safe application error. Existing row ownership values are left unchanged.

The verified SQL backup is kept outside the project. It was not modified or restored during this code rollback. Do not run the former multi-user migration. A database schema rollback is a separate operation and must not be attempted without a reviewed migration and a verified restore procedure.

## Security and access limitation

There is no login or authorization in the restored app. Anyone who can reach the local server can use its record-management and operational-reset features. Keep the server bound to its existing loopback interface and do not expose it to a LAN or the public internet. CSRF validation, prepared statements, server-side validation, output escaping, and database relationship checks remain application protections; they are not user access controls.

The support undo stack and recent activity list remain PHP-session state. They are not persistent audit history and can be lost when the session ends or is cleared.
