<?php
/*
** Copyright (c) Richard Uren 2012 <richard@teleport.com.au>
** All Rights Reserved
**
** --
**
** LICENSE: Redistribution and use in source and binary forms, with or
** without modification, are permitted provided that the following
** conditions are met: Redistributions of source code must retain the
** above copyright notice, this list of conditions and the following
** disclaimer. Redistributions in binary form must reproduce the above
** copyright notice, this list of conditions and the following disclaimer
** in the documentation and/or other materials provided with the
** distribution.
**
** THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED
** WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF
** MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN
** NO EVENT SHALL CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT,
** INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
** BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS
** OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
** ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR
** TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE
** USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH
** DAMAGE.
**
** --
**
** This is a reference implementation for interfacing with www.handsetdetection.com
**
*/

//
// NOTE NOTE NOTE : The system requires about 30M of free APC cache. If the cache fills up 
// and stuff gets purged then we go to file and you know how that works - Slowly. 
// The api.ini config file setting is apc.shm_size = xxM
// where xx is 32 by default, make it 64 or greater to be extra happy :)
if (! function_exists('json_encode')) {
	require_once('json.php');
}

// From http://php.net/manual/en/function.apache-request-headers.php
if( !function_exists('apache_request_headers') ) {
function apache_request_headers() {
  $arh = array();
  $rx_http = '/\AHTTP_/';
  foreach($_SERVER as $key => $val) {
    if( preg_match($rx_http, $key) ) {
      $arh_key = preg_replace($rx_http, '', $key);
      $rx_matches = array();
      // do some nasty string manipulations to restore the original letter case
      // this should work in most cases
      $rx_matches = explode('_', $arh_key);
      if( count($rx_matches) > 0 and strlen($arh_key) > 2 ) {
        foreach($rx_matches as $ak_key => $ak_val) $rx_matches[$ak_key] = ucfirst($ak_val);
        $arh_key = implode('-', $rx_matches);
      }
      $arh[$arh_key] = $val;
    }
  }
  return( $arh );
}
}

// Note : Cache objects may be > 1Mb : So memcache is a bad option in this instance
// Json encoding device profile cache entries makes them 1/4th the size, so everything 
// squezes into about 25mb of APC cache instead of 100Mb or so. (just under the 32mb APC default).
class Cache {
	protected static $prefix = 'hd32';
	
	function Cache() {
	}
	
	public static function read($key) {
		return apc_fetch(self::$prefix.$key);
	}

	public static function write($key, $data) {
		return apc_add(self::$prefix.$key, $data, 7200);
	}

	public static function json_read($key) {
		$data = apc_fetch(self::$prefix.$key);
		if (empty($data))
			return false;	
		return json_decode($data, true);
	}

	public static function json_write($key, $data) {
		return apc_add(self::$prefix.$key, json_encode($data), 7200);
	}			
}

class HD3 {
	var $realm = 'APIv3';
	var $reply = null;
	var $rawreply = null;
	var $fastjson;
	var $timeoutError = false;
	var $detectRequest = array();
	var $error = '';
	var $logger = null;
	
	var $config = array (
		'username' => '',
		'secret' => '',
		'site_id' => '',
		'mobile_site' => '',
		'use_proxy' => 0,
		'proxy_server' => '',
		'proxy_port' => '',
		'proxy_user' => '',
		'proxy_pass' => '',
		'use_local' => 0,
		'non_mobile' => "/^Feedfetcher|^FAST|^gsa_crawler|^Crawler|^goroam|^GameTracker|^http:\/\/|^Lynx|^Link|^LegalX|libwww|^LWP::Simple|FunWebProducts|^Nambu|^WordPress|^yacybot|^YahooFeedSeeker|^Yandex|^MovableType|^Baiduspider|SpamBlockerUtility|AOLBuild|Link Checker|Media Center|Creative ZENcast|GoogleToolbar|MEGAUPLOAD|Alexa Toolbar|^User-Agent|SIMBAR|Wazzup|PeoplePal|GTB5|Dealio Toolbar|Zango|MathPlayer|Hotbar|Comcast Install|WebMoney Advisor|OfficeLiveConnector|IEMB3|GTB6|Avant Browser|America Online Browser|SearchSystem|WinTSI|FBSMTWB|NET_lghpset/",
		'match_filter' => " _\\#-,./:\"'",
		'api_server' => 'api.handsetdetection.com',
		'log_server' => 'log.handsetdetection.com',
		'anon_log_local_fails' => 0,
		'timeout' => 10
	);	
	
	// hdconfig.php is the v3 PHP config file name.
	var $configFile = 'hdconfig.php';
	
	function HD3($config = null) {
		
		if (! empty($config)) {
			$this->config = array_merge($this->config, $config);
		} elseif (! file_exists($this->configFile)) {
			echo 'Error : Invalid config file and missing config array. Either pass a config array to the consutictor or create a hdconfig.php file.';
			exit(1);
		} else {
			$hdconfig = array();
			// Note : require not require_once as multiple invocations will require config file again.
			require($this->configFile);
			$this->config = array_merge($this->config, (array) $hdconfig);
		}
							
		if (empty($this->config['username']) || empty($this->config['secret'])) {
			echo 'Error : Please set your username and secret in the hdconfig.php file or in your hd3 constructor config array.<br/>';
			echo 'Error : Download a premade config file for this site from your "My Sites" section on your <a href="http://www.handsetdetection.com/users/index">My Profile</a> page';
			exit;
		}

		$this->match_filter = preg_split('//', $this->config['match_filter'], null, PREG_SPLIT_NO_EMPTY);
		$this->_hd3log('Config '.print_r($this->config, true));
		$this->fastjson = function_exists('json_encode') && function_exists('json_decode');
		$this->setup();
	}
	
	function setLocalDetection($enable){ $this->config['use_local'] = $enable;}	
	function setProxyUser($user){ $this->config['proxy_user'] = $user; }
	function setProxyPass($pass){ $this->config['proxy_pass'] = $pass; }
	function setUseProxy($proxy){ $this->config['use_proxy'] = $proxy; }
	function setProxyServer($name) { $this->config['proxy_server'] = $name; }
	function setProxyPort($number) {$this->config['proxy_port'] = $number; }
	function setError($msg) { $this->error = $msg; $this->_hd3log($msg); }
	function setMobileSite($mobile_site) { $this->config['mobile_site'] = $mobile_site; }
	function setSecret($secret) { $this->config['secret'] = $secret; }
	function setUsername($user) { $this->config['username'] = $user; }
	function setTimeout($timeout) { $this->config['timeout'] = $timeout; }
	function setDetectVar($key, $value) { $this->detectRequest[strtolower($key)] = $value; }
	function setSiteId($siteid) { $this->config['site_id'] = (int) $siteid; }
	function setUseLocal($value) { $this->config['use_local'] = $value; }
	function setReply($reply) { $this->reply = $reply; }
	function setLogger($function) { $this->config['logger'] = $function; }
	
	function getLocalDetection() { return $this->config['use_local']; }
	function getProxyUser(){ return $this->config['proxy_user']; }
	function getProxyPass(){ return $this->config['proxy_pass']; }
	function getUseProxy(){ return $this->config['use_proxy']; }
	function getProxyServer(){ return $this->config['proxy_server']; }
	function getProxyPort(){ return $this->config['proxy_port']; }
	function getError() { return $this->error; }
	function getSecret() { return $this->config['secret']; }
	function getUsername() { return $this->config['username']; }
	function getTimeout() { return $this->config['timeout']; }
	function getReply() { return $this->reply; }
	function getRawReply() { return $this->rawreply; }
	function getSiteId() { return $this->config['site_id']; }
	function getUseLocal() { return $this->config['use_local']; }
	
	function _hd3log($msg) {
		//syslog(LOG_NOTICE, microtime()." ".$msg);
		if (isset($this->config['logger']) && is_callable($this->config['logger'])) {
			call_user_func($this->config['logger'], $msg);
		}
	}	
		
	function redirectToMobileSite(){
		if ($this->mobile_site != '') {
			header('Location: '.$this->mobile_site);
			exit;
		} 
	} 
		
	/** Public Functions **/
	// Read http headers from the server - likely what you want to send to HD for detection.
	// You can override or add to these with setDetectVar($key, $value)
	function setup() {
		$this->reply = null;
		$this->rawreply = null;
		$this->detectRequest = apache_request_headers();
		$this->detectRequest['ipaddress'] = $_SERVER['REMOTE_ADDR'];
		unset($this->detectRequest['Cookie']);
	}

	// Device Functions
	function deviceVendors() {
		$this->reply = array();
		return ($this->config['use_local'] ? $this->_localDeviceVendors() : $this->_remote('device/vendors', null));
	}

	function deviceModels($vendor) {
		$this->reply = array();
		return ($this->config['use_local'] ? $this->_localDeviceModels($vendor) : $this->_remote("device/models/$vendor", null));
	}
	
	function deviceView($vendor, $model) {
		$this->reply = array();
		return ($this->config['use_local'] ? $this->_localDeviceView($vendor, $model) : $this->_remote("device/view/$vendor/$model", null));
	}
	
	function deviceWhatHas($key, $value) {
		$this->reply = array();
		return ($this->config['use_local'] ? $this->_localDeviceWhatHas($key, $value) : $this->_remote("device/whathas/$key/$value", null));
	}

	// Site Functions	
	function siteAdd($data) {
		return $this->_remote("site/add", $data);
	}
	
	function siteEdit($data) {
		$this->reply = array();
		$id = (int) (empty($data['id']) ? $this->config['site_id'] : $data['id']);
		return $this->_remote("site/edit/$id", $data);
	}
	
	function siteView($id=null) {
		$this->reply = array();
		$id = (int) (empty($id) ? $this->config['site_id'] : $id);
		return $this->_remote("site/view/$id", null);
	}

	function siteDelete($id=null) {
		$this->reply = array();
		$id = (int) (empty($id) ? $this->config['site_id'] : $id);
		return $this->_remote("site/delete/$id", null);
	}
		
	function siteDetect($data=null) {
		$this->reply = array();
		$this->setError('');
		$id = (int) (empty($data['id']) ? $this->config['site_id'] : $data['id']);
		$requestBody = array_merge($this->detectRequest, (array) $data);
		
		// Dont send detection requests if non_mobile matches
		// Prevent bots & spiders (search engines) chalking up high detection counts.
		if (! empty($requestBody['user-agent']) && preg_match($this->config['non_mobile'], $requestBody['user-agent'])) {
			$this->_hd3log('FastFail : Probable bot, spider or script');
			$this->reply['status'] = 301;
			$this->reply['message'] = 'FastFail : Probable bot, spider or script';
			return false;
		}

		if ($this->config['use_local']) {
			$this->_hd3log("Starting Local Detection");
			$result = $this->_localSiteDetect($requestBody);
			$this->_hd3log("Finishing Local Detection : result is ($result)");
			return $result;
		} else {
			$result = $this->_remote("site/detect/$id", $requestBody);
			if (! $result) {
				return false;
			}
			$reply = $this->getReply();
			if (isset($reply['status']) && (int) $reply['status'] == 0 || $reply['status'] == "0") {
				return true;
			}			
			return false;
		}
	}
	
	// Convenience Function to download everything
	function siteFetchAll($id=null) {
		$status = false;
		$status = $this->siteFetchSpecs($id);
		if (! $status)
			return false;
		$status = $this->siteFetchTrees($id);		
		if (! $status)
			return false;
		return true;
	}

	function siteFetchTrees($id=null) {
		$id = (int) (empty($id) ? $this->config['site_id'] : $id);
		$status = $this->_remote("site/fetchtrees/$id", null);
		if (! $status)
			return false;

		$status = file_put_contents("hd3trees.json", $this->getRawReply());
		if ($status === false)
			return false;
		return $this->_setCacheTrees();
	}
	
	function _setCacheTrees() {
		$str = @file_get_contents("hd3trees.json");
		if ($str === false || empty($str)) {
			$this->reply['status'] = 299;
			$this->reply['message'] = 'Unable to open specs file hd3trees.json';
			$this->setError("Error : 299, Message : _setCacheTrees cannot open hd3trees.json. Is it there ? Is it world readable ?");
			return false;
		}			
		
		if ($this->fastjson) {
			$data = json_decode($str, true);
		} else {
			$data = $json->decode($str);			
		}
		
		foreach($data['trees'] as $key => $branch) {
			$this->tree[$key] = $branch;
			Cache::write($key, $branch);
		}
		return true;
	}
	
