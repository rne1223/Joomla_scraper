<?php
require_once('simple_html_dom.php');
class SpiderTree
{
    /**
     * Settings in order to modify curl
     */
    private $ch;                             // will initialize curl handle in __construct
    private $timeStart;                     
    private $timeLapsed;
    private $VAR_CURLOPT_FAILONERROR = 0;    // if HTTP code > 300 still returns the page
    private $VAR_CURLOPT_FOLLOWLOCATION = 1; // allow redirects 
    private $VAR_CURLOPT_RETURNTRANSFER = 1; // will return the page in a variable 
    private $VAR_CURLOPT_USERAGENT = "Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.0)";
    private $intMaxDepth = 10; 		        // max number of pages to crawl  --> note that this should not be used for control loop; it is a safety valve
    private $intDepth = 0;		            // tracks depth as spider crawls 
    private $strRootLink;		            // Passed link
    private $arrIDs = array('menu'=>'pbutts','content'=>'prg-cont'); // Hold selected IDs in order to search
    private $arrUrlExt = array('','org','php','html','htm','com','edu');
    private $arrLinksFound = array();	    // holds links found so they won't be added again
    private $scraped = array();	    // holds links found so they won't be added again
    private $host;                          // contains the Host name eg: http://google.com -> host=google.com
    public  $booMax=false;
    public  $arrTree = array();	            // holds links to crawl 
    public  $display = 1;            // Flag to display errors
    public  $intFetch = 6;                 // How many should be process at a time

    /*
     * Constructor 
     * Sets the time and initiates curl
     * @strInpuLink - input link from the user
     */
    function __construct($strInputLink) 
    {
        unset($this->arrLinksFound, $this->arrTree); // Clear the arrays
        ob_start();  // to output faster

        $this->host = parse_url($strInputLink,PHP_URL_HOST); //contains our current host
        $this->ch = curl_multi_init();
        $this->strRootLink = $strInputLink;
        $this->intDepth = 0;
        $this->arrTree = array($this->strRootLink);
        $this->BuildTree($this->arrTree);
    }	

    function __destruct() 
    {
        curl_multi_close($this->ch);  
        ob_end_flush();
    }

    /**
     * Verifies a link
     *
     * @returns bool -> true if is acceptable false otherwise
     */
    function accExt($strLink)
    {
        $found = false; // Flag to check for extensions

        if(empty($strLink))
            return $found;

        $strExt = pathinfo($strLink,PATHINFO_EXTENSION);  

        if(!empty($this->arrUrlExt))
        {
            $search = array_flip($this->arrUrlExt);

            if(isset($search[$strExt]))
                return !$found; // return true if extension found
            else
                return $found;
        }
        else
            return $found;
    }

    /**
     * Converts a complicated path into the real path
     *
     *
     * @param   String  Path that needs to be converted
     * @return  String  Returns real path
     */
    function realPath($path) 
    { 
        $out=array(); 
        foreach(explode('/', $path) as $i=>$fold)
        { 
            if ($fold=='' || $fold=='.') 
                continue; 
            if ($fold=='..' && $i>0 && end($out)!='..') 
                array_pop($out); 
            else 
                $out[]= $fold; 
        } 

        return ($path{0}=='/'?'/':'').join('/', $out); 
    } 

    /**
     * Checks if a link already exist 
     *
     * @return bool True if exist False otherwise
     **/
    function exist($strLink)
    {
        $found = false;

        if(empty($strLink))
            return $found;

        if(!empty($this->arrLinksFound))
        {
            $search = array_flip($this->arrLinksFound);

            if(isset($search[$strLink]))
                return !$found;
            else
                return $found;
        }
        else
            return $found;
    }

    /**
     * Checks if a link already exist 
     *
     * @return bool True if exist False otherwise
     **/
    function scraped($strLink)
    {
        $found = false;

        if(empty($strLink))
            return $found;

        if(!empty($this->arrLinksFound))
        {
            $search = array_flip($this->scraped);

            if(isset($search[$strLink]))
                return !$found;
            else
                return $found;
        }
        else
            return $found;
    }

    /**
     * Builds a tree like structure with one parent and multiple childs
     *
     * @arrInput -> passed in array holding parent links
     */
    function BuildTree($arrInput)
    {
        if(empty($arrInput))
        {
            echo "<i><b>DONE</b></i>";
            return;
            // return array('error'=>-1,'errortext'=>'<b>Passed array if empty</b><br>');
        }

        if(!is_array($arrInput))
        {
            echo "<b>Error: input not allowed</b><br>";
            return;
            // return array('error'=>-2,'errortext'=>'<b>Passed argument is not array</b><br>');
        }

        if($this->intMaxDepth-1 < $this->intDepth)
        {
            $this->booMax = true;
            return array('error'=>-3,'errortext'=>'<b>Max Depth Reached</b><br>');
        }

        $arrData = $this->Pipe($arrInput);

        if(!empty($arrData))
            $this->arrTree[] = $arrData;

        unset($arrInput,$arrData); // Not in use then delete

        ++$this->intDepth;
        $this->BuildTree($this->arrTree[$this->intDepth]);
    }

