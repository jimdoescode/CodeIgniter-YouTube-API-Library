<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/*
Copyright (C) 2011 by Jim Saunders

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.
*/

class Youtube
{
    const HTTP_1 = '1.1';
    const HOST = 'gdata.youtube.com';
    const PORT = '80';
    const SCHEME = 'http';
    const METHOD = 'GET';
    const LINE_END = "\r\n";
    const API_VERSION = '2';

    const URI_BASE = 'http://gdata.youtube.com/';

    const DEBUG = false;

    private $_uris = array(
        'STANDARD_TOP_RATED_URI'            => 'feeds/api/standardfeeds/top_rated',
        'STANDARD_MOST_POPULAR_URI'         => 'feeds/api/standardfeeds/most_popular',
        'STANDARD_MOST_RECENT_URI'          => 'feeds/api/standardfeeds/most_recent',
        'STANDARD_RECENTLY_FEATURED_URI'    => 'feeds/api/standardfeeds/recently_featured',
        'STANDARD_WATCH_ON_MOBILE_URI'      => 'feeds/api/standardfeeds/watch_on_mobile',
        'PLAYLIST_URI'                      => 'feeds/api/playlists',
        'USER_URI'                          => 'feeds/api/users',
        'INBOX_FEED_URI'                    => 'feeds/api/users/default/inbox',
        'SUBSCRIPTION_URI'                  => 'feeds/api/users/default/subscriptions',
        'FAVORITE_URI'                      => 'feeds/api/users/default/favorites',
        'VIDEO_URI'                         => 'feeds/api/videos',
        'USER_UPLOADS_REL'                  => 'schemas/2007#user.uploads',
        'USER_PLAYLISTS_REL'                => 'schemas/2007#user.playlists',
        'USER_SUBSCRIPTIONS_REL'            => 'schemas/2007#user.subscriptions',
        'USER_CONTACTS_REL'                 => 'schemas/2007#user.contacts',
        'USER_FAVORITES_REL'                => 'schemas/2007#user.favorites',
        'VIDEO_RESPONSES_REL'               => 'schemas/2007#video.responses',
        'VIDEO_RATINGS_REL'                 => 'schemas/2007#video.ratings',
        'VIDEO_COMPLAINTS_REL'              => 'schemas/2007#video.complaints',
        'PLAYLIST_REL'                      => 'schemas/2007#playlist',
        'IN_REPLY_TO_SCHEME'                => 'schemas/2007#in-reply-to',
        'UPLOAD_TOKEN_REQUEST'              => 'action/GetUploadToken'
    );

    private $_header = array(
        'Host'=>self::HOST,
        'Connection'=>'close',
        'User-Agent'=>'CodeIgniter',
        'Accept-encoding'=>'identity'
    );

    private $_oauth = array();
    private $_access = false;

    /**
     * Create YouTube object
     *
     * @param string $clientId The clientId issued by the YouTube dashboard
     * @param string $developerKey The developerKey issued by the YouTube dashboard
     */
    public function __construct($params)
    {
        if(isset($params['apikey']))$this->_header['X-GData-Key'] = 'key='.$params['apikey'];
        $this->CI =& get_instance();
        if(isset($params['oauth']))
        {
            $this->_oauth['key'] = $params['oauth']['key'];
            $this->_oauth['secret'] = $params['oauth']['secret'];
            $this->_oauth['algorithm'] = $params['oauth']['algorithm'];
            $this->_access = $params['oauth']['access_token'];
        }
    }

