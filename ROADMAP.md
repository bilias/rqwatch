# Roadmap

## In progress
-

## Features bellow are in todo list.
-

## Features bellow might be implemented (more like a notes table)
- Configs in DB and menu entry
- Throttling mail reports to admin
- Release audit trail
- Threshold alerting (ie many reject/discards)
- Spam reports: users' Junk moves (Dovecot IMAPSieve) reported to Rqwatch, matched to the
  mail log, reviewed by admin with one-click fuzzy learn / blacklist
- Score distribution report over the filtered mail set, against action thresholds
- Map hits for regexp and glob maps: when writing map files, tag each entry with its row id
  as a multimap option (`SYMBOL:score:rqm_<id>`), so a hit credits the entry instead of only
  the map. Needs testing on a real Rspamd for rules without `dynamic_symbols`.

### Translations
- Multi lang support

### UI
- Responsive/better/modern design (help would be appreciated for this)
