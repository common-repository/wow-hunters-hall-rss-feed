<?php
/*
Version: 0.2
Plugin Name: WoW Hunter's Hall Feed Widget
Description: A widget to show the WHH feed on your site.
Author: David Dashifen Kees
Author URI: http://dashfien.com
*/

add_action("widgets_init", "whh_feed::load_widget");
if(!class_exists('WP_Http')) include_once(ABSPATH . WPINC. '/class-http.php');

class whh_feed extends WP_Widget {
	const version = 0.2;
	private $whh_feed;
	
	public static function load_widget() { register_widget("whh_feed"); }
	
	/* WIDGET FUNCTIONALITY
	 * this class isn't just the brains behind our forums, it's also a WP Widget!  the functions under this heading all 
	 * facilitate the display of recent forum activity in a sidebar widget panel for themes that support them.
	 */
	
	public function __construct() {
		$widget_ops  = array("classname" => "www-feed-widget", "description" => "A widget to show the WoW Hunter's Hall feed on your site.  The feed is reloaded once an hour.");
		parent::__construct("www-feed-widget", "WoW Hunter's Hall Feed", $widget_ops);
		$this->whh_feed = WP_PLUGIN_DIR . "/wow-hunters-hall-rss-feed/whh-feed.xml";
	}
	
	public function widget($args, $instance) {
		extract($instance);		// whh_feed_widget_title, whh_feed_widget_count, whh_feed_age_limit_in_minutes
		extract($args);			// before_widget, before_title, after_title, after_widget
		
		// at this point, we want to get our RSS feed from the WHH.  to do so we use the WP_Http object whose code
		// we included above this class definition.  notice that before we do anything, we see if the file is old 
		// enough to warrant re-fetching it for new data.

		$age_limit_in_seconds = $whh_feed_age_limit_in_minutes * 60;
		$feed_age = is_file($this->whh_feed) ? time()-filemtime($this->whh_feed) : $age_limit_in_seconds + 1;
		if($feed_age > $age_limit_in_seconds) $this->getWHHFeed();

		$count  = 0;
		$posts  = array();
		$format = "<li><a href=\"%s\">%s</a></li>";
		$widget = "$before_widget $before_title $whh_feed_widget_title $after_title";
		
		try {
			if(!is_file($this->whh_feed)) throw new Exception("No Feed");
			$xml    = new SimpleXMLElement(file_get_contents($this->whh_feed));
			
			// if we didn't throw an exception above -- the SimpleXMLElement constructor will do so if we couldn't
			// parse the XML that we got from the WHH -- then we're good to build our widget here.  notice that 
			// even though the XML object works best with a foreach loop, we use the $count variable to make sure
			// that we don't display more than the selected number of posts here.
			
			$widget .= "<ul class=\"whh-feed-posts\">";
			foreach($xml->channel->item as $item) {
				$widget .= sprintf($format, $item->link, $item->title);
				if(++$count == $whh_feed_widget_count) break;
			} $widget .= "</ul>";
		} catch(Exception $e) {
			// if we've caught an exception either the file didn't exist or we couldn't construct an XML element from its
			// contents.  either way, we can't really show the widget right now.
		
			$widget .= "<p>We were unable to load the WoW Hunter's Hall feed.  They must be out taming feral druids.</p>";
		}
		
		// whether it's an error message or an unordered list of posts, we want to add our $after_widget code and then
		// print it to the screen.
			
		$widget .= $after_widget;
		echo $widget;
	}

	public function update($new_instance, $old_instance) {
		$instance = $old_instance;
		$instance["whh_feed_widget_count"] = $new_instance["whh_feed_widget_count"];
		$instance["whh_feed_widget_title"] = strip_tags($new_instance["whh_feed_widget_title"]);
		$instance["whh_feed_age_limit_in_minutes"]   = $new_instance["whh_feed_age_limit_in_minutes"];
		return $instance;
	}

	public function form($instance) {
		$defaults = array(
			"whh_feed_widget_count" => 5,							// how many posts to show
			"whh_feed_widget_title" => "Hunter Community News",		// title to show above the posts
			"whh_feed_age_limit_in_minutes" => 60					// how long to wait before reloading the RSS
		);
		
		$instance = wp_parse_args((array) $instance, $defaults); ?>	
		
		<p>
			<label for="<?php echo $this->get_field_id("whh_feed_widget_title"); ?>"><strong>Title:</strong></label>
			<input id="<?php echo $this->get_field_id("whh_feed_widget_title"); ?>" name="<?php echo $this->get_field_name("whh_feed_widget_title"); ?>" value="<?php echo $instance["whh_feed_widget_title"]; ?>" size="25">
		</p>
		<p>
			<label for="<?php echo $this->get_field_id("whh_feed_widget_count"); ?>"><strong>Number of Posts to Show:</strong></label>
			<select id="<?php echo $this->get_field_id("whh_feed_widget_count"); ?>" name="<?php echo $this->get_field_name("whh_feed_widget_count"); ?>">
				<?php for($i=1; $i<=10; $i++) { ?>
					<option value="<?php echo $i?>" <?php echo $i==$instance["whh_feed_widget_count"] ? "selected" : ""?>><?php echo $i?></option>
				<?php } ?>
			</select>
		</p>
	<?php }
	
	protected function getWHHFeed() {
		// this method is the "meat" of the plugin.  it uses the WP_Http object to grab the RSS feed from the WoW Hunter's
		// Hall and copy it down to this site.  we then write that file into the same folde as this plugin so that we don't
		// constantly grab the feed all the time whenever someone navigates aronud on this site.
		
		$xml = "";
		$tries = 0;
		$isXML = false;
		$request = new WP_Http();
		
		while(!$isXML) {
			try {
				// we're going to try and get our XML 5 times.  since the SimpleXMLElement will toss an exception if we
				// couldn't get XML from the WHH, then we'll end up in our catch block where we increment the $tries
				// variable and sleep for a second.  but, if no exception is thrown, then we set the $isXML flag and teh
				// while loop ends.			
			
				$results = $request->request("http://feeds.feedburner.com/WowHuntersHall");
				$xml = $results["body"];
				new SimpleXMLElement($xml);
				$isXML = true;			
			} catch (Exception $e) {
				// here in the catch block we want to increment tries.  once we've tried 5 times, we make our $xml variable
				// to null and then set the $isXML flag to get out of the while loop.  this will take us to the code below.
				// but, if we haven't tried 5 times, then we sleep for a second.  this should hopefully give the WHH time
				// to respond when we try again next time.
	
				if(++$tries < 5) sleep(1);
				else {
					$xml = NULL;
					$isXML = true;
				}
			}
		}
		
		// now, either $xml is NULL (if we tried and failed 5 times to get the WHH feed) or it's not.  if it is, then we 
		// don't do anything so that we don't overwrite previously fetch XML.  but if it's not NULL then we must have 
		// successfully pulled the RSS from the WHH so we're A-OK.
		
		if(!is_null($xml)) file_put_contents($this->whh_feed, $xml);		
	}
} ?>