<?php

// Include the kaltura client
require_once(  dirname( __FILE__ ) . '/kaltura_client_v3/KalturaClient.php' );


/**
 * Generates a kaltura result object based on url Parameters 
 */
class KalturaGetResultObject {
	 
	var $resultObj = null; // lazy init with getResultObject
	var $clientTag = null;
	
	// Local flag to store whether output was came from cache or was a fresh request
	private $outputFromCache = false;
	
	/**
	 * Variables set by the Frame request:
	 */
	private $urlParameters = array(
		'cache_st' => null,
		'p' => null,
		'wid' => null,
		'uiconf_id' => null,
		'entry_id' => null,
		'flashvars' => null,
		'playlist_id' => null,
	);

	function __construct( $clientTag = 'php'){
		$this->clientTag = $clientTag;
		//parse input:
		$this->parseRequest();
		// load the request object:
		$this->getResultObject();
	}

	/**
	 * Kaltura object provides sources, sometimes no sources are found or an error occurs in 
	 * a video delivery context we don't want ~nothing~ to happen instead we send a special error
	 * video. 
	 */
	public static function getErrorVideoSources(){
		// @@TODO pull this from config: 'Kaltura.MissingFlavorVideoUrls' 
		return array(
		    'iphone' => array( 
		    	'src' => 'http://www.kaltura.com/p/243342/sp/24334200/playManifest/entryId/1_g18we0u3/flavorId/1_ktavj42z/format/url/protocol/http/a.mp4',
		    	'type' =>'video/h264',
				'data-flavorid' => 'iPhone'
		    ),
		    'ogg' => array(  
		    	'src' => 'http://www.kaltura.com/p/243342/sp/24334200/playManifest/entryId/1_g18we0u3/flavorId/1_gtm9gzz2/format/url/protocol/http/a.ogg',
		    	'type' => 'video/ogg',
		    	'data-flavorid' => 'ogg'
		    ),
		    'webm' => array(
		    	'src' => 'http://www.kaltura.com/p/243342/sp/24334200/playManifest/entryId/1_g18we0u3/flavorId/1_bqsosjph/format/url/protocol/http/a.webm',
		    	'type' => 'video/webm',
		    	'data-flavorid' => 'webm'
		    )
		 );
	}
	// check if the requested url is a playlist
	public function isPlaylist(){
		return ( $this->urlParameters['playlist_id'] !== null || $this->urlParameters['entry_id'] === null);
	}
	public function isCachedOutput(){
		return $this->outputFromCache;
	}
	
	private function getUserAgent() {
		return $_SERVER['HTTP_USER_AGENT'];
	}

	public function getSourceForUserAgent( $sources = null, $userAgent = false ){
		// Get all sources
		if( !$sources ){
			$sources = $this->getSources();
		}
		// Get user agent
		if( !$userAgent ){
			$userAgent = $this->getUserAgent();
		}
	
		//@@TODO integrate a library for doing this user-agent -> source selection
		// what follows somewhat arbitrary
		
		$flavorUrl = false ;
		// First set the most compatible source ( iPhone h.264 )
		if( isset( $sources['iphone'] ) ) {
			$flavorUrl = $sources['iphone']['src'];
		}
		
		// 3gp check 
		if( isset( $sources['3gp'] ) ) {
			// Blackberry ( newer blackberry's can play the iPhone src but better safe than broken )
			if( strpos( $userAgent, 'BlackBerry' ) !== false ){
				$flavorUrl = $sources['3gp']['src'];
			}
			// if we have no iphone source then do use 3gp:
			if( !$flavorUrl ){
				$flavorUrl = $sources['3gp']['src'];
			}
		}
		
		// Firefox > 3.5 and chrome support ogg
		if( isset( $sources['ogg'] ) ){
			// chrome supports ogg:
			if( strpos( $userAgent, 'Chrome' ) !== false ){
				$flavorUrl = $sources['ogg']['src'];
			}
			// firefox 3.5 and greater supported ogg:
			if( strpos( $userAgent, 'Firefox' ) !== false ){
				$flavorUrl = $sources['ogg']['src'];
			}
		}
		
		// Firefox 4x and chrome support webm ( use after ogg )
		if( isset( $sources['webm'] ) ){
			if( strpos( $userAgent, 'Chrome' ) !== false ){
				$flavorUrl = $sources['webm']['src'];
			}
			if( strpos( $userAgent, 'Firefox/4' ) !== false
				||
				strpos( $userAgent, 'Firefox/5' ) !== false 
			){
				$flavorUrl = $sources['webm']['src'];
			}
		}
		if( !$flavorUrl ){
			throw new Exception( "No mobile flavor can be found" );
		}
		
		return $flavorUrl;
	}
	