	function siteFetchSpecs($id=null) {
		$id = (int) (empty($id) ? $this->config['site_id'] : $id);
		$status = $this->_remote("site/fetchspecs/$id", null);
		if (! $status)
			return false;

		if (! isset($this->reply['status']))  {
			$this->setError('Error : '.print_r($this->reply, true));
			return false;
		}
		
		if ((int) $this->reply['status'] != 0) {
			$this->setError('Error : '.print_r($this->reply, true));
			return false;
		}
			
		$status = file_put_contents("hd3specs.json", $this->getRawReply());
		if ($status === false)
			return false;
		return $this->_setCacheSpecs();
	}

	function _setCacheSpecs($id=null,$type=null) {
		$str = @file_get_contents("hd3specs.json");
		if ($str === false || empty($str)) {
			$this->reply['status'] = 299;
			$this->reply['message'] = 'Unable to open specs file hd3specs.json';
			$this->setError(" Error: 299, Message : _setCacheSpecs cannot open hd3specs.json. Is it there ? Is it world readable ?");
			return false;
		}
		
		if ($this->fastjson) {
			$data = json_decode($str, true);
		} else {
			$data = $json->decode($str);			
		}
		$return_specs = null;
		if (! empty($data['devices'])) {
			foreach($data['devices'] as $device) {
				$device_id = $device['Device']['_id'];
				$device_specs = $device['Device']['hd_specs'];
				Cache::json_write('device'.$device_id, $device_specs);
				if ($id && $id == $device_id && $type == 'device') 
					$return_specs = $device_specs;
			}
		}
		if (! empty($data['extras'])) {
			foreach($data['extras'] as $extra) {
				$extra_id = $extra['Extra']['_id'];
				$extra_specs = $extra['Extra']['hd_specs'];
				Cache::json_write('extra'.$extra_id, $extra_specs);
				if ($id && $id == $extra_id && $type == 'extra') 
					$return_specs = $extra_specs;
			}		
		}
					
		if ($id && $type && $return_specs)
			return $return_specs;
		if (empty($id))
			return true;
		return false;
	}

	function _getCacheSpecs($id, $type) {
		if (! $result = Cache::json_read($type.$id)) {
			$this->_hd3log("Id $id for $type not found in cache : reloading whole cache");
			return $this->_setCacheSpecs($id, $type);
		}
		return $result;
	}
		
	// User Functions
	// User actions. Always remote.
	function user($suburl, $data=null) {
		$ret = $this->_remote('user', $suburl, $data, $this->config['api_server']); 
		return $ret;
	}
	
	function _remote($suburl, $data) {
		// One hostname has multiple ip addresses, by design.
		// Get the list (of ip's), randomize the order, then pop the top & try until no servers left.
		$serverlist = gethostbynamel($this->config['api_server']);
		if (empty($serverlist)) {
			$this->setError("Error : No servers resolved to ".$this->config['api_server']);
			return false;
		}
		shuffle($serverlist);
		$url = "http://".$this->config['api_server']."/apiv3/$suburl.json";

		if (empty($data))
			$data = array();
			
		if ($this->fastjson) {
			$jsondata = json_encode($data);
		} else {
			$json = new Services_JSON(SERVICES_JSON_LOOSE_TYPE);
			$jsondata = $json->encode($data);
		}
		$this->reply = null;
		$this->rawreply = false;	
		foreach((array)$serverlist as $serverip) {
			$this->_hd3log("Attempting connection to $url");
			$this->rawreply = $this->_post($this->config['api_server'], $serverip, $url, $jsondata);
			if ($this->rawreply !== false) break;
		}
		if ($this->rawreply === false) {
			$this->_hd3log("Connection to $url Failed");
			return false;
		}
		
		if ($this->fastjson) {
			$this->reply = json_decode($this->rawreply, true);
		} else {
			$this->reply = $json->decode($this->rawreply);			
		}

		if (empty($this->reply)) {
			$this->setError('Empty reply');
			return false;
		}			
			
		if (! isset($this->reply['status']))  {
			$this->setError('Error : No status set in reply');
			return false;
		}
		
		if ((int) $this->reply['status'] != 0) {
			$this->setError('Error : '.@$this->reply['status'].', Message : '.@$this->reply['message'], true);
			return false;
		}
		
		return true;
	}
		
