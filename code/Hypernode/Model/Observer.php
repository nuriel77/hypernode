<?php 

class Byte_Hypernode_Model_Observer {

	const HELPER_CLASS = 'hypernode/cacheable'; 
	
    /**
     * This method is called when http_response_send_before event is triggered to identify
     * if current page can be cached and set correct cookies for hypernode.
     * 
     * @param $observer Varien_Event_Observer
     */
    public function hypernode(Varien_Event_Observer $observer)
    {
        /*
         * We set three types of flags through cookies:
         * - setUncachedSession sets uncached_session=1 (which is when a session is started)
         * - setUncached        sets uncached_once=1    (for pages that should not be cached, but do not trigger custom content on normal pages)
         * - setCacheable       sets cache_me=1         (tell Hypernode the page can be cached)
         *
         * The three fields operate independently, so we don't need special if, then, else, etc.
         */

		// return if Hypernode is not enabled in Magento
        if ( ! Mage::app()->useCache('hypernode')) {
            return false;
        }

        $event = $observer->getEvent();
		//Mage::Log("Event: ". $event);

		// Instantiate the helper class
        $helper = Mage::helper(self::HELPER_CLASS); /* @var $helper Byte_Hypernode_Model_Cacheable */

        $helper->debugCaching($event);

        // Let's start with only caching the frontend of the site
        // If we are not on the frontend of the site, cache nothing
        if (! $helper->isFrontEnd()) {
            $helper->setUncached();
        }

        // Is this an admin page? Or is it a login action? Or is it a checkout action? -> Added note: Checkout and Adminarea don't cache by default
    //   if ($helper->isAdminArea() || $helper->isLoginAction() || $helper->isCheckoutPage()) {
       if ( $helper->isLoginAction()) {
			$helper->setUncached();
       }

        /*
         * We don't want pages cached when:
         * - a user is logged in
         * - a user has quote items (basket)
         * - a user is comparing items
         * - is the admin logged in?
         */

        if ($helper->isCustomerLoggedIn() || $helper->isAdminLoggedIn() || $helper->quoteHasItems() || $helper->hasCompareItems()) {
            $helper->startUncachedSession();
        } else {
            $helper->stopUncachedSession();
        }

        // Okey, now let's give the signal that certain pages are cacheable, provided other flags are not set
        // PS: isCMSPage includes frontpage
        if ($helper->isProductPage() || $helper->isCategoryPage() || $helper->isCMSPage() || $helper->isBlogPage() ) {
            $helper->setCacheable();
        }

        // Certain pages are whitelisted. They need to pass once without starting a nocache session
        if ($helper->isExcludedPage() || $helper->isExcludedRoute()) {
            $helper->setUncached();
            $helper->unsetCacheable();
        }

        $helper->sendCacheCookies();
        return false;
    }
    
    /**
     * @see Mage_Core_Model_Cache
     * 
     * @param Mage_Core_Model_Observer $observer 
     */
    public function onCategorySave($observer)
    {
        $category = $observer->getCategory(); /* @var $category Mage_Catalog_Model_Category */
        if ($category->getData('include_in_menu')) {
            // notify user that hypernode needs to be refreshed
            Mage::app()->getCacheInstance()->invalidateType(array('hypernode'));
        }
        
        return $this;
    }

	public function flushHypernode($observer)
	{
		Mage::Log( 'We are at flushHypernode!');
		// If Hypernode is not enabled on admin don't do anything
        if (!Mage::app()->useCache('hypernode')) {
            return;
        }
		Mage::helper('hypernode')->purgeVarnish();
	}

    /**
     * Listens to application_clean_cache event and gets notified when a product/category/cms 
     * model is saved.
     *
     * @param $observer Mage_Core_Model_Observer
     */
    public function purgeCache($observer)
    {

        // If Hypernode is not enabled on admin don't do anything
        if (!Mage::app()->useCache('hypernode')) {
            return;
        }

        $tags = $observer->getTags();
        $urls = array();

        if ($tags == array()) {
            $errors = Mage::helper('hypernode')->purgeAll();
            if (!empty($errors)) {
                Mage::getSingleton('adminhtml/session')->addError("Hypernode Purge failed");
            } else {
                Mage::getSingleton('adminhtml/session')->addSuccess("The Hypernode cache storage has been flushed.");
            }
            return;
        }

        // compute the urls for affected entities 
        foreach ((array)$tags as $tag) {
            //catalog_product_100 or catalog_category_186
            $tag_fields = explode('_', $tag);
            if (count($tag_fields)==3) {
                if ($tag_fields[1]=='product') {
                    // Mage::log("Purge urls for product " . $tag_fields[2]);

                    // get urls for product
                    $product = Mage::getModel('catalog/product')->load($tag_fields[2]);
                    $urls = array_merge($urls, $this->_getUrlsForProduct($product));
                } elseif ($tag_fields[1]=='category') {
                    // Mage::log('Purge urls for category ' . $tag_fields[2]);

                    $category = Mage::getModel('catalog/category')->load($tag_fields[2]);
                    $category_urls = $this->_getUrlsForCategory($category);
                    $urls = array_merge($urls, $category_urls);
                } elseif ($tag_fields[1]=='page') {
                    $urls = $this->_getUrlsForCmsPage($tag_fields[2]);
                }
            }
        }

        // Try not to issue multiple PURGE requests for the same URL
        $urls = array_values(array_unique($urls));

        // Clean URLs
        $cleanurls = Array();
        foreach ($urls as $url) {
            $cleanurls[] = preg_replace('/\?.*/', '', $url); // strip any query string
        }
        $urls = $cleanurls;

        if (!empty($urls)) {
            $errors = Mage::helper('hypernode')->purge($urls);
            if (!empty($errors)) {
                Mage::getSingleton('adminhtml/session')->addError(
                    "Some Hypernode purges failed: <br/>" . implode("<br/>", $errors));
            } else {
                Mage::getSingleton('adminhtml/session')->addSuccess(
                    "Purges have been submitted successfully: <br/>" . implode("<br/>", $urls));
            }
        }

        return $this;
    }

