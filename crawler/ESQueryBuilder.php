<?php


namespace JTP\Crawler;


use ESQueryBuilder\Macros;

class ESQueryBuilder extends \ESQueryBuilder\ESQueryBuilder
{
    public function __construct($index = '') {
        parent::__construct('crawl-data');
        $this->where(Macros::query_string(Helpers::getGeneralSettings()['global-filter']));
    }
}
