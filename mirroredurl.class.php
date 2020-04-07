<?php
class MirroredURL {
	private $_sBaseDir;
	private $_sDataBase;
	private $_sSnapBase;
	private $_sUrl;
	private $_sFile;
	private $_sTs;
	private $_sSlug;
	private $_vTest;
	
	public function __construct ($params = array()) {
		global $_REQUEST;
		
		$params = array_merge([
		"url" => null,
		"file" => null,
		"slug" => null,
		"ts" => null,
		"base dir" => dirname(__FILE__),
		"data base" => "/covid-data",
		"snap base" => "/html/snapshots/",
		"test" => (isset($_REQUEST['MirroredURL-Test']) ? $_REQUEST['MirroredURL-Test'] : null),
		], $params);
		
		$this->_vTest = $params['test'];
		
		$this->_sBaseDir = $params['base dir'];
		$this->_sDataBase = $params['data base'];
		$this->_sSnapBase = $params['snap base'];

		$this->_sTs = $params['ts'];
		$this->_sSlug = $params['slug'];
		
		if (!is_null($params['url'])) :
			$this->set_url($params['url']);
		endif;

		if (!is_null($params['file'])) :
			if (is_null($this->_sTs)) :
				$this->_sTs = $this->get_ts($params['file']);
			endif;
			
			$this->set_file($params['file']);
		endif;
	} /* MirroredURL::__construct () */
	
	protected function is_test ($tag = null) {
		$ret = !is_null($this->_vTest);
		if (!is_null($tag)) :
			$ret = ($ret and $this->_vTest == $tag);
		endif;
		return $ret;
	}
	
	protected function console_log ($obj, $name = null) {
		if ($this->is_test()) :
			if (is_string($obj)) :
				$v = $obj."\n";
			else :
				ob_start();
				var_dump($obj);
				$v = ob_get_clean();
			endif;

			if (is_null($name)) :
				printf("%s", $v);
			else :
				printf("%s=%s", $name, $v);
			endif;

		endif;
	}
	
	public function ts () {
		return $this->_sTs;
	}
	
	protected function re_ts($after = '') {
		$pattern = "|^(.*";
		$pattern .= preg_quote($this->_sSnapBase);
		$pattern .= "[a-z0-9]+/+";
		$pattern .= ")([0-9]+Z)";
		$pattern .= $after;
		$pattern .= '$|ix';
		return $pattern;
	}
	
	public function get_ts ($file) {
		$base = null;
		
		$pattern = $this->re_ts('(/+.*)');
		if (preg_match($pattern, $file, $ref)) :
			$parent = $ref[1];
			$base = $ref[2];
		endif;
		return $base;
	}
	
	public function get_contents () {
		$filepath = $this->get_readable();
		return file_get_contents($filepath);
	}

	protected function to_get_params ($params) {
		$http_params = [];
		foreach ($params as $key => $value) :
			$http_params[] = urlencode($key) . "=" . urlencode($value);
		endforeach;
		
		return (count($http_params)==0 ? '' : '?' . implode("&", $http_params));
	}
	
	public function get_mirror_url () {
		$params = [
		"date" => $this->ts(),
		"mirrored" => $this->file(),
		];

		return "/" . $this->to_get_params($params);
	}
	
	public function get_readable () {
		$filepath = $this->get_path($this->_sFile);

		if (is_dir($filepath) and is_readable("${filepath}/index.html")) :
			$filepath = "${filepath}/index.html";
		endif;
		
		$ext = "html";
		if (!is_readable($filepath) and is_readable("${filepath}.${ext}")) :
			$filepath = "${filepath}.${ext}";
		endif;
		
		return $filepath;
	}
	
	public function get_path ($filename = null) {
		$path = rtrim($this->_sBaseDir, '/');
		if (!is_null($filename)) :
			$file = ltrim($filename, '/');
			$path = "${path}/${file}";
		endif;
		return $path;
	}
	
	public function file () {
		return $this->_sFile;
	}
	
