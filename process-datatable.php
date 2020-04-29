<?php
	global $params;
	global $metaInput;
	global $out;
	global $outWhat;
	global $metaTable, $dataTable;
	global $rawDataOut;
	
	$slug = $params['slug'];
	$ext = 'json';
	
	$arX = new ArchivedSource(["slug" => $slug, "ts" => $oDateTime->datetimecode(), "file type" => $ext]);

	$metaInput['file'] = $arX->payload_file();
	$outWhat = "Data Set";
	
	$json = $arX->payload_contents();

	$hash = json_decode($json);
	if (is_null($hash)) :
		header("Content-type: text/plain");
		echo $json;
	else :
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
		$viewOptions = ['<a href="#view-json-source" class="tab">json source</a>'];
		
		$dataTable = get_json_to_table($hash, $params['slug']);

		if (count($dataTable['THEAD']) + count($dataTable['TBODY']) > 0) :
			$viewOptions = array_merge(
			['<a href="#view-data-table" class="tab">data table</a>'],
			$viewOptions);
		endif;
		
		$metaTable[] = ["View",  implode(" / ", $viewOptions)];

		$rawDataOut = $hash;
	endif;