	/**
	*  Access Control Handling
	*/
	public function isAccessControlAllowed( &$resultObject = null ) {
		if( !$resultObject ){
			$resultObject =  $this->getResultObject();
		}
		
		$accessControl = $resultObject['accessControl'];
		
		// Check if we had no access control due to playlist
		if( is_array( $accessControl ) && isset( $accessControl['code'] )){
			// Error ? .. should do better error checking.
			// errors we have seen so far: 
				//$accessControl['code'] == 'MISSING_MANDATORY_PARAMETER'
				//$accessControl['code'] == 'INTERNAL_SERVERL_ERROR'  
			return true;
		}
		
		// Checks if admin
		if( $accessControl->isAdmin ) {
			return true;
		}

		/* Domain Name Restricted */
		if( $accessControl->isSiteRestricted ) {
			return "Un authorized domain\nWe're sorry, this content is only available on certain domains.";
		}

		/* Country Restricted */
		if($accessControl->isCountryRestricted) {
			return "Un authorized country\nWe're sorry, this content is only available on certain countries.";
		}

		/* Session Restricted */
		if( $accessControl->isSessionRestricted && $accessControl->previewLength == -1 ) {
			return "No KS where KS is required\nWe're sorry, access to this content is restricted.";
		}

		if($accessControl->isScheduledNow === 0) {
			return "Out of scheduling\nWe're sorry, this content is currently unavailable.";
		}

		return true;
	}
	// Load the Kaltura library and grab the most compatible flavor
	public function getSources(){
		global $wgKalturaServiceUrl, $wgKalturaCDNUrl, $wgKalturaUseManifestUrls;
		// Check the access control before returning any source urls
		if( !$this->isAccessControlAllowed() ) {
			return array();
		}

		$resultObject =  $this->getResultObject(); 
		// add any web sources
		$sources = array();

		// Check for error in getting flavor
		if( isset( $resultObject['flavors']['code'] ) ){
			switch(  $resultObject['flavors']['code'] ){
				case  'ENTRY_ID_NOT_FOUND':
					throw new Exception( "Entry Id not found\n" . $resultObject['flavors']['message'] );
				break;
			}
			// @@TODO should probably refactor to use throw catch error system.
			return array();
		}

		// Store flavorIds for Akamai HTTP
		$ipadFlavors = '';
		$iphoneFlavors = '';

		// Decide if to use playManifest or flvClipper URL
		if( $wgKalturaUseManifestUrls ){
			$flavorUrl =  $wgKalturaServiceUrl .'/p/' . $this->getPartnerId() . '/sp/' .
			$this->getPartnerId() . '00/playManifest/entryId/' .
			$this->urlParameters['entry_id'];
		} else {
			$flavorUrl = $wgKalturaCDNUrl .'/p/' . $this->getPartnerId() . '/sp/' .
			$this->getPartnerId() . '00/flvclipper/entry_id/' .
			$this->urlParameters['entry_id'];
		}

		foreach( $resultObject['flavors'] as $KalturaFlavorAsset ){

			// if flavor status is not ready - continute to the next flavor
			if( $KalturaFlavorAsset->status != 2 ) { continue; }
			
			if( $wgKalturaUseManifestUrls ){
				// If we have apple http steaming then use it for ipad & iphone instead of regular flavors
				if( strpos( $KalturaFlavorAsset->tags, 'applembr' ) !== false ) {
					$assetUrl = $flavorUrl . '/format/applehttp/protocol/http';
	
					$sources['applembr'] = array(
						'src' => $assetUrl . '/a.m3u8',
						'type' => 'application/vnd.apple.mpegurl',
						'data-flavorid' => 'AppleMBR'
					);
				} else {
					$assetUrl = $flavorUrl . '/flavorId/' . $KalturaFlavorAsset->id . '/format/url/protocol/http';
				}
				
			} else {
				$assetUrl =  $flavorUrl . '/flavor/' . 	$KalturaFlavorAsset->id;
			}

			// Add iPad Akamai flavor to iPad flavor Ids list
			if( strpos( $KalturaFlavorAsset->tags, 'ipadnew' ) !== false ) {
				$ipadFlavors .= $KalturaFlavorAsset->id . ",";
			}

			// Add iPhone Akamai flavor to iPad&iPhone flavor Ids list
			if( strpos( $KalturaFlavorAsset->tags, 'iphonenew' ) !== false )
			{
				$ipadFlavors .= $KalturaFlavorAsset->id . ",";
				$iphoneFlavors .= $KalturaFlavorAsset->id . ",";
			}

			if( strpos( $KalturaFlavorAsset->tags, 'iphone' ) !== false ){
				$sources['iphone'] = array(
					'src' => $assetUrl . '/a.mp4',
					'type' => 'video/h264',
					'data-flavorid' => 'iPhone'
				);
			};
			if( strpos( $KalturaFlavorAsset->tags, 'ipad' ) !== false ){
				$sources['ipad'] = array(
					'src' => $assetUrl  . '/a.mp4',
					'type' => 'video/h264',
					'data-flavorid' => 'iPad'
				);
			};

			if( $KalturaFlavorAsset->fileExt == 'webm' ){
				$sources['webm'] = array(
					'src' => $assetUrl . '/a.webm',
					'type' => 'video/webm',
					'data-flavorid' => 'webm'
				);
			}

			if( $KalturaFlavorAsset->fileExt == 'ogg' || $KalturaFlavorAsset->fileExt == 'ogv'
				|| $KalturaFlavorAsset->fileExt == 'oga'
			){
				$sources['ogg'] = array(
					'src' => $assetUrl . '/a.ogg',
					'type' => 'video/ogg',
					'data-flavorid' => 'ogg'
				);
			};
			if( $KalturaFlavorAsset->fileExt == '3gp' ){
				$sources['3gp'] = array(
					'src' => $assetUrl . '/a.3gp',
					'type' => 'video/3gp',
					'data-flavorid' => '3gp'
				);
			};
		}

		$ipadFlavors = trim($ipadFlavors, ",");
		$iphoneFlavors = trim($iphoneFlavors, ",");

		// Create iPad flavor for Akamai HTTP
		if ( $ipadFlavors )
		{
			$assetUrl = $flavorUrl . '/flavorIds/' . $ipadFlavors . '/format/applehttp/protocol/http';

			$sources['ipadnew'] = array(
				'src' => $assetUrl . '/a.m3u8',
				'type' => 'application/vnd.apple.mpegurl',
				'data-flavorid' => 'iPadNew'
			);
		}

		// Create iPhone flavor for Akamai HTTP
		if ($iphoneFlavors)
		{
			$assetUrl = $flavorUrl . '/flavorIds/' . $iphoneFlavors . '/format/applehttp/protocol/http';

			$sources['iphonenew'] = array(
				'src' => $assetUrl . '/a.m3u8',
				'type' => 'application/vnd.apple.mpegurl',
				'data-flavorid' => 'iPhoneNew'
			);
		}
		
		foreach($sources as &$source ){
			// Play manifest also support referrer passing ( always output = ) 
			if( isset( $source['src'] )){
				$source['src'] .= '?referrer=' . base64_encode( $this->getReferer() );
			}
		}
        //echo '<pre>'; print_r($sources); exit();
		return $sources;
	}
	
