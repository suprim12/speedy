<?php
/**
 * Speedy Cache plugin for Craft CMS
 *
 * @link      https://suprim.com.au
 * @author suprim <support@suprim.com.au> <https://suprim.com.au>
 * @copyright Copyright (c) 2026 suprim
 */

namespace suprim\speedy\db;

use craft\db\Command;
use suprim\speedy\Plugin;

/**
 * Craft’s database command, noting each query and where it was run from while Speedy counts a rebuilt page’s queries.
 */
class CountedCommand extends Command
{
    protected function queryInternal($method, $fetchMode = null)
    {
        // Queries that may come from the query cache don’t reach the database each time, so aren’t counted.
        if ($this->db->getQueryCacheInfo($this->queryCacheDuration, $this->queryCacheDependency) === null) {
            Plugin::getInstance()->queries->note($this->getRawSql(), debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 60));
        }

        return parent::queryInternal($method, $fetchMode);
    }
}
