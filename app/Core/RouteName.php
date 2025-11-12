<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Core;

enum RouteName: string
{
	case CONFIG_RELOAD = 'config_reload';
	case LOGIN = 'login';
	case LOGOUT = 'logout';
	case HOMEPAGE = 'homepage';
	case ADMIN_HOMEPAGE = 'admin_homepage';
	case SEARCH_RESULTS = 'search_results';
	case ADMIN_SEARCH_RESULTS = 'admin_search_results';
	case REPORTS = 'reports';
	case ADMIN_REPORTS = 'admin_reports';
	case DAY_LOGS = 'day_logs';
	case ADMIN_DAY_LOGS = 'admin_day_logs';
	case QUARANTINE = 'quarantine';
	case ADMIN_QUARANTINE = 'admin_quarantine';
	case QUARANTINE_DAY = 'quarantine_day';
	case ADMIN_QUARANTINE_DAY = 'admin_quarantine_day';
	case DETAIL = 'detail';
	case ADMIN_DETAIL = 'admin_detail';
	case SEARCH = 'search';
	case ADMIN_SEARCH = 'admin_search';
	case SEARCH_FILTER_DEL = 'search_filter_del';
	case ADMIN_SEARCH_FILTER_DEL = 'admin_search_filter_del';
	case ADMIN_USERS = 'admin_users';
	case ADMIN_USERADD = 'admin_useradd';
	case ADMIN_USEREDIT = 'admin_useredit';
	case ADMIN_USERDEL = 'admin_userdel';
	case ADMIN_USERSEARCH = 'admin_usersearch';
	case ADMIN_USERLOGINAS = 'admin_userloginas';
	case ADMIN_USER = 'admin_user';
	case ADMIN_ALIASES = 'admin_aliases';
	case ADMIN_ALIASES_SEARCH = 'admin_aliases_search';
	case ADMIN_ALIASES_ADD = 'admin_aliases_add';
	case ADMIN_ALIASES_EDIT = 'admin_aliases_edit';
	case ADMIN_ALIASES_DEL = 'admin_aliases_del';
	case PROFILE = 'profile';
	case SHOWMAIL = 'showmail';
	case ADMIN_SHOWMAIL = 'admin_showmail';
	case ATTACHSAVE = 'attachsave';
	case ADMIN_ATTACHSAVE = 'admin_attachsave';
	case ATTACHOPEN = 'attachopen';
	case ADMIN_ATTACHOPEN = 'admin_attachopen';
	case RELEASEMAIL = 'releasemail';
	case ADMIN_RELEASEMAIL = 'admin_releasemail';
	case ADMIN_MAPS = 'admin_maps';
	case MAPS = 'maps';
	case ADMIN_MAP_SHOW_ALL = 'admin_map_show_all';
	case ADMIN_MAPS_CUSTOM_SHOW = 'admin_maps_custom_show';
	case ADMIN_MAPS_CUSTOM_ADD = 'admin_maps_custom_add';
	case ADMIN_MAPS_CUSTOM_EDIT = 'admin_maps_custom_edit';
	case ADMIN_MAPS_CUSTOM_DEL = 'admin_maps_custom_del';
	case ADMIN_MAP_SEARCH_ENTRY = 'admin_map_search_entry';
	case MAP_SHOW_ALL = 'map_show_all';
	case ADMIN_MAP_SHOW = 'admin_map_show';
	case MAP_SHOW = 'map_show';
	case ADMIN_MAP_ADD_ENTRY = 'admin_map_add_entry';
	case MAP_ADD_ENTRY = 'map_add_entry';
	case ADMIN_MAP_EDIT_ENTRY = 'admin_map_edit_entry';
	case MAP_EDIT_ENTRY = 'map_edit_entry';
	case ADMIN_MAP_DEL_ALL_ENTRIES = 'admin_map_del_all_entries';
	case MAP_DEL_ALL_ENTRIES = 'map_del_all_entries';
	case ADMIN_MAP_DEL_ENTRY = 'admin_map_del_entry';
	case MAP_DEL_ENTRY = 'map_del_entry';
	case ADMIN_MAP_TOGGLE_ENTRY = 'admin_map_toggle_entry';
	case MAP_TOGGLE_ENTRY = 'map_toggle_entry';
}
