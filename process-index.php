<?php
	global $params;
	global $metaInput;
	global $out;
	global $outWhat;
	global $metaTable, $dataTable;
	global $rawDataOut;

	$tableClass = "nav";
	
	$lists = get_snapshot_lists(dirname(__FILE__) . "/covid-data");
	$files = $lists['files'];
	$slugs = array_map(function ($e) { return $e[0]; }, $lists['sets']);
	$timestamps = array_map(function ($e) { return $e[1]; }, $lists['sets']);
	
	$allSlugs = $lists['available slugs'];
	$availableSlugs = array_unique(array_map(function ($e) { return $e[0]; }, $lists['available slugs']));
	
	$slugLinks = array_map(function ($s) use ($allSlugs) {
		list($type, $siteslug) = explode("/", trim($s, "/"), 2) + ['', ''];
		return [
		$s,	$type,
		make_browse_link(["class" => "browse ".$siteslug, "href" => ["slug" => $s], "text" => $siteslug]),
		get_most_recent_timestamp(get_slug_timestamps($s, $allSlugs)),
		array_merge([$type], get_slug_tags($siteslug)),
	]; }, $availableSlugs);

	$oNow = new SnapshotDateTime(time());

	foreach ($slugLinks as $slugLink) :
		list($slug, $snapType, $link, $ts, $tags) = $slugLink;
		$slugpath = explode("/", trim($slug, '/'));
		
		$oLatest = new SnapshotDateTime($ts);
		$latestLink = make_browse_link(["class" => "view", "href" => ["date" => $ts, "slug" => $slug], "text" => $oLatest->human_readable()]);
		$latest = "latest: ${latestLink}";
		$ext = ($slugpath[0]=='html' ? 'html' : 'json');

		$myLink = $link;
		if ($slug==$params['slug']) :
			$myLink = preg_replace('!<a \s+!ix', '<a class="current" ', $myLink);
		endif;
		
		if (is_null($params['tag']) or in_array($params['tag'], $tags)) :
			$arX = new ArchivedSource([
				"slug" => $slug,
				"ts" => $oLatest->datetimecode(),
				"file type" => $ext
			]);
			$arXservices = get_all_archive_services_links(/*url=*/ $arX);
			$metaTable[] = [
				$snapType,
				$myLink . ' ' . $arXservices,
				implode("; ", $tags),
				"<small>${latest}</small>",
				"@class" => array_map(function ($e) { return 'tagged-'.$e; }, $tags)
			];
		endif;
	
		$all_tags = array_merge($all_tags, $tags);
	endforeach;
	usort($metaTable, function ($a, $b) { return strcmp(strip_tags($a[1]), strip_tags($b[1])); });

	$metaTable = array_merge([ ["Type", "<b>Source</b>", "<b>Tags</b>", "<b>Latest</b>"] ], $metaTable);

	$all_tags = array_unique($all_tags);
	
	$outWhat = "Listing";
	$oDateTime = new SnapshotDateTime(time());
	
	$rawDataOut = null;
	
	$dataTHEAD = [];
	$dataTBODY = [];
	$dataTHEAD = ["Source", "Type", "Timestamp"];
	foreach ($lists['sets'] as $pair) :
		list($slug, $ts) = $pair;
		$slugParts = array_filter(explode("/", trim($slug, '/'), 2)) + ['', ''];
		$slugtype = $slugParts[0]; $slugsource = $slugParts[1];
		$ext = ($slugtype=='html' ? 'html' : 'json');
		
		$oDateTime = new SnapshotDateTime($ts);

		$arX = new ArchivedSource(["slug" => $slug, "ts" => $oDateTime->datetimecode(), "file type" => $ext]);
		
		if (!array_key_exists("${tableClass} $slugsource", $dataTBODY)) :
			$dataTBODY["${tableClass} $slugsource"] = [];
		endif;

		$checksum = $arX->payload_checksum();
		$dataTBODY["${tableClass} $slugsource"][] = [
			"Type" => $slugtype,
			"Source" => $slugsource,
			"Timestamp" => make_browse_link([
			"href" => ["slug" => $slug, "date" => $ts],
			"text" => $oDateTime->human_readable(),
			"title" => is_null($checksum) ? "source: ".$arX->source_url() : "checksum: ".$arX->payload_checksum(),
		])];
	endforeach;
	$dataTable = array_map(function ($e) use ($dataTHEAD) { return ["THEAD" => $dataTHEAD, "TBODY" => $e]; }, $dataTBODY);
