<?php
declare(strict_types=1);

namespace dev\winterframework\pdbc\core;

use dev\winterframework\pdbc\ResultSet;

interface RowMapper {

    /**
     * Difference Between ResultSetExtractor vs. RowMapper
     *
     * ResultSetExtractor:
     * --------------------
     *    ResultSetExtractor is suppose to extract the whole ResultSet (possibly multiple rows)
     *    i.e. One result object for the entire ResultSet using loop with next()
     *
     * RowMapper:
     * --------------------
     *     A RowMapper is suppose to map one result object per row.
     *     i.e. Looping to next row done by caller(not by this class)
     *
     *
     * @param ResultSet $rs
     * @return mixed
     */
    public function mapRow(ResultSet $rs, int $rowNum): mixed;
}