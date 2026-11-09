<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Bulk;

/**
 * A dataset that needs only some files of its zip archive. The importer extracts just these.
 */
interface SelectsArchiveMembers
{
    /**
     * File names (without directories) to extract from the archive.
     *
     * @return list<string>
     */
    public function archiveMembers(): array;
}