    /**
     * Returns all the urls related to product
     * @param Mage_Catalog_Model_Product $product
     */
    protected function _getUrlsForProduct($product){
        $urls = array();

        $store_ids = $product->getStoreIds();

    foreach ($store_ids as $store_id) {
        $routePath = 'catalog/product/view';
        $routeParams['id']  = $product->getId();
        $routeParams['s']   = $product->getUrlKey();
        $routeParams['_store'] = (!$store_id ? 1: $store_id);
        $url = Mage::getUrl($routePath, $routeParams);
        $urls[] = $url;

        // Collect all rewrites
        $rewrites = Mage::getModel('core/url_rewrite')->getCollection();
        if (!Mage::getConfig('catalog/seo/product_use_categories')) {
            $rewrites->getSelect()
            ->where("id_path = 'product/{$product->getId()}'");
        } else {
            // Also show full links with categories
            $rewrites->getSelect()
            ->where("id_path = 'product/{$product->getId()}' OR id_path like 'product/{$product->getId()}/%'");
        }
        foreach($rewrites as $r) {
            unset($routeParams);
            $routePath = '';
            $routeParams['_direct'] = $r->getRequestPath();
            $routeParams['_store'] = $r->getStoreId();
            $url = Mage::getUrl($routePath, $routeParams);
            $urls[] = $url;
        }
    }

        return $urls;
    }


    /** 
     * Returns all the urls pointing to the category
     */
    protected function _getUrlsForCategory($category) {
        $urls = array();

        $store_ids = $category->getStoreIds();

    foreach ($store_ids as $store_id) {
            $routePath = 'catalog/category/view';
        $routeParams['id']     = $category->getId();
        $routeParams['s']      = $category->getUrlKey();
        $routeParams['_store'] = (!$store_id ? 1: $store_id);
        $url = Mage::getUrl($routePath, $routeParams);
        $urls[] = $url;

        // Collect all rewrites
        $rewrites = Mage::getModel('core/url_rewrite')->getCollection();
        $rewrites->getSelect()->where("id_path = 'category/{$category->getId()}'");
        foreach($rewrites as $r) {
            unset($routeParams);
            $routePath = '';
            $routeParams['_direct'] = $r->getRequestPath();
            $routeParams['_store'] = $store_id; # Default store id is 1
            $routeParams['_store'] = $r->getStoreId();
            $routeParams['_nosid'] = True;
            $url = Mage::getUrl($routePath, $routeParams);
            $urls[] = $url;
        }
    }

        return $urls;
    }

    /**
     * Returns all urls related to this cms page
     */
    protected function _getUrlsForCmsPage($cmsPageId)
    {
        $urls = array();
        $page = Mage::getModel('cms/page')->load($cmsPageId);

        if ($page->getId()) {
            // TODO, FIXME: this does not work, but I don't know why.
            // A page can be in multiple storefronts or in all of them,
            // see table cms_page_store, but I cannot work out how to
            // get to these store objects through the API (AAH)
            $store_ids = $page->getStoreIds();

            // Always add a domain name. Choose the default store.
            if (count($store_ids) == 0) {
            $store_ids[] = 1;
            }

            foreach ($store_ids as $store_id) {
            $routePath = 'cms/page/view';
            $routeParams['id']     = $page->getId();
            $routeParams['s']      = $page->getUrlKey();
            $routeParams['_store'] = (!$store_id ? 1: $store_id);

            $url     = Mage::getUrl($routePath, $routeParams);
            $urlhost = parse_url($url, PHP_URL_HOST);

            $urls[]  = $url;
            $urls[]  = "http://$urlhost/" . $page->getIdentifier();
            }

            return $urls;
        }
    }
}