	//************ Private Functions ***********//
	// From http://www.enyem.com/wiki/index.php/Send_POST_request_(PHP)
	// PHP 4/5 http post function
	// And modified to fit	
	function _post($server, $serverip, $url, $jsondata) {

		$host = $serverip;
		$port = 80;
		$timeout = $this->config['timeout'];
		$timeoutError = false;
		$uri = parse_url($url);
		$realm = $this->realm;
		$username = $this->config['username'];
		$nc = "00000001";
		$snonce = $this->realm;
		$cnonce = md5(time().$this->config['secret']);
		$qop = 'auth';
		
		if ($this->config['use_proxy']) {
			$host = $this->config['proxy_server'];
			$port = $this->config['proxy_port'];
		}

		// AuthDigest Components
		// http://en.wikipedia.org/wiki/Digest_access_authentication
		$ha1 = md5($username.':'.$realm.':'.$this->config['secret']);
		$ha2 = md5('POST:'.$uri['path']);
		$response = md5($ha1.':'.$snonce.':'.$nc.':'.$cnonce.':'.$qop.':'.$ha2);
		// * Connect *
		//echo "Connecting to $host, port $port, url $url<br/>";
		$errno = ""; 
		$errstr="";
		$fp = fsockopen($host, $port, $errno, $errstr, $timeout); 		
		if (! $fp) {
			$this->setError("Cannot connect to $host, port $port : ($errno) $errstr");
			return false;
		}
		$this->_hd3log("Socket open to $host, port $port : ($errno) $errstr");

		//* * connection successful, write headers */
		// Use HTTP/1.0 (to disable content chunking on large replies).
		$out = "POST $url HTTP/1.0\r\n";  
		$out .= "Host: $server\r\n";
		if ($this->config['use_proxy'] && ! empty($this->config['proxy_user']) && ! empty($this->config['proxy_pass'])) {
			$out .= "Proxy-Authorization:Basic ".base64_encode("$this->proxy_user:$this->proxy_pass")."\r\n";
		}
		$out .= "Content-Type: application/json\r\n";
		$out .= 'Authorization: Digest '.
			'username="'.$username.'", '.
			'realm="'.$realm.'", '.
			'nonce="'.$snonce.'", '.
			'uri="'.$uri['path'].'", '.
			'qop='.$qop.', '.
            'nc='.$nc.', '.
            'cnonce="'.$cnonce.'", '.
            'response="'.$response.'", '.
            'opaque="'.$realm.'"'."\r\n";
		$out .= "Content-length: " . strlen($jsondata) . "\r\n\r\n";
		$out .= "$jsondata\r\n\r\n";

		$this->_hd3log("Sending : $out");
		fputs($fp, $out);
		
		$reply = "";
		$time = time();

		/*
		 * Get response. Badly behaving servers might not maintain or close the stream properly, 
		 * we need to check for a timeout if the server doesn't send anything.
		 */
		$timeout_status = FALSE;
		
		stream_set_blocking ( $fp, 0 );
		while ( ! feof( $fp )  and ! $timeout_status) {
			$r = fgets($fp, 1024*25);
			if ( $r ) {
				$reply .= $r;
				$time = time();
			}
			if ((time() - $time) > $timeout)
				$timeout_status = TRUE;
		}
		
		$this->_hd3log($reply);
		
		if ($timeout_status == TRUE) {
			$this->setError("Timeout when reading the stream."); 	
			$this->timeoutError = true;	
			return false;
		}
		if (!feof($fp)) {
			$this->setError("Reading stream but failed to read the entire stream.");	
			return false;
		}

		fclose($fp); 

   		$hunks = explode("\r\n\r\n",trim($reply));
   		if (!is_array($hunks) or count($hunks) < 2) {
			$this->setError("The response is too short.");
       		return FALSE;
       	}
   		$header = $hunks[count($hunks) - 2];
   		$body = $hunks[count($hunks) - 1];
   		$headers = explode("\n",$header);

		if (strlen($body)) return $body;
		$this->setError("The response body is empty.");
		return FALSE;
	}

