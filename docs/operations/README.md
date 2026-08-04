# Kumwe operations

These runbooks describe the supported operational boundary for clean Kumwe 2.x
installations. They do not provide a Kumwe 1.x data or schema migration path.

- [Install](install.md)
- [Production deployment](deploy.md)
- [2.x upgrades](upgrade.md)
- [Backup and restore](backup-restore.md)
- [Monitoring](monitoring.md)
- [Release verification](release-verification.md)
- [Incident response](incident-response.md)

Commands assume the repository or signed release archive is the current working
directory. Run commands as an unprivileged deployment account. Keep TLS
termination, the host firewall and off-host backup storage outside the Compose
project.
