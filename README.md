CodeIgniter Youtube API Library
===============================

This library will let a user authenticate with youtube and perform actions such as uploading a video, commenting and rating videos, and getting video feeds.

Some basic documentation can be found at http://code.google.com/apis/youtube/articles/codeigniter_library.html
For the most up to date documentation checkout my blog at http://jimdoescode.blogspot.com

Usage
------
Copy the files under your application directory. Then load the library like this:

$params['apikey'] = 'YOUTUBE API KEY';

$this->load->library('youtube', $params);

$this->youtube->getMostViewedVideoFeed(array('max-results'=>30));

License
-------
This library is licensed under the MIT license. 

Sparks
------
You can also use this library with Sparks. Simply install using sparks then call.

$this->load->spark('youtube/1.0.0');

Then load the library as specified in the usage.