	function _localGetSpecs() {
		$str = @file_get_contents("hd3specs.json");
		if ($str === false || empty($str)) {
			$this->reply['status'] = 299;
			$this->reply['message'] = 'Unable to open specs file hd3specs.json';
			$this->setError("Error: 299, Message : _localGetSpecs cannot open hd3specs.json. Is it there ? Is it world readable ?");
			return false;
		}
		
		if ($this->fastjson) {
			$data = json_decode($str, true);
		} else {
			$data = $json->decode($str);			
		}
		return $data;
	}
	
	function _localDeviceVendors() {
		$data = $this->_localGetSpecs();
		if (empty($data))
			return false;

		$tmp = array();
		foreach($data['devices'] as $item) {
			$tmp[] = $item['Device']['hd_specs']['general_vendor'];
		}
		
		$this->reply['vendor'] = array_unique($tmp);
		sort($this->reply['vendor']);
		$this->reply['status'] = 0;
		$this->reply['message'] = 'OK';
		return true;				
	}
	
	function _localDeviceModels($vendor) {
		$data = $this->_localGetSpecs();
		if (empty($data))
			return false;

		$vendor = strtolower($vendor);
		$tmp = array();
		$trim = '';
		foreach($data['devices'] as $item) {
			if ($vendor === strtolower($item['Device']['hd_specs']['general_vendor'])) {
				$tmp[] = $item['Device']['hd_specs']['general_model'];
			}
			$key = $vendor." ";
			if (! empty($item['Device']['hd_specs']['general_aliases'])) {
				foreach($item['Device']['hd_specs']['general_aliases'] as $alias_item) {
					// Note : Position is 0, at the start of the string, NOT False.
					$result = stripos($alias_item, $key);
					if ($result == 0 && $result !== false) {
						$tmp[] = str_replace($key, '', $alias_item);
					}
				}
			}
		}
		sort($tmp);
		
		$this->reply['model'] = array_unique($tmp);
		$this->reply['status'] = 0;
		$this->reply['message'] = 'OK';
		return true;
	}
	
	function _localDeviceView($vendor, $model) {
		$data = $this->_localGetSpecs();
		if (empty($data))
			return false;

		$vendor = strtolower($vendor);
		$model = strtolower($model);
		foreach($data['devices'] as $item) {
			if ($vendor === strtolower($item['Device']['hd_specs']['general_vendor']) && $model === strtolower($item['Device']['hd_specs']['general_model'])) {
				$this->reply['status'] = 0;
				$this->reply['message'] = 'OK';
				$this->reply['device'] = $item['Device']['hd_specs'];
				return true;
			}
		}
		
		$this->reply['status'] = 301;
		$this->reply['message'] = 'Nothing found';
		$this->_hd3log("_localDeviceView finds no matching device with vendor ($vendor) and model ($model)");
		return false;
	}
	
	function _localDeviceWhatHas($key, $value) {
		$data = $this->_localGetSpecs();
		if (empty($data))
			return false;

		$tmp = array();
		$value = strtolower($value);
		foreach($data['devices'] as $item) {
			if (empty($item['Device']['hd_specs'][$key])) {
				continue;
			}
			
			$match = false;			
			if (is_array($item['Device']['hd_specs'][$key])) {
				foreach($item['Device']['hd_specs'][$key] as $check) {
					if (stristr($check, $value)) {
						$match = true;
					}
				}
			} elseif (stristr($item['Device']['hd_specs'][$key], $value)) {
				$match = true;
			}
			
			if ($match == true) {
				$tmp[] = array('id' => $item['Device']['_id'], 
					'general_vendor' => $item['Device']['hd_specs']['general_vendor'],
					'general_model' => $item['Device']['hd_specs']['general_model']);
			}
		}
		$this->reply['devices'] = $tmp;
		$this->reply['status'] = 0;
		$this->reply['message'] = 'OK';
		return true;		
	}
		
	function _localSiteDetect($headers) {
		// Load json data (if its not loaded)
		$device = null;
		$id = $this->_getDevice($headers);
		if ($id) {
			$this->_hd3log("Looking to read $id from cache");
		
			$device = $this->_getCacheSpecs($id, 'device');
			if ($device === false) {
				$this->_hd3log("Cache problem : Unable to make cache");
				$this->reply['status'] = 225;
				$this->reply['class'] = 'Unknown';
				$this->reply['message'] = 'Unable to write cache or main datafile.';
				$this->error = $this->reply['message'];
				return false;
			}
			
			$this->_hd3log("$id fetched from cache");

			// Perform Browser & OS (platform) detection
			$platform = array();
			$browser = array();
			$platform_id = $this->_getExtra('platform', $headers);
			$browser_id = $this->_getExtra('browser', $headers);
			if ($platform_id) 
				$platform = $this->_getCacheSpecs($platform_id, 'extra');
			if ($browser_id)
				$browser = $this->_getCacheSpecs($browser_id, 'extra');
				
			$this->_hd3log("platform ".$platform);
			$this->_hd3log("browser".$browser);

			// Selective merge
			if (! empty($browser['general_browser'])) {
				$platform['general_browser'] = $browser['general_browser'];
				$platform['general_browser_version'] = $browser['general_browser_version'];
			}
	
			if (! empty($platform['general_platform'])) {
				$device['general_platform'] = $platform['general_platform'];
				$device['general_platform_version'] = $platform['general_platform_version'];	
			}
			if (! empty($platform['general_browser'])) {
				$device['general_browser'] = $platform['general_browser'];
				$device['general_browser_version'] = $platform['general_browser_version'];	
			}			
												
			$this->reply['hd_specs'] = $device;
			$this->reply['status'] = 0;
			$this->reply['message'] = 'OK';
			$this->reply['class'] = (empty($device['general_type']) ? "Unknown" : $device['general_type']);
			$this->devices[$id] = $device;
			return true;
		}
		if (! isset($this->reply['status']) || $this->reply['status'] == 0) {
			$this->reply['status'] = 301;
			$this->reply['class'] = 'Unknown';
			$this->reply['message'] = 'Nothing found';
			$this->setError('Error: 301, Nothing Found');
		}
		return false;
	}
	
	function _getDevice($headers) {
		// Remember the agent for generic matching later.
		$agent = "";

		// Convert all headers to lowercase 
		$headers = array_change_key_case($headers);
		
		$this->_hd3log('Working with headers of '.print_r($headers, true));
		$this->_hd3log('Start Checking Opera Special headers');
		// Opera mini puts the vendor # model in the header - nice! ... sometimes it puts ? # ? in as well :(
		if (! empty($headers['x-operamini-phone']) && trim($headers['x-operamini-phone']) != "? # ?") {
			$_id = $this->_matchDevice('x-operamini-phone', $headers['x-operamini-phone']);
			if ($_id) {
				$this->_hd3log('End x-operamini-phone check - x-operamini-phone found');
				return $_id;
			}
			unset($headers['x-operamini-phone']);
		}
		$this->_hd3log('Finish Checking Opera Special headers');

		// Profile header matching
		$this->_hd3log('Start Profile Check');
		if (! empty($headers['profile'])) {
			$_id = $this->_matchDevice('profile', $headers['profile']);
			if ($_id) {
				$this->_hd3log('End profile check - profile found');
				return $_id;
			}
			unset($headers['profile']);
		}
		$this->_hd3log('End profile check - no profile found');
		$this->_hd3log('Start x-wap-profile check');
		if (! empty($headers['x-wap-profile'])) {
			$_id = $this->_matchDevice('profile', $headers['x-wap-profile']);
			if ($_id) {
				$this->_hd3log('End profile check - profile found');
				return $_id;
			}
			unset($headers['profile']);
		}
		
		$this->_hd3log('End x-wap-profile check - no profile found');

		// Various types of user-agent x-header matching, order is important here (for the first 3).
		// Choose any x- headers .. skip the others.
		$order = array('x-operamini-phone-ua', 'x-mobile-ua', 'user-agent');
		foreach($headers as $key => $value) {
			if (! in_array($key, $order) && preg_match("/^x-/i",$key))
				$order[] = $key;
		}

		if (! empty($headers['user-agent']) && empty($agent))
			$agent = $headers['user-agent'];
		
		foreach($order as $item) {
			if (! empty($headers[$item])) {
				$this->_hd3log("Trying user-agent match on header $item");
				$_id = $this->_matchDevice('user-agent', $headers[$item]);
				if ($_id) {
					return $_id;
				}
				unset($headers[$item]);
			}
		}

		// Generic matching - Match of last resort.
		$this->_hd3log('Trying Generic Match');
		return $this->_matchDevice('user-agent', $agent, true);
	}

	function _matchDevice($header, $value, $generic=0) {
		// Strip unwanted chars from lower case version of value
		$value = str_replace($this->match_filter, "", strtolower($value));
		$treetag = $header.$generic;
		
		return $this->_match($header, $value, $treetag);
	}

	// Tries headers in diffferent orders depending on the extra $class.
	function _getExtra($class, $valuearr) {
		if ($class == 'platform') {
			$checkOrder = array_merge(array('x-operamini-phone-ua','user-agent'), array_keys($valuearr)); 
		} elseif ($class == 'browser') {
			$checkOrder = array_merge(array('agent'), array_keys($valuearr)); 			
		}

		foreach($checkOrder as $field) {
			if (! empty($valuearr[$field]) && ($field == 'user-agent' || strstr($field, 'x-') !== false)) {
				$_id = $this->_matchExtra('user-agent', $valuearr[$field], $class);
				if ($_id) {
					return $_id;
				}
			}
		}
		return false;
	}
				
	function _matchExtra($header, $value, $class) {
		// Note : Extra manipulations less onerous than for devices.	
		$value = strtolower(str_replace(" ","", trim($value)));
		$treetag = $header.$class;
		
		return $this->_match($header, $value, $treetag);
	}
	
	function _match($header, $newvalue, $treetag) {
		
		$f = 0;
		$r = 0;
		
		$this->_hd3log("Looking for $treetag $newvalue"); 

		if ($newvalue == "") {
			$this->_hd3log("Value empty - returning false");
			return false;
		}
		
		if (strlen($newvalue) < 4) {
			$this->_hd3log("Value ($newvalue) too small - returning false");
			return false;
		}

		$this->_hd3log("Loading match branch"); 
		$branch = $this->_getBranch($treetag);
		if (empty($branch)) {
			$this->_hd3log("Match branch ($treetag) empty - returning false");
			return false;
		}
		$this->_hd3log("Match branch loaded");		
		
		if ($header == 'user-agent') {		
			// Sieve matching strategy
			foreach((array) $branch as $order => $filters) {
				foreach((array) $filters as $filter => $matches) {
					$f++;
					if (strpos($newvalue, (string) $filter) !== false) {
						foreach((array) $matches as $match => $node) {
							$r++;
							if (strpos($newvalue, (string) $match) !== false) {
								$this->_hd3log("Match Found : $filter $match wins on $newvalue ($f/$r)");
								return $node;
							}
						}
					}
				}
			}
		} else {
			// Direct matching strategy
			if (! empty($branch[$newvalue])) {
				$node = $branch[$newvalue];
				$this->_hd3log("Match found : $treetag $newvalue ($f/$r)");
				return $node;
			}
		}
		
		$this->_hd3log("No Match Found for $treetag $newvalue ($f/$r)");
		return false;
	}

	function _getBranch($branch) {
		if (! empty($this->tree[$branch])) {
			$this->_hd3log("$branch fetched from memory");
			return $this->tree[$branch];
		}

		$tmp = Cache::read($branch);
		if ($tmp !== false) {
			$this->_hd3log("$branch fetched from cache");
			$this->tree[$branch] = $tmp;
			return $tmp;
		}			

		$this->_setCacheTrees();
		if (empty($this->tree[$branch]))
			$this->tree[$branch] = array();

		$this->_hd3log("$branch built and cached");
		return $this->tree[$branch];
	}
}?>