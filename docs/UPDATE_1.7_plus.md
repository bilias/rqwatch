# Update instructions from < 1.7 Rqwatch versions

## WARNING
Rqwatch 1.7+ requires Rspamd 3.14.2+ and contains important performance optimizations.

There is also a **change in the DB schema** and upgrade must be done is steps.

## Update instructions
You should first follow [MAIL_RECIPIENTS_UPDATE](MAIL_RECIPIENTS_UPDATE.md)
in order to **update your database prior upgrading Rqwatch** code and migrate data to the new mail recipients table.

## Rspamd metadata_exporter multipart formatter
According to [Rspamd](https://docs.rspamd.com/modules/metadata_exporter/#settings-http-backend)
the `meta_headers` has been deprecated in 3.14.2
We  switched Rqwatch to use the new `multipart` formatter instead of the `default` one.

See [metadata_exporter.conf](https://github.com/bilias/rqwatch/blob/master/contrib/rspamd/local.d/metadata_exporter.conf) for configuration updates:
- Comment out `meta_headers`
- Use `formatter = "multipart";` instead of `formatter = "default";`
- Update `url` to enable the new API on Rqwatch which is
`/api/metadata_importer_multipart.php` instead of `/api/metadata_importer.php` which is still available for backwards compatibility.
