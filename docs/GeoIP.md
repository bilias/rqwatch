# GeoIP
As root:
```
wget https://github.com/maxmind/geoipupdate/releases/download/v7.1.1/geoipupdate_7.1.1_linux_arm64.rpm

dnf localinstall ./geoipupdate_7.1.1_linux_amd64.rpm

edit /etc/GeoIP.conf

AccountID XXXXX
LicenseKey XXXXXXXXX
EditionIDs GeoLite2-Country GeoLite2-City
```

For account details:
https://www.maxmind.com/en/accounts/current/license-key

More details:
https://dev.maxmind.com/geoip/updating-databases/

```
cat << EOF > /etc/cron.daily/geoipupdate
#!/usr/bin/env bash
/usr/bin/geoipupdate
EOF

chmod 755 /etc/cron.daily/geoipupdate
/etc/cron.daily/geoipupdate
```