    /**
     * Gets child links inside an html page
     *
     * @arrPageLinks Array - links to obtain child links from
     * @returns Array
     */
    function Pipe($arrParentLinks) 
    {
        $arrHandles = array();
        $arrPages = array();
        $max = count($arrParentLinks);
        $len = $this->intFetch;

        if($max < $len)
        {
            $arrHandles = $this->addHandles($this->ch,$arrParentLinks);
            $this->execHandles();
            $arrPages = array_merge($arrPages,$this->getPages($arrHandles));
        }
        else
        {
            for($start=0; $start < $max; $start+=$len) 
            {
                $arrHandles = $this->addHandles($this->ch,array_slice($arrParentLinks,$start,$len));
                $this->execHandles();
                $arrPages = array_merge($arrPages,$this->getPages($arrHandles));
            }
        }

        unset($max,$len,$arrHandles,$arrParentLinks);
        return $arrPages;
    } 

    /**
     * Returns html as an string
     *
     * @param   id      Id that we want to get the html from     
     * @return  string  Html inside the id
     **/
    function getHtml($HTML,$id)
    {
        $parser = new DOMDocument;
        @$parser->loadHTML($HTML);
        $html = $parser->saveXML($parser->getElementById($id));

        unset($parser);
        return $html;
    }

    /**
     * This function retrives data from multiple pages
     *
     * @param   Array   Curl handles to retrive data from the web
     * @return  Array   HTML pages with their data 
     **/
    function getPages($arrHandles)
    {
        $pages = $output = array();
        $html = $errortext = $url = '';
        $error = -1; 
        $http_code = 0;

        // Get pages from the web and process them
        foreach($arrHandles as $handle) 
        {
            $error      = curl_errno($handle);
            $errortext  = curl_error($handle);

            // If there weren't error retriving the page
            if($error == 0)
            {        
                $html       = curl_multi_getcontent($handle);
                $url        = curl_getinfo($handle,CURLINFO_EFFECTIVE_URL);
                $http_code  = curl_getinfo($handle,CURLINFO_HTTP_CODE);

                // If the status isn't 200 means that the page doesn't exist
                if($http_code == 200)
                {
                    // If it can be scrape then pull all links,images,html ect from url
                    if($this->accExt($url) && !$this->scraped($url))
                    {
                        $this->arrScraped[] = $url;

                        // Remove everything except the selected ID
                        if($this->intDepth == 0)
                            $html = $this->getHtml($html,$this->arrIDs['menu']);
                        else
                            $html = $this->getHtml($html,$this->arrIDs['content']);

                        $page = $this->getPage($html,$url);

                        if(!empty($page))
                            $pages = array_merge($pages,$page); // Get pages from the page

                    } // If it can't be scrape but it already exist like a .pdf then just pull the info
                    else if(!$this->accExt($url) && $this->exist($url) && !$this->scraped($url))
                    {
                        $this->arrScraped[] = $url;

                        if($this->display)
                        {
                            echo "<pre> Parent:  ====>  $url<br></pre>";
                            ob_flush();
                            flush();
                            usleep(50000);
                        }
                    }
                }
                else
                {
                    echo "<pre><b>Error: $http_code </b> ====> <u>$url</u> doesn't exist<br></pre>";
                    ob_flush();
                    flush();
                    usleep(50000);
                }
            }
            else
            {
                echo "<pre><b>Error: $error</b> ====> <u>$url</u> <b>$errortext</b><br></pre>";
                ob_flush();
                flush();
                usleep(50000);
            }

            // In order to clear memory
            curl_multi_remove_handle($this->ch,$handle);     
            curl_close($handle);
        }

        unset($arrHandles);
        return $pages; 
    }

    /**
     * This function retrives the data
     *
     * @return array 
     **/
    function getPage($HTML,$pUrl) 
    {                              
        if($this->display)
        {
            echo "<pre> Parent:  ====> $pUrl<br>";
            ob_flush();
            flush();
            usleep(50000);
        }

        $Page = array();
        // $Page['title'] = $this->getTitle($pUrl);
        $this->getLinks($HTML,$pUrl,$Page);

        if($this->display)
        {
            echo "</pre>";
            ob_flush();
            flush();
            usleep(50000);
        }

        return $Page;
    }

