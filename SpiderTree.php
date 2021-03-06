<?php
class SpiderTree
{
    /**
     * Settings in order to modify curl
     */
    public $ch;                   // will initialize curl handle in __construct
    public $host;                 // contains the Host name eg: http://google.com -> host=google.com
    public $parser;
    public $strRootLink;		  // Passed link by the user
    public $display         = 0;  // Flag to display errors
    public $display_error   = 0;  // Flag to display errors
    public $booMax          = 0;  // Flag to check if the max was reached
    public $gotMenu         = 0;  // Flag to check if the menu has been retrived
    public $intFetch        = 6;  // How many handles should be process at a time
    public $intDepth        = 0;  // tracks depth as spider crawls 
    public $intMaxDepth     = 2; // max number of pages to crawl  
    public $Tree            = array(); // holds links to crawl 
    public $arrIDs          = array('menu'=>'pbutts','content'=>'prg-cont'); // Hold selected IDs in order to search
    public $arrUrlExt       = array('','org','php','html','htm','com','edu');
    public $arrScraped      = array();	    // holds links found so they won't be added again
    public $arrLinksFound   = array();	    // holds links found so they won't be added again
    public $arrErrors       = array();
    public $VAR_CURLOPT_FAILONERROR     = 0;    // if HTTP code > 300 still returns the page
    public $VAR_CURLOPT_FOLLOWLOCATION  = 1; // allow redirects 
    public $VAR_CURLOPT_RETURNTRANSFER  = 1; // will return the page in a variable 
    public $VAR_CURLOPT_USERAGENT       = "Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.0)";

    /*
     * Constructor 
     * Sets the time and initiates curl
     * @strInpuLink - input link from the user
     */
    function __construct($strInputLink) 
    {
        unset($this->arrLinksFound, $this->arrTree); // Clear the arrays
        ob_start();  // to output faster

        $this->parser = new DOMDocument();
        $this->host = parse_url($strInputLink,PHP_URL_HOST); //contains our current host
        $this->ch = curl_multi_init();
        $this->strRootLink = $strInputLink;
        $this->intDepth = 0;
        $this->Tree = array($this->strRootLink);
        $this->BuildTree($this->Tree);
    }	

    function __destruct() 
    {
        curl_multi_close($this->ch);  
        ob_end_flush();
    }

    /**
     * Builds a tree like structure with one parent and multiple childs
     *
     * @arrInput -> passed in array holding parent links
     */
    function BuildTree($arrInput)
    {
        if($this->intMaxDepth-1 < $this->intDepth)
        {
            $this->booMax = 1;
            return;
        }

        if(empty($arrInput))
        {
            echo "<i><b>DONE</b></i>";
            return;
        }

        if(!is_array($arrInput))
        {
            echo "<b>Error: input not allowed</b><br>";
            return;
        }

        $arrData = $this->Pipe($arrInput);
        $childs  = array();

        // Get the links to search
        foreach ($arrData as $page)
        {
            $childs = array_merge($childs,$page['links']);
            // $cont[] = array('url'=>$page['url'],'title'=>$page['title'],'html'=>$page['html']);
            echo $page['url'].'<br>';
            echo $page['html'];
            echo '++++++++++++++++++<br>';
        }

        if(!empty($arrData))
            $this->arrTree[] = $arrData;

        // Clear some memory
        unset($arrInput,$arrData); 

        ++$this->intDepth;
        $this->BuildTree($childs);
    }

    /**
     * Gets child links inside an html page
     *
     * @Urls        Array - links to obtain child links from
     * @returns     Array
     */
    function Pipe($Urls) 
    {
        $fetchedData = array();
        $handles = array();
        $max = count($Urls);
        $len = $this->intFetch;

        if($max < $len)
        {
            $handles = $this->addHandles($this->ch,$Urls);
            $this->execHandles();
            $fetchedData = array_merge($fetchedData,$this->getPages($handles));
        }
        else
        {
            for($start=0; $start < $max; $start+=$len) 
            {
                $handles = $this->addHandles($this->ch,array_slice($Urls,$start,$len));
                $this->execHandles();
                $fetchedData = array_merge($fetchedData,$this->getPages($handles));
            }
        }

        unset($max,$len,$handles,$Urls);
        return $fetchedData;
    } 

    /**
     * This function retrives data from multiple pages
     *
     * @param   Array   Curl handles to retrive data from the web
     * @return  Array   HTML pages with their data 
     **/
    function getPages($arrHandles)
    {
        $pages  = $output = array();
        $html   = $errortext = $url = '';
        $error  = -1; 
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
                        if(!$this->gotMenu)
                        {
                            $page = $this->getPage($this->arrIDs['menu'],$html,$url);
                            $this->gotMenu = 1;
                        }
                        else
                            $page = $this->getPage($this->arrIDs['content'],$html,$url);

                        if(!empty($page))
                            $pages[] = $page; // Get pages from the page

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
                    if($this->display)
                    {
                        echo "<pre><b>Error: $http_code </b> ====> <u>$url</u> doesn't exist<br></pre>";
                        ob_flush();
                        flush();
                        usleep(50000);
                    }
                    $this->arrErrors[$url] = "Error: $http_code  ====> $url doesn't exist";
                }
            }
            else
            {
                if($this->display )
                {
                    echo "<pre><b>Error: $error</b>  ====> <u>$url</u> <b>$errortext</b><br></pre>";
                    ob_flush();
                    flush();
                    usleep(50000);
                }
                $this->arrErrors[$url] = "Error: $error  ====> $url $errortext";
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
    function getPage($id,$HTML,$url) 
    {                              
        $title = $this->getTitle($url); 

        if(empty($title))
            $title = 'Home';

        $html = $this->getHtmlById($id,$HTML);

        if($this->display)
        {
            echo "<pre> Parent:$title  ====> $url<br>";
            ob_flush();
            flush();
            usleep(50000);
        }

        $Page = array();
        $Page['url']    = $url;
        $Page['title']  = $title;
        $Page['html']   = $html;
        $Page['links']  = $this->getLinks($html,$url,'a');

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
     * Gets links of a certain tag 
     *
     * @return array with 
     */
    function getLinks($HTML,$ParentUrl,$tag) 
    {                              
        @$this->parser->loadHTML($HTML); 
        $arrLinks = array();
        $path = $href = $host = '';

        foreach($this->parser->getElementsByTagName($tag) as $link) 
        { 
            $host = parse_url($path,PHP_URL_HOST);
            $ParentUrl = rtrim($ParentUrl,'/');

            if ($tag == 'a')
            {
                $path = rtrim($link->getAttribute('href'),'/');
            }
            else if ($tag == 'img')
            {
                $path = rtrim($link->getAttribute('src'),'/');
            }

            // If scraping from another host
            if (strpos($path,'http') !== false && $this->host != $host)
            {
                continue;    
            } // If it has our host don't do any string manipulation
            else if (strpos($path,'http') !== false && $this->host == $host)
            {
                continue;
            }

            // Clean link from '#','?',javascript and other stuff
            if (!empty($path))
                $cleanHref = $this->cleanLink($path);

            if (strpos($ParentUrl,"\n") !== false || strpos($path,"\n") !== false)
            {
                continue;
            }

            // If after being process nothing gets returned
            if (!empty($cleanHref))
            {
                if ($cleanHref[0] != '/') 
                {
                    // Parent url can't contain extensions
                    // such as http://example.com/index.php     
                    if(strpos($ParentUrl,'php') !== false)
                        $ParentUrl = dirname($ParentUrl);

                    $href = $ParentUrl.'/'.$cleanHref;
                }
                else
                    $href = $this->strRootLink.$cleanHref;

                if (strpos($href,'..') !== false)
                {
                    // We remove the root so it doesn't mess with the double back slashes from http://
                    $href = str_replace($this->strRootLink,'',$href);
                    $href = $this->realPath($href);
                    // We add it back to make it a comple url
                    $href = $this->strRootLink.$href;
                }

                if (!$this->exist($href)) 
                {
                    if ($this->display)
                    {
                        echo "\tChild:  ====>  $href\n";
                        ob_flush();
                        flush();
                        usleep(50000);
                    }

                    $arrLinks[] = $href;
                    $this->arrLinksFound[] = $href;
                }
            }
        } 

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


        // Remove '#' '?' 'javascript:void()' 
        if( strpos($strLink,'#') !== false || 
            strpos($strLink,'?') !== false || 
            strpos($strLink,'javascript') !== false || 
            strpos($strLink,'@') !== false ||
            strpos($strLink,'index.php') !== false)
        {
            $strLink = dirname($strLink);
        }

        if(strlen($strLink) > 1)
            return $strLink;
    }

    /**
     * Returns html as an string
     *
     * @param   id      Id that we want to get the html from     
     * @return  string  Html inside the id
     **/
    function getHtmlById($id,$HTML)
    {
        $this->parser = new DOMDocument;
        @$this->parser->loadHTML($HTML);
        $html = $this->parser->saveXML($this->parser->getElementById($id));
        $html = $this->cleanHtml($html);

        return $html;
    }
    
    function cleanHtml($HTML)
    {
        @$this->parser->loadHTML($HTML);
        $xpath = new DOMXPath($this->parser);
        $query = "//div[@id='searchbar'] | //img[@class='page-header']/..";
        $oldnodes = $xpath->query($query);

        foreach($oldnodes as $node) 
        {
            $node->parentNode->removeChild($node);
        }

        return $this->parser->saveXML();
    }

    /**
     * This function retrives the title for an html page
     *
     * @param   String  HTML page
     * @return  String  title of html page
     **/
    function getTitle($url)
    {
        $title = str_replace($this->strRootLink,'',$url);
        $title = str_replace('.php','',$title); // remove .php
        $title = str_replace('%20','_',$title); // replace with whites spaces
        $title = str_replace('/',' ',$title);   
        
        $title = ucwords($title);
        return $title;
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

        if(!empty($this->arrScraped))
        {
            $search = array_flip($this->arrScraped);

            if(isset($search[$strLink]))
                return !$found;
            else
                return $found;
        }
        else
            return $found;
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
