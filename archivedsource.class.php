<?php
class ArchivedSource {
	private $_sSlug;
	private $_sTs;
	private $_sExt;
	private $_sDataDir;
	private $_bInfixTs;
	
	public function __construct ($params = []) {
		$params = array_merge([
		"slug" => null,
		"ts" => null,
		"file type" => null,
		"data dir" => dirname(__FILE__) . "/covid-data",
		"infix ts" => false,
		], $params);

		$this->_sSlug = $params['slug'];
		$this->_sTs = $params['ts'];
		$this->_sExt = $params['file type'];
		$this->_sDataDir = $params['data dir'];
		$this->_bInfixTs = $params['infix ts'];
		
		if (!is_readable($this->url_file())) :
			$this->_bInfixTs = !$this->_bInfixTs;
		endif;
		
	} /* ArchivedSource::__construct () */

	protected function infix_ts () {
		return $this->_bInfixTs;
	}

	protected function data_dir () {
		return $this->_sDataDir;
	}
	
	protected function data_prefix ($basedir = null) {
		return (is_null($basedir) ? $this->data_dir() : $basedir) . "/" . $this->slug_path() . "-";
	}
	
	public function slug_path () {
		$path = explode('/', trim($this->slug(), '/'));
		if ($this->infix_ts()) :
			$path = array_merge(array_slice($path, 0, 1), [ $this->ts() ], array_slice($path, 1));
		endif;
		return '/' . implode('/', $path);
	}
	
	public function slug () {
		return $this->_sSlug;
	}
	
	public function ts () {
		return $this->_sTs;
	}
	
	public function source_url ($part = null) {
		$sourceUrl = null;
		
		// URL of snapshot: Get it from the file, if available
		$url_file = $this->url_file();
		if (is_readable($url_file)) :
			$sourceUrl = trim(file_get_contents($url_file));
		endif;
		
		// Allow parsing of the URL into little bits: scheme, host, path, query, fragment...
		if (!is_null($sourceUrl) and !is_null($part)) :
			$parts = parse_url($sourceUrl);
			$sourceUrl = (isset($parts[$part]) ? $parts[$part] : null);
		endif;
		return $sourceUrl;
	}
	
	public function capture_file ($type, $basedir = null) {
		$types = array_reverse(explode("|", $type));
		$readable = null;
		foreach ($types as $ext) :
			$file = $this->data_prefix($basedir).$this->ts().".".$ext;
			if (is_readable($file)) :
				$readable = $file;
			endif;
		endforeach;
		
		return (is_null($readable) ? $file : $readable);
	}
	public function capture_url ($type) {
		return $this->capture_file($type, '/covid-data');
	}
	
	public function url_file () {
		return $this->capture_file('url.txt');
	} /* ArchivedSource::url_file () */

	public function payload_file () {
		return $this->capture_file($this->_sExt);
	}
	public function payload_contents () {
		$content = null;
		
		$file = $this->payload_file();
		if (is_readable($file)) :
			$content = file_get_contents($file);
		endif;
		return $content;
	}
	public function payload_warc_url () {
		$url = null;
		
		$readable = $this->payload_warc_file();
		if (is_readable($readable)) :
			$url = $this->capture_url("warc.gz");
		endif;
		return $url;
	}
	
	public function payload_warc_file () {
		return $this->capture_file("warc.gz");
	}
	
	public function screenshot_file () {
		return $this->capture_file("png");
	}

	public function screenshot_url () {
		$url = null;
		
		$file = $this->screenshot_file();
		if (is_readable($file)) :
			$url = $this->capture_url("png");
		endif;
		return $url;
	}
	
	public function payload_checksum_file () {
		$file = $this->capture_file($this->_sExt);
		return $file . ".sha512";
	}

	public function payload_checksum () {
		$checksum = (is_readable($this->payload_checksum_file()) ? file_get_contents($this->payload_checksum_file()) : null);
		if (!is_null($checksum)) :
			if (preg_match('|^([A-Z0-9_-]+) \s* [^=]* = \s* (\S+)\s*$|x', $checksum, $m)) :
				$checksum = $m[2] . ' (' . $m[1] . ')';
			endif;
		endif;
		return $checksum;
	}
} /* class ArchivedSource */
