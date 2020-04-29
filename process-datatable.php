<?php
	global $params;
	global $metaInput;
	global $out;
	global $outWhat;
	global $metaTable, $dataTable;
	global $rawDataOut;
	
	$slug = $params['slug'];
	$ext = 'json|csv';
	
	$arX = new ArchivedSource(["slug" => $slug, "ts" => $oDateTime->datetimecode(), "file type" => $ext]);

	$metaInput['file'] = $arX->payload_file();
	$outWhat = "Data Set";
	
	$json = $arX->payload_contents();

	$hash = json_decode($json);
	$haveTable = false;
	if (is_null($hash)) :
		$lines = preg_split("/[\r\n]+/", $json);
		$firstLine = array_shift($lines);
		$firstRow = str_getcsv($firstLine);
		if (count($firstRow) > 0) :
			$sourceType = 'csv';
			$dataTable['THEAD'] = $firstRow;
		
			foreach ($lines as $line) :
				$csv = str_getcsv($line);
				if (is_array($csv)) :
					if (count($csv) > 1) :
						$row = [];
						foreach ($csv as $idx => $td) :
							if (array_key_exists($idx, $dataTable['THEAD'])) :
								$th = $dataTable['THEAD'][$idx];
							else :
								$th = "Col-${idx}";
								$dataTable['THEAD'][] = $th;
							endif;
							
							$row[$th] = $td;
						endforeach;
						$dataTable['TBODY'][] = $row;
					endif;
				endif;
			endforeach;
		endif;
		
		if (count($dataTable) > 0) :
			$haveTable = true;
			$dataTable = ["data" => $dataTable];
		endif;

		$rawDataOut = $json;
	else :
		$sourceType = 'json';
		$haveTable = true;
		$dataTable = get_json_to_table($hash, $params['slug']);	
		$rawDataOut = $hash;
	endif;
	
		header("Content-type: text/html");
		
		// URL of snapshot: Get it from the file, if available
		$sourceUrl = $arX->source_url();
		
		if (!is_null($sourceUrl)) :
			$source = parse_url($sourceUrl);
			$metaTable[] = [
				"Source",
				'<a href="'.htmlspecialchars($sourceUrl).'">'.$source['host'].'</a>'
					.get_all_archive_services_links($arX),
			];
		endif;
		if (!is_null($oDateTime)) :
			$lists = get_snapshot_lists(dirname(__FILE__) . "/covid-data");
			$allSlugs = $lists['available slugs'];
			$allTS = array_map(function ($e) { return new SnapshotDateTime($e); }, get_slug_timestamps($slug, $allSlugs));
			$selector=make_browse_selector(["action" => "/".ALACOVDAT_URL, "slug" => $slug, "date" => $allTS, "selected" => $oDateTime]);
			
			$metaTable[] = ["Timestamp", $selector];
		endif;
		$viewOptions = ['<a href="#view-json-source" class="tab">'.$sourceType.' source</a>'];
		
		if ($haveTable) :
			$viewOptions = array_merge(
			['<a href="#view-data-table" class="tab">data table</a>'],
			$viewOptions);
		endif;
		
		$metaTable[] = ["View",  implode(" / ", $viewOptions)];