	public function filepath () {
		return $this->get_path($this->_sFile);
	}
	
	protected function set_url (string $url) {
		$this->_sUrl = $url;
		
		$source = [];
		if (strlen($this->_sUrl) > 0) :
			$source = parse_url($this->_sUrl);
		endif;
		
		$source = array_merge([
		"scheme" => "file",
		"host" => "localhost",
		"path" => "",
		"query" => "",
		"fragment" => "",
		], $source);

		if (strlen($source['path']) > 0) :
			$base = rtrim($this->_sDataBase, "/")."/".trim($this->_sSnapBase, "/");
			$slug = $this->_sSlug;
			$ts = $this->_sTs;
			$host = rtrim($source['host'], "/");
			$path = ltrim($source['path'], "/");
			$query = (strlen($source['query']) > 0 ? '?'.$source['query'] : "");
			$file = "${base}/${slug}/${ts}/${host}/${path}${query}";
			
			$this->console_log($file, "set_url.file");
			$this->set_file($file);
		endif;
		
	}
	
	protected function set_file (string $file) {
		$this->_sFile = $file;
		$this->console_log($file, "_sFile");
		
		$filepath = $this->get_path($file);
		
		if (!is_readable($this->get_readable())) :
			// check whether we've got a screwy timestamp
			$v = $this->seek_file($file, $filepath, $this->ts());
			$this->console_log($v, "seek_file");

			list($newpath, $newfile, $newparent, $newts) = $v;
			
			if ($newpath != $filepath) :
				$filepath = $newpath;
				$this->_sFile = $newfile;
				$this->_sTs = $newts;
			endif;
		endif;
		
	}
	
	protected function seek_file (string $file, string $filepath, string $ts) {
		$found = false;
		
		$parent = $filepath;
		$base = basename($filepath);
		
		while (!$found and strlen($parent) > 1) :
			$base = basename($parent);
			$parent = dirname($parent);

			$pattern = $this->re_ts();
			if (preg_match($pattern, $parent, $ref)) :
				$parent = $ref[1];
				$base = $ref[2];
				$found = true;
			endif;
		endwhile;
		
		if ($found) :
			$aTs = array_map(function ($e) { return basename($e); }, glob($parent."/[0-9]*Z"));
			sort($aTs);
			$aTs = array_values(array_filter($aTs, function ($e) use ($base) { return ($e >= $base); }));
			if (count($aTs) >= 1) :
				$parent = rtrim($parent, '/') . '/';
				$testFile = str_replace($parent.$base, $parent."/".$aTs[0], $filepath);
				$this->console_log($testFile, "testfile");
				if (is_readable($testFile)) :
					$filepath = $testFile;
					$file = str_replace($this->get_path(), "", $testFile);
					$base = $aTs[0];
				endif;
			endif;
		endif;

		return [$filepath, $file, $parent.$base, $ts];
	}
}

if (basename($_SERVER['SCRIPT_NAME'])==basename(__FILE__)) :
	echo "Hello world!\n";
	$snapDir = '/covid-data/html/snapshots';
	$files = [
"${snapDir}/jeffco/20200407110001Z/www.jccal.org/Default.asp?ID=993&pg=News.html",
"${snapDir}/jeffco/20200407110000Z/www.jccal.org/Default.asp?ID=993&pg=News.html",
"${snapDir}/jeffco/99999999999999Z/www.jccal.org/Default.asp?ID=993&pg=News.html",
"${snapDir}/mobilealabama/20200407110001Z/www.cityofmobile.org/visitors",
"${snapDir}/aum/20200407004838Z/www.aum.edu/coronavirus",
	];
	foreach ($files as $file) :
		$oMirror = new MirroredURL(["file" => $file, "test" => "yes"]);
		$contents = $oMirror->get_contents();
		printf("<%s\n", $file);
		printf("get_contents(): %d %s\n", strlen($contents), "'" . substr($contents, 0, 10) . "...'");
		printf("\n");
	endforeach;
	exit;
endif;

