
Name: app-samba-directory
Epoch: 1
Version: 2.1.10
Release: 1%{dist}
Summary: Samba Directory
License: GPLv3
Group: ClearOS/Apps
Source: %{name}-%{version}.tar.gz
Buildarch: noarch
Requires: %{name}-core = 1:%{version}-%{release}
Requires: app-base
Requires: app-users
Requires: app-groups => 1:1.2.3

%description
The Samba Directory app provides the necessary tools for users, groups, accounts and other directory services.

%package core
Summary: Samba Directory - Core
License: LGPLv3
Group: ClearOS/Libraries
Provides: system-ldap-driver
Provides: system-windows-driver
Provides: system-accounts
Provides: system-accounts-driver
Provides: system-groups-driver
Provides: system-users-driver
Requires: app-base-core
Requires: app-accounts >= 1:1.5.5
Requires: app-groups-core >= 1:1.5.10
Requires: app-ldap-core >= 1:1.5.5
Requires: app-mode-core
Requires: app-network-core
Requires: app-ntp-core
Requires: app-samba-common-core >= 1:1.5.5
Requires: krb5-workstation
Requires: nscd
Requires: samba4
Requires: samba4-dc
Requires: samba4-client
Requires: samba4-winbind-clients

%description core
The Samba Directory app provides the necessary tools for users, groups, accounts and other directory services.

This package provides the core API and libraries.

%prep
%setup -q
%build

%install
mkdir -p -m 755 %{buildroot}/usr/clearos/apps/samba_directory
cp -r * %{buildroot}/usr/clearos/apps/samba_directory/

install -d -m 0755 %{buildroot}/var/clearos/samba_directory
install -d -m 0755 %{buildroot}/var/clearos/samba_directory/backup
install -d -m 0755 %{buildroot}/var/clearos/samba_directory/extensions
install -d -m 0775 %{buildroot}/var/clearos/samba_directory/lock
install -D -m 0755 packaging/app-samba-dc-initialize %{buildroot}/usr/sbin/app-samba-dc-initialize
install -D -m 0644 packaging/samba.php %{buildroot}/var/clearos/base/daemon/samba.php
install -D -m 0644 packaging/samba_directory.php %{buildroot}/var/clearos/accounts/drivers/samba_directory.php

%post
logger -p local6.notice -t installer 'app-samba-directory - installing'

%post core
logger -p local6.notice -t installer 'app-samba-directory-core - installing'

if [ $1 -eq 1 ]; then
    [ -x /usr/clearos/apps/samba_directory/deploy/install ] && /usr/clearos/apps/samba_directory/deploy/install
fi

[ -x /usr/clearos/apps/samba_directory/deploy/upgrade ] && /usr/clearos/apps/samba_directory/deploy/upgrade

exit 0

%preun
if [ $1 -eq 0 ]; then
    logger -p local6.notice -t installer 'app-samba-directory - uninstalling'
fi

%preun core
if [ $1 -eq 0 ]; then
    logger -p local6.notice -t installer 'app-samba-directory-core - uninstalling'
    [ -x /usr/clearos/apps/samba_directory/deploy/uninstall ] && /usr/clearos/apps/samba_directory/deploy/uninstall
fi

exit 0

%files
%defattr(-,root,root)
/usr/clearos/apps/samba_directory/controllers
/usr/clearos/apps/samba_directory/htdocs
/usr/clearos/apps/samba_directory/views

%files core
%defattr(-,root,root)
%exclude /usr/clearos/apps/samba_directory/packaging
%dir /usr/clearos/apps/samba_directory
%dir /var/clearos/samba_directory
%dir /var/clearos/samba_directory/backup
%dir /var/clearos/samba_directory/extensions
%dir %attr(0775,root,webconfig) /var/clearos/samba_directory/lock
/usr/clearos/apps/samba_directory/deploy
/usr/clearos/apps/samba_directory/language
/usr/clearos/apps/samba_directory/libraries
/usr/sbin/app-samba-dc-initialize
/var/clearos/base/daemon/samba.php
/var/clearos/accounts/drivers/samba_directory.php
