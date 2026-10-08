# /etc/rspamd/local.d/worker-fuzzy.inc

# https://docs.rspamd.com/workers/fuzzy_storage

# merge this into an existing file

# Inner options only: rspamd.conf includes this inside worker "fuzzy" { }.
# count = 1 enables the worker (stock is -1).
# Needs a Redis shared by all rspamd servers so a hash learned on one is known on all.

count = 1;
backend = "redis";
expire = 90d;
