# The official 8.4 image installs server-minimal, which omits mysqlbinlog. Resolve its exact
# version and trusted key, then install Oracle's complete, signed server/client RPM set.
# This image belongs only to the disposable native recovery fixture.
FROM mysql:8.4 AS reference
RUN printf '%s' "$MYSQL_VERSION" > /tmp/mysql-recovery-version

FROM oraclelinux:9-slim
COPY --from=reference /etc/pki/rpm-gpg/RPM-GPG-KEY-mysql /etc/pki/rpm-gpg/RPM-GPG-KEY-mysql
COPY --from=reference /tmp/mysql-recovery-version /tmp/mysql-recovery-version
RUN set -eu; \
    printf '%s\n' \
        '[mysql-recovery]' \
        'name=MySQL 8.4 recovery fixture' \
        'baseurl=https://repo.mysql.com/yum/mysql-8.4-community/el/9/$basearch/' \
        'enabled=1' 'gpgcheck=1' 'module_hotfixes=true' \
        'gpgkey=file:///etc/pki/rpm-gpg/RPM-GPG-KEY-mysql' \
        > /etc/yum.repos.d/mysql-recovery.repo; \
    version="$(cat /tmp/mysql-recovery-version)"; \
    microdnf install -y "mysql-community-server-$version" "mysql-community-client-$version"; \
    test "$(rpm -q --qf '%{VERSION}-%{RELEASE}' mysql-community-server)" = "$version"; \
    test "$(rpm -q --qf '%{VERSION}-%{RELEASE}' mysql-community-client)" = "$version"; \
    mysqld --version; mysql --version; mysqldump --version; mysqlbinlog --version; \
    microdnf clean all

# The server RPM creates the unprivileged mysql account; the drill overrides it explicitly with
# --user 0 only where it must own the bind-mounted data directory it initialises.
USER mysql