    /**
     * Gets links using the simple_html_dot class
     *
     * @return array with 
     */
    function getLinks($HTML, $ParentUrl, &$Page=null) 
    {                              
        $arrLinks = array();
        $strSubHref = $strFullHref = $host = '';

        $parser = new DOMDocument();
        @$parser->loadHTML($HTML); 

        //Loop through each <a> tag in the dom and add it to the link array 
        foreach($parser->getElementsByTagName('a') as $link) 
        { 
            $strSubHref = rtrim($link->getAttribute('href'),'/');
            $ParentUrl = rtrim($ParentUrl,'/');
            $host = parse_url($strSubHref,PHP_URL_HOST);

            if(strpos($ParentUrl,"\n") !== false || strpos($strSubHref,"\n") !== false)
                continue;

            // If scraping from another host
            if(strpos($strSubHref,'http') !== false && $this->host != $host)
            {
                continue;    
            } // If it has our host don't do any string manipulation
            else if(strpos($strSubHref,'http') !== false && $this->host == $host)
            {
                continue;
            }

            // Clean link from '#','?',javascript and other stuff
            if(!empty($strSubHref))
                $cleanHref = $this->cleanLink($strSubHref);

            // If after being process nothing gets returned
            if(!empty($cleanHref))
            {
                // Parent url can't contain extensions
                // such as http://example.com/index.php     
                if(strpos($ParentUrl,'php') !== false)
                    $ParentUrl = dirname($ParentUrl);

                $strFullHref = $ParentUrl.$cleanHref;

                if(strpos($strFullHref,'..') !== false)
                {
                    // We remove the root so it doesn't mess with the double back slashes from http://
                    $strFullHref = str_replace($this->strRootLink,'',$strFullHref);
                    $strFullHref = $this->realPath($strFullHref);
                    // We add it back to make it a comple url
                    $strFullHref = $this->strRootLink.$strFullHref;
                }

                if(!$this->exist($strFullHref)) 
                {
                    if($this->display)
                    {
                        echo "\tChild:  ====>  $strFullHref\n";
                        ob_flush();
                        flush();
                        usleep(50000);
                    }

                    if($Page !== null)
                        $Page[] = $strFullHref;
                    else
                        $arrLinks[] = $strFullHref;

                    $this->arrLinksFound[] = $strFullHref;
                }
            }
        } 

        unset($parser);

        if($Page === null)
            return $arrLinks;
    }

    /**
     * Clean links from unwanted stuff
     *
     * @return String if there is something to return Void otherwise
     **/
    function cleanLink($strLink)
    {
        if(strpos($strLink,' ') !==false)
            $strLink = str_replace(' ','%20',$strLink);

        // docs found in the menu -> this means that is under /student_orgs
        // a hack for rop.mercedlearn.org's menu
        if($this->intDepth == 0 && strpos($strLink,'docs') !== false && 
            strpos($strLink,'for_teachers') === false)
        {
            $strLink = '/student_orgs/'.$strLink;
        }

        if($strLink[0] != '/' ) 
            $strLink = '/'.$strLink;

        // Remove '#' '?' 'javascript:void()' 
        if( strpos($strLink,'#') !== false || 
            strpos($strLink,'?') !== false || 
            strpos($strLink,'javascript') !== false || 
            strpos($strLink,'@') !== false )
        {
            $strLink = dirname($strLink);
        }

        if(strlen($strLink) > 1)
            return $strLink;
    }

    /**
     * Create cUrl multi handles
     *
     * @param reference curlHandle is a reference to the multi_init
     * @param array     arrUrl is an array containing the urls that need handle
     * @returns arrays of handles 
     */
    function addHandles(&$curlHandle,$arrUrl) 
    {
        $arrHandles = array();
        foreach($arrUrl as $url) 
        {
            $handle = curl_init();
            curl_setopt($handle, CURLOPT_URL, $url);
            curl_setopt($handle, CURLOPT_FAILONERROR, $this->VAR_CURLOPT_FAILONERROR);
            curl_setopt($handle, CURLOPT_FOLLOWLOCATION, $this->VAR_CURLOPT_FOLLOWLOCATION);
            curl_setopt($handle, CURLOPT_RETURNTRANSFER, $this->VAR_CURLOPT_RETURNTRANSFER);

            if(strlen($this->VAR_CURLOPT_USERAGENT)>0) 
                curl_setopt($handle, CURLOPT_USERAGENT, $this->VAR_CURLOPT_USERAGENT);

            curl_multi_add_handle($curlHandle,$handle);
            $arrHandles[] = $handle;
        }
        return $arrHandles;   
    } 

    /**
     * Executes handles 
     *
     * @return void
     **/
    function execHandles()
    {
        // Processes each of the handles from $arrHandles
        do {
            $mrc = curl_multi_exec($this->ch,$active);   // fetch pages in parallel
            // usleep(rand(1,2)*1000);  // reduce heavy load on the server, wait from 1 to 2 sec
        } while ($mrc == CURLM_CALL_MULTI_PERFORM);

        while ($active and $mrc == CURLM_OK) 
        {
            // wait for network
            if (curl_multi_select($this->ch) != -1) 
            {
                // pull in any new data, or at least handle timeouts
                do {
                    $mrc = curl_multi_exec($this->ch, $active);
                    // usleep(rand(1,2)*1000); // reduce heavy load on the server, wait from 1 to 2 sec
                } while ($mrc == CURLM_CALL_MULTI_PERFORM);
            }
        }

        // In Case there was an error while cUrl was fetching pages
        if ($mrc != CURLM_OK) 
            echo "<b>Error: Curl multi read error $mrc<b><br>";
    }
} 
?>
