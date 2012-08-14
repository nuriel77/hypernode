<?php



class Byte_Hypernode_Helper_Cacheable extends Mage_Core_Helper_Abstract
{

    private $_routeName;
    private $_controllerName;
    private $_actionName;

    private $_uncached_session;
    private $_uncached_once;
    private $_cache_me;

    /*
     * Three cookie values are in play:
     * - uncached_session - let Hypernode know there is a session going on
     * - uncached_once    - let Hypernode know that this page should not be cached, but there is no session going on
     * - cache_me         - let Hypernode know that this page is cacheable, except for in sessions
     *
     * We want to be very conservative about what we cache. We used to cache everything, except for blacklisted 
     * pages. That led to overcaching. Now we only cache explicitly, so that when API calls are made, or when
     * independent pages are generated, we do not cache the page.
     * 
     * When uncached_session is set, the page is never cached by Hypernode, no matter what might otherwise be going pn.
     * When uncached_once is set, the page is also never cached by Hypernode.
     * Only when these values are not set, AND cache_me is set to 1, is the page cached.
     * 
     * This means that we need to set and unset uncached_session at key moments.
     */ 
     
    /* This is called when a user is logging in */
    public function startUncachedSession() {
        $this->_uncached_session = 1;
    }

    /* This is called when a user is logging out */
    public function stopUncachedSession()
    {
        $this->_uncached_session = 0;
    }

    /* This is called when a user is on a page that does not start a session, but should not be cached, e.g. /admin/ before the POST */
    public function setUncached()
    {
        $this->_uncached_once = 1;
    }

    public function unsetUncached()
    {
        $this->_uncached_once = 0;
    }

    /* This is called when a page is rendered that can be cached under normal circumstances, i.e. not logged in, no items in shopping cart */
    public function setCacheable() {
        $this->_cache_me = 1;
    }

    public function unsetCacheable() {
        $this->_cache_me = 0;
    }

    /* Send cookies only once */
    public function sendCacheCookies() {
        $this->getCookie()->set('uncached_session', $this->_uncached_session ? 1 : 0);
        $this->getCookie()->set('uncached_once',    $this->_uncached_once ? 1 : 0);
        $this->getCookie()->set('cache_me',         $this->_cache_me ? 1 : 0);

        Mage::log("Sending cookies: uncached_session = " . ($this->_uncached_session ? 1 : 0) . 
                        ", uncached_once = " . ($this->_uncached_once ? 1 : 0) .
                        ", cache_me = " . ($this->_cache_me ? 1 : 0));
    }

    /*
     * Real helper functions that determine the state of the application
     */

    public function isFrontEnd()
    {
        // http://freegento.com/doc/dd/dc2/class_mage___core___model___design___package.html
        // http://freegento.com/doc/dc/d33/class_mage___core___model___app___area.html
        //
        // If we are in the frontend area of Magento (irrespective of URL), return false
        // This is also irrespective of logon state.
        //

        $design = Mage::getSingleton('core/design_package');
        return $design instanceof Mage_Core_Model_Design_Package && $design->getArea() == "frontend";
    }
   
    public function getActionName() {
        if ($this->_actionName)
            return $this->_actionName;

        $this->_actionName = Mage::app()->getRequest()->getActionName();

        return $this->_actionName;
    }
 
    public function getControllerName() {
        if ($this->_controllerName)
            return $this->_controllerName;

        $this->_controllerName = Mage::app()->getRequest()->getControllerName();

        return $this->_controllerName; 
    }

    public function getRouteName() {
        if ($this->_routeName)
            return $this->_routeName;

        $this->_routeName = Mage::app()->getRequest()->getRouteName();

        return $this->_routeName; 
    }

    /*
     * Cacheable pages
     */

    public function isCheckoutPage() {
        if (substr($this->getControllerName(), 0, 8) === "checkout")
            Mage::log("This is a checkout page");

        return $this->getControllerName() === "checkout_cart";
    }

    public function isProductPage() {
        if ($this->getControllerName() === "product")
            Mage::log("This is a product page");

        return $this->getControllerName() === "product";
    }

    public function isCategoryPage() {
        if ($this->getControllerName() === "category")
            Mage::log("This is a category page");

        return $this->getControllerName() === "category";
    }

    // includes frontpage
    public function isCMSPage() {
        if ($this->getRouteName() === "cms")
           Mage::log("This is a CMS page");

        return $this->getRouteName() === "cms";
    }

    // includes blog
    public function isBlogPage() {
        if ($this->getRouteName() === "blog")
            Mage::log("This is a blog page");

        return $this->getRouteName() === "blog";
    }

    /*
     * Admin pages and admin session
     */

    public function isLoginAction() 
    {
        return $this->getActionName() === "login";
    }

    public function isAdminArea()
    {
        // http://stackoverflow.com/questions/3041510/determine-if-on-product-page-programmatically-in-magento
        return $this->getRouteName() === "adminhtml";
    }

    public function isAdminLoggedIn()
    {
        $adminSession = Mage::getSingleton('admin/session');
        return $adminSession instanceof Mage_Admin_Model_Session && $adminSession->isLoggedIn();
    }

    // Do the current user have any items in her basket?
    public function quoteHasItems()
    {
        $quote = Mage::getSingleton('checkout/session')->getQuote();

        return $quote instanceof Mage_Sales_Model_Quote && $quote->hasItems();
    }

    // Does the user have any items to compare?
    public function hasCompareItems()
    {
        // see Mage_Catalog_Helper_Product_Compare
        return Mage::helper('catalog/product_compare')->getItemCount() > 0;
    }

    // Is the customer logged into her account?
    public function isCustomerLoggedIn()
    {
        $customerSession = Mage::getSingleton('customer/session');

        return $customerSession instanceof Mage_Customer_Model_Session && $customerSession->isLoggedIn();
    }

    // Has the admin excluded this page in the backend?
    public function isExcludedPage()
    {
        $url         = Mage::helper('core/url')->getCurrentURL();
        $helper      = Mage::helper('hypernode/data');
        $excluded    = $helper->getExcludedURLs();
        $currentURL  = parse_url($url, PHP_URL_PATH);
        $queryString = parse_url($url, PHP_URL_QUERY); 

        foreach ($excluded as $pattern) {
            if(preg_match("!$pattern!", $currentURL) or preg_match("!$pattern!", $queryString)) {
                return true;
            }
        }

        // not matched, not excluded
        return false;
    }





    /*
     * Debug 
     */
    public function debugCaching($event) {
        $url      = Mage::helper('core/url');
        $request  = Mage::app()->getRequest();
        $response = Mage::app()->getResponse();

        Mage::log( implode( ", ",
                            array(  "url: " .           $url->getCurrentUrl(),
                                    "code: " .          $response->getHttpResponseCode(),
                                    "controller: " .    $this->getControllerName(),
                                    "route: " .         $this->getRouteName(),
                                    "action: " .        $this->getActionName(),
                                    "excluded: " .     ($this->isExcludedPage() ? "true" : "false"),
                                    "isadminlogin: " . ($this->isAdminLoggedIn() ? "true" : "false"),
                                    "iscustlogin: " .  ($this->isCustomerLoggedIn() ? "true" : "false")
                            )
                          ));
    }

    /**
     * Retrieves current cookie.
     * 
     * @return Mage_Core_Model_Cookie
     */
    public function getCookie()
    {
        return Mage::app()->getCookie();
    }

}
