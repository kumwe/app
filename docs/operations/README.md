# Kumwe operations

These runbooks cover installation, deployment, monitoring, backup, recovery,
upgrades, release verification, and incident response for Kumwe.

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
