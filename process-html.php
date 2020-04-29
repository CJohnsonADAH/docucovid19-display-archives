<?php
	global $refs, $params;
	
	global $metaTable, $dataTable;
	global $out;
	global $outWhat;
	global $oDateTime;
	
	$slug = $refs[0];
	$ext = $refs[1];
	$site = $refs[3];
	
	$arX = new ArchivedSource(["slug" => $slug, "ts" => $oDateTime->datetimecode(), "file type" => $ext]);
	$arXservices = get_all_archive_services_links($arX);

	$sourceUrl = $arX->source_url();
	if (!is_null($sourceUrl)) :
		$host = $arX->source_url('host');
		$metaTable[] = ["Source", '<a href="'.htmlspecialchars($sourceUrl).'">'.$host.'</a>'.$arXservices];
	endif;
	
	$lists = get_snapshot_lists(dirname(__FILE__) . "/covid-data");
	$allSlugs = $lists['available slugs'];
	$allTS = array_map(function ($e) { return new SnapshotDateTime($e); }, get_slug_timestamps($slug, $allSlugs));
	$selector=make_browse_selector(["action" => "/".ALACOVDAT_URL, "slug" => $slug, "date" => $allTS, "selected" => $oDateTime]);
	
	$metaTable[] = ["Timestamp", $selector];
	if (!is_null($checksum=$arX->payload_checksum())) :
		$metaTable[] = ["Checksum", $checksum];
	endif;
	
	$oFile = new MirroredURL(["archive" => $arX]);

	$mirrorUrl = $oFile->get_mirror_url();
	
	$snapshotSection = '';
	$viewOptions = ['<a class="tab" href="#html-view-source">view source (html)</a>'];
	if (!is_null($warc_url=$oFile->warc_url())) :
		$viewOptions = array_merge(
			$viewOptions,
			['<a href="'.htmlspecialchars($warc_url).'">WARC</a>']
		);
	endif;
	
	if (!is_null($png_url=$arX->screenshot_url())) :
		$snapshotSection .= '<section id="html-view-screenshot"><div><img src="' . htmlspecialchars($png_url) . '" /></div></section>';
		$viewOptions = array_merge(
			['<a class="tab" href="#html-view-screenshot">screen shot (png)</a>'],
			$viewOptions
		);
	endif;
	
	if (is_readable($oFile->get_readable())) :
		$snapshotSection .= '<section id="html-view-snapshot"><iframe src="' . htmlspecialchars($mirrorUrl) . '" width="95%" height="800">';
		$snapshotSection .= "</iframe></section>";
		$viewOptions = array_merge(
			['<a class="tab" href="#html-view-snapshot">webpage snapshot</a>'],
			$viewOptions
		);
	endif;
	
	$metaTable[] = ["View", implode(" / ", $viewOptions)];
	
	header("Content-type: text/html");

	$html = $arX->payload_contents();
	$rawDataOut = null;
	$out = '';
	if (preg_match('|<title>([^<]*)</title>|ix', $html, $ref)) :
		$out .= "<h2>" . $ref[1] . "</h2>";
	else :
		$out .= "<h2>" . $arX->source_url('host') . "</h2>";
	endif;
	$out .= "<section id='html-view-source'>";
	if (!is_null($warc=$arX->payload_warc_url())) :
		$out .= '[<a href="' . htmlspecialchars($warc) . '">download WARC archive</a>]';
	endif;
	$out .= "<code><pre>".htmlspecialchars($html)."</pre></code></section>\n";
	$out .= $snapshotSection;
	
	$outWhat = "HTML Front Page";
