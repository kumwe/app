# Operating @@LABEL@@

Build, inspect, run conformance, and sign the same immutable ZIP bytes. Retain the inspection checksum with deployment
records. Installation starts disabled; activation is a separate authorized operation. Before an upgrade, take the
normal database and extension-storage backups and rehearse migration rollback in a staging environment.

The migration owns only the table name allocated through `ExtensionTableNames`. Its idempotent `up()` method resumes
safely after interrupted DDL, and `down()` is limited to compensating a failed installation attempt. Normal upgrade and
uninstall data retention or removal remains controlled by Kumwe's lifecycle policy rather than by migration rollback.
