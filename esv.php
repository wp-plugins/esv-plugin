<?php
/*
Plugin Name: ESV Plugin
Plugin URI: http://www.musterion.net/wordpress-esv-plugin/
Description: Allows the user to utilize services from the ESV Web Service
Version: 3.4.0
Author: Chris Roberts
Author URI: http://www.musterion.net/
*/

/*  Copyright 2008 Chris Roberts (email : columcille@gmail.com)

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

$ESV_Version = "3.4.0";
$ESV_Loaded = 0;

// Add to the Admin function list
if (! function_exists('esv_addoptions')) {
	function esv_addoptions() {

		if (function_exists('add_options_page')) {
			require_once(ABSPATH ."wp-content/plugins/esv-plugin/esv_adminOptions.php");
			add_options_page('ESV Plugin Options', 'ESV', 9, basename(__FILE__), 'esv_options_subpanel');
		}
	}
}

if (! function_exists('esv_edit_post')) {
        function esv_edit_post($Post_Id)
        {
                global $wpdb;

                $query = "SELECT post_content FROM wp_posts WHERE ID = ". $Post_Id .";";
                $rslt = $wpdb->get_results($query);

                foreach ($rslt as $result)
                {
                        $content = $result->post_content;

                        $content = esv_verse($content);

                        $query = "UPDATE wp_posts SET post_content = '". addslashes($content) ."' WHERE ID = ". $Post_Id .";";
                        $wpdb->query($query);
                }
        }
}

// esv_runtime_modify is called when the user reads a post. It modifies the
// link formed when the post is saved and converts it to the style specified
// in the link.
if (! function_exists('esv_runtime_modify')) {
	function esv_runtime_modify($content)
	{
		// Are we parsing everything at runtime?
		if (get_option("esv_process_ref") == "runtime")
		{
			$content = esv_verse($content);
		}

        // Find any of our formatted links. It is looking for a url with the
        // format:
        // <a href="urltoESV" esv_reference="scripture_ref" esv_header="on|off" esv_format="tooltip|inline|block|link">scripture_ref</a>
        preg_match_all(
        '/'
        .'\<a\shref=["\'](?:[^"\']+?)["\']\s'
        .'(?:class="bibleref"\stitle=".+?"\s)?'
        .'esv_reference=["\'](.+?)["\']\s'
        .'esv_header=["\']((?:on|off){1})["\']\s'
        .'esv_format=["\']((?:tooltip|inline|block|link){1})["\']'
        .'\>'
        .'(.+?)'
        .'\<\/a\>'
        .'/', $content, $Verses);

        for ($i = 0 ; $i < sizeof($Verses[1]) ; $i++)
        {
            $reference = $Verses[1][$i];
            $header = $Verses[2][$i];
            $format = $Verses[3][$i];
            $linktext = $Verses[4][$i];
            $VerseText = "";

            $VerseText = esv_formatReference($reference, $header, $format, $linktext);

            $content = str_replace($Verses[0][$i], $VerseText, $content);
        }

		// Check for specific tags
		preg_match_all('/\[esvignore\](.+?)\[\/esvignore\]/', $content, $matches);
		for ($i = 0 ; $i < sizeof($matches[1]) ; $i++)
		{
			$reference = trim($matches[1][$i]);
			$linkmatch = $reference;
			$content = str_replace($matches[0][$i], $linkmatch, $content);
		}

		// Are we running in backward-compat mode?
		if (get_option("esv_backward_compat") == "true")
		{
			// Look for the old tags
			preg_match_all('/\[bible\](.+?)\[\/bible\]/', $content, $matches);
			for ($i = 0 ; $i < sizeof($matches[1]) ; $i++)
			{
				$reference = trim($matches[1][$i]);
				$VerseText = esv_getVerse($reference, "tooltip", "on");
				$content = str_replace($matches[0][$i], $VerseText, $content);
			}

			preg_match_all('/\[bibleblock\](.+?)\[\/bibleblock\]/', $content, $matches);
			for ($i = 0 ; $i < sizeof($matches[1]) ; $i++)
			{
				$reference = trim($matches[1][$i]);
				$VerseText = esv_getVerse($reference, "block", "on");
				$content = str_replace($matches[0][$i], $VerseText, $content);
			}

			preg_match_all('/\[biblelink\](.+?)\[\/biblelink\]/', $content, $matches);
			for ($i = 0 ; $i < sizeof($matches[1]) ; $i++)
			{
				$reference = trim($matches[1][$i]);
				$VerseText = '<a class="bibleref" title="'. $reference .'" href="http://www.gnpcb.org/esv/search/?q='. urlencode($reference) .'">'. $reference .'</a>';
				$content = str_replace($matches[0][$i], $VerseText, $content);
			}

			preg_match_all('/\[bibleignore\](.+?)\[\/bibleignore\]/', $content, $matches);
			for ($i = 0 ; $i < sizeof($matches[1]) ; $i++)
			{
				$reference = trim($matches[1][$i]);
				$linkmatch = $reference;
				$content = str_replace($matches[0][$i], $linkmatch, $content);
			}
		}

		return $content;
	}
}

if (! function_exists('esv_verse')) {
	function esv_verse($content) {
		$esvref = get_option('esv_ref_action');

		if (get_option('esv_webkey') == "") {
			return $content;
		}

		// First, check for references that don't have tags
		// Make sure we're not using a ref already included in tags
		$anchor_regex = '<a\s+href.*?<\/a>';
		$pre_regex = '<pre>.*<\/pre>';
		$code_regex = '<code>.*<\/code>';
		$bible_regex = '\[bible\].*\[\/bible\]';
		$bibleblock_regex = '\[bibleblock\].*\[\/bibleblock\]';
		$biblelink_regex = '\[biblelink\].*\[\/biblelink\]';
		$bibleignore_regex = '\[bibleignore\].*\[\/bibleignore\]';
		$esvbible_regex = '\[esvbible\s?.*\].*\[\/esvbible\]';
		$esvignore_regex = '\[esvignore\].*\[\/esvignore\]';
		$tag_regex = '<(?:[^<>\s]*)(?:\s[^<>]*){0,1}>';
		$split_regex = "/((?:$anchor_regex)|(?:$pre_regex)|(?:$bible_regex)|(?:$bibleblock_regex)|(?:$esvignore_regex)|(?:$biblelink_regex)|(?:$bibleignore_regex)|(?:$esvbible_regex)|(?:$code_regex)|(?:$tag_regex))/i";

		$parsed_text = preg_split($split_regex, $content, -1, PREG_SPLIT_DELIM_CAPTURE);
		$linked_text = '';

		while (list($key, $value) = each($parsed_text)) {
			if (preg_match($split_regex, $value)) {
				$linked_text .= $value; // if it is within an element, leave it as is
			} else {
				// Okay, we have text not inside of tags. Now do something with it... And please, try not to break anything.
				$linked_text .= esv_extract_ref($value); // parse it for Bible references
			}
		}

		$content = $linked_text;

		// Check for the [esvbible] tag
		preg_match_all('/\[esvbible([^\]]+)?\]([^\[]+)\[\/esvbible\]/', $content, $matches);
		for ($i = 0 ; $i < sizeof($matches[0]) ; $i++)
		{
			$options = $matches[1][$i];
			$linktext = $matches[2][$i];

			$reference = "";
			$header = "";
			$format = "";

			preg_match('/reference=["\']([^"]+)["\']/', $options, $references);
			if (is_array($references))
			{
				$reference = $references[1];
			}

			preg_match('/header=["\']((?:on|off|yes|no){1})["\']/', $options, $headers);
			if (is_array($headers))
			{
				$header = $headers[1];
			}

			preg_match('/format=["\']((?:tooltip|inline|block|link|ignore){1})["\']/', $options, $formats);
			if (is_array($formats))
			{
				$format = $formats[1];
			}

			if ($reference == "")
			{
				$reference = $linktext;
			}

			if ($header == "")
			{
				$header = "on";
			}

			if ($format == "")
			{
				$format = get_option("esv_ref_action");
			}

			if (get_option("esv_process_ref") == "save")
			{
				$linkmatch = esv_buildLink($reference, $header, $format, $linktext);
			} else {
				$linkmatch = esv_formatReference($reference, $header, $format, $linktext);
			}

			$content = str_replace($matches[0][$i], $linkmatch, $content);
		}

		return $content;
	}
}

if (! function_exists('esv_extract_ref')) {
	function esv_extract_ref($text) {
		$volume_regex = '1|2|3|I|II|III|1st|2nd|3rd|First|Second|Third';

		$book_regex  = 'Genesis|Exodus|Leviticus|Numbers|Deuteronomy|Joshua|Judges|Ruth|Samuel|Kings|Chronicles|Ezra|Nehemiah|Esther';
		$book_regex .= '|Job|Psalms?|Proverbs?|Ecclesiastes|Songs? of Solomon|Song of Songs|Isaiah|Jeremiah|Lamentations|Ezekiel|Daniel|Hosea|Joel|Amos|Obadiah|Jonah|Micah|Nahum|Habakkuk|Zephaniah|Haggai|Zechariah|Malachi';
		$book_regex .= '|Mat+hew|Mark|Luke|John|Acts?|Acts of the Apostles|Romans|Corinthians|Galatians|Ephesians|Phil+ippians|Colossians|Thessalonians|Timothy|Titus|Philemon|Hebrews|James|Peter|Jude|Revelations?';

		$abbrev_regex  = 'Gen|Ex|Exo|Lev|Num|Nmb|Deut?|Josh?|Judg?|Jdg|Rut|Sam|Ki?n|Chr(?:on?)?|Ezr|Neh|Est';
		$abbrev_regex .= '|Jb|Psa?|Pr(?:ov?)?|Eccl?|Song?|Isa|Jer|Lam|Eze|Dan|Hos|Joe|Amo|Oba|Jon|Mic|Nah|Hab|Zeph?|Hag|Zech?|Mal';
		$abbrev_regex .= '|Mat|Mr?k|Lu?k|Jh?n|Jo|Act|Rom|Cor|Gal|Eph|Col|Phi(?:l?)?|The?|Thess?|Tim|Tit|Phile|Heb|Ja?m|Pe?t|Ju?d|Rev';

		$book_regex = '(?:'.$book_regex.')|(?:'.$abbrev_regex.')\.?';

		$verse_substr_regex = "(?:[:.][0-9]{1,3})?(?:[-&,;]\s?[0-9]{1,3})*";
		$verse_regex = "[0-9]{1,3}(?:". $verse_substr_regex ."){1,2}";

		$passage_regex = '/(?:('.$volume_regex.')\s)?('.$book_regex.')\s('.$verse_regex.')/e';
		$replacement_regex = "esv_assemble_ref('\\0','\\1','\\2','\\3')";

		$text = preg_replace($passage_regex, $replacement_regex, $text);

		return $text;
	}
}

if (! function_exists('esv_assemble_ref')) {
	function esv_assemble_ref($reference = '', $volume = '', $book = '', $verse = '') {
		$esvref = get_option('esv_ref_action');

		if ($volume) {
			$volume = str_replace('III','3',$volume);
			$volume = str_replace('Third','3',$volume);
			$volume = str_replace('II','2',$volume);
			$volume = str_replace('Second','2',$volume);
			$volume = str_replace('I','1',$volume);
			$volume = str_replace('First','1',$volume);
			$volume = $volume{0}; // will remove st,nd,and rd (presupposes regex is correct)
		}

		$reference = $volume ." ". $book ." ". $verse;
		$reference = trim($reference);

		if (get_option("esv_process_ref") == "save")
		{
			return esv_buildLink($reference);
		} else {
			return esv_formatReference($reference);
		}
	}
}

if (! function_exists('esv_buildLink')) {
	// Builds the link used when modifying the post in the database
	function esv_buildLink($reference, $header="on", $format="", $linktext="")
	{
		if ($format == "")
		{
			$format = get_option('esv_ref_action');
		}

		if ($linktext == "")
		{
			$linktext = $reference;
		}

		$newref = "";
		$versehref = "http://www.gnpcb.org/esv/search/?q=". urlencode($reference);
		$linkhead = '<a href="'. $versehref .'" class="bibleref" title="'. $reference .'"';
		$linkfoot = $linktext ."</a>";

		switch ($format)
		{
			case "tooltip":
				$linkhead .= ' esv_reference="'. $reference .'" esv_header="'. $header .'" esv_format="tooltip">';
				break;
			case "inline":
				$linkhead .= ' esv_reference="'. $reference .'" esv_header="'. $header .'" esv_format="inline">';
				break;
			case "block":
				$linkhead .= ' esv_reference="'. $reference .'" esv_header="'. $header .'" esv_format="block">';
				break;
			case "link":
				$linkhead .= ' esv_reference="'. $reference .'" esv_header="'. $header .'" esv_format="link">';
				break;
			case "ignore":
				$linkhead = $reference;
				$linkfoot = '';
				break;
			default:
				$linkhead .= '>';
				break;
		}

		return $linkhead . $linkfoot;
	}
}

// Build the parsed reference/tag to send to the visitor
if (! function_exists('esv_formatReference')) {
	function esv_formatReference($reference, $header="on", $format="", $linktext="")
	{
		if ($format == "")
		{
			$format = get_option('esv_ref_action');
		}

		if ($linktext == "")
		{
			$linktext = $reference;
		}

		switch ($format)
		{
			case "tooltip":
				$VerseText = esv_getVerse($reference, "tooltip", $header, $linktext);
				break;
			case "inline":
				$VerseText = esv_getVerse($reference, "inline", $header);
				break;
			case "block":
				$VerseText = esv_getVerse($reference, "block", $header);
				break;
			case "link":
				$VerseText = '<a class="bibleref" title="'. $reference .'" href="http://www.gnpcb.org/esv/search/?q='. urlencode($reference) .'">'. $linktext .'</a>';
				break;
			case "ignore":
				$VerseText = $reference;
				break;
		}

		return $VerseText;
	}
}

if (! function_exists('esv_getVerse')) {
	function esv_getVerse($reference, $format, $header, $linktext="") {
		global $wpdb, $table_prefix, $doing_rss;
		$table_name = $table_prefix . "esv";

		// use $readerr for error checking below
		$readerr = 0;

		$ESVKey = get_option('esv_webkey');
		$url_reference = urlencode($reference);

		// See if we have this cached already
		$query = "SELECT Reference, Verse FROM ". $table_name ." WHERE Reference = '". $reference ."';";
		$result = $wpdb->get_row($query, ARRAY_A);

		if ($result['Reference'] == $reference) {
			$VerseText = stripslashes($result['Verse']);
		} else {
			// Build the options string based on stored options
			$options = "include-passage-references=". get_option('esv_include_reference') ."&include-first-verse-numbers=". get_option('esv_first_verse_num') ."&include-verse-numbers=". get_option('esv_verse_num') ."&include-footnotes=". get_option('esv_footnote') ."&include-footnote-links=". get_option('esv_footnote_link') ."&include-headings=". get_option('esv_incl_headings') ."&include-subheadings=". get_option('esv_incl_subheadings') ."&include-surrounding-chapters=". get_option('esv_surround_chap') ."&include-audio-link=". get_option('esv_inc_audio') ."&audio-format=". get_option('esv_audio_fmt') ."&audio-version=". get_option('esv_audio_src') ."&include-short-copyright=". get_option('esv_incl_short_copyright') ."&include-copyright=". get_option('esv_incl_copyright') ."&include-word-ids=". get_option('esv_incl_word_ids');
			$VerseText = "";

			$url = "http://www.esvapi.org/v2/rest/passageQuery?key=". $ESVKey ."&passage=". $url_reference ."&". $options;

			if (function_exists("curl_init")) {
				$ch = curl_init($url);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
				$VerseText = curl_exec($ch);
				curl_close($ch);
			} else if (ini_get('allow_url_fopen') == true) {
				if ($rvs = fopen($url, 'r')) {
					$VerseText = "";

					while (!feof($rvs)) {
						$VerseText .= fgets($rvs);
					}

					fclose($rvs);
				}
			} else {
				$readerr = 1;
				$VerseText = "Error retrieving passage text - curl and remote fopen both seem to be disabled on your host.";
			}

			if ($readerr == 0)
			{
				if (strpos($VerseText, "ERROR: You have exceeded your quota") === false) {
					$query = "INSERT INTO ". $table_name ." (Reference, Verse, Added) VALUES ('". $reference ."', '". addslashes($VerseText) ."', NOW());";
					$wpdb->query($query);
				} else {
					$VerseText = "Error retrieving Bible passage. Please check again tomorrow.";
				}
			}
		}

		if ($linktext == "")
		{
			$linktext = $reference;
		}

		// Format the verse
		if ($format == "tooltip" && $doing_rss != 1) {
			$VerseText = str_replace("\n", "", $VerseText);
			$VerseText = str_replace("\r", "", $VerseText);
			$VerseText = strip_tags($VerseText, "<div><br><span><p>");
			$VerseText = str_replace("(Listen)", "", $VerseText);
			$VerseText = str_replace("'", "&#8217;", $VerseText);

			preg_match('/<div class="esv">(.*?)<div class="esv-text">/i', $VerseText, $matches);
			$VerseRef = $matches[0];
			$VerseRef = strip_tags($VerseRef, "");
			$VerseText = preg_replace('/<div class="esv">(.*?)<div class="esv-text">/i', '', $VerseText);

			if ($header == "on")
			{
				// $headertext = '<a href="http://www.gnpcb.org/esv/search/?q='. $url_reference .'">'. trim($reference) .'</a>';
				// $headertext = '<a href=\'http://www.cnn.com/\'>'. trim($reference) .'</a>';
				
				$headertext = trim($reference);
			} else {
				$headertext = "";
			}
			
			$randomIdentifier = time() + rand();
			
			// The next few lines determine how the tooltip is to be activated
			if (get_option('tippy_openTip') == "hover")
			{
				$activateTippy = "onmouseover";
				
				$addHref = 'href="http://www.gnpcb.org/esv/search/?q='. urlencode($reference) .'" ';
				
				$linkTitle = '';
			} else {
				$activateTippy = "onmouseup";
				$addHref = "";
				$linkTitle = 'title="Click for verse text"';
			}

			$ReturnText = '<cite class="bibleref" title="'. $reference .'" style="display: none;"></cite>'.
			'<a '. $linkTitle .' class="tippy_link" '. $activateTippy .'="domTip_toolText(\'bref'. $randomIdentifier .'\', \''. htmlentities($VerseText) .'\',  \''. $headertext .'\', \'http://www.gnpcb.org/esv/search/?q='. $url_reference .'\');" onmouseout="domTip_clearTip(\'false\')" '. $addHref .'>'.
			$linktext .
			'</a>';
		} else if ($format == "tooltip" && $doing_rss == 1) {
			$ReturnText = '<cite class="bibleref" title="'. $reference .'">'. $linktext .'</cite>';
		} else if ($format == "inline" || $format == "block") {
			preg_match_all('/\<h2\>([^<]+)\s?(?:\<small(?:[^>]+)?\>\((.*)\)\<\/small\>)?/', $VerseText, $matchItems);
			$VerseText = preg_replace('/\<h2\>([^<]+)\s?(?:\<small(?:[^>]+)?\>\((.*)\)\<\/small\>)?/', "<span class='esv_inline_header'></span>", $VerseText);

			$verseRef = $matchItems[1][0];
			$listenLink = $matchItems[2][0];

			if ($header == "on")
			{
				if (get_option('esv_include_reference') == "true")
				{
					$VerseText = preg_replace('/\<span class=\'esv_inline_header\'\>\<\/span\>/', "<span style='font-size: larger; font-weight: bold;'><a class=\"bibleref\" title=\"". $reference ."\" href=\"http://www.gnpcb.org/esv/search/?q=". $reference ."\">". $verseRef ."</a></span><span class='esv_inline_header'></span>", $VerseText);
				}

				if (get_option('esv_inc_audio') == "true" && $listenLink != "")
				{
					$VerseText = preg_replace('/\<span class=\'esv_inline_header\'\>\<\/span\>/', "<span style='font-size: smaller;'>(". $listenLink .")</span>", $VerseText);
				}
			} else if ($format != "ignore") {
				$VerseText = "<cite class=\"bibleref\" title=\"". $reference ."\" style=\"display: none;\">". $reference ."</cite>". $VerseText;
			} else {
                return $reference;
            }

			if ($format == "block")
			{
				$ReturnText = "<blockquote class=\"esvblock\">". $VerseText ."</blockquote>";
			} else {
				$ReturnText = "<div class='esvblock'>". $VerseText ."</div><br />";
			}

			$ReturnText = $ReturnText;
		}

		return $ReturnText;
	}
}

if (! function_exists('esv_display'))
{
	$esv_setdisplay = 0;

	function esv_display($content = '')
	{
		global $esv_setdisplay, $wpdb;

		$wpurl = get_bloginfo('wpurl');

		if ($esv_setdisplay == 0) {
			$esv_setdisplay = 1;

			$content .= '<link rel="stylesheet" type="text/css" href="'. $wpurl .'/wp-content/plugins/esv-plugin/esv.css" media="screen" />';
		}

		echo $content;
	}
}
	
if (! function_exists('esv_activate'))
{
	// Check settings and see if we need to initialize the plugin or update any
	// new options.
	function esv_activate()
	{
		global $wpdb, $ESV_Version;

		// Set all the default options, starting with creating the table to
		// store ESV passages.
		$table_name = $wpdb->prefix . "esv";
		
		if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name)
		{
			$sql = "CREATE TABLE ". $table_name ." (
			Reference tinytext,
			Verse     blob,
			Added     datetime
            );";
			
			require_once(ABSPATH . 'wp-admin/upgrade-functions.php');
			dbDelta($sql);
			
			update_option('esv_webkey', 'IP');
			update_option('esv_version', $ESV_Version);
			
			update_option('esv_include_reference', 'true');
			update_option('esv_first_verse_num', 'true');
			update_option('esv_verse_num', 'true');
			update_option('esv_footnote', 'false');
			update_option('esv_footnote_link', 'false');
			update_option('esv_incl_headings', 'false');
			update_option('esv_incl_subheadings', 'false');
			update_option('esv_surround_chap', 'false');
			update_option('esv_inc_audio', 'true');
			update_option('esv_audio_fmt', 'flash');
			update_option('esv_incl_short_copyright', 'true');
			update_option('esv_incl_copyright', 'false');
			update_option('esv_ref_action', 'link');
			update_option('esv_show_header', 'true');
			update_option('esv_process_ref', 'runtime');
			update_option('esv_backward_compat', 'false');
			update_option('esv_audio_src', 'mm');
			update_option('esv_incl_word_ids', 'false');			
		}
		
		// Update check
		
		// Versions are stored as strings. Reformat them so we can compare them.
		// Starting with version 2.0.5, internal version numbers will always have
		// at least three numbers, even if that means version numbers like
		// 2.1.0 or 3.0.0
		$oldvers = str_replace(".", "", get_option('esv_version'));
		$curvers = str_replace(".", "", $ESV_Version);
		
		// See if a 1.x version is installed
		if (get_option('esv_audio_fmt') != "" && $oldvers == "")
		{
			$oldvers = 100;
		}
		
		if ($oldvers < $curvers)
		{
			if ($oldvers < 330) {
                update_option('esv_audio_src', 'mm');
                update_option('esv_incl_word_ids', 'false');
			} else if ($oldvers < 310) {
				update_option('esv_process_ref', 'runtime');
				update_option('esv_backward_compat', 'true');
			} else if ($oldvers < 210) {
				update_option('esv_show_header', 'true');
			}
			
			update_option('esv_version', $ESV_Version);
		}
	}
	
	register_activation_hook(__FILE__, 'esv_activate');
}

add_action('admin_menu', 'esv_addoptions');
add_action('wp_head', 'esv_display', 40);

if (get_option('esv_process_ref') == 'save')
{
	add_action('save_post', 'esv_edit_post', 4);
}

add_filter('the_content', 'esv_runtime_modify', 4);
?>