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
- Symbols report: top Rspamd symbols over the filtered mail set (hits, clean/flagged split,
  avg score, contribution), with drill-down, for score tuning (near misses, released mails,
  rejects)
- Score distribution report over the filtered mail set, against action thresholds
- Map entry hits: record which map entries actually match mail.
  When writing map files, Rqwatch tags each entry with its row id as a multimap option
  (`SYMBOL:score:rqm_<id>`), keeping any custom symbol and score already on the entry;
  combined maps pass the ids through the Rqwatch Lua scripts. Rspamd returns the tag
  in the symbol's options, and the importer (and spool replay) counts a hit on that entry
  (hits, last hit), as it already does for fuzzy learns. Map pages show Hits and Last hit
  with a filter for idle entries and an optional cron to disable them, the mail detail page
  links each tagged symbol to its map entry, and an entry can list the mails it matched
  within a date window. Tagging is opt-in per custom map (multimap maps only, with the
  Rspamd rule symbol set in the map config) and needs a migration adding hit columns to the
  map tables.

### Translations
- Multi lang support

### UI
- Responsive/better/modern design (help would be appreciated for this)