    /**
     * Builds out an http header based on the specified parameters.
     *
     * @param $url string the url this header will go to.
     * @param $prepend any header data that needs to be added to the header before it is built.
     * @param $append any header data that needs to be added after the header is built.
     * @param $method the http method to be used 'POST', 'GET', 'PUT' etc.
     * return string the http header.
     **/
    private function _build_header($url = false, $prepend = false, $append = false, $method = self::METHOD)
    {
        $str = $prepend === false ? '' : $prepend;
        foreach($this->_header AS $key=>$value)
            $str .= $key.": ".$value.self::LINE_END;
        if($this->_access !== false && $url !== false)
        {
            $this->CI->load->helper('oauth_helper');
            $str .= get_auth_header($url, $this->_oauth['key'], $this->_oauth['secret'], $this->_access, $method, $this->_oauth['algorithm']);
        }
        $str .= $append === false ? '' : $append;

        return $str;
    }

    /**
     * Connects to the configured server and returns a handle for I/O
     **/
    private function _connect($host = self::HOST, $port = self::PORT, $ssl = false)
    {
        $connect = $ssl === false ? 'tcp' : 'ssl';
        $opts = array(self::SCHEME=>array('method'=>self::METHOD, 'header'=>$this->_build_header()));
        $context = stream_context_create($opts);
        $handle = stream_socket_client($connect.'://'.$host.':'.$port, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $context);
        
        return $handle;
    }
    
    /**
     * Checks that the response from the server after we make our request is good.
     * If it isn't then we log the response we got and return false.
     **/
    private function _check_status($handle)
    {
        $gotStatus = false;
        $response = '';
        $resparray = array();
        while(($line = fgets($handle)) !== false && !$this->_timedout($handle))
        {
            $gotStatus = $gotStatus || (strpos($line, 'HTTP') !== false);
            if($gotStatus)
            {
                $response .= $line;
                array_push($resparray, $line);
                if(rtrim($line) === '')break;
            }
        }
        
        $matches = explode(' ', $resparray[0]);
        $status = $gotStatus ? intval($matches[1]) : 0;
        if($status < 200 || $status > 299)
        {
            error_log('YouTube library received bad response: '.$response);
            if(!self::DEBUG)return false;
            else return $response;
        }
        return true;
    }
    
    private function _read($handle)
    {
        if($this->_check_status($handle) !== true)return false;
        $response = '';
        //Get the chunk size
        $chunksize = rtrim(fgets($handle));
        //Convert hex chunk size to int
        if(ctype_xdigit($chunksize))$chunksize = hexdec($chunksize);
        else //We aren't dealing with a chunksize so set the response.
        {
            $response = $chunksize;
            $chunksize = 0;
        }
        
        if(self::DEBUG)error_log("\nCHUNKSIZE: {$chunksize}");
        
        while($chunksize > 0 && !$this->_timedout($handle))
        {
            $line = fgets($handle, $chunksize);
            //If fgets stops on a newline before reaching
            //chunksize. Loop till we get to the chunksize.
            while(strlen($line) < $chunksize)
                $line .= fgets($handle);

            $response .= rtrim($line);
            if(self::DEBUG)error_log("\nCHUNK: {$line}");
            
            $chunksize = rtrim(fgets($handle));
            //If we have a valid number for chunksize and we
            //didn't get an error while reading the last line
            if(ctype_xdigit($chunksize) && $line !== false)$chunksize = hexdec($chunksize);
            else break;
            
            if(self::DEBUG)error_log("\nCHUNKSIZE: {$chunksize}");
        }
        if(self::DEBUG)error_log("\nRESPONSE: {$response}");
        return $response;
    }
    
    /**
     * Writes the specified request to the file handle.
     **/
    private function _write($handle, $request)
    {
        if(self::DEBUG)error_log($request);
        fwrite($handle, $request);
        return $request;
    }
    
    /**
     * Checks that the specified file handle hasn't timed out.
     **/
    private function _timedout($handle)
    {
        if($handle)
        {
            $info = stream_get_meta_data($handle);
            return $info['timed_out'];
        }
        return false;
    }

