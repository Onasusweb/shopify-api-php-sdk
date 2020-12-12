<?php
namespace Shopify;

use GuzzleHttp\Exception\RequestException;
use Shopify\Common\ClientInterface;
use Shopify\Exception\ApiException;

/**
 * Class Client
 * @package Shopify
 */
class Client implements ClientInterface
{
    /**
     * Define constant for current Shopify api version
     */
    const SHOPIFY_API_VERSION = '2020-10';

    /**
     * Define rest api call
     */
    const REST_API = 'rest';

    /**
     * Header parameter of shopify access token
     */
    const SHOPIFY_ACCESS_TOKEN = 'X-Shopify-Access-Token';

    /**
     * Define response header pagination string
     */
    const PAGINATION_STRING = 'Link';

    /**
     * Response header parameter of shopify api limit
     */
    const API_CALL_RATE_LIMIT_HEADER = 'http_x_shopify_shop_api_call_limit';

    /**
     * Define graphQL api call
     */
    const GRAPHQL = 'graphql';

    /**
     * Shopify graphql base url
     * @var string
     */
    protected $graphql_api_url = "https://{shopify_domain}/admin/api/{version}/graphql.json";

    /**
     * Shopify domain name
     * @var string
     */
    protected $shop;

    /**
     * Shopify api key
     * @var string
     */
    protected $api_key;

    /**
     * Shopify password for private app
     * @var string
     */
    protected $password;

    /**
     * Shopify shared secret key for private app
     * @var string
     */
    protected $api_secret_key;

    /**
     * Access token for public app
     * @var string
     */
    protected $access_token;
    
    /**
     * array('version')
     * @var array
     */
    protected $api_params;

    /**
     * Shopify api call url
     * @var array
     */
    protected $base_urls;

    /**
     * Get api header array according to private and public app
     * @var array
     */
    protected $requestHeaders;

    /**
     * Shopify api version
     * @var string
     */
    protected $api_version;

    /**
     * Get response header
     * @var string
     */
    protected $next_page;

    /**
     * Get response header
     * @var string
     */
    protected $prev_page;

    /**
     * Static variable to api is going to reach
     * @var bool
     */
    protected static $wait_next_api_call = false;

    /**
     * Prepare data for rest api request
     * @param $method
     * @param $path
     * @param array $params
     * @return array
     * @throws ApiException
     * @throws ClientException
     */
    public function call($method, $path , array $params = [])
    {
        $url = $this->base_urls[self::REST_API];
        $options = [];
        $allowed_http_methods = $this->getHttpMethods();
        if(!in_array($method, $allowed_http_methods)){
            throw new ApiException(implode(",",$allowed_http_methods)." http methods are allowed.",0);
        }
        if(isset($this->requestHeaders[self::REST_API]) && is_array($this->requestHeaders[self::REST_API])) {
            $options['headers'] = $this->requestHeaders[self::REST_API];
        }

        // Change url in case of access_scopes
        if($path == 'access_scopes'){
            $url = $this->apiScopeUrl($url);
        }

        $url=strtr($url, [
            '{resource}' => $path,
        ]);

        if(in_array($method,['GET','DELETE'])) {
            $options['query'] = $params;
        }else {
            $options['json'] = $params;
        }

        $http_response = $this->request($method,$url,$options);

        if (strtoupper($method) === 'GET'  && $http_response->getHeaderLine(self::PAGINATION_STRING)) {
            $this->next_page = $this->parseLinkString($http_response->getHeaderLine(self::PAGINATION_STRING),'next');
            $this->prev_page = $this->parseLinkString($http_response->getHeaderLine(self::PAGINATION_STRING),'previous');
        }
        if($http_response->getHeaderLine(self::API_CALL_RATE_LIMIT_HEADER)) {
            list($api_call_requested, $api_call_Limit) = explode('/', $http_response->getHeaderLine(self::API_CALL_RATE_LIMIT_HEADER));
            static::$wait_next_api_call = $api_call_requested / $api_call_Limit >= 0.8;
        }
        return \GuzzleHttp\json_decode($http_response->getBody()->getContents(),true);
    }

