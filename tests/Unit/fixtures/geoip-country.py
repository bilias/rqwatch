# Rqwatch
# Copyright (C) 2026 Giannis Kapetanakis
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at http://mozilla.org/MPL/2.0/.
#
# Writes geoip-country.mmdb, the made-up country database of
# tests/Unit/Helpers/CountryTest.php (its own data, not MaxMind's):
# 2.2.2.0/24 and 2a02:2::/32 Greece, 3.3.3.0/24 a record without a
# country, 4.4.4.0/24 a country with an empty name. To change it:
# pip install mmdb-writer, then python3 tests/Unit/fixtures/geoip-country.py
from pathlib import Path

from mmdb_writer import MMDBWriter
from netaddr import IPSet

w = MMDBWriter(ip_version=6, database_type='GeoLite2-Country', languages=['en'],
               description='rqwatch unit test data', ipv4_compatible=True)
w.insert_network(IPSet(['2.2.2.0/24', '2a02:2::/32']), {'country': {'iso_code': 'GR', 'names': {'en': 'Greece'}}})
w.insert_network(IPSet(['3.3.3.0/24']), {'continent': {'code': 'EU', 'names': {'en': 'Europe'}}})
w.insert_network(IPSet(['4.4.4.0/24']), {'country': {'iso_code': 'XX', 'names': {'en': ''}}})
w.to_db_file(str(Path(__file__).with_suffix('.mmdb')))