    /**
     * Executes a request that does not pass data, and returns the response.
     *
     * @param string $uri The URI that corresponds to the data we want.
     * @param array $params additional parameters to pass
     * @return the xml response from youtube.
     **/
    private function _response_request($uri, array $params = array())
    {
        if(!empty($params))$uri .= '?'.http_build_query($params);
        $request = self::METHOD." {$uri} HTTP/".self::HTTP_1.self::LINE_END;

        $url = self::URI_BASE.substr($uri, 1);

        $fullrequest = $this->_build_header($url, $request, self::LINE_END);
        
        if(self::DEBUG)error_log($fullrequest);
        
        $handle = $this->_connect();
        $this->_write($handle, $fullrequest);
        $output = $this->_read($handle);

        fclose($handle);
        $handle = null;

        return $output;
    }

    /**
     * Retrieves a specific video entry.
     *
     * @param $videoId The ID of the video to retrieve.
     * @param $fullEntry (optional) Retrieve the full metadata for the entry.
     *         Only possible if entry belongs to currently authenticated user.
     * @return the xml response from youtube
     */
    public function getVideoEntry($videoId, $fullEntry = false, array $params = array())
    {
        if($fullEntry)return $this->_response_request("/{$this->_uris['USER_URI']}/default/uploads/{$videoId}", $params);
        else return $this->_response_request("/{$this->_uris['VIDEO_URI']}/{$videoId}", $params);
    }

    /**
     * Retrieves a feed of videos related to the specified video ID.
     *
     * @param string $videoId The videoId of interest
     * @param array $params additional parameters to pass to youtube see: http://code.google.com/apis/youtube/2.0/reference.html#Query_parameter_definitions
     * @return the xml response from youtube.
     */
    public function getRelatedVideoFeed($videoId, array $params = array())
    {
        return $this->_response_request("/{$this->_uris['VIDEO_URI']}/{$videoId}/related", array_merge(array('start-index'=>1, 'max-results'=>10), $params));
    }

    /**
     * Retrieves a feed of video responses related to the specified video ID.
     *
     * @param string $videoId The videoId of interest
     * @param array $params additional parameters to pass to youtube see: http://code.google.com/apis/youtube/2.0/reference.html#Query_parameter_definitions
     * @return the xml response from youtube.
     */
    public function getVideoResponseFeed($videoId, array $params = array())
    {
        return $this->_response_request("/{$this->_uris['VIDEO_URI']}/{$videoId}/responses", array_merge(array('start-index'=>1, 'max-results'=>10), $params));
    }
    
    /**
     * Retrieves a feed of videos based on an array of keywords.
     *
     * @param string $keywords Words to search by. Use "" for exact search, - for not and | for or.
     * @param array $params additional parameters to pass to youtube see: http://code.google.com/apis/youtube/2.0/reference.html#Query_parameter_definitions
     * @return the xml response from youtube.
     */
    public function getKeywordVideoFeed($keywords, array $params = array())
    {
        //Only need to convert spaces the rest get converted later.
        $params['q'] = str_replace(' ', '+', $keywords);
        return $this->_response_request("/{$this->_uris['VIDEO_URI']}", array_merge(array('start-index'=>1, 'max-results'=>10), $params));
    }

    /**
     * Retrieves a feed of video comments related to the specified video ID.
     *
     * @param string $videoId The videoId of interest
     * @param array $params additional parameters to pass to youtube see: http://code.google.com/apis/youtube/2.0/reference.html#Query_parameter_definitions
     * @return the xml response from youtube.
     */
    public function getVideoCommentFeed($videoId, array $params = array())
    {
        return $this->_response_request("/{$this->_uris['VIDEO_URI']}/{$videoId}/comments", array_merge(array('start-index'=>1, 'max-results'=>10), $params));
    }

    public function getTopRatedVideoFeed(array $params = array())
    {
        return $this->_response_request("/{$this->_uris['STANDARD_TOP_RATED_URI']}", array_merge(array('start-index'=>1, 'max-results'=>10), $params));
    }
    