    /**
     * Prepare data for graphql api request
     * @param string $url
     * @return string
     * 
     */
    public function apiScopeUrl($url){
        return str_replace('api/'.$this->getApiVersion().'/', 'oauth/', $url);
    }

    /**
     * Prepare data for graphql api request
     * @param string $query
     * @return mixed|void
     * @throws ApiException
     * @throws ClientException
     */
    public function callGraphql($query)
    {
        $url = $this->base_urls[self::GRAPHQL];
        $options = [];
        if(isset($this->requestHeaders[self::GRAPHQL]) && is_array($this->requestHeaders[self::GRAPHQL])) {
            $options['headers'] = $this->requestHeaders[self::GRAPHQL];
        }
        $options['body'] = $query;
        if(self::$wait_next_api_call)
        {
            usleep(1000000 * rand(3, 6));
        }
        $http_response = $this->request('POST', $url, $options);
        $response = \GuzzleHttp\json_decode($http_response->getBody()->getContents(),true);
        if(isset($response['errors']))
        {
            $http_bad_request_code = 400;
            throw new ApiException(\GuzzleHttp\json_encode($response['errors']),$http_bad_request_code);
        }
        return $response;
    }

    /**
     * Send http request
     * @param string $method
     * @param string $url
     * @param array $options
     * @return array|mixed
     * @throws ApiException
     */
    public function request($method,$url,array $options)
    {
        try {
            
            $client  = new \GuzzleHttp\Client();
            return $client->request($method, $url, $options);
        }
        catch (RequestException $e) {
            $error_response = $e->getResponse()->getBody()->getContents();
            if(!empty($error_response)) {
                $json_error = json_decode($error_response,true);
                $error_message = isset($json_error['errors'])?json_encode($json_error['errors']):json_encode($json_error);
            }
            else {
                $error_message = $e->getMessage();
            }
            throw new ApiException($error_message,$e->getCode());
        }
    }

    /**
     * Get previous page_info for any resource(products/orders)
     * @return string
     */
    public function getPrevPage()
    {
        return $this->prev_page;
    }

    /**
     * Check previous page_info for any resource(products/orders)
     * @return string
     */
    public function hasPrevPage()
    {
        return !empty($this->prev_page);
    }

    /**
     * Get next page_info for any resource(products/orders)
     * @return string
     */
    public function getNextPage(){
        return $this->next_page;
    }

    /**
     * Check next page_info for any resource(products/orders)
     * @return string
     */
    public function hasNextPage(){
        return !empty($this->next_page);
    }

    /**
     * Parse header string for previous and next page_info
     * @param $pagination_string
     * @param $page_link
     * @return string
     */
    public function parseLinkString($pagination_string,$page_link)
    {
        $matches = [];
        preg_match("/<(.*page_info=([a-z0-9\-]+).*)>; rel=\"?{$page_link}\"?/i", $pagination_string, $matches);
        return isset($matches[2]) ? $matches[2] : NULL;
    }


    /**
     * Get latest api version
     * @return string
     */
    public function getApiVersion()
    {
        return $this->api_version;
    }

    /**
     * Set api version
     * @param api_version
     * Exception for valid value
     * @throws ApiException
     */
    protected function setApiVersion()
    {
        $this->api_version = !empty($this->api_params['version'])?$this->api_params['version']:self::SHOPIFY_API_VERSION;
        if (!preg_match('/^[0-9]{4}-[0-9]{2}$|^unstable$/', $this->api_version))
        {
            throw new ApiException('Api Version must be of YYYY-MM or unstable',0);
        }
    }

    /**
     * Get allowed http api methods
     * @return array
     */
    public function getHttpMethods()
    {
        return ['POST', 'PUT','GET', 'DELETE'];
    }

    /**
     * Set shopify domain
     * @param $shop
     * Exception for invalid shop name
     * @throws ApiException
     */
    protected function setShop($shop)
    {
        if (!preg_match('/^[a-zA-Z0-9\-]{3,100}\.myshopify\.(?:com|io)$/', $shop)) {
            throw new ApiException(
                'Shop name should be 3-100 letters, numbers, or hyphens eg mypetstore.myshopify.com',0
            );
        }
        $this->shop = $shop;
    }

    /**
     * Return shopify domain
     * @return string
     */
    public function getShop()
    {
        return $this->shop;
    }
}