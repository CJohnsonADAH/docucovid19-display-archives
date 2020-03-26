<?php

$DATESTAMP = $_REQUEST['date'];
$JSON_FILE = dirname(__FILE__) . "/covid-data/capture-${DATESTAMP}.json";

echo file_get_contents($JSON_FILE);