    public function getMostPopularVideoFeed(array $params = array())
    {
        return $this->_response_request("/{$this->_uris['STANDARD_MOST_POPULAR_URI']}", array_merge(array('start-index'=>1, 'max-results'=>10), $params));
    }

    public function getMostViewedVideoFeed(array $params = array())
    {
	return $this->getMostPopularVideoFeed($params);
    }

    /**
     * Retrieves a feed of the most recently uploaded videos.
     *
     * @param array $params additional parameters to pass to youtube see: http://code.google.com/apis/youtube/2.0/reference.html#Query_parameter_definitions
     * @return the xml response from youtube.
     */
    public function getMostRecentVideoFeed(array $params = array())
    {
        return $this->_response_request("/{$this->_uris['STANDARD_MOST_RECENT_URI']}", array_merge(array('start-index'=>1, 'max-results'=>10), $params));
    }

    public function getRecentlyFeaturedVideoFeed(array $params = array())
    {
        return $this->_response_request("/{$this->_uris['STANDARD_RECENTLY_FEATURED_URI']}", array_merge(array('start-index'=>1, 'max-results'=>10), $params));
    }

    public function getWatchOnMobileVideoFeed(array $params = array())
    {
        return $this->_response_request("/{$this->_uris['STANDARD_WATCH_ON_MOBILE_URI']}", array_merge(array('start-index'=>1, 'max-results'=>10), $params));
    }

    /**
     * Retrieves a feed of playlist urls that the specified user manages.
     *
     * @param string $user the user whose playlists you wish to retrieve
     * @param array $params additional parameters to pass.
     * @return the xml response from youtube.
     */
    public function getUserPlaylistFeed($user = 'default', array $params = array())
    {
        return $this->_response_request("/{$this->_uris['USER_URI']}/{$user}/playlists", array_merge(array('v'=>self::API_VERSION), $params));
    }

    /**
     * Retrieves a feed of videos for the specified playlist.
     *
     * @param string $playlist the id of the playlist you wish to retrieve
     * @param array $params additional parameters to pass
     * @return the xml response from youtube.
     */
    public function getPlaylistFeed($playlist, array $params = array())
    {
        return $this->_response_request("/{$this->_uris['PLAYLIST_URI']}/{$playlist}", array_merge(array('v'=>self::API_VERSION), $params));
    }

    public function getSubscriptionFeed($user = 'default', array $params = array())
    {
        return $this->_response_request("/{$this->_uris['USER_URI']}/{$user}/subscription", array_merge(array('start-index'=>1, 'max-results'=>10), $params));
    }

    public function getContactFeed($user = 'default', array $params = array())
    {
        return $this->_response_request("/{$this->_uris['USER_URI']}/{$user}/contacts", array_merge(array('v'=>self::API_VERSION), $params));
    }

    /**
     * Get all of the uploads the specified user has made to youtube.
     * If no user is specified then the currently authenticated user
     * is used.
     *
     * @param string $user the youtube user name of the user whose uploads you want.
     * @param array $params additional parameters to pass to youtube see: http://code.google.com/apis/youtube/2.0/reference.html#Query_parameter_definitions
     * @return the xml response from youtube.
     **/
    public function getUserUploads($user = 'default', array $params = array())
    {
        return $this->_response_request("/{$this->_uris['USER_URI']}/{$user}/uploads", array_merge(array('start-index'=>1, 'max-results'=>10), $params));
    }

    public function getUserFavorites($user = 'default', array $params = array())
    {
        return $this->_response_request("/{$this->_uris['USER_URI']}/{$user}/favorites", array_merge(array('start-index'=>1, 'max-results'=>10), $params));
    }

    public function getUserProfile($user = 'default', array $params = array())
    {
        return $this->_response_request("/{$this->_uris['USER_URI']}/{$user}", array_merge(array('v'=>self::API_VERSION), $params));
    }

    public function getUserActivity($user = 'default', array $params = array())
    {
        return $this->_response_request("/{$this->_uris['USER_URI']}/{$user}/events", array_merge(array('v'=>self::API_VERSION), $params));
    }

    /**
     * Get a feed of the currently authenticated users inbox.
     *
     * @return the youtube response xml.
     **/
    public function getInboxFeedForCurrentUser(array $params = array())
    {
        if($this->_access !== false)return $this->_response_request ("/{$this->_uris['INBOX_FEED_URI']}", array_merge(array('v'=>self::API_VERSION), $params));
        else return false;
    }

    /**
     * Executes a request and passes metadata, then returns the response.
     *
     * @param $uri the URI for this request.
     * @param $metadata the data to send for this request (usually XML)
     * @return mixed false if not authroized otherwise the response is returned.
     **/
    private function _data_request($uri, $metadata, $method = 'POST')
    {
        if($this->_access !== false)
        {
            $header = "POST {$uri} HTTP/".self::HTTP_1.self::LINE_END;
            $url = self::URI_BASE.substr($uri, 1);
            $encoding = "UTF-8";
            $extra = "Content-Type: application/atom+xml; charset={$encoding}".self::LINE_END;
            $extra .= "GData-Version: 2.0".self::LINE_END;
            mb_internal_encoding($encoding);
            
            $extra .= "Content-Length: ".mb_strlen($metadata.self::LINE_END).self::LINE_END.self::LINE_END;
            
            $fullrequest = $this->_build_header($url, $header, $extra, $method);
            $fullrequest .= $metadata.self::LINE_END;
            
            $handle = $this->_connect();
            $this->_write($handle, $fullrequest);
            $output = $this->_read($handle);
            
            fclose($handle);
            $handle = null;
            
            return $output;
        }
        return false;
    }

    /**
     * Directly uploads videos stored on your server to the youtube servers.
     *
     * @param string $path The path on your server to the video to upload.
     * @param string $contenttype The mime-type of the video to upload.
     * @param string $metadata XML information about the video to upload.
     * @param int (optional) $filesize the size of the video to upload in bytes. The library will calculate it if not set.
     * @param string (optional) $user the user name whose account this video will go to. Defaults to the authenticated user.
     **/
    public function directUpload($path, $contenttype, $metadata, $filesize = false, $user = 'default')
    {
        if($this->_access !== false)
        {
            $uri = "/{$this->_uris['USER_URI']}/{$user}/uploads";
            $header = "POST {$uri} HTTP/".self::HTTP_1.self::LINE_END;
            //We use a special host for direct uploads.
            $host = 'uploads.gdata.youtube.com';
            $url = "http://{$host}{$uri}";
            $extra = "GData-Version: 2.0".self::LINE_END;
            //Add the file name to the slug parameter.
            $extra .= "Slug: ".basename($path).self::LINE_END;
            //Create a random boundary string.
            $this->CI->load->helper('string');
            $boundary = random_string();
            $extra .= "Content-Type: multipart/related; boundary=\"{$boundary}\"".self::LINE_END;

            //Build out the data portion of the request
            $data = "--{$boundary}".self::LINE_END;
            $data .= "Content-Type: application/atom+xml; charset=UTF-8".self::LINE_END.self::LINE_END;
            $data .= $metadata.self::LINE_END;
            $data .= "--{$boundary}".self::LINE_END;
            $data .= "Content-Type: ".$contenttype.self::LINE_END;
            $data .= "Content-Transfer-Encoding: binary".self::LINE_END.self::LINE_END;

            $end = self::LINE_END."--{$boundary}--".self::LINE_END;

            //If file size is not set then calculate it
            //NOTE: This may cause memory problems for large videos
            if($filesize === false)$filesize = filesize($path);

            $length = strlen($data) + intval($filesize) + strlen($end);

            //Calculate the size of the data portion.
            $extra .= "Content-Length: {$length}".self::LINE_END.self::LINE_END;
            $this->_header['Host'] = $host;//Swap the default host
            $start = $this->_build_header($url, $header, $extra, 'POST');
            $this->_header['Host'] = self::HOST;//Revert the default host.
            $start .= $data;

            $handle = null;
            //Connect to the special upload host
            $handle = $this->_connect($host);
            //Write the request header
            $this->_write($handle, $start);
            //Write the file data
            $this->_write_file($handle, $path);
            //Write the ending
            $this->_write($handle, $end);

            $output = $this->_read($handle);

            fclose($handle);
            $handle = null;

            return $output;
        }
        return false;
    }

    /**
     * Writes a file specified by path to the stream specified by the handle.
     * The file is written in chunks specified by the chunk size to decrease
     * the potential memory footprint.
     *
     * @param stream $handle Handle to the stream to write to
     * @param string $path Path of the file to read from
     * @param int $chunksize Size of each chunk that is read from the file.
     */
    private function _write_file($handle, $path, $chunksize = 8192)
    {
        $filehandle = fopen($path, 'r');
        while(!feof($filehandle))
            fwrite($handle, fread($filehandle, $chunksize));

        fclose($filehandle);
        $filehandle = null;
    }


    /**
     * Makes a data request for a youtube upload token.
     * You must provide details for the video prior to
     * the request. These are specified in xml and are
     * passed as the metadata field.
     *
     * @param string $metadata XML information about the video about to be uploaded.
     * @return mixed false if not authorized otherwise the response is returned.
     **/
    public function getFormUploadToken($metadata)
    {
        return $this->_data_request("/{$this->_uris['UPLOAD_TOKEN_REQUEST']}", $metadata);
    }
    
    /**
     * Add a comment to a video or a reply to another comment.
     * To reply to a comment you must specify the commentId
     * otherwise it is just a regular comment.
     *
     * @param string $videoId the video the comment goes with.
     * @param string $comment the comment
     * @param string (optional) $commentId the id of the comment to reply to.
     * @return mixed false if not authenticated otherwise the http response is returned.
     **/
    public function addComment($videoId, $comment, $commentId = false)
    {
        
            $uri = "/{$this->_uris['VIDEO_URI']}/{$videoId}/comments";
            $url = self::URI_BASE.substr($uri, 1);
            
            $xml = "<?xml version='1.0' encoding='UTF-8'?><entry xmlns='http://www.w3.org/2005/Atom' xmlns:yt='http://gdata.youtube.com/schemas/2007'>";
            if($commentId !== false)$xml .= "<link rel='http://gdata.youtube.com/schemas/2007#in-reply-to' type='application/atom+xml' href='{$url}/{$commentId}'/>";
            $xml .= "<content>{$comment}</content></entry>";
            
            return $this->_data_request($uri, $xml);
    }
    
    /**
     * Add a video response to another video.
     *
     * @param string $videoId the youtube id of the video the response is to.
     * @param string $responseId the youtube id of the video response.
     * @return mixed false if not authenticated otherwise the http response is returned.
     **/
    public function addVideoResponse($videoId, $responseId)
    {
        $uri = "/{$this->_uris['VIDEO_URI']}/{$videoId}/responses";
        $xml = "<?xml version='1.0' encoding='UTF-8'?><entry xmlns='http://www.w3.org/2005/Atom'><id>{$responseId}</id></entry>";
        
        return $this->_data_request($uri, $xml);
    }
    
    /**
     * Adds a numeric rating between 1 and 5 to the specified video
     *
     * @param string $videoId the youtube video id.
     * @param int $rating the numeric rating between 1 and 5.
     * @return mixed false if not authenticated or rating is invalid otherwise the http response is sent.
     **/
    public function addNumericRating($videoId, $rating)
    {
        if(is_numeric($rating) && $rating > 0 && $rating < 6)
        {
            $xml = "<?xml version='1.0' encoding='UTF-8'?><entry xmlns='http://www.w3.org/2005/Atom' xmlns:gd='http://schemas.google.com/g/2005'><gd:rating value='{$rating}' min='1' max='5'/></entry>";
            return $this->_data_request("/{$this->_uris['VIDEO_URI']}/{$videoId}/ratings", $xml);
        }
        return false;
    }
    
    /**
     * Adds a like or dislike rating to the specified video.
     *
     * @param string $videoId the youtube video id.
     * @param bool $like boolean where true = like and false = dislike.
     * @return mixed false if not authenticated otherwise the http response is sent.
     **/
    public function addLikeDislike($videoId, $like)
    {
        $xml = "<?xml version='1.0' encoding='UTF-8'?><entry xmlns='http://www.w3.org/2005/Atom' xmlns:yt='http://gdata.youtube.com/schemas/2007'><yt:rating value='".($like === true ? 'like':'dislike')."'/></entry>";
        return $this->_data_request("/{$this->_uris['VIDEO_URI']}/{$videoId}/ratings", $xml);
    }
    
    /**
     * Subscribes the currently authenticated user to the specified user.
     * 
     * @param string $userId the user you want to subscribe to.
     * @return mixed false if not authenticated otherwise the http response is sent.
     */
    public function addSubscription($userId) 
    {
        $xml = "<?xml version='1.0' encoding='UTF-8'?><entry xmlns='http://www.w3.org/2005/Atom' xmlns:yt='http://gdata.youtube.com/schemas/2007'><category scheme='http://gdata.youtube.com/schemas/2007/subscriptiontypes.cat' term='channel'/><yt:username>{$userId}</yt:username></entry>";
        return $this->_data_request("/{$this->_uris['SUBSCRIPTION_URI']}", $xml);
    }
    
    /**
     * Adds specified video as a favorite video.
     * 
     * @param string $videoId the youtube video you want to add to favorites.
     * @return mixed false if not authenticated otherwise the http response is sent. 
     */
    public function addFavorite($videoId) 
    {
        $xml = "<?xml version='1.0' encoding='UTF-8'?><entry xmlns='http://www.w3.org/2005/Atom'><id>{$videoId}</id></entry>";
        return $this->_data_request("/{$this->_uris['FAVORITE_URI']}", $xml);
    }

    /**
     * Adds specified video to the specified playlist
     * 
     * @param string $videoId the youtube video you want to add to the playlist.
     * @param string $playlistId the youtube playlist you want to add the video to.
     * @return mixed false if not authenticated otherwise the http response is sent. 
     */
    public function addVideoToPlaylist($videoId, $playlistId)
    {
        $xml = "<?xml version='1.0' encoding='UTF-8'?><entry xmlns='http://www.w3.org/2005/Atom' xmlns:yt='http://gdata.youtube.com/schemas/2007'><id>{$videoId}</id></entry>";
        return $this->_data_request("/{$this->_uris['PLAYLIST_URI']}/{$playlistId}", $xml);
    }
    
    /**
     * Sets the position of the specified video entry in the specified playlist
     * 
     * @param string $playlistEntryId the youtube video entry you want to change the position.
     * @param string $playlistId the youtube playlist you want to change the position.
     * @param integer $position the position of the video in the playlist.
     * @return mixed false if not authenticated otherwise the http response is sent. 
     */
    public function setVideoPositionInPlaylist($playlistEntryId, $playlistId, $position)
    {
        $xml = "<?xml version='1.0' encoding='UTF-8'?><entry xmlns='http://www.w3.org/2005/Atom' xmlns:yt='http://gdata.youtube.com/schemas/2007'><yt:position>{$position}</yt:position></entry>";
        return $this->_data_request("/{$this->_uris['PLAYLIST_URI']}/{$playlistId}/{$playlistEntryId}", $xml, 'PUT');
    }
}
// ./application/libraries
