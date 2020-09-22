<?php
	global $params;
	global $oDateTime;
	
	$slug = $params['slug'];

	$mirrorFile = dirname(__FILE__) . $params['mirrored'];
	$mirrorUrl = 'http://' . $_SERVER['HTTP_HOST'] . $params['mirrored'];
	$oFile = new MirroredURL(["file" => $params['mirrored'], "url" => $mirrorUrl, "ts" => $oDateTime->datetimecode()]);

	if (is_readable($oFile->get_readable())) :
		$mirrorHtml = $oFile->get_filtered_html(/*dependencies=*/ true);
		echo $mirrorHtml;
	else :
		$readable = $oFile->get_readable();
		print "<html><head><title>404 Not Found</title></head><body><h1>Not Found</h1><p>Missing component: <code>${mirrorFile}</code> =&gt; <code>${readable}</code></p></body></html>";
	endif;
	exit;