	// Parse the embedFrame request and sanitize input
	private function parseRequest(){
		global $wgAllowRemoteKalturaService, $wgEnableScriptDebug;
		// Support /key/value path request:
		if( isset( $_SERVER['PATH_INFO'] ) ){
			$urlParts = explode( '/', $_SERVER['PATH_INFO'] );
			foreach( $urlParts as $inx => $urlPart ){
				foreach( $this->urlParameters as $attributeKey => $na){
					if( $urlPart == $attributeKey && isset( $urlParts[$inx+1] ) ){
						$_REQUEST[ $attributeKey ] = $urlParts[$inx+1];
					}
				}
			}
		}

		// Check for urlParameters in the request:
		foreach( $this->urlParameters as $attributeKey => $na){
			if( isset( $_REQUEST[ $attributeKey ] ) ){
				// set the url parameter and don't let any html in:
				if( is_array( $_REQUEST[$attributeKey] ) ){
					$payLoad = array();
					foreach( $_REQUEST[$attributeKey] as $key => $val ){
						$payLoad[$key] = htmlspecialchars( $val );
					}
					$this->urlParameters[ $attributeKey ] = $payLoad;
				} else {
					$this->urlParameters[ $attributeKey ] = htmlspecialchars( $_REQUEST[$attributeKey] );
				}
			}
		}

		// add p == _widget
		if( isset( $this->urlParameters['p'] ) && !isset( $this->urlParameters['wid'] ) ){
			$this->urlParameters['wid'] = '_' . $this->urlParameters['p'];  
		}
		//die('flashvars:'. $this->urlParameters[ 'flashvars' ] );
			
		// Check for debug flag
		if( isset( $_REQUEST['debugKalturaPlayer'] ) || isset( $_REQUEST['debug'] ) ){
			$this->debug = true;
			$wgEnableScriptDebug = true;
		}

		// Check for required config
		if( $this->urlParameters['wid'] == null ){
			throw new Exception( 'Can not display player, missing widget id' );
		}
		
		// If remote service is allowed enable the $wgKalturaServiceUrl $wgKalturaCDNUrl and $wgKalturaServiceBase to be set via iframe request
		// NOTE this is kind of dangerous XSS wise and should only be used in testing
		if( $wgAllowRemoteKalturaService ){
			global $wgKalturaServiceUrl, $wgKalturaCDNUrl,  $wgKalturaServiceBase;
			if( isset( $_REQUEST['host'] ) ){
				$wgKalturaServiceUrl = 'http://' . $_REQUEST['host'];
			}
			if( isset( $_REQUEST['cdnHost'] ) ){
				$wgKalturaCDNUrl = 'http://' .  $_REQUEST['cdnHost'];
			}
		}
	}
	private function getCacheDir(){
		global $mwEmbedRoot, $wgScriptCacheDirectory;
		$cacheDir = $wgScriptCacheDirectory . '/iframe';
		// make sure the dir exists:
		if( ! is_dir( $cacheDir) ){
			@mkdir( $cacheDir, 0777, true );
		}
		return $cacheDir;
	}
	/**
	 * Returns a cache key for the result object based on Referer and partner id
	 */
	private function getResultObjectCacheKey(){
		global $wgKalturaServiceUrl;		
		// Get a key based on partner id,  entry_id and ui_confand and refer url:
		$playerUnique = ( isset( $this->urlParameters['entry_id'] ) ) ?  $this->urlParameters['entry_id'] : '';
		$playerUnique .= ( isset( $this->urlParameters['uiconf_id'] ) ) ?  $this->urlParameters['uiconf_id'] : '';
		$playerUnique .= ( isset( $this->urlParameters['cache_st'] ) ) ? $this->urlParameters['cache_st'] : ''; 
		$playerUnique .= $this->getReferer();

		// hash the service url, the partner_id, the player_id and the Referer url: 
		return substr( md5( $wgKalturaServiceUrl ), 0, 5 ) . '_' . $this->getPartnerId() . '_' . 
			   substr( md5( $playerUnique ), 0, 16 );
	}

