local logger = require "rspamd_logger"
logger.infox("RQWATCH_MIME_FROM_RCPT_TO_WHITELIST loaded")

local function trim(s)
  return (s:gsub("^%s*(.-)%s*$", "%1"))
end

local from_to_map = rspamd_config:add_map{
  type = "set",
  description = "Whitelist MIME From|To combinations",
  url = "http://127.0.0.1/maps/mime_from_rcpt_to_whitelist.txt"
}

rspamd_config:register_symbol{
  name = "RQWATCH_MIME_FROM_RCPT_TO_WHITELIST",
  callback = function(task)
    local from = task:get_from('mime') -- MIME only
    local rcpt = task:get_recipients('smtp') -- SMTP only

    if from and rcpt then
      for _, f in ipairs(from) do
        for _, r in ipairs(rcpt) do
		    if f.addr and r.addr then
            local key = string.format("%s|%s", trim(f.addr:lower()), trim(r.addr:lower()))
            logger.infox(task, "RQWATCH_MIME_FROM_RCPT_TO_WHITELIST: checking key: %s", key)

            if from_to_map:get_key(key) then
              logger.infox(task, "RQWATCH_MIME_FROM_RCPT_TO_WHITELIST: Match for key: %s", key)
              task:insert_result("RQWATCH_MIME_FROM_RCPT_TO_WHITELIST", 1.0, key)
              return true
            else
              logger.infox(task, "RQWATCH_MIME_FROM_RCPT_TO_WHITELIST: No match for key: %s", key)
            end

			 else
            logger.infox(task, "RQWATCH_MIME_FROM_RCPT_TO_WHITELIST: Missing from or to addr")
		    end
		  end
      end
    else
      logger.infox(task, "RQWATCH_MIME_FROM_RCPT_TO_WHITELIST: Missing from or to")
    end

    return false
  end,
  priority = 10,
  type = "normal",
  score = 1.0, -- Metric score
}
