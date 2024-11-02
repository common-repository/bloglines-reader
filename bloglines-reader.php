<?php
/*
Plugin Name: BloglinesReader
Plugin URI: http://blog.codahale.com/bloglinesreader/
Description: Downloads and displays your feeds from Bloglines.
Author: Coda Hale & Peter Dolan
Version: trunk
Author URI: http://blog.codahale.com
*/

/*  Copyright 2005  Coda Hale  (email : coda.hale@gmail.com)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

define('OPT_USERNAME', 'chBloglinerUsername');
define('OPT_BASEFOLDER','chBloglinerBaseFolder');
define('OPT_UPDATEFREQUENCY','chBloglinerUpdateFrequency');
define('OPT_CACHEAGE','chBloglinerCacheAge');
define('OPT_CACHE','chBloglinerCache');

class chBloglinesFeedReader {

  var $bloglinesUsername;
  var $baseFolder;
  var $updateFrequency; // in seconds

  function chBloglinesFeedReader($username, $folder = '', $updateTime = 3600 /* one hour */  ) {
    $this->bloglinesUsername = $username;
    $this->baseFolder = $folder;
    $this->updateFrequency = $updateTime;
  }

  // returns an array of formatting links based on presets
  function getFormatForType($formatType) {
    switch ($formatType) {
      case 'unorderedList':
        return array(
          'beginFeeds' => '<ul>',
          'endFeeds' => '</ul>',
          'beginFolder' => '<li>%title%<ul>',
          'endFolder' => '</ul></li>',
          'beginLink' => '<li><a href="%url%">%title%</a></li>',
          'endLink' => '');
    }
  }


  // returns the cache's age in seconds
  // returns -1 if the cache doesn't exist
  function getCacheAge() {
    $cacheAge = get_option(OPT_CACHEAGE);
    if ($cacheAge == '') {
      return -1;
    } else {
      return time() - $cacheAge;
    }
  }

  // updates the cache with $feedsData
  function updateCache($feedsData) {
    update_option(OPT_CACHE,$feedsData);
    update_option(OPT_CACHEAGE, time() );
  }

  // retrieves the data from the cache
  function getFeedsFromCache() {
    $age = number_format($this->getCacheAge() / 60,2);
    // echo "<!-- retrieved from cache, which will expire in $age minutes -->";
    return get_option(OPT_CACHE);
  }

  // download feeds data in OPML format from Bloglines
  function getFeedsFromBloglines() {

    // if we can open urls with fopen, do so, otherwise use the curl library
    if ( ini_get('allow_url_fopen') ) $loadMethod = "fopen";
        else $loadMethod = "curl";

    $url = "http://www.bloglines.com/export?id=" . $this->bloglinesUsername;
    if ($this->baseFolder !== '') $url .= "&folder=$this->baseFolder"; // if Bloglines gets a blank base folder, it returns nothing

    // echo "<!-- retrieved from bloglines: $url -->";

    switch ($loadMethod) {
      case "curl":
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        return curl_exec($ch);
        curl_close($ch);
        break;
      case "fopen":
        return file_get_contents($url);
        break;
    }

  }

  // returns the specified Bloglines feeds in OPML format
  function getFeeds() {
    $cacheAge = $this->getCacheAge();

    // if the cache doesn't exist, or if the cache has gone stale
    if ( ($cacheAge == -1) || ($cacheAge > $this->updateFrequency) ) {
      // get the feeds from Bloglines via HTTP
      $feedsData = $this->getFeedsFromBloglines();
      // update the cache with the new information
      $this->updateCache($feedsData);

      // return the newly-downloaded OPML data
      return $feedsData;
    } else {
      // get the data from the cache
      return $this->getFeedsFromCache();
    }
  }

  // formats an OPML <outline> element (in array form) using a formatting string
  function formatElement($opmlElement, $formatString) {
    $title = $opmlElement['attributes']['TITLE'];
    $url = $opmlElement['attributes']['HTMLURL'];
    $type = $opmlElement['attributes']['TYPE'];
    $feedurl = $opmlElement['attributes']['XMLURL'];

    $formattedString = $formatString;
    $formattedString = str_replace('%title%', $title, $formattedString);
    $formattedString = str_replace('%url%', $url, $formattedString);
    $formattedString = str_replace('%type%', $type, $formattedString);
    $formattedString = str_replace('%feedurl%', $feedurl, $formattedString);

    return $formattedString;
  }

  // processes an OPML set of Bloglines feeds, and formats them using the $formatStrings array
  function processFeedData($feedsOPML, $formatStrings) {

    $outputData = '';

    // parse opml data into something usable
    $parser = xml_parser_create(''); // pass empty encoding type because otherwise PHP5 doesn't autodetect
    xml_parse_into_struct($parser, $feedsOPML, $feedsData); // parse it into a nice, nested array
    xml_parser_free($parser); // free up the xml parser

    foreach($feedsData as $element) { // traverse the array from the top

        // if it's an <outline> element, but not its CDATA entry
        if ( ($element['tag'] == 'OUTLINE') && ($element['type'] != 'cdata') ) {
          if ($element['type'] == 'open') { // if it's an element's opening
            // check to see if it's the parent outline element, named Subscriptions
            if ( ($element['level'] == '3') && ($element['attributes']['TITLE'] == 'Subscriptions') ) {

              // begin the blogroll
              $outputData .= $this->formatElement($element, $formatStrings['beginFeeds']);

            } else {

             // begin the folder
              $outputData .= $this->formatElement($element, $formatStrings['beginFolder']);
            }

          } elseif ($element['type'] == 'close') { // if it's an element's closing

            // check to see if it's the parent outline element, named Subscriptions
            if ($element['level'] == '3') {
              // end the blogroll
              $outputData .= $this->formatElement($element, $formatStrings['endFeeds']);

            } else {
              // end the folder
              $outputData .= $this->formatElement($element, $formatStrings['endFolder']);
            }
          } elseif ($element['type'] == 'complete') {
            // link
            $outputData .= $this->formatElement($element, $formatStrings['beginLink']);
            $outputData .= $this->formatElement($element, $formatStrings['endLink']);
          }
        }
    }
    return $outputData;
  }

}

