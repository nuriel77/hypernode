<?php

class Byte_Hypernode_Helper_Data extends Mage_Core_Helper_Abstract
{

    /**
     * Check if hypernode is enabled in Cache management.
     * 
     * @return boolean  True if hypernode is enable din Cache management. 
     */
    public function useHypernodeCache(){
        return Mage::app()->useCache('hypernode');
    }

    /**
     * Return excluded URLs from configuration
     *
     * @return array
     */
    public function getExcludedURLs()
    {
        $excludeConfig = Mage::getStoreConfig('hypernode/excludes/exclude');
        $excludeURLs   = array();

        foreach (explode("\n", $excludeConfig) as $value) {

            // Ensure that is not an empty line
            if (preg_match('/\S/', $value)) {
                $excludeURLs[] = trim($value);
            }
        }

        return $excludeURLs;
    }

    /**
     * Return excluded Routes from configuration
     *
     * @return array
     */
    public function getExcludedRoutes()
    {
        $excludeConfig = Mage::getStoreConfig('hypernode/routes/exclude');
        $excludeRoutes   = array();

        foreach (explode("\n", $excludeConfig) as $value) {

            // Ensure that is not an empty line
            if (preg_match('/\S/', $value)) {
                $excludeRoutes[] = trim($value);
            }
        }

        return $excludeRoutes;
    }

    /**
     * Return hypernode servers from configuration
     * 
     * @return array 
     */
    public function getHypernodeServers()
    {
        $serverConfig = Mage::getStoreConfig('hypernode/server_options/servers');
        $hypernodeServers = array();
        
        foreach (explode(',', $serverConfig) as $value ) {
            $hypernodeServers[] = trim($value);
        }

        return $hypernodeServers;
    }

	/* Purge Varnish Cache */
	public function purgeVarnish()
	{

		// Get the current baseUrl
		$baseUrl = parse_url(  Mage::getBaseUrl() );
		$myUrl = $baseUrl['scheme'] . '://' . $baseUrl['host'] . '/.*';

		// Here we use Curl to call Varnish 
		// to purge the provided baseUrl (all content of this
		//	baseurl will be purged in varnish)
        $ch = curl_init();
        curl_setopt($ch,CURLOPT_URL, $myUrl);
        curl_setopt($ch,CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch,CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch,CURLOPT_CUSTOMREQUEST, 'PURGE');
		$result = curl_exec($ch);
        curl_close($ch);
		if ($result) {
			return true;
		} else {	
			return false;
		}
	}

    /**
     * Purges all cache on all Hypernode servers.
     * 
     * @return array errors if any
     */
    public function purgeAll()
    {
    $collection = Mage::getModel("core/store")->getCollection();
    $urls = Array();

    foreach ($collection as $store) {
        $urls[] = $store->getBaseUrl() . ".*";

        # Sometimes we see multiple storefronts with the same frontend URL. We do not need to flush those URLs twice. So uniq and sort.
        $urls = array_values(array_unique($urls));
    }

    $this->purge($urls);
    }

    /**
     * Purge an array of urls on all hypernode servers.
     * 
     * @param array $urls
     * @return array with all errors 
     */
    public function purge(array $urls)
    {
        $hypernodeServers = $this->getHypernodeServers();
        $errors = array();

        // Init curl handler
        $curlHandlers = array(); // keep references for clean up
        $mh = curl_multi_init();

        // Uniq the URL's so we don't flood the console with duplicate URLs
        $urls = array_values(array_unique($urls));

        foreach ((array)$hypernodeServers as $hypernodeServer) {
            foreach ($urls as $url) {
				Mage::Log( 'Loop url is: ' . $url ) ; 
				if ( $url !== '/.*' ){
	                $urlpath = parse_url($url, PHP_URL_PATH);
	                $urlhost = parse_url($url, PHP_URL_HOST);
	
	                $hypernodeUrl = "http://" . $hypernodeServer . $urlpath;
				} else {
					$hypernodeUrl = "http://" . $hypernodeServer . $url;
				}

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $hypernodeUrl);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PURGE');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, FALSE);

                if ($urlhost) {
                    curl_setopt($ch, CURLOPT_HTTPHEADER, array("Host: $urlhost"));
                }

                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

                curl_multi_add_handle($mh, $ch);
                $curlHandlers[] = $ch;
            }
        }

        do {
            $n = curl_multi_exec($mh, $active);
        } while ($active);
        
        // Error handling and clean up
        foreach ($curlHandlers as $ch) {
            $info = curl_getinfo($ch);
            
            if (curl_errno($ch)) {
                $errors[] = "Cannot purge url {$info['url']} due to error" . curl_error($ch);
            } else if ($info['http_code'] != 200 && $info['http_code'] != 404) {
                $errors[] = "Cannot purge url {$info['url']}, http code: {$info['http_code']}";
            }
            
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        
        return $errors;
    }
}
