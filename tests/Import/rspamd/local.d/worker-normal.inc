# Rspamd normal workers for qa/import-tests.sh: one, so the map readiness
# probes and the tests reach the same worker (each loads maps on its own).
count = 1;