// $format is an associative array with format strings:
// beginFeeds, endFeeds, beginFolder, endFolder, beginLink, endLink
function showBloglinesReader($formatType = 'unorderedList', $format = '') {

  // load options from database
  $userName = get_option(OPT_USERNAME);
  $baseFolder = get_option(OPT_BASEFOLDER);
  $updateFrequency = get_option(OPT_UPDATEFREQUENCY);
  if ($updateFrequency == '') { $updateFrequency = 3600; }

  if ($userName == '') die('BloglineReader error: Username not specified! Go to the Bloglined options page and enter your username.');

  $bloglinesFeedReader = new chBloglinesFeedReader($userName, trim($baseFolder), $updateFrequency);
  if ($formatType == '') {
    $formatStrings = $format;
  } else {
    $formatStrings = $bloglinesFeedReader->getFormatForType($formatType);
  }
  echo $bloglinesFeedReader->processFeedData($bloglinesFeedReader->getFeeds(),$formatStrings);

}

function bloglinesReaderAddOptionsPage() {
  add_options_page('BloglinesReader', 'BloglinesReader', 8, __FILE__, 'bloglinesReaderDisplayOptionsPage');
}

function bloglinesReaderDisplayOptionsPage() {
  $userName = get_option(OPT_USERNAME);
  $baseFolder = get_option(OPT_BASEFOLDER);
  $updateFrequency = get_option(OPT_UPDATEFREQUENCY);
  if ($updateFrequency == '') { $updateFrequency = 3600; }

  $cacheAge = get_option(OPT_CACHEAGE);
  $cacheAgeMinutes = 0- (((time() - $cacheAge) - $updateFrequency) / 60);

  if ($cacheAgeMinutes > 0) {
    $cacheAgeDesc = 'The cache will expire in '. number_format($cacheAgeMinutes, 2) .' minutes.';
  } elseif ($cacheAgeMinutes < 0) {
    $cacheAgeDesc = 'The cache expired '. number_format($cacheAgeMinutes, 2) .' minutes ago.';
  } else {
    $cacheAgeDesc = "The cache has been cleared, or has yet to be created.";
  }

  if(isset($_POST['submit_options']))
  {

    $userName = $_POST['userName'];
    $baseFolder = $_POST['baseFolder'];
    $updateFrequency = $_POST['updateFrequency'];
    if ($updateFrequency == '') { $updateFrequency = 3600; }

    update_option(OPT_USERNAME, $userName);
    update_option(OPT_BASEFOLDER,$baseFolder);
    update_option(OPT_UPDATEFREQUENCY, $updateFrequency);

    echo '<div class="updated"><p><strong>Options saved.</strong></p></div>';
  }

  if(isset($_POST['submit_clearcache']))
  {
    update_option(OPT_CACHE,'');
    update_option(OPT_CACHEAGE,0);
    $cacheAgeDesc = "The cache has been cleared, or has yet to be created.";

    echo '<div class="updated"><p><strong>Cache cleared.</strong></p></div>';
  }

  echo '
  <div class="wrap">
  <h2>BloglinesReader Options</h2>
  <p>BloglinesReader will download your <a href="http://www.bloglines.com">Bloglines</a> feeds and display them on your blog.
  It caches the results, and will get new results after an amount of time you specify (default is 1 hour).</p>

  <fieldset class="options"><legend>Options</legend>
  <form method="post">

  <p><label for="userName">Username:</label> <input type="text" name="userName" value="'.$userName.'" /></p>
  <p><label for="baseFolder">Base Folder:</label> <input type="text" name="baseFolder" value="'.$baseFolder.'" /></p>
  <p><label for="userName">Update Frequency:</label> <input type="text" name="updateFrequency" value="'.$updateFrequency.'" /> (in seconds)</p>
  <p class="submit"><input type="submit" name="submit_options" value="Save Settings" /></p></form></fieldset>

  <fieldset class="options"><legend>Cache</legend>
  <form method="post">
  <p>'.$cacheAgeDesc.'</p>
  <p class="submit"><input type="submit" name="submit_clearcache" value="Clear Cache" /></p></form></fieldset>

  <fieldset class="options"><legend>Cache Contents</legend>
  <form method="post">
  <pre>'.htmlentities (get_option(OPT_CACHE)).'</pre>
  </form></fieldset>
  </div>
  ';
}

add_action('admin_menu', 'bloglinesReaderAddOptionsPage');

?>