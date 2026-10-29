-- Rspamd rules for qa/import-tests.sh: the X-Import-Test header of a
-- test mail selects the symbol, so no scanner or network is needed.

local function import_test(task, value)
	local h = task:get_header('X-Import-Test')
	return h ~= nil and h:lower() == value
end

-- LOCAL_* is in rqwatch's $local_symbol_patterns: recorded as a map hit
rspamd_config.LOCAL_IMPORT_TEST_SPAM = {
	callback = function(task)
		if import_test(task, 'spam') then
			return true, 'spam'
		end
	end,
	score = 50.0,
	description = 'Import test: spam',
}

-- between contrib's discard (12) and rspamd's reject (15)
rspamd_config.LOCAL_IMPORT_TEST_DISCARD = {
	callback = function(task)
		if import_test(task, 'discard') then
			return true, 'discard'
		end
	end,
	score = 12.5,
	description = 'Import test: discard by score',
}

-- an antivirus hit: contrib's force_actions.conf discards on CLAM_VIRUS
rspamd_config.CLAM_VIRUS = {
	callback = function(task)
		if import_test(task, 'virus') then
			return true, 'Eicar-Test-Signature'
		end
	end,
	score = 0.0,
	group = 'antivirus',
	description = 'Import test: virus',
}
