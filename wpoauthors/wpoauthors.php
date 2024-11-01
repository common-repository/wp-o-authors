<?php

/*
Plugin Name: WP-o-Matic Authors
Version: 1.0
Plugin URI: Plugin URL goes here (e.g. http://yoursite.com/wordpress-plugins/plugin/)
Description: An add-on to WP-o-Matic that creates a new author for each campaign.
Author: Telecom Bretagne
Author URI: http://www.coyotte508.com
*/

class WPOAuthors {
    var $version;
	var $autoprocess;

	/* Used when WP-o-Matic imports posts and
		we process them */
	var $wpopostid;
	var $wpoauthor;
	var $wpopermalink;

    /* Constructor */
    function WPOAuthors() {
        $this->version =  '1.0.0';
        $this->installed = get_option('wpoa_version');
		$this->autoprocess = get_option('wpoa_processwpoposts');


        /*
         * We specify all the hooks and filters for the plugin
        */
        register_activation_hook(__FILE__, array(&$this,'activate'));
        register_deactivation_hook(__FILE__, array(&$this,'deactivate'));

        /*
         * Admin menu.
        */
        add_action('admin_menu', array(&$this, 'adminMenu'));


		/*
		 * To filter WP-o-Matic queries, so that we can add the original
		 * author when a post is imported by WP-o-Matic
		*/
		add_action('query', array(&$this, 'filterWpoQuery'));
    }

    /**
     * Adds the WP Authors item to menu
     *
     */
    function adminMenu() {
        add_options_page("WPO Authors", "WP-o-Matic Authors" , 8, __FILE__, array(&$this, 'admin'));
    }

    /*
     * The admin menu that's displayed.
     * The display of the menu occurs in display.php
     */
    function admin() {
        /* We process the menu and then display it */
        if (isset ($_REQUEST['wpoa_wpomatic'])) {
			/* The wpomatic form was used */
			if (!isset($_REQUEST['process_wpomatic_posts']))
				$results = array();
			else
				$results = $_REQUEST['process_wpomatic_posts'];
            if (isset($_REQUEST['author_template']) && $_REQUEST['author_template']) {
                update_option('wpoa_authornametemplate', $_REQUEST['author_template']);
            }

			update_option('wpoa_processwpoposts', in_array('new', $results));

			if (in_array('all', $results)) {
				$this->processAllPostsForWpo();
			}
		}
        if (!get_option('wpoa_authornametemplate')) {
            add_option('wpoa_authornametemplate', "{original_author} on {source_host}");
        }
        include('display.php');
    }

    /* Add a user to the database */
    function addUser($user_login,$user_email,$user_pass) {
        require('../wp-blog-header.php');
        require_once( ABSPATH . WPINC . '/registration.php');

        if(username_exists($user_login) || !validate_username($user_login) || !is_email($user_email) || email_exists($user_email))
            return null;

        $user_id = wp_create_user( $user_login, $user_pass, $user_email );

        return $user_id;
    }


	/**
	 * A function that processes all existing posts and, if the post was imported by WP-o-Matic,
	 * creates an author from the combination of the original author and the source website
	 * and then set him as the author of the post. Of course, if the author already exists then
	 * its not created.
	 *
	 * @brief creates authors for all posts imported by wpomatic to allow comprehensive listing
	*/
	function processAllPostsForWpo()
	{
		@set_time_limit(0);
		/* Retrieves all posts */
		$args = array(
            'post_type' => 'post',
            'numberposts' => -1,
		);

		$post_list = get_posts($args);

		/* Process all posts */
		foreach($post_list as $post)
		{
			$this->changeAuthorOfWpoPost($post->ID);
		}
	}

	/**
	 * A function that filters query. If the query is one such as inserting a "wpo_originalauthor",
	 * then the function assumes it is WP-o-Matic importing a post from another blog and, if the
	 * right option is set, tries to create an author corresponding to the original author.
	 *
	 * Note that this function especially relies on the internal behavior of WP-o-Matic, and may
	 * not work on future WP-o-Matic versions.
	 *
	 * @brief On wpomatic importing, creates an author matching the post' original author and change the author of the post
	 * @param query the query to filer
	 * @return @c query inchanged
	*/
	function filterWpoQuery($query)
	{
		if (!$this->autoprocess)
			return $query;

		global $wpdb;

		/* Tries to match the query to the one wpomatic uses for inserting the original author / permalink.
			This is the part to change if the plugin doesn't work with future versions of wpomatic.
			When all have been stored for a specific post, variables are reset and the post is processed*/
		if(preg_match("/^INSERT INTO $wpdb->postmeta[ ]*\(post_id,meta_key,meta_value[ ]*\)[ ]*"
					  . "VALUES[ ]*\('?([0-9]+)'?,'wpo_originalauthor','(.*)'\)[ ]*\$/", $query, $matches)) {
			if ($this->wpopostid != $matches[1]) {
				$this->resetWpoFields();
				$this->wpopostid = $matches[1];
			}
			$this->wpoauthor = $matches[2];
		} else if(preg_match("/^INSERT INTO $wpdb->postmeta[ ]*\(post_id,meta_key,meta_value[ ]*\)[ ]*"
					  . "VALUES[ ]*\('?([0-9]+)'?,'wpo_sourcepermalink','(.*)'\)[ ]*\$/", $query, $matches)) {
			if ($this->wpopostid != $matches[1]) {
				$this->resetWpoFields();
				$this->wpopostid = $matches[1];
			}
			$this->wpopermalink = $matches[2];
		} else {
			return $query;
		}

		if ($this->wpopostid && $this->wpopermalink && $this->wpoauthor)
		{
			$wpopostid = $this->wpopostid;
			$wpoauthor = $this->wpoauthor;
			$wpopermalink = $this->wpopermalink;
			$this->resetWpoFields();
			$this->changeAuthorOfWpoPost($wpopostid,$wpoauthor,$wpopermalink);
		}

		return $query;
	}

    /**
     * When a post was processed or we get to a new post, all remnant data
     * about the post we were processing is cleared.
     */
	function resetWpoFields()
	{
		$this->wpopostid = 0;
		$this->wpoauthor = '';
		$this->wpopermalink = '';
	}


	/**
	 * A function that automatically replaces the author of the post given in parameter
	 * by the 'imported' wpomatic author, even if it needs to create the author. The source
	 * host is also inserted in the author name, to make distinctions between authors with same
	 * names but from different websites
	 *
	 * Relies on the wpo_orignalauthor meta, from wpomatic.
	 * @brief changes the author of a wpomatic post by one that matches the original author
	 * @param id the id of the post which author is to change
	 * @return true if post converted
	*/
	function changeAuthorOfWpoPost($id, $author='', $perma='')
	{
		/* The main class of the $wpomatic plugin */
		$orAuthor = $author ? $author : get_post_meta($id, "wpo_originalauthor",true);
		$permalink = $perma ? $perma : get_post_meta($id, "wpo_sourcepermalink",true);

		if (!$orAuthor || !$permalink )
			return false;

		$completeName = $this->completeAuthorName($orAuthor, $permalink);

		/* Tests if the user already exists.
		   If not, we create it. */
		if ( !($authid = username_exists($completeName))) {
			$authid = $this->addUser($completeName, hash('md5', $completeName).$this->random_string(5).'@wpoauthors.com', $this->random_string(10));

			if (!$authid)
				return false; //even with all our efforts we couldn't create a valid user
		}

		/* Finally the author of the post is changed */
		wp_update_post(array('ID' => $id, 'post_author' => $authid));

		return true;
	}

	function random_string($max = 20){
        $chars = "abcdefghijklmnopqrstuvwxwz0123456789_";
        for($i = 0; $i < $max; $i++){
            $rand_key = mt_rand(0, strlen($chars)-1);
            $string  .= $rand_key[$i];
        }
        return str_shuffle($string);
    }


    function normalize ($string) {
        $table = array(
            'Š'=>'S', 'š'=>'s', 'Ð'=>'Dj', 'Ž'=>'Z', 'ž'=>'z', 'C'=>'C', 'c'=>'c', 'C'=>'C', 'c'=>'c',
            'À'=>'A', 'Á'=>'A', 'Â'=>'A', 'Ã'=>'A', 'Ä'=>'A', 'Å'=>'A', 'Æ'=>'A', 'Ç'=>'C', 'È'=>'E', 'É'=>'E',
            'Ê'=>'E', 'Ë'=>'E', 'Ì'=>'I', 'Í'=>'I', 'Î'=>'I', 'Ï'=>'I', 'Ñ'=>'N', 'Ò'=>'O', 'Ó'=>'O', 'Ô'=>'O',
            'Õ'=>'O', 'Ö'=>'O', 'Ø'=>'O', 'Ù'=>'U', 'Ú'=>'U', 'Û'=>'U', 'Ü'=>'U', 'Ý'=>'Y', 'Þ'=>'B', 'ß'=>'Ss',
            'à'=>'a', 'á'=>'a', 'â'=>'a', 'ã'=>'a', 'ä'=>'a', 'å'=>'a', 'æ'=>'a', 'ç'=>'c', 'è'=>'e', 'é'=>'e',
            'ê'=>'e', 'ë'=>'e', 'ì'=>'i', 'í'=>'i', 'î'=>'i', 'ï'=>'i', 'ð'=>'o', 'ñ'=>'n', 'ò'=>'o', 'ó'=>'o',
            'ô'=>'o', 'õ'=>'o', 'ö'=>'o', 'ø'=>'o', 'ù'=>'u', 'ú'=>'u', 'û'=>'u', 'ý'=>'y', 'ý'=>'y', 'þ'=>'b',
            'ÿ'=>'y', 'R'=>'R', 'r'=>'r',
        );

        return strtr($string, $table);
    }


	/**
	 * A function to get the complete name of an imported author by wpomatic, given
	 * their name and the website the post is imported from.
	 *
	 * @brief gets the complete name of an author given the author and the website
	 * @param url the url of the website the post is imported from
	 * @param author the name of the original author
	 * @return The complete name: @c author on [domain name]
	*/
	function completeAuthorName($name, $url)
	{
		/* gets the host name */
		preg_match("/^(http:\/\/)?([^\/]+)/i", $url, $matches);

        return sanitize_user($this->normalize(str_replace(array('{original_author}', '{source_host}'), array($name, $matches[2]), get_option('wpoa_authornametemplate'))),true);
	}

    #Called at the activation of the plugin
    function activate() {
        // only re-install if new version or uninstalled
        if(! $this->installed || $this->installed != $this->version) {
            /* use dbDelta() to create tables */
            add_option('wpoa_version', $this->version);

            $this->installed = true;
        }
        if (!get_option('wpoa_authornametemplate')) {
            add_option('wpoa_authornametemplate', "{original_author} on {source_host}");
        }
    }

    #Called at the deactivation of the plugin
    function deactivate() {
    }
}

$wpoauthors = & new WPOAuthors();

?>