	private function getResultObjectFromApi(){
		$client = $this->getClient();
		if( ! $client ){
			return array();
		}

		$client->startMultiRequest();
		try{
			// NOTE this should probably be wrapped in a service class
			$kparams = array();

			// sources
			$client->addParam( $kparams, "entryId",  $this->urlParameters['entry_id'] );
			$client->queueServiceActionCall( "flavorAsset", "getByEntryId", $kparams );

			// access control NOTE: kaltura does not use http header spelling of Referer instead kaltura uses: "referrer"
			$client->addParam( $kparams, "contextDataParams",  array( 'referrer' =>  $this->getReferer() ) );
			$client->queueServiceActionCall( "baseEntry", "getContextData", $kparams );

			// Entry Meta
			$client->addParam( $kparams, "entryId",  $this->urlParameters['entry_id'] );
			$client->queueServiceActionCall( "baseEntry", "get", $kparams );						
			
			
			// Entry Custom Metadata			
			$metaDataFilter = new KalturaMetadataFilter();
			$metaDataFilter->metadataObjectTypeEqual = KalturaMetadataObjectType::ENTRY;
			$metaDataFilter->orderBy = KalturaMetadataOrderBy::CREATED_AT_ASC;
			$metaDataFilter->objectIdEqual = $this->urlParameters['entry_id'];
			$metadataPager =  new KalturaFilterPager();
			$metadataPager->pageSize = 1;

			$client->addParam( $kparams, "metaDataFilter",  $metaDataFilter );
			$client->addParam( $kparams, "metadataPager",  $metadataPager );
			$client->queueServiceActionCall( "metadata_metadata", "list", $kparams );
			
			
			if($this->urlParameters['uiconf_id']) {
				$client->addParam( $kparams, "id",  $this->urlParameters['uiconf_id'] );
				$client->queueServiceActionCall( "uiconf", "get", $kparams );
			}
			
			$rawResultObject = $client->doQueue();
			$client->throwExceptionIfError( $this->resultObj );
		} catch( Exception $e ){
			// update the Exception and pass it upward
			throw new Exception( KALTURA_GENERIC_SERVER_ERROR . "\n" . $e->getMessage() );
			return array();
		}

		
		$resultObject = array(
			'flavors' 			=> 	$rawResultObject[0],
			'accessControl' 	=> 	$rawResultObject[1],
			'meta'				=>	$rawResultObject[2],			
			'entry_id'			=>	$this->urlParameters['entry_id'],
			'partner_id'		=>	$this->getPartnerId(),
			'ks' 				=> 	$this->getKS()
		);
		
		if( isset( $rawResultObject[3]->objects ) && 
			isset( $rawResultObject[3]->objects[0] ) && 
			isset( $rawResultObject[3]->objects[0]->xml )
		){
			
			$resultObject['entryMeta'] = $this->xmlToArray( new SimpleXMLElement( $rawResultObject[3]->objects[0]->xml ) );
		}
		
		if( isset( $rawResultObject[4] ) && $rawResultObject[4]->confFile ){
			$resultObject[ 'uiconf_id' ] = $this->urlParameters['uiconf_id'];
			$resultObject[ 'uiConf'] = $rawResultObject[4]->confFile;
		}

		// Check access control and throw an exception if not allowed: 
		$acStatus = $this->isAccessControlAllowed( $resultObject );
		if( $acStatus !== true ){
			throw new Exception( $acStatus );
		}	
		
		return $resultObject;
	}
	
