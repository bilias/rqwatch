#!/bin/bash
# Rqwatch
# Copyright (C) 2026 Giannis Kapetanakis
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at http://mozilla.org/MPL/2.0/.
#
# Starts the web server for start_web_container (qa/lib.sh), as root inside
# the qa/web/Containerfile image, with the copy at /var/www/html/rqwatch:
# contrib/apache-rqwatch.conf and qa/web/php-fpm-rqwatch.conf installed as
# docs/INSTALL.md installs them, except that httpd listens on 127.0.0.1 at
# each port in $WEB_PORTS, all serving rqwatch's vhost (the container shares
# the host's network, where rootless Podman can't bind port 80), and uploads
# go to $UPLOAD_TMP_DIR.
set -euo pipefail
cd /var/www/html/rqwatch

# install_conf SOURCE DEST EXPECTED SED_EXPR: SOURCE through SED_EXPR to DEST,
# failing if the result lacks the line EXPECTED (the shipped file changed)
install_conf() {
	sed -e "$4" "$1" > "$2"
	if ! grep -qxF "$3" "$2"; then
		echo "web-start.sh: '$3' not set in $2: has $1 changed?" >&2
		exit 1
	fi
}

# one Listen line per port, and the vhost on all of them
read -ra ports <<< "$WEB_PORTS"
listen=
vhost=
for port in "${ports[@]}"; do
	listen+="${listen:+\\n}Listen 127.0.0.1:${port}"
	vhost+="${vhost:+ }*:${port}"
done

install_conf /etc/httpd/conf/httpd.conf /etc/httpd/conf/httpd.conf.qa \
	"Listen 127.0.0.1:${ports[0]}" "s/^Listen 80$/${listen}/"
mv /etc/httpd/conf/httpd.conf.qa /etc/httpd/conf/httpd.conf
install_conf contrib/apache-rqwatch.conf /etc/httpd/conf.d/rqwatch.conf \
	"<VirtualHost ${vhost}>" "s/^<VirtualHost \*:80>$/<VirtualHost ${vhost}>/"
install_conf qa/web/php-fpm-rqwatch.conf /etc/php-fpm.d/rqwatch.conf \
	"php_admin_value[upload_tmp_dir] = ${UPLOAD_TMP_DIR}" \
	"s#^php_admin_value\[upload_tmp_dir\] = .*#php_admin_value[upload_tmp_dir] = ${UPLOAD_TMP_DIR}#"

# systemd-tmpfiles creates it on a host; nothing does in a container
mkdir -p /run/php-fpm
php-fpm -F &
exec httpd -DFOREGROUND