	/**
	 * convert smimple xml to array
	 */
	function xmlToArray ( $data ){
		if (is_object($data)) $data = get_object_vars($data);
		return (is_array($data)) ? array_map( array( $this, __FUNCTION__) ,$data) : $data;
	}
	
	private function getReferer(){
		return ( isset( $_SERVER['HTTP_REFERER'] ) ) ? $_SERVER['HTTP_REFERER'] : 'http://www.kaltura.org/';
	}

	private function getClient(){
		global $mwEmbedRoot, $wgKalturaUiConfCacheTime, $wgKalturaServiceUrl, $wgScriptCacheDirectory, 
			$wgMwEmbedVersion, $wgKalturaServiceTimeout;

		$cacheDir = $wgScriptCacheDirectory;

		$cacheFile = $this->getCacheDir() . '/' . $this->getPartnerId() . ".ks.txt";
		$cacheLife = $wgKalturaUiConfCacheTime;

		$conf = new KalturaConfiguration( $this->getPartnerId() );
		
		$conf->serviceUrl = $wgKalturaServiceUrl;
		$conf->clientTag = $this->clientTag;
		$conf->curlTimeout = $wgKalturaServiceTimeout;
		
		$client = new KalturaClient( $conf );

		// Check modify time on cached php file
		$filemtime = @filemtime($cacheFile);  // returns FALSE if file does not exist
		if ( !$filemtime || filesize( $cacheFile ) === 0 || ( time() - $filemtime >= $cacheLife ) ) {
			try{
		    	$session = $client->session->startWidgetSession( $this->urlParameters['wid'] );
		    	$this->ks = $session->ks;
		    	$this->putCacheFile( $cacheFile,  $this->ks );
			} catch ( Exception $e ){
				throw new Exception( KALTURA_GENERIC_SERVER_ERROR . "\n" . $e->getMessage() );
			}
		} else {
		  	$this->ks = file_get_contents( $cacheFile );
		}
		// Set the kaltura ks and return the client
		$client->setKS( $this->ks );

		return $client;
	}
	public function getKS(){
		if( !isset( $this->ks ) ){
			$this->getClient();
		}
		return $this->ks;
	}	
	public function getPartnerId(){
		// Partner id is widget_id but strip the first character
		return substr( $this->urlParameters['wid'], 1 );
	}
	public function getEntryId(){
		return $this->urlParameters['entry_id'];
	}
	public function getUrlParameters(){
		return $this->urlParameters;
	}
	public function getJSON(){
		return json_encode( $this->getResultObject() );
	}
	public function getUiConf(){
		$result = $this->getResultObject();
		if( isset( $result['uiConf'] ) ){
			return $result['uiConf'];
		} else {
			return false;
		}
	}
	private function getResultObject(){
		global $wgKalturaUiConfCacheTime;
		// Check if we have a cached result object:
		if( !$this->resultObj ){
			$cacheFile = $this->getCacheDir() . '/' . $this->getResultObjectCacheKey() . ".entry.txt";
	
			// Check modify time on cached php file
			$filemtime = @filemtime($cacheFile);  // returns FALSE if file does not exist
			if ( !$filemtime || filesize( $cacheFile ) === 0 || ( time() - $filemtime >= $wgKalturaUiConfCacheTime ) ){
				$this->resultObj = $this->getResultObjectFromApi();
			} else {
				$this->outputFromCache = true;
				$this->resultObj = unserialize( file_get_contents( $cacheFile ) );
			}
			
			// Test if the resultObject can be cached ( no access control restrictions )
			if( $this->isAccessControlAllowed( $this->resultObj ) === true ){
				$this->putCacheFile( $cacheFile, serialize( $this->resultObj  ) );
			}
		}
		return $this->resultObj;
	}
	private function putCacheFile( $cacheFile, $data ){
		global $wgEnableScriptDebug;
		// Don't cache things when in "debug" mode:
		if( $wgEnableScriptDebug ){
			return ;
		}
		file_put_contents( $cacheFile, $data );
	}
}