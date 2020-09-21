<?php

namespace SocialKit;

class Story {
	use \SocialTrait\Extension;

	private $id;
	private $conn;
	private $timelineObj;
	private $timelineId;
	private $recipientObj;
	public $data;
	public $template;
	public $view_all_comments = false;
	private $comment_mentions;
	private $escapeObj;
	private $registerObj;
	private $maintimeline;
	private $buzzer;
	public $post_origin;
	private $misc;
	private $posts_votingfeature_setting;
	private $adznouncer = null;
	private $title_character_limit = 60;
	private $description_character_limit = 100;
	private $adverts;
	private $mediaId = 0;
	private $mediaExists;
	private $cache;
	private $showTimelinePostsForce = false;
	private $activity;

	/**
	* Set Parent Comment ID on sub comment
	*
	* @var $parent_comment_id
	*/
	protected $parent_comment_id = 0;


	function __construct()
	{
		global $conn;
		$this->conn = $conn;
		$this->escapeObj = new \SocialKit\Escape();
		$this->maintimeline = getMainTimeline();
		$this->cache = \ADZbuzzCore\Cache::get_instance();
		$this->activity = new \SocialKit\ActivityLog();
		require_once(ROOT_DIR . "/classes/Buzzer.class.php");
		$this->buzzer = new \Buzzer();
		$this->misc = new Misc();
		$this->adverts = new \SocialKit\Adverts();
		$this->posts_votingfeature_setting = $this->misc->get_parameter_by_name('posts_votingfeature_setting');

		if ( $title_character_limit = $this->misc->get_parameter_by_name("maintimeline.preview_title_character_limit") )
		{
			$this->title_character_limit = $title_character_limit;
		}

		if ( $description_character_limit = $this->misc->get_parameter_by_name("maintimeline.preview_description_character_limit") )
		{
			$this->description_character_limit = $description_character_limit;
		}

		if ( function_exists('getADZnouncerTimeline'))
		{
			$this->adznouncer = getADZnouncerTimeline();
		}

		return $this;
	}

	public function setConnection(\mysqli $conn)
	{
		$this->conn = $conn;
		return $this;
	}


	protected function getConnection()
	{
		return $this->conn;
	}

	public function getRows()
	{
		global $config;
		/*$query1 = $this->getConnection()->query("SELECT * FROM " . DB_POSTS . " WHERE id=" . $this->id . " AND active=1");*/
		$key = DB_POSTS . ".id=" . $this->id;
		//disabled the caching temporarily until we get the correct caching logic
		$this->data = (false !== $this->cache->exists(__FUNCTION__, $key)) ? json_decode($this->cache->get(__FUNCTION__, $key), true) : array();
		//$this->data = array();

		if(empty($this->data)) {

			$query1 = $this->getConnection()->query("SELECT * FROM " . DB_POSTS . " WHERE id=" . $this->id);
			if (!$query1) {
				return false;
			}
			$post = $query1->fetch_array(MYSQLI_ASSOC);
			/*$userObj = new \SocialKit\User($this->getConnection());
			$post['timeline'] = $userObj->getById($post['timeline_id']);
			$this->cache->set(__FUNCTION__, $key, json_encode($post), 600);
			unset($post['timeline_id']);*/

			if ($post['id'] == $post['post_id'])
			{
				$this->data = $post;
				$this->cache->set(__FUNCTION__, $key, json_encode($this->data), 600);
			}
			else
			{
				$key = DB_POSTS . ".id=" . $post['post_id'];
				$this->data = (false !== $this->cache->exists(__FUNCTION__, $key)) ? json_decode($this->cache->get(__FUNCTION__, $key), true) : array();

				if(empty($this->data)){
					$query2 = $this->getConnection()->query("SELECT * FROM " . DB_POSTS . " WHERE id=" . $post['post_id'] . " AND active=1");
					if (!$query2) {
						return false;
					}
					if ($query2->num_rows == 1) {
						$this->data = $query2->fetch_array(MYSQLI_ASSOC);
						/*$userObj = new \SocialKit\User($this->getConnection());
						$this->data['timeline'] = $userObj->getById($this->data['timeline_id']);*/
						$this->cache->set(__FUNCTION__, $key, json_encode($this->data), 600);
						//unset($this->data['timeline_id']);
					}
				}
			}
		}

		if (!empty($this->data)) {
			$userObj = new \SocialKit\User($this->getConnection());
			$this->data['timeline'] = $userObj->getById($this->data['timeline_id']);
			unset($this->data['timeline_id']);

			/* Timeline Object */
			$this->timelineObj = $userObj;

			// Get Type
			$this->data['type'] = $this->getType();


			// See if it's reported
			$this->data['isReported'] = $this->isReported();


			// Get recipient, if available
			$this->data['recipient'] = $this->getRecipient();


			// Get activity text (sub-text)
	        $this->data['activity_text'] = $this->getActivity();


	        // Emoticons
	        $this->data['original_text'] = $this->data['text'];
	        $this->data['editable_text'] = str_replace("<br>", '&#10;' , trim($this->data['text']));
	        $this->data['editable_text'] = $this->escapeObj->getEditHashtags($this->data['editable_text']);
	        $this->data['editable_text'] = $this->escapeObj->getEditMentions($this->data['editable_text']);
	        $this->data['editable_text'] = $this->escapeObj->getEditLinks($this->data['editable_text']);
	        $this->data['text'] = $this->escapeObj->getEmoticons($this->data['text']);
	        $this->data['text'] = $this->escapeObj->getLinks($this->data['text']);
	        $this->data['text'] = $this->escapeObj->getHashtags($this->data['text']);
	        $this->data['text'] = $this->escapeObj->getMentions($this->data['text']);


	        // Media, if available
			$this->data['media'] = $this->getMedia();



			// Location
	        $this->data['location'] = $this->getLocation();

        	// Via
        	$this->data['via'] = (isset($post)) ? $this->getVia($post) : array();


			// Admin Rights
	        $this->data['admin'] = $this->isAdmin();

	        if ( isset($this->data['id']))
	        {
	        	//Link preview if any
		        $this->data['link_preview_data'] = $this->buzzer->get_latest_links(array(
		        	'id_post' => $this->data['id'],
		        	'limit' => 1
		        	));

		        if ( $this->data['link_preview_data'])
		        {
		        	
		        	$this->data['link_preview_data'] = array_shift($this->data['link_preview_data']);
		        	$this->data['link_preview_data']['media'] = $this->buzzer->get_latest_media_preview($this->data['link_preview_data']['id']);



		        	if ( $this->data['link_preview_data']['media'] != NULL)
		        	{
		        		$this->data['link_preview_data']['image'] = $config['site_url'].'/'.$this->data['link_preview_data']['media']['url'].'.'.$this->data['link_preview_data']['media']['extension'];
		        	}

		        	/*if ( ! @getimagesize($this->data['link_preview_data']['image']))
		        	{
		        		$this->data['link_preview_data']['image'] = $config['theme_url'].'/images/no-image.jpg';
		        	}*/ //darwin.28aug2017: we should not do getimagesize to a remote url (via http/s) if we have a local copy of it. doing so is very slow
		        	// var_dump($this->data['link_preview_data']);

		        }	
	        }
	        
	        

	        // Invoke plugins
	        //$this->data = $this->invoke('post_content_editor', $this->data);


	        // Basic Template Data
	        $this->getBasicTemplateData();

	        // Caching
			/*$_SESSION['tempche']['story'][$this->id] = $this->data;
			$_SESSION['tempche']['story'][$this->id]['expire_time'] = time() + (60 * 5);*/

	        return $this->data;
	    }
	    return false;
	}

	public function isLiked($timeline_id=0) {
	    global $user;

	    $timeline_id = (int) $timeline_id;
	    $count = 0;

	    if ($timeline_id == 0) {
	        $timeline_id = $user['id'];
	    }

	    $key = DB_POSTLIKES . ".post_id=" . $this->id . ".timeline_id=" . $timeline_id;
	    if(false !== $this->cache->exists(__FUNCTION__, $key)) $count = $this->cache->get(__FUNCTION__, $key);
	    if($count > 0) return true;

	    $query = $this->getConnection()->query("SELECT id FROM " . DB_POSTLIKES . " WHERE post_id=" . $this->id . " AND timeline_id=$timeline_id AND active=1");
	    
	    if ($query->num_rows == 1) {
	    	$this->cache->set(__FUNCTION__, $key, $query->num_rows, 600);
	        return true;
	    }
	}

	public function isShared($timeline_id=0) {
	    global $user;

	    $timeline_id = (int) $timeline_id;
	    $count = 0;

	    if ($timeline_id == 0) {
	        $timeline_id = $user['id'];
	    }

	    $key = DB_POSTS . ".parent_id=" . $this->id . ".timeline_id=" . $timeline_id;
	    if(false !== $this->cache->exists(__FUNCTION__, $key)) $count = $this->cache->get(__FUNCTION__, $key);
	    if($count > 0) return true;
	    
	    $query = $this->getConnection()->query("SELECT parent_id FROM " . DB_POSTS . " WHERE parent_id=" . $this->id . " AND timeline_id=$timeline_id AND active=1");
	    
	    if ($query->num_rows > 0) {
	    	$this->cache->set(__FUNCTION__, $key, $query->num_rows, 600);
	        return true;
	    }
	}

	public function isFollowed($timeline_id=0) {
	    global $user;

	    $timeline_id = (int) $timeline_id;
	    $count = 0;

	    if ($timeline_id == 0) {
	        $timeline_id = $user['id'];
	    }

	    $key = DB_POSTFOLLOWS . ".post_id=" . $this->id . ".timeline_id=" . $timeline_id;
	    if(false !== $this->cache->exists(__FUNCTION__, $key)) $count = $this->cache->get(__FUNCTION__, $key);
	    if($count > 0) return true;
	    
	    $query = $this->getConnection()->query("SELECT id FROM " . DB_POSTFOLLOWS . " WHERE post_id=" . $this->id . " AND timeline_id=$timeline_id AND active=1");
	    
	    if ($query->num_rows == 1) {
	    	$this->cache->set(__FUNCTION__, $key, $query->num_rows, 600);
	        return true;
	    }
	}

	public function isReported() {
	    if (! isLogged()) {
			return false;
		}
		
		global $user;
		$count = 0;

		if (array_key_exists('id', $this->data)) {
			$key = DB_REPORTS . ".post_id=" . $this->data['id'] . ".reporter_id=" . $user['id'];
		    //if(false !== ($this->cache->exists(__FUNCTION__, $key))) return true;

			$query = $this->getConnection()->query("SELECT id FROM " . DB_REPORTS . " WHERE reporter_id=" . $user['id'] . " AND post_id=" . $this->data['id'] . " AND type='story'");
			if ($query) {
				if ($query->num_rows == 1) {
					$this->cache->set(__FUNCTION__, $key, $query->num_rows, 600);
					return true;
				}
			}
		}
		return false;
	}

	public function isAdmin()
	{
		if (! isLogged())
		{
			return false;
		}

		$admin = false;
        
        if ($this->timelineObj->isAdmin())
        {
			$admin = true;
		}

		if (is_array($this->data['recipient']))
		{
			if ($this->recipientObj->isAdmin())
			{
				$admin = true;
			}
		}

        return $admin;
	}

	public function numLikes()
	{
		$key = DB_POSTLIKES . ".post_id=" . $this->id;
		$count = (false !== ($this->cache->exists(__FUNCTION__, $key))) ? $this->cache->get(__FUNCTION__, $key) : 0;
		if($count > 0) return $count;

	    $query = $this->getConnection()->query("SELECT COUNT(id) AS count FROM " . DB_POSTLIKES . " WHERE post_id=" . $this->id . " AND active=1");
	    $fetch = $query->fetch_array(MYSQLI_ASSOC);
	    $this->cache->set(__FUNCTION__, $key, $fetch['count'], 600);

	    return $fetch['count'];
	}

	public function numComments()
	{ //adzbuzz 305 DIM
	    $key = DB_COMMENTS . ".post_id=" . $this->id;
		$count = (false !== ($this->cache->exists(__FUNCTION__, $key))) ? $this->cache->get(__FUNCTION__, $key) : 0;
		if($count > 0) return $count;

	    $query = $this->getConnection()->query("SELECT COUNT(id) AS count FROM " . DB_COMMENTS . " WHERE post_id=" . $this->id . " AND active=1
		AND id not in (SELECT c.orig_id FROM ".DB_COMMENTS." c  
						WHERE c.post_id = " . $this->id . " and c.orig_id is not null)");
	    $fetch = $query->fetch_array(MYSQLI_ASSOC);
	    $this->cache->set(__FUNCTION__, $key, $fetch['count'], 600);
	    
	    return $fetch['count'];
	}

	public function numShares()
	{
		$key = DB_POSTSHARES . ".post_id=" . $this->id;
		$count = (false !== ($this->cache->exists(__FUNCTION__, $key))) ? $this->cache->get(__FUNCTION__, $key) : 0;
		if($count > 0) return $count;

	    $query = $this->getConnection()->query("SELECT COUNT(id) AS count FROM " . DB_POSTSHARES . " WHERE post_id=" . $this->id . " AND active=1");
	    $fetch = $query->fetch_array(MYSQLI_ASSOC);
	    $this->cache->set(__FUNCTION__, $key, $fetch['count'], 600);
	    
	    return $fetch['count'];
	}

	public function numFollowers()
	{
		$key = DB_POSTSHARES . ".post_id=" . $this->id;
		$count = (false !== ($this->cache->exists(__FUNCTION__, $key))) ? $this->cache->get(__FUNCTION__, $key) : 0;
		if($count > 0) return $count;

	    $query = $this->getConnection()->query("SELECT COUNT(id) AS count FROM " . DB_POSTFOLLOWS . " WHERE post_id=" . $this->id . " AND active=1");
	    $fetch = $query->fetch_array(MYSQLI_ASSOC);
	    $this->cache->set(__FUNCTION__, $key, $fetch['count'], 600);
	    
	    return $fetch['count'];
	}

	public function numVotes( $id = 0, $type=null, $forceRecount = false) {

	    if ( !empty($id))
	    {
	    	$this->id = $id;
	    }

	    $key = DB_POSTVOTES . ".post_id=" . $this->id;
	    if (!$forceRecount) {
			$count = (false !== ($this->cache->exists(__FUNCTION__, $key))) ? $this->cache->get(__FUNCTION__, $key) : 0;
			if($count > 0) return $count;
	    }
	    
	    global $conn;
	    $sql = "SELECT COUNT(id) AS total FROM ".DB_POSTVOTES." WHERE post_id=".$this->id;

	    if ( !is_null($type))
	    {
	        $type = (int) $type;
	        $sql .= " AND type=$type";
	    }

	    $query = $this->getConnection()->query($sql);

	    $row = $query->fetch_array(MYSQLI_ASSOC);
	    $this->cache->set(__FUNCTION__, $key, $row['total'], 600);

	    return ( isset($row['total'])) ? $row['total']: 0;

	}

	public function getType()
	{
		return "story";
	}

	public function getMediaID()
	{
		return $this->data['media_id'];
	}

	public function getMedia()
	{
		$get = false;

		if ($this->data['media_id'] > 0)
		{
			$get = array();
			$get['type'] = 'photos';
			$mediaObj = new \SocialKit\Media();
			$media = $mediaObj->getById($this->data['media_id']);

			if ($media['type'] == "photo")
			{
				$get = $media;
				$get['type'] = 'photos';
				$get['each'][0]['url'] = SITE_URL . '/' . $get['each'][0]['url'] . '.' . $get['each'][0]['extension'];
				$get['each'][0]['post_id'] = $this->data['id'];
				$get['each'][0]['post_url'] = smoothLink('story/' . $this->data['id']);
			}
			elseif ($media['type'] == "album")
			{
				$get = $media;
				$get['type'] = 'photos';
				$get['each'] = array();

				if ($get['temp'] == 0)
				{
					for ($each_i = 0; $each_i < 6; $each_i++)
					{
						if (array_key_exists('each', $media) && isset($media['each'][$each_i]) && is_array($media['each'][$each_i]))
						{
							$get['each'][$each_i] = $media['each'][$each_i];
							$get['each'][$each_i]['url'] = SITE_URL . '/' . $media['each'][$each_i]['url'] . '_100x100.' . $media['each'][$each_i]['extension'];
						}
					}
				}
				else
				{
					$get['each'] = array_key_exists('each', $media) ? $media['each'] : [];
					
					if (array_key_exists('each', $media)) {
						foreach ($media['each'] as $each_i => $each_v)
						{
							$get['each'][$each_i]['url'] = SITE_URL . '/' . $each_v['url'] . '_100x100.' . $each_v['extension'];
						}
					}
				}
			}
			
			unset($this->data['media_id']);
		}
		elseif (! empty($this->data['soundcloud_uri']))
		{
			$get = array();
			$get['type'] = 'soundcloud';
			$get['each'][]['url'] = $this->data['soundcloud_uri'];
			unset($this->data['soundcloud_uri']);
		}
		elseif (! empty($this->data['youtube_video_id']))
		{
			$get = array();
			$get['type'] = 'youtube';
			$get['each'][]['id'] = $this->data['youtube_video_id'];
			unset($this->data['youtube_video_id']);
		}

		return $get;
	}

	public function getLocation() {
		$location = false;

		if (! empty($this->data['google_map_name'])) {
			$location = array(
				'name' => $this->data['google_map_name']
			);
		}

		return $location;
	}

	public function getVia($post) {
		$via = false;

		if ($this->data['id'] !== $post['id'] && $this->data['timeline']['id'] !== $post['timeline']['id']) {
            $via_type = $post['type2'];
            
            if ($post['type2'] === "with") {
                $via_type = 'tag';
            }
            
            $via = array(
            	'type' => $via_type,
            	'timeline' => $post['timeline']
            );
        }

        return $via;
	}

	public function getActivity() {
		$activity = false;

		if (! empty($this->data['activity_text'])) {
	            
            preg_match(
            	'/\[album\]([0-9]+)\[\/album\]/i',
            	$this->data['activity_text'],
            	$matches
            );

            $activity_query1 = $this->getConnection()->query("SELECT id,name FROM " . DB_MEDIA . " WHERE id=" . $matches[1]);
            $activity_fetch1 = $activity_query1->fetch_object();

            $activity_text_replace = '<a href="' . smoothLink('album/' . $activity_fetch1->id) . '" data-href="/album/' . $activity_fetch1->id . '">' . $activity_fetch1->name . '</a>';

            $activity = str_replace(
            	$matches[0],
            	'<a href="' . smoothLink('album/' . $activity_fetch1->id) . '" data-href="/album/' . $activity_fetch1->id . '">' . $activity_fetch1->name . '</a>',
            	$this->data['activity_text']
            );
        }

        return $activity;
	}

	public function getRecipient() {
		$recipient = false;
		
		if (array_key_exists('recipient_id', $this->data)) {
	
			if ($this->data['recipient_id'] > 0) {
				$recipientObj = new \SocialKit\User($this->getConnection());
				$recipient = $recipientObj->getById($this->data['recipient_id']);
				$this->recipientObj = $recipientObj;
			}

			unset($this->data['recipient_id']);
		}
		return $recipient;
	}

	public function getLikes($offset=0, $limit=0)
	{
		$get = array();
		$key = DB_POSTLIKES . ".post_id=" . $this->id;
		//$get = (false !== ($this->cache->exists(__FUNCTION__, $key))) ? json_decode($this->cache->get(__FUNCTION__, $key), true) : array();

		//if( !empty($get) ) return $get;

		$queryText = "SELECT id,timeline_id FROM " . DB_POSTLIKES . " WHERE post_id=" . $this->id . " AND active=1";

		if( $limit > 0 ) $queryText .= " LIMIT $limit";
		if( $offset > 0 ) $queryText .= " OFFSET $offset";

		$query = $this->getConnection()->query($queryText);
	    
	    if ($query->num_rows > 0)
	    {
	        while ($fetch = $query->fetch_array(MYSQLI_ASSOC))
	        {
	        	$get[] = $fetch['timeline_id'];
	        }
	        //$this->cache->set(__FUNCTION__, $key, json_encode($get), 600);
	    }

	    return $get;
	}

	public function getComments($li=0)
	{
		$comments = '';
		$numComments = $this->numComments();

		if ($li < 1)
		{
			$li = $numComments;
		}

		$commentFeed = new \SocialKit\CommentFeed($this->getConnection());
		$commentFeed->setPostId($this->id);
		$commentFeed->setLimit($li);
		//$commentFeed->setTotal($numComments);

		$commentFeedObj = array_reverse($commentFeed->getFeed());
        foreach ($commentFeedObj as $commentId)
        {
        	$comment = new \SocialKit\Comment($this->conn);
        	$comment->setId($commentId);
        	$comment->setOrigId($commentId);
        	$comment->setTimelineId($this->timelineId);
        	$comments .= $comment->getTemplate();
        }
        return $comments;
	}

	public function getCommentIds($li=0) {
		$get = array();
		$comments = '';
		$numComments = $this->numComments();

		if ($li < 1)
		{
			$li = $numComments;
		}

		$commentFeed = new \SocialKit\CommentFeed($this->getConnection());
		$commentFeed->setPostId($this->id);
		$commentFeed->setLimit($li);
		$commentFeed->setTotal($numComments);

        foreach ($commentFeed->getFeed() as $commentId)
        {
        	$get[] = $commentId;
        }

        return $get;
	}

	public function getShares($offset=0, $limit=0)
	{
		$get = array();
		//$key = DB_POSTSHARES . ".post_id=" . $this->id;
		//$get = (false !== ($this->cache->exists(__FUNCTION__, $key))) ? json_decode($this->cache->get(__FUNCTION__, $key), true) : array();

		//if( !empty($get) ) return $get;

		//$query = $this->getConnection()->query("SELECT id,timeline_id FROM " . DB_POSTSHARES . " WHERE post_id=" . $this->id . " AND active=1");

		$queryText = "SELECT id FROM posts WHERE parent_id IN (SELECT post_id FROM postshares) AND parent_id = $this->id AND active = 1";

		if($limit > 0) $queryText .= " LIMIT $limit"; 
		if($offset > 0) $queryText .= " OFFSET $offset"; 

		
		$query = $this->getConnection()->query($queryText);

		if ($query->num_rows > 0)
	    {
	        while ($fetch = $query->fetch_array(MYSQLI_ASSOC))
	        {
	        	$get[] = $fetch['id'];
	        }
	        //$this->cache->set(__FUNCTION__, $key, json_encode($get), 600);
	    }

	    return $get;
	}

	public function getSharers($offset=0, $limit=0)
	{
		$get = array();
		//$key = DB_POSTSHARES . ".post_id=" . $this->id;
		//$get = (false !== ($this->cache->exists(__FUNCTION__, $key))) ? json_decode($this->cache->get(__FUNCTION__, $key), true) : array();

		//if( !empty($get) ) return $get;

		$queryText = "SELECT DISTINCT(timeline_id) FROM " . DB_POSTSHARES . " WHERE post_id=" . $this->id . " AND active=1";

		if($limit > 0) $queryText .= " LIMIT $limit"; 
		if($offset > 0) $queryText .= " OFFSET $offset";
		
		$query = $this->getConnection()->query($queryText);

		if ($query->num_rows > 0)
	    {
	        while ($fetch = $query->fetch_array(MYSQLI_ASSOC))
	        {
	        	$get[] = $fetch['timeline_id'];
	        }
	        //$this->cache->set(__FUNCTION__, $key, json_encode($get), 600);
	    }

	    return $get;
	}

	public function getFollowers()
	{
		$get = array();
		$key = DB_POSTFOLLOWS . ".post_id=" . $this->id;
		$get = (false !== ($this->cache->exists(__FUNCTION__, $key))) ? json_decode($this->cache->get(__FUNCTION__, $key), true) : array();

		if( !empty($get) ) return $get;

		$query = $this->getConnection()->query("SELECT id,timeline_id FROM " . DB_POSTFOLLOWS . " WHERE post_id=" . $this->id . " AND active=1");
	    
	    if ($query->num_rows > 0)
	    {
	        while ($fetch = $query->fetch_array(MYSQLI_ASSOC))
	        {
	        	$sharer = new \SocialKit\User($this->getConnection());
	        	$sharer->setId($fetch['timeline_id']);

	            $get[] = $sharer->getRows();
	        }
	        $this->cache->set(__FUNCTION__, $key, json_encode($get), 600);
	    }

	    return $get;
	}

	public function getCommentBox($timelineId=0, $commentid, $is_sub=0, $sub_count=0)
	{
	    if (! isLogged())
	    {
	        return false;
	    }
	    
	    global $themeData, $user;
	    $continue = true;
	    $timelineId = (int) $timelineId;

	    if ($timelineId < 1)
	    {
	    	$timelineId = $user['id'];
	        $timeline = $user;
	    }
	    else
	    {
	        $timelineObj = new \SocialKit\User();
	        $timelineObj->setId($timelineId);
	        $timeline = $timelineObj->getRows();

	        if (! $timelineObj->isAdmin() && $is_sub==0)
	        {
	        	$continue = false;
	        }
	    }
	    
	    if ($this->data['timeline']['type'] == "user")
	    {
	        if ($this->data['timeline']['id'] != $timelineId && $this->data['timeline']['comment_privacy'] == "following")
	        {
	            if (! $this->timelineObj->isFollowing($timelineId))
	            {
	                $continue = false;
	            }
	        }
	    }
	    
	    if ($continue == false)
	    {
	        return false;
	    }

	    //$themeData['story_id'] = $postId;

	    $themeData['publisher_id'] = $timeline['id'];
	    $themeData['publisher_url'] = $timeline['url'];
	    $themeData['publisher_username'] = $timeline['username'];
	    $themeData['publisher_name'] = $timeline['name'];
	    $themeData['publisher_thumbnail_url'] = $timeline['thumbnail_url'];
	    $themeData['parent_comment_id'] = $commentid;
	    $themeData['commentid'] = $commentid;
	    $themeData['is_sub_comment'] = $is_sub;

	    //CREATE OUR CUSTOM EMOTICONS
	    $emoticons = getEmoticons();
	    $emoticonListsComments = '';
	    
	    if (is_array($emoticons)) {
	        
	        foreach ($emoticons as $emo_code => $emo_icon) {
	            $emoticonListsComments .= '<img src="' . $emo_icon . '" width="16px" style="padding:0;margin:2px" onclick="addEmoToInput(\''.$emo_code.'\',\'textarea[data-parentcid='.$commentid.']\',\''.$commentid.'\');">';
	            
	        }
	    }

	    $themeData['sub_textarea'] = "";
    	$themeData['sub_hidden'] = "";

    	if ($is_sub > 0) {
			$themeData['sub_hidden'] = "hidden";    		
    	}

	    if( $is_sub > 0 && $sub_count > 1) {
	    	$themeData['sub_textarea'] = "sub-comment-textarea-$commentid";
	    	$themeData['sub_hidden'] = "hidden";
	   	}

    	$themeData['list_emoticons_comments'] = $emoticonListsComments;


	    return \SocialKit\UI::view('comment/publisher-box/content');
	}

	public function setId($id) {
		$this->id = (int) $id;
	}

	public function isVoted( $id=0,$forceCheck = false)
	{
		if (! isLogged())
	    {
	        return false;
	    }
	    
	    if ( !empty($id))
	    {
	    	$this->id = $id;
	    }

	    global $user;

	    $key = DB_POSTVOTES . ".post_id=" . $this->id . ".timeline_id=" . $user['id'];
		if(!$forceCheck){
			$row = (false !== ($this->cache->exists(__FUNCTION__, $key))) ? $this->cache->get(__FUNCTION__, $key) : array();
			if(isset($row['type'])) return true;
		}
	    $sql = 'SELECT type FROM '.DB_POSTVOTES.' WHERE post_id='.$this->id.' AND timeline_id='.$user['id'].' LIMIT 1';

	    $query = $this->getConnection()->query($sql);
	    $row = $query->fetch_array(MYSQLI_ASSOC);
	    $this->cache->set(__FUNCTION__, $key, json_encode($row), 600);

	    return isset($row['type']) ? true : false;

	}

	public function getCurrentVote( $id=0)
	{
		if (! isLogged())
	    {
	        return false;
	    }
	    
	    if ( !empty($id))
	    {
	    	$this->id = $id;
	    }

	    global $user;

	    $key = DB_POSTVOTES . ".post_id=" . $this->id . ".timeline_id=" . $user['id'];
		$row = (false !== ($this->cache->exists(__FUNCTION__, $key))) ? $this->cache->get(__FUNCTION__, $key) : array();
		if(isset($row['type'])) return $row['type'];

	    $sql = 'SELECT type FROM '.DB_POSTVOTES.' WHERE post_id='.$this->id.' AND timeline_id='.$user['id'].' LIMIT 1';
	    $query = $this->getConnection()->query($sql);
	    $row = $query->fetch_array(MYSQLI_ASSOC);
	    $this->cache->set(__FUNCTION__, $key, json_encode($row), 600);

	    return isset($row['type']) ? $row['type'] : 0;
	}

	public function putVote( $id=0, $timeline_id=0, $type=0)
	{
		if (! isLogged())
	    {
	        return false;
	    }
	    global $user;

	    if ( !empty($id))
	    {
	    	$this->id = $id;
	    }
	    if ( !empty($timeline_id))
	    {
	    	$timeline_id = $user['id'];
	    }

	    if ( empty($this->id)) { return false;}
	    if ( empty($timeline_id)) { return false;}
	    if ( empty($type)) { return false;}

	    $time = time();

	    if ( !$this->isVoted($this->id,true))
	    {
	    	$sql = "INSERT INTO ".DB_POSTVOTES." (`active`, `post_id`, `time`, `timeline_id`, `type`) VALUES(1,$this->id,$time,$timeline_id,$type)";
	    }
	    else
	    {
	    	$previous_vote = $this->getCurrentVote($id);

	    	//unvote
	    	if ( $previous_vote == $type)
	    	{
	    		$type = 0;
	    	}

	    	$sql = "UPDATE ".DB_POSTVOTES." SET type=$type WHERE post_id=$this->id AND timeline_id=$timeline_id";
	    }

	    
	    $query = $this->getConnection()->query($sql);

	    $this->cache->delete("numVotes", DB_POSTVOTES . ".post_id=" . $this->id);
	    $this->cache->delete("isVoted", DB_POSTVOTES . ".post_id=" . $this->id . ".timeline_id=" . $timeline_id);
	    $this->cache->delete("getCurrentVote", DB_POSTVOTES . ".post_id=" . $this->id . ".timeline_id=" . $timeline_id);

	    return $query;
	}


	public function putLike()
	{
	    
	    if (! isLogged())
	    {
	        return false;
	    }
	    
	    global $user;
	    
	    if ($this->isLiked())
	    {
		    global $user;

	        $timeline_id = $user['id'];	        
	
		    	$query = $this->getConnection()->query("SELECT id,timeline_id,post_id FROM " . DB_POSTLIKES . " WHERE post_id=" . $this->id . " AND timeline_id=$timeline_id AND active=1");
		        $dbPostsLikes = $query->fetch_array(MYSQLI_ASSOC); 
		    	

		    	$query = $this->getConnection()->query("SELECT timeline_id FROM " . DB_POSTS . " WHERE post_id=" . $dbPostsLikes['post_id']);

		    	if($query){

		    		$dbPosts = $query->fetch_array(MYSQLI_ASSOC); 
		    	
    	        
	    	        if($dbPostsLikes['timeline_id'] != $dbPosts['timeline_id'] && $_SESSION["like_bonus"] > 0)
			        {
			        	$registerObj = new \SocialKit\registerUser();
			        	$transaction_id_gen = $registerObj->getTransactionId();
			        	$message = 'social_activity_bonus';


			        	$check_user = $this->getConnection()->query("SELECT `transaction_id` FROM " . DB_TRANSACTIONS . " WHERE `user_id` =" .$dbPosts['timeline_id']. " AND payment_type = '".$message."' AND date(`created`) = CURRENT_DATE" );


		                $count_rows = $check_user->num_rows;


		                // if there is already a affliate bonus today, try to update it.
		                if($check_user->num_rows > 0)
		                {
		                    $check_user_id = $check_user->fetch_array(MYSQLI_ASSOC); 
		                    $insert_bonus = $this->getConnection()->query("UPDATE " . DB_TRANSACTIONS . " SET `amount` = `amount`-". $_SESSION['like_bonus'] ." , `status` = 'confirmed' WHERE `transaction_id` = " . $check_user_id['transaction_id']);
		                }
		                else
		                {
		                    $this->getConnection()->query("INSERT INTO " . DB_TRANSACTIONS . " (transaction_id,user_id,ref_id,payment_type,amount,status,notification_sent,created,process_date) VALUES (" . $transaction_id_gen . ", " . $dbPosts['timeline_id'] . " ,0,'".$message."'," . $_SESSION['like_bonus'] . ",'confirmed',0, now(), now())");
		                }
				        $registerObj->insertAffliateAmounts($dbPosts['timeline_id'],'affliate_bonus',$_SESSION['like_bonus'],1,true);
			        }	
		    	}

	    	$this->getConnection()->query("DELETE FROM " . DB_POSTLIKES . " WHERE post_id=" . $this->id . " AND timeline_id=" . $user['id'] . " AND active=1");
	    }
	    else
	    {
	        
             
            $last_inserted = $this->getConnection()->query("INSERT INTO " . DB_POSTLIKES . " (timeline_id,active,post_id,time) VALUES (" . $user['id'] . ",1," . $this->id . "," . time() . ")");
            
            $get_last_insert = mysqli_insert_id($this->conn);

            $get_likes = $this->getConnection()->query("SELECT `timeline_id` FROM " . DB_POSTLIKES . " WHERE `id` = ". $get_last_insert);
            $get_likes_id = $get_likes->fetch_array(MYSQLI_ASSOC);

            $like_received = $this->getConnection()->query("SELECT `timeline_id` FROM " . DB_POSTS . " WHERE `post_id` = ". $this->id);
	        $like_received_id = $like_received->fetch_array(MYSQLI_ASSOC);

	        $this->putNotification('like');
	        
	        if($get_likes_id['timeline_id'] != $like_received_id['timeline_id'] && $_SESSION["like_bonus"] > 0)
	        {
	        	$registerObj = new \SocialKit\registerUser();
            	$transaction_id_gen = $registerObj->getTransactionId();
	        	$message = 'social_activity_bonus';


	        	$check_user = $this->getConnection()->query("SELECT `transaction_id` FROM " . DB_TRANSACTIONS . " WHERE `user_id` =" .$like_received_id['timeline_id']." AND payment_type = '".$message."' AND date(`created`) = CURRENT_DATE" );


                $count_rows = $check_user->num_rows;


                // if there is already a affliate bonus today, try to update it.
                if($check_user->num_rows > 0)
                {
                    $check_user_id = $check_user->fetch_array(MYSQLI_ASSOC); 
                    $insert_bonus = $this->getConnection()->query("UPDATE " . DB_TRANSACTIONS . " SET `amount` = `amount`+". $_SESSION['like_bonus'] ." , `status` = 'confirmed' WHERE `transaction_id` = " . $check_user_id['transaction_id']);
                }
                else
                {
                    $this->getConnection()->query("INSERT INTO " . DB_TRANSACTIONS . " (transaction_id,user_id,ref_id,payment_type,amount,status,notification_sent,created,process_date) VALUES (" . $transaction_id_gen . ", " . $like_received_id['timeline_id'] . " ,0,'".$message."'," . $_SESSION['like_bonus'] . ",'confirmed',0, now(), now())");
                }
		        $this->getConnection()->query("UPDATE " . DB_POSTLIKES . " SET `rewarded` = 1 where `id` = ".$get_last_insert);
		        $registerObj->insertAffliateAmounts($like_received_id['timeline_id'],'affliate_bonus',$_SESSION['like_bonus']);
	        }
	    }


	    $activityObj = $this->activity->getActivityByName('likepost');

		if( $activityObj['status'] == 1) {

			$query = $this->getConnection()->query("SELECT `timeline_id` FROM " . DB_POSTS . " WHERE post_id=$this->id");

			if( $query ) {
				$timeline = $query->fetch_assoc();

				$buzz_data = array(
					'buzzer_activities_id' => $activityObj['activity_id'],
					'module_id' => $this->id,
					'user1_id' => $user['id'],
					'user2_id' => $timeline['timeline_id'],
					'time' => time()
				);
				
				if ($this->activity->checkLog($buzz_data) == false)
				{
					$activity_id = $this->activity->putLog($buzz_data);
					$buzz_data['activity_name'] = $activityObj['name'];
					pushNotify($buzz_data);
				} else {
					$this->activity->removeLog($buzz_data);
				}
			}
		}

	    $this->cache->delete("isLiked", DB_POSTLIKES . ".post_id=" . $this->id . ".timeline_id=" . $timeline_id);
		$this->cache->delete("numLikes", DB_POSTLIKES . ".post_id=" . $this->id);
		$this->cache->delete("getLikes", DB_POSTLIKES . ".post_id=" . $this->id);

	    return true;
	}

	public function putShare() {
	    if (! isLogged())
	    {
	        return false;
	    }
	    
	    global $user;

		$this->getConnection()->query("INSERT INTO " . DB_POSTSHARES . " (timeline_id,active,post_id,time) VALUES (" . $user['id'] . ",1," . $this->id . "," . time() . ")");
		$this->putNotification('share');

		$this->cache->delete("numShares", DB_POSTSHARES . ".post_id=" . $this->id);
		$this->cache->delete("getShares", DB_POSTSHARES . ".post_id=" . $this->id);

	    return true;
	}

	public function putFollow()
	{
	    if (! isLogged())
	    {
	        return false;
	    }
	    
	    global $user;
	    
	    if ($this->isFollowed())
	    {
	        $this->getConnection()->query("DELETE FROM " . DB_POSTFOLLOWS . " WHERE post_id=" . $this->id . " AND timeline_id=" . $user['id'] . " AND active=1");
	        $this->putNotification('follow');
	    }
	    else
	    {
	        $this->getConnection()->query("INSERT INTO " . DB_POSTFOLLOWS . " (timeline_id,active,post_id,time) VALUES (" . $user['id'] . ",1," . $this->id . "," . time() . ")");
	    }

	    $this->cache->delete("isFollowed", DB_POSTFOLLOWS . ".post_id=" . $this->id . ".timeline_id=" . $user['id']);
	    $this->cache->delete("getFollowers", DB_POSTFOLLOWS . ".post_id=" . $this->id);

	    return true;
	}


	public function putComment($text='', $timelineId=0, $comment_id=0, $media_id=0, $subtimelineId = 0,$force = false, $parentcid=0)
	{
		if (! isLogged())
	    {
	        return false;
	    }
	    
	    global $user, $config;

	    $ntext = str_replace("\n", "", trim($text));

	    if (empty($ntext) && $this->mediaId < 1)
	    {
	        return false;
	    }

	    if ($config['comment_character_limit'] > 0)
	    {
	        if (strlen($ntext) > $config['comment_character_limit'])
	        {
	            return false;
	        }
	    }
	    
	    $timelineId = (int) $timelineId;

	    if ($timelineId < 1)
	    {
	        $timelineId = $user['id'];
	    }
	    
	    $timelineObj = new \SocialKit\User($this->getConnection());
	    $timelineObj->setId($timelineId);
	    $timeline = $timelineObj->getRows();
	    $continue = true;
	    if (!$force) {
	    	
		    if (! $timelineObj->isAdmin() && $parentcid==0)
		    {
		    	return false;
		    }
		    
		    if ($this->data['timeline']['type'] == "user" && $this->data['timeline']['id'] != $timelineId)
		    {
		        
		        if ($this->data['timeline']['comment_privacy'] == "following")
		        {
		            
		            if (! $this->timelineObj->isFollowing($timelineId))
		            {
		                $continue = false;
		            }
		        }
		    }
		    elseif ($this->data['timeline']['type'] == "group")
		    {
		        
		        if (! $this->timelineObj->isFollowing($timelineId))
		        {
		            $continue = false;
		        }
		    }
		    elseif ($this->data['timeline']['type'] == "community")
		    {
		        
		        if (! $this->timelineObj->isFollowing($timelineId))
		        {
		            $continue = false;
		        }
		    }
		    elseif ($this->data['timeline']['type'] == "team")
		    {
		        
		        if (! $this->timelineObj->isFollowing($timelineId))
		        {
		            $continue = false;
		        }
		    }
		}
	    if (!$continue)
	    {
	        return false;
	    }
	    
	    if ($subtimelineId) {
	    	$timelineId = $subtimelineId;
	    }


	    /* Links */
	    $text = $this->escapeObj->createLinks($text);

	    /* Hashtags */
	    $text = $this->escapeObj->createHashtags($text);

	    /* Mentions */
	    $mentions = $this->escapeObj->createMentions($text);
	    $text = $mentions['content'];
	    $this->comment_mentions = $mentions['mentions'];

	    /* Text */
	    $text = $this->escapeObj->postEscape($text);

	    //original id for saving in DB parent_comment_id
	    $pid = $parentcid;
	    if($parentcid){
	    	$pid = $this->getOriginalID($parentcid)?$this->getOriginalID($parentcid):$parentcid;
	    }

	    // set parent comment id
	    $this->parent_comment_id = $pid; 

	    /* Query */
	    $query = $this->getConnection()->query("INSERT INTO " . DB_COMMENTS . " (timeline_id,active,post_id,media_id,parent_id,text,time,parent_comment_id) VALUES ($timelineId,1," . $this->id . ",$this->mediaId,$comment_id,'$text'," . time() . ",".$pid.")");
	    
	    if ($query)
	    {
	        $commentId = $this->getConnection()->insert_id;
	        
	        /* Put follow */
	        if (! $this->isFollowed())
	        {
	            $this->putFollow();
	        }
	        
	        /* Notify followers */
	        $this->putNotification('comment', $commentId);

	        // clear parent comment id
	    	$this->parent_comment_id = 0; 

	        $this->cache->delete("numComments", DB_COMMENTS . ".post_id=" . $this->id);

	        $activityObj = $this->activity->getActivityByName('commentpost');

			if( $activityObj['status'] == 1 ) {

				$query = $this->getConnection()->query("SELECT timeline_id FROM " . DB_POSTS . " WHERE post_id=$this->id");
				if( $query ) {
					$timeline = $query->fetch_assoc();

					$buzz_data = array(
						'buzzer_activities_id' => $activityObj['activity_id'],
						'module_id' => $commentId,
						'user1_id' => $timelineId,
						'user2_id' => $timeline['timeline_id'],
						'time' => time()
					);
					
					$activity_id = $this->activity->putLog($buzz_data);
					$buzz_data['activity_name'] = $activityObj['name'];
					
					$followers = $this->getFollowers();

					foreach ($followers as $follower) {
						$buzz_data['user2_id'] = $follower['id'];
						pushNotify($buzz_data);	
					}
				}
			}
	        
	        /* Return results */
	        return $commentId;
	    }
	}

	public function getOriginalID($id){

		$checkparent = $this->getConnection()->query("SELECT orig_id FROM ".DB_COMMENTS." WHERE id = ".$id);
		if($checkparent->num_rows){
			return $checkparent->fetch_array(MYSQLI_ASSOC)['orig_id'];
		}else{
			return 0;
		}

	}

	/**
	* Victor Tagupa
	*
	* Get Comment Text
	*
	* @param integer $id	
	* @return string
	*/
	public function getCommentText($id)
	{
		$fetch = $this->getConnection()->query("SELECT text FROM ".DB_COMMENTS." WHERE id = ".$id);
		if($fetch->num_rows){
			return $fetch->fetch_array(MYSQLI_ASSOC)['text'];
		}
		return;
	}

	/**
	* Victor Tagupa
	*
	* Get Comment Text
	*
	* @param integer $id	
	* @return integer
	*/
	public function getCommentTimelineID($id)
	{
		$fetch = $this->getConnection()->query("SELECT timeline_id FROM ".DB_COMMENTS." WHERE id = ".$id);
		if($fetch->num_rows){
			return $fetch->fetch_array(MYSQLI_ASSOC)['timeline_id'];
		}
		return;
	}

	public function putReport()
	{
		global $conn, $user;

		if (! isLogged()) {
			return false;
		}

		if ($this->isReported())
		{
			return false;
		}

		$reason = $conn->real_escape_string($_POST['reason']);

		if($reason=="others"){
			$reason =  @$_POST['others']?$conn->real_escape_string($_POST['others']): '';
		}		

		$recipient = @$this->data["recipient"]["id"]?$this->data["recipient"]["id"]:"";

		$q = "INSERT INTO " . DB_REPORTS . " (active,post_id,reporter_id,type,time,timeline_id,recipient_id, report_details) VALUES (1," . $this->id ."," . $user['id'] . ",'story',".time().",".$this->timelineObj->data["id"].",'".$recipient."','".$reason."')";
		
		$query = $this->getConnection()->query($q);

		if (! $query)
		{
			return false;
		}

		return true;
	}

	public function putApproveUnapprove( $action = 0)
	{
		if (! isLogged()) {
			return false;
		}

		$timelineObj = new \SocialKit\User();
        $timelineObj->setId($this->data['recipient']['id']);

        if ( $timelineObj->isGroupAdmin() && !empty($this->data['recipient']['group_moderate_post'])) {

        	$sql = 'UPDATE '. DB_POSTS .' SET active='.$action.' WHERE post_id='.$this->id;
        	$query = $this->getConnection()->query($sql);

        	return $query;

        }

        return false;

	}
	public function putRemove()
	{
		if (! isLogged()) {
			return false;
		}

		$continue = false;
        
        if ($this->timelineObj->isAdmin())
        {
            $continue = true;
        }
        elseif (is_array($this->data['recipient']))
        {
            if ($this->recipientObj->isAdmin())
            {
                $continue = true;
            }
        }
        else
        {
        	//currently logged in user
	        //is the owner
	        if ( $this->isOwner())
	        {
	        	$continue = true;
	        }
        }

		if($this->isShared())
		{
			$this->getConnection()->query("UPDATE " . DB_POSTS . " SET hidden = 1 WHERE post_id=" . $this->id);
			$continue = false;
		}
		elseif(! $this->isShared())
		{
			if ( $this->isOwner())
			{
				$continue = true;	
			}
			
		}
        
        if ($continue == true)
        {
        	if ($this->data['media']['type'] == "photos")
        	{
        		$continue = true;

        		if (isset ($this->data['media']['temp']))
	        	{
	        		$continue = false;

	        		if ($this->data['media']['temp'] == 1)
	        		{
	        			$continue = true;
	        		}
	        	}

        		if ($continue)
        		{
        			foreach ($this->data['media']['each'] as $key => $value)
        			{
	        			$this->getConnection()->query("DELETE FROM " . DB_MEDIA . " WHERE id=" . $value['id'] . " AND type='photo'");
	        			$this->getConnection()->query("DELETE FROM " . DB_POSTS . " WHERE media_id=" . $value['id']);

	        			$dirImages = glob(str_replace(SITE_URL . "/", "", $value['url']) . "*");
	        			
	        			foreach ($dirImages as $k => $img)
	        			{
	                        unlink($img);
	                    }
	        		}
        		}
        	}
			/*if($this->getCount() == 1)
			{
				$this->getConnection()->query("DELETE FROM " . DB_POSTS . " WHERE post_id=" . $this->data['parent_id']);
				$this->getConnection()->query("DELETE FROM " . DB_COMMENTS . " WHERE post_id=" . $this->data['parent_id']);
				$this->getConnection()->query("DELETE FROM " . DB_COMMENTLIKES . " WHERE post_id=" . $this->data['parent_id']);
				$this->getConnection()->query("DELETE FROM " . DB_POSTLIKES . " WHERE post_id=" . $this->data['parent_id']);
			}*/
			$this->getConnection()->query("DELETE FROM " . DB_POSTS . " WHERE post_id=" . $this->id);
			$this->getConnection()->query("DELETE FROM " . DB_COMMENTLIKES . " WHERE post_id =" . $this->id);
			$this->getConnection()->query("DELETE FROM " . DB_COMMENTS . " WHERE post_id =" . $this->id);
			$this->getConnection()->query("DELETE FROM " . DB_POSTLIKES . " WHERE post_id =" . $this->id);
			$this->getConnection()->query("DELETE FROM " . DB_POSTSHARES . " WHERE time=" . $this->data['time'] . " AND post_id =" . $this->data['parent_id']);
			$this->getConnection()->query("DELETE FROM " . DB_USER_ACTIVITY . " WHERE module_id=" . $this->id);

			//is a blog post
			if ( $this->is_blog_post($this->id))
			{
				$this->getConnection()->query("DELETE FROM " . DB_BLOG_POSTS . " WHERE id_post=" . $this->id);
			}

			$this->cache->delete("numShares", DB_POSTSHARES . ".post_id=" . $this->data['parent_id']);
			$this->cache->delete("getShares", DB_POSTSHARES . ".post_id=" . $this->data['parent_id']);

			return true;
        }
	}

	public function putNotification($action)
	{
		if (! isLogged())
		{
			return false;
		}
		
		global $lang, $user;
		$text = '';

		// if ($this->data['timeline']['id'] == $user['id']) {
			// return false;
		// }

		
		/*require_once(ROOT_DIR . "/classes/Buzzer.class.php");

		$buzzer = new \Buzzer();*/
		//maintimeline data
    	$link_preview_data = $this->buzzer->get_latest_links(array(
    			'limit' => 1,
    			'id_post' => $this->id
    		));
    	if ( $link_preview_data)
    	{
    		$link_preview_data = array_shift($link_preview_data);
    	}
    	
    	
		if ($action == "like")
		{
			if($this->data['timeline']['id'] == $user['id']) return false;

			$count = $this->numLikes();
	        
	        if ($this->isLiked())
	        {
	            $count = $count - 1;
	        }
	        
	        if ($count > 1)
	        {
	            $text .= str_replace('{count}', ($count-1), $lang['notif_other_people']) . ' ';
	        }
	        
	        $text .= str_replace('{post}', substr(strip_tags($this->data['text']), 0, 45), $lang['likes_your_post']);
	        $query = $this->getConnection()->query("SELECT id FROM " . DB_NOTIFICATIONS . " WHERE timeline_id=" . $this->data['timeline']['id'] . " AND post_id=" . $this->id . " AND type='like' AND active=1");
			
		    if ($query->num_rows > 0)
		    {
		        $this->getConnection()->query("DELETE FROM " . DB_NOTIFICATIONS . " WHERE timeline_id=" . $this->data['timeline']['id'] . " AND post_id=" . $this->id . " AND type='like' AND active=1");
		    }
		    else
		    {
		    	$like_url = "/story/" . $this->id;

		    	//if its a maintimeline post
		    	if ( $link_preview_data)
		    	{
		    		$article_base_slug = 'shares';
		    		if( isset($this->data['recipient']['type']) && $this->data['recipient']['type'] == 'ads')
		    		{
		    			$article_base_slug = 'vote';
		    		}
		    		$like_url = "/{$article_base_slug}/".$link_preview_data['id'].'/'.create_slug(htmlspecialchars_decode($link_preview_data['title']));
		    	}

		    	// $text = addslashes($text);
		    	$q12 = $this->getConnection()->query("INSERT INTO " . DB_NOTIFICATIONS . " (timeline_id,active,notifier_id,post_id,text,time,type,url) VALUES (" . $this->data['timeline']['id'] . ",1," . $user['id'] . "," . $this->id . ",'$text'," . time() . ",'like','$like_url')");
		    	
		    	if ($q12) {
		        	triggerNotification('like', $this->data['timeline']['id']);
		        }
		    }

		    return true;
		}
		elseif ($action == "share")
		{
			if($this->data['timeline']['id'] == $user['id']) return false;

			$count = $this->numShares();

			if ($count > 1)
	        {
	            $text .= str_replace('{count}', ($count-1), $lang['notif_other_people']) . ' ';
	        }
	        
	        $text .= str_replace('{post}', substr(strip_tags($this->data['text']), 0, 45), $lang['shared_your_post']);

	        $query = $this->getConnection()->query("SELECT id FROM " . DB_NOTIFICATIONS . " WHERE timeline_id=" . $this->data['timeline']['id'] . " AND post_id=" . $this->id . " AND type='share' AND active=1");
			
		    if ($query->num_rows > 0)
		    {
		        $this->getConnection()->query("DELETE FROM " . DB_NOTIFICATIONS . " WHERE timeline_id=" . $this->data['timeline']['id'] . " AND post_id=" . $this->id . " AND type='share' AND active=1");
		    }
		    else
		    {
		    	// $text = addslashes($text);
		    	$sq1 = $this->getConnection()->query("INSERT INTO " . DB_NOTIFICATIONS . " (timeline_id,active,notifier_id,post_id,text,time,type,url) VALUES (" . $this->data['timeline']['id'] . ",1," . $user['id'] . "," . $this->id . ",'$text'," . time() . ",'share','\/story\/" . $this->id . "')");
		    	if ($sq1) {
		        	triggerNotification('share', $this->data['timeline']['id']);
		        }

		    }

		    return true;
		}
		elseif ($action == "comment")
		{
			$count = $this->numComments();
			
			if ($count > 1)
            {
                $text .= str_replace('{count}', ($count-1), $lang['notif_other_people']) . ' ';
            }

            $postText = strip_tags($this->data['text']);
	        $timeline_id = $this->data['timeline']['id'];

            if (!empty($this->parent_comment_id))
	        {
	        	$postText = $this->getCommentText($this->parent_comment_id);
	        	$postText = strip_tags($postText);
	        	$timeline_id = $this->getCommentTimelineID($this->parent_comment_id);
	        }

            // echo $lang['commented_on_post']; commented on your post
            // echo $lang['commented_on_user_post']; commented on {user}'s post 
            /* Notify story followers */
            foreach ($this->getFollowers() as $follower)
	        {	
	        	if ($user['id'] ==  $follower['id']) {
	        		continue;
	        	}
	        	
	        	$text = '';	        	
	        	// notify user on a sub comment
        		if ($follower['id'] == $timeline_id)
	            {	
	            	if (!empty($this->parent_comment_id))
	            	{
	            		$text .= str_replace('{comment}', substr($postText, 0, 45), $lang['replied_on_comment']);
	            	}
	            	else
	            	{
	            		$text .= str_replace('{post}', substr($postText, 0, 45), $lang['commented_on_post']);
	            	}
	            }
	            else
	            {	
	            	// notify post follower
	            	if ($user['id'] == $timeline_id) {
	            		if (!empty($this->parent_comment_id))
	            		{
	            			$text .= str_replace(
			                    array(
			                        '"{user}"',
			                        '"{comment}"',
			                    ),
			                    array(
			                        getUserLoginName($this->conn,$timeline_id),
			                        substr($postText, 0, 45)
			                    ),
			                    $lang['replied_on_user_comment']
		                    );
	            		}
	            		else
	            		{
	            			$text .= str_replace(
			                    array(
			                        '{user}',
			                        '"{post}"'
			                    ),
			                    array(
			                        getUserLoginName($this->conn,$timeline_id),
			                        substr($postText, 0, 45)
			                    ),
			                    $lang['commented_on_user_post']
		                    );
	            		}
	            	} else {
	            		if (!empty($this->parent_comment_id))
	            		{
	            			// notify comment owner
		            		$text .= str_replace(
			                    array(
			                        '"{user}"',
			                        '"{comment}"'
			                    ),
			                    array(
			                        getUserLoginName($this->conn,$timeline_id),
			                        substr($postText, 0, 45)
			                    ),
			                    $lang['replied_on_user_comment']
		                    );
	            		}
	            		else
	            		{
	            			// notify post owner
		            		$text .= str_replace(
			                    array(
			                        '{user}',
			                        '{post}'
			                    ),
			                    array(
			                        getUserLoginName($this->conn,$timeline_id),
			                        substr($postText, 0, 45)
			                    ),
			                    $lang['commented_on_user_post']
		                    );
	            		}
	            	}
	            	
	            }
	        	
	            
            	$query = $this->getConnection()->query("SELECT id FROM " . DB_NOTIFICATIONS . " WHERE timeline_id=" . $follower['id'] . " AND post_id=" . $this->id . " AND type='comment' AND active=1");
				// do not removed notifications
				// Victor Tagupa
				// 2017/09/16
			    // if ($query->num_rows > 0)
			    // {
			    //     $this->getConnection()->query("DELETE FROM " . DB_NOTIFICATIONS . " WHERE timeline_id=" . $follower['id'] . " AND post_id=" . $this->id . " AND type='comment' AND active=1");
			    // }
			    // else
			    // {
			    	$notification_comment_url = "\/story\/" . $this->id;
			    	//if its a maintimeline post
			    	if ( $link_preview_data)
			    	{
			    		$article_base_slug = 'shares';
			    		if( isset($this->data['recipient']['type']) && $this->data['recipient']['type'] == 'ads')
			    		{
			    			$article_base_slug = 'vote';
			    		}
			    		$notification_comment_url = "/{$article_base_slug}/".$link_preview_data['id'].'/'.create_slug(htmlspecialchars_decode($link_preview_data['title']));
			    	}
			    	$text = addslashes($text);
			    	// echo "INSERT INTO " . DB_NOTIFICATIONS . " (timeline_id,active,notifier_id,post_id,text,time,type,url) VALUES (" . $follower['id'] . ",1," . $user['id'] . "," . $this->id . ",'$text'," . time() . ",'comment','$notification_comment_url');";
			    	$q1 = $this->getConnection()->query("INSERT INTO " . DB_NOTIFICATIONS . " (timeline_id,active,notifier_id,post_id,text,time,type,url) VALUES (" . $follower['id'] . ",1," . $user['id'] . "," . $this->id . ",'$text'," . time() . ",'comment','$notification_comment_url')");
			    	if ($q1) {
			        	triggerNotification('comment', $follower['id']);
			        }
			    // }
	        }

	        /* Notify people mentioned */
	        if (func_num_args() > 1)
	        {
	        	$commentId = (int) func_get_arg(1);
	        	$text = $lang['mentioned_in_comment'];

		        foreach ($this->comment_mentions as $mention)
		        {
	            	$query = $this->getConnection()->query("SELECT id FROM " . DB_NOTIFICATIONS . " WHERE timeline_id=" . $mention . " AND post_id=" . $this->id . " AND type='post_mention' AND active=1");
					
				    if ($query->num_rows > 0)
				    {
				        $this->getConnection()->query("DELETE FROM " . DB_NOTIFICATIONS . " WHERE timeline_id=" . $mention . " AND post_id=" . $this->id . " AND type='post_mention' AND active=1");
				    }
				    else
				    {
				    	// $text = addslashes($text);
				    	$m1 = $this->getConnection()->query("INSERT INTO " . DB_NOTIFICATIONS . " (timeline_id,active,notifier_id,post_id,text,time,type,url) VALUES (" . $mention . ",1," . $user['id'] . "," . $this->id . ",'$text'," . time() . ",'post_mention','\/story\/" . $this->id . "#comment_$commentId')");
				    	if ($m1) {
				        	triggerNotification('post_mention', $mention);
				        	$_SESSION['mention_ids'][] = $mention;
				        }
				    }
		        }
	        }

            return true;
		}
	}

	/* Template Methods */

	public function getTemplate() {

		if (! is_array($this->data))
		{
			$this->getRows();

		}

		if (! isset($this->data['id']))
		{
			return false;
		}

		global $themeData, $user, $call;

		$lounge_id = LIVE?69120:9997;

		$recipient_id = (false !== $this->recipientObj && !is_null($this->recipientObj))?$this->recipientObj->data['id']:false;
		if($recipient_id != $lounge_id) {
			//<!-- Check if Timeline ID is accessible
			$timeline_owner = (isset($user) && ($this->timelineObj->data['id'] == $user['id']));

			if ($this->timelineObj->data["type"] == "user" && $call!='community') {
				$timeline_post_privacy = $this->timelineObj->data["post_privacy"];
				if ($timeline_post_privacy == "following" && !($timeline_owner || $this->timelineObj->isFollowing()) && $this->showTimelinePostsForce == false) return false;
			} elseif ($this->timelineObj->data["type"] == "group") {
				$timeline_group_privacy = $this->timelineObj->data["group_privacy"];
				$isGroupAdmin = $this->timelineObj->isGroupAdmin();
				$isFollowedBy = $this->timelineObj->isFollowedBy();
				if (in_array($timeline_group_privacy, array("secret", "closed")) && !($isFollowedBy || $isGroupAdmin)) return false;
			} elseif ($this->timelineObj->data["type"] == "team") {
				$timeline_team_privacy = $this->timelineObj->data["team_privacy"];
				$isFootballTeamAdmin = $this->timelineObj->isFootballTeamAdmin();
				$isFollowedBy = $this->timelineObj->isFollowedBy();
				if (in_array($timeline_team_privacy, array("secret", "closed")) && !($isFollowedBy || $isFootballTeamAdmin)) return false;
			}

			//-->

			//<!-- Check if Recipient ID is accessible
			if (false !== $recipient_id) {
				$recipient_owner = (isset($user) && ($recipient_id == $user['id']));
				if ($this->recipientObj->data["type"] == "user") {
					$timeline_post_privacy = $this->recipientObj->data["post_privacy"];
					if ($timeline_post_privacy == "following" && !($recipient_owner || $this->recipientObj->isFollowing()) && $this->showTimelinePostsForce == false) return false;
				} elseif ($this->recipientObj->data["type"] == "group") {
					$timeline_group_privacy = $this->recipientObj->data["group_privacy"];
					$isGroupAdmin = $this->recipientObj->isGroupAdmin();
					$isFollowedBy = $this->recipientObj->isFollowedBy();
					if (in_array($timeline_group_privacy, array("secret", "closed")) && !($isFollowedBy || $isGroupAdmin)) return false;
				} elseif ($this->recipientObj->data["type"] == "team") {
					$timeline_team_privacy = $this->recipientObj->data["team_privacy"];
					$isFootballTeamAdmin = $this->recipientObj->isFootballTeamAdmin();
					$isFollowedBy = $this->recipientObj->isFollowedBy();
					if (in_array($timeline_team_privacy, array("secret", "closed")) && !($isFollowedBy || $isFootballTeamAdmin)) return false;
				}
			}
			//-->
		}

		
		// Basic Template Data
		$this->getBasicTemplateData();

		// Recipient Template Data
        $this->getRecipientTemplate();

        //if posts voting feature is enabled to all posts type
        if ( $this->posts_votingfeature_setting !== false && $this->posts_votingfeature_setting == 999)
        {
        	//Vote Buttons
        	$themeData['story_vote_buttons'] = $this->getVoteButtonsTemplate();	
        }
        

        /* Control buttons */
        $themeData['story_control_buttons'] = $this->getControlButtonTemplate();

        /* Text */
        $themeData['story_text_html'] = $this->getTextTemplate();

        /* Media */
        $themeData['media_html'] = $this->getMediaTemplate();

        /* Location */
        $themeData['story_location_name'] = '';
        if (! empty ($this->data['location']))
        {
			$themeData['story_location_name'] = $this->data['location']['name'];
        }

        $themeData['story_location_html'] = $this->getLocationTemplate();

        // Like Activity
        $themeData['story_like_activity'] = $this->getLikeActivityTemplate();

        // Comment Activity
        $themeData['story_comment_activity'] = $this->getCommentActivityTemplate();

        // Share Activity
        $themeData['story_share_activity'] = $this->getShareActivityTemplate();

        // Follow Activity
        $themeData['story_follow_activity'] = $this->getFollowActivityTemplate();


        if( !isLogged())
        {
        	$themeData['story_activity_wrapper_hide'] = 'hide';
        }

        $themeData['user_sub_account_options'] = getSubAccountTemplate();
        

        //if posts voting feature is enabled to all posts type
        if ( $this->posts_votingfeature_setting !== false && $this->posts_votingfeature_setting == 999)
        {
        	//Vote Activities
        	$themeData['story_vote_activities'] = $this->getVoteActivitiesTemplate();
        }
        
        // Via
        $themeData['via'] = $this->getViaTemplate();

        // View all comments
        $themeData['view_all_comments_html'] = '';
        $commentsNum = $themeData['story_comments_num'];
        
        if ($this->view_all_comments == false) {
            
            if ($commentsNum > 1) {
            	$themeData['view_all_comments_html'] = \SocialKit\UI::view('story/view-all-comments-html');
            }

            $commentsNum = 1;
        }

        // Comments
        $themeData['comments'] = $this->getComments($commentsNum);

        // Comment Publisher Box
        $show_comment_publisher_box = true;
        $commentPublisherBox = '';

		if ($this->data['timeline']['type'] == "group")
        {
            if (! $this->timelineObj->isFollowedBy())
            {
                $show_comment_publisher_box = false;
            }
        } elseif ($this->data['timeline']['type'] == "community")
        {
            if (! $this->timelineObj->isFollowedBy())
            {
                $show_comment_publisher_box = false;
            }
        } elseif ($this->data['timeline']['type'] == "team")
        {
            if (! $this->timelineObj->isFollowedBy())
            {
                $show_comment_publisher_box = false;
            }
        }
        
        $IsTimelinePage = true;

        if (!isset($_GET["my-timeline"])) {
        	$IsTimelinePage = false;
        }
        
        if ($IsTimelinePage) {
	        if ($this->data['timeline']['type'] == "user")
	        {
	            if ($this->data['timeline']['comment_privacy'] == "following" && $this->data['timeline']['id'] != $user['id'])
	            {
	                if (! $this->timelineObj->isFollowing())
	                {
	                    $show_comment_publisher_box = false;
	                }
	            }

	        } elseif ($this->data['timeline']['type'] == "group")
	        {
	            if (! $this->timelineObj->isFollowedBy())
	            {
	                $show_comment_publisher_box = false;
	            }
	        } elseif ($this->data['timeline']['type'] == "team")
	        {
	            if (! $this->timelineObj->isFollowedBy())
	            {
	                $show_comment_publisher_box = false;
	            }
	        }
	    } 

        if ($show_comment_publisher_box == true)
        {
        	if ($this->timelineObj->isAdmin())
        	{
        		$commentPublisherBox = $this->getCommentBox($this->data['timeline']['id'],$this->data['id'],0);
        	}
        	else
        	{
        		$commentPublisherBox = $this->getCommentBox(0,$this->data['id'],0);
        	}
        }

        $themeData['comment_publisher_box'] = $commentPublisherBox;

        $this->getBasicLinkPreviewTemplateData();
        $this->getBlogPostLink();

        $emoticons = getEmoticons();
		$emoticonListsComments = '';

		if (is_array($emoticons)) {

	        foreach ($emoticons as $emo_code => $emo_icon) {
	            $emoticonListsComments .= '<img src="' . $emo_icon . '" width="16px" style="padding:0;margin:2px" onclick="addEmoToInput(\'' . $emo_code . '\',\'.comment-textarea textarea\');">';
	        }
	    }

	    $themeData['list_emoticons_comments'] = $emoticonListsComments;
	    
        $this->template = \SocialKit\UI::view('story/content');
        return $this->template;
	}

	public function getBasicTemplateData() {
		global $themeData, $config;

		$themeData['story_id'] = $this->data['id'];
		$themeData['location'] = $this->data['google_map_name'];
		$themeData['editable_text'] = $this->data['editable_text'];
		$themeData['story_activity_text'] = $this->data['activity_text'];
        $themeData['story_time'] = date('c', $this->data['time']);

        $themeData['story_timeline_id'] = $this->data['timeline']['id'];
        $themeData['story_timeline_url'] = $this->data['timeline']['url'];
        $themeData['story_timeline_username'] = $this->data['timeline']['username'];
        $themeData['story_timeline_name'] = $this->data['timeline']['name'];
        $themeData['story_timeline_thumbnail_url'] = $this->data['timeline']['thumbnail_url'];

		$share_story_id = '';
		if ($this->data['parent_id'] > 0) {
			$share_story_id = $this->data['parent_id'];
		} elseif($this->data['parent_id'] == 0) {
			$share_story_id = $this->data['id'];
		}
		$themeData['share_story_id'] = $share_story_id;
        
	}

	public function getBasicLinkPreviewTemplateData()
	{
		global $themeData, $config;

		$themeData['story_link_preview_html'] = '';

		$link_preview_data = $this->buzzer->get_latest_links(array(
			'id_post'=>$this->data['id'],
			'limit' => 1
		));

    	if ( isset($this->data['link_preview_data']['id']))
    	{
    		
    		$parsed_url = parse_url($this->data['link_preview_data']['url']);
    		$themeData['embeded_url'] = $this->createEmbedURL($this->data['link_preview_data']['url']);

    		/*$media_preview = $this->buzzer->get_latest_media_preview($this->data['link_preview_data']['id']);

			if($media_preview['media_id']!=NULL)
			{
				$this->data['link_preview_data']['image'] = SITE_URL.'/'.$media_preview['url'].'.'.$media_preview['extension'];
			}elseif ( !@getimagesize($this->data['link_preview_data']['image']))
    		{
    			$this->data['link_preview_data']['image'] = $config['theme_url']. '/images/no-image.jpg';
    		}*/

    		$this->data['link_preview_data']['title'] = htmlspecialchars_decode(addslashes($this->data['link_preview_data']['title']));
    		$this->data['link_preview_data']['title'] = strip_tags($this->data['link_preview_data']['title']);
    		$this->data['link_preview_data']['description'] = htmlspecialchars_decode(addslashes($this->data['link_preview_data']['description']));
    		$this->data['link_preview_data']['description'] = strip_tags($this->data['link_preview_data']['description']);

    		$article_base_slug = 'shares';
    		if( isset($this->data['recipient']['type']) && $this->data['recipient']['type'] == 'ads')
    		{
    			$article_base_slug = 'vote';
    		}

    		$this->data['link_preview_data']['description'] = $this->escapeObj->getEmoticons($this->data['link_preview_data']['description']);
		    $this->data['link_preview_data']['description'] = $this->escapeObj->getLinks($this->data['link_preview_data']['description']);
		    $this->data['link_preview_data']['description'] = $this->escapeObj->getHashtags($this->data['link_preview_data']['description']);
		    $this->data['link_preview_data']['description'] = $this->escapeObj->getMentions($this->data['link_preview_data']['description']);

    		$themeData['story_link_preview_url'] = '/'.$article_base_slug.'/'.$this->data['link_preview_data']['id'].'/'.create_slug(htmlspecialchars_decode($this->data['link_preview_data']['title']));
    		$themeData['story_link_preview_orig_url'] = $this->data['link_preview_data']['url'];
    		$themeData['story_link_preview_id'] = $this->data['link_preview_data']['id'];
    		$themeData['story_link_preview_image'] = $this->data['link_preview_data']['image'];
    		$themeData['story_link_preview_title'] = $this->data['link_preview_data']['title'];
    		$themeData['story_link_preview_description'] = $this->data['link_preview_data']['description'];
    		$themeData['story_link_preview_domain'] = $parsed_url['host'];

    		//lAURENCE: ADDED LANGUAGE
    		$story_link_languages = "";
					if($this->data['languages']!=NULL){
						foreach(explode(',',$this->data['languages']) as $vLang){
							$story_link_languages .= getLanguageInfo($vLang)['language'].", ";
						}
					}

			$themeData['story_link_languages'] = $story_link_languages?rtrim($story_link_languages, ', '):"N/A";

			global $conn;
			$sharer_languages = "";
			//query all languages
			$getLanguages = $conn->query('SELECT languages.id, languages.country_id, languages.`language`, languages.shortcode, countries.country FROM languages LEFT JOIN countries ON languages.country_id = countries.id');

			if($getLanguages){foreach($getLanguages as $lang => $langOption){
				$sharer_languages .= '<option value="'.$langOption['shortcode'].'" >'.$langOption['language'].'</option>';    
			}}

				$themeData['sharer_languages']	= "{".rtrim($sharer_languages,',')."}";
				$langoptions = '(<a href="#" id="suggestlang_'.$this->data['id'].'" data-title="Select Language">Suggest</a>)<div class="webui-popover-content" id="suggestlang_content_'.$this->data['id'].'">
				             <select name="languageOpt" class="form-control" id="suggestlang_form_'.$this->data['id'].'">
				             	<option value="">---Recommend Language---</option>
				             	'.$sharer_languages.'
				             </select></div>';

				//if user already recommended
			$query = $conn->query("select recommended from language_recommendation where post_id=".$this->data['id']." AND user_id=".$_SESSION['user_id']); 
			if($query->num_rows>=1){
				$langoptions = "<span class='' style='margin-top:6px;'>Recommended: ".getLanguageInfo($query->fetch_assoc()['recommended'])['language']."</span>";
			}
			$themeData['sharer_languages_suggested'] = $langoptions;


    		
    		if ( empty($this->data['link_preview_data']['description']))
    		{
    			$this->data['link_preview_data']['text'] = ($this->data['link_preview_data']['text'] != '.') ? $this->data['link_preview_data']['text'] : '' ;
				$this->data['link_preview_data']['description'] = $this->data['link_preview_data']['text'];
    		}

    		if ( strlen($this->data['link_preview_data']['title']) > $this->title_character_limit*2)
			{
				$this->data['link_preview_data']['title'] = trim(substr($this->data['link_preview_data']['title'], 0,$this->title_character_limit*2)).'...';
			}
			$this->data['link_preview_data']['title'] = remove_non_utf8_chars($this->data['link_preview_data']['title']);
			$themeData['story_link_preview_title'] = $this->data['link_preview_data']['title'];
			if ( strlen($this->data['link_preview_data']['description']) > $this->description_character_limit*2)
			{
				$this->data['link_preview_data']['description'] = trim(substr($this->data['link_preview_data']['description'], 0,$this->description_character_limit*2)).'...';
			}
			$this->data['link_preview_data']['description'] = remove_non_utf8_chars($this->data['link_preview_data']['description']);
			$this->data['link_preview_data']['description'] = $this->escapeObj->getEmoticons($this->data['link_preview_data']['description']);
		    $this->data['link_preview_data']['description'] = $this->escapeObj->getLinks($this->data['link_preview_data']['description']);
		    $this->data['link_preview_data']['description'] = $this->escapeObj->getHashtags($this->data['link_preview_data']['description']);
		    $this->data['link_preview_data']['description'] = $this->escapeObj->getMentions($this->data['link_preview_data']['description']);
		    
			$themeData['story_link_preview_description'] = $this->data['link_preview_data']['description'];
			$themeData['story_link_preview_id'] = $this->data['link_preview_data']['id'];

    		$themeData['story_link_padding_bottom'] = '30%';
    		$themeData['story_id'] = $this->data['id'];

    		// $story_link_preview_file = 'story/link-preview';

    		/*if ( isset($this->adznouncer['id']))
    		{
    			if ( $this->data['recipient']['id'] == $this->adznouncer['id'])
    			{*/
			$themeData['story_link_preview_shared_date'] = formatTimeReadable(strtotime($this->data['link_preview_data']['shared']));
			$themeData['story_promoted_icon'] = '';
			if ( $this->adverts->isPromoted($this->data['link_preview_data']['id']))
			{
				$themeData['story_promoted_icon'] = '<span class="voting-item-activity icon-bullhorn promoted-color"></span>';	
			}

			

			$story_link_preview_file  = 'story/link-preview-adznouncer';
    			/*}
    		}*/

    		$themeData['story_link_preview_html'] = \SocialKit\UI::view($story_link_preview_file);

    	}

	}
	public function getRecipientTemplate() {
		global $themeData;
		$themeData['story_recipient_html'] = '';
		
		if (isset($this->data['recipient']['id']))
		{
            $themeData['story_recipient_id'] = $this->data['recipient']['id'];
            $themeData['story_recipient_url'] = $this->data['recipient']['url'];
            $themeData['story_recipient_url_data_href'] = '/timeline/'. $this->data['recipient']['username'];
            $themeData['story_recipient_username'] = $this->data['recipient']['username'];
            $themeData['story_recipient_name'] = $this->data['recipient']['name'];
            $themeData['story_recipient_thumbnail_url'] = $this->data['recipient']['thumbnail_url'];

	        

				
        	if ( isset($this->data['link_preview_data']['id']))
        	{
        		
        		$article_base_slug = 'shares';
	    		if( isset($this->data['recipient']['type']) && $this->data['recipient']['type'] == 'ads')
	    		{
	    			$article_base_slug = 'vote';
	    		}
        		$themeData['story_recipient_url'] = '/'.$article_base_slug.'/'.$this->data['link_preview_data']['id'].'/'.create_slug(htmlspecialchars_decode($this->data['link_preview_data']['title']));
        		$themeData['story_recipient_url_data_href'] = $themeData['story_recipient_url'];
        	}
	        

            $themeData['story_recipient_html'] = \SocialKit\UI::view('story/recipient-html');
        }
	}

	public function getModerateButtonTemplate() {
		
		global $themeData, $user;

		$timelineObj = new \SocialKit\User();
        $timelineObj->setId($this->data['recipient']['id']);

        //if Moderate post is enabled
        // if ( !empty($this->data['recipient']['group_moderate_post'])) {

        	//current user is
        	//is a group admin on the timeline viewed and moderate post setting is enabled
        	//OR owner of an unapproved post
        	if ( ( !empty($this->data['recipient']['group_moderate_post']) && $timelineObj->isGroupAdmin())
				|| ( $this->data['timeline']['id'] == $user['id'] && empty($this->data['active'])) ) {

				$themeData['story_moderate_button_text'] = (!empty($this->data['active'])) ? 'Approved': 'Unapproved';
				$themeData['story_moderate_button_enabled'] = (!empty($this->data['recipient']['group_moderate_post'])) ? 'enabled': 'disabled';
				
				$themeData['story_moderate_button_class'] = (!empty($this->data['active'])) ? 'success': 'warning';
				$themeData['story_moderate_button_data'] = (!empty($this->data['active'])) ? 'data-moderate="0"': 'data-moderate="1"';

				if ( !empty($this->data['recipient']['group_moderate_post']) && $timelineObj->isGroupAdmin()) {
					$themeData['story_moderate_button_function'] = "onclick=\"SK_viewApproveUnapprove(".$this->data['id'].")\"";					
				}

	            return \SocialKit\UI::view('story/moderate-button');
	        }
        // }
		
	}
	public function getRemoveButtonTemplate() {
		if ($this->data['admin'] == true || isValidPostMasterAccount($this->id)) {
            return \SocialKit\UI::view('story/remove-button');
        }
	}

	public function getEditButtonTemplate() {

		global $themeData, $user;

		if ($this->data['timeline']['id'] == $_SESSION['user_id'] || isValidPostMasterAccount($this->id)) {

			//** if blog post return blogpost edit button
			if($this->is_blog_post($this->id)){
				$blogpost = $this->get_blog_post($this->id);
				$themeData['edit_blog_link'] = '//'.$_SERVER['SERVER_NAME'].'/blog/'.$user['username'].'/'.$blogpost['slug'].'/edit';
				$button = \SocialKit\UI::view('story/blog-edit-button');
			}else{
				$button = \SocialKit\UI::view('story/edit-button');
			}
        }

        return $button;
	}

	public function getReportButtonTemplate() {
		if ($this->data['admin'] != true && ! $this->isReported() && !isValidPostMasterAccount($this->id)) {
            return \SocialKit\UI::view('story/report-button');
        }
	}

	public function getLikeButtonTemplate() {
		if ($this->isLiked()) {
            return \SocialKit\UI::view('story/unlike-button');
        } else {
            return \SocialKit\UI::view('story/like-button');
        }
	}

	public function getShareButtonTemplate() {
			return \SocialKit\UI::view('story/share-button');
	   	}

	public function getFollowButtonTemplate() {
		if ($this->isFollowed()) {
	        return \SocialKit\UI::view('story/unfollow-button');
	    } else {
	        return \SocialKit\UI::view('story/follow-button');
	    }
	}

	public function getUpvoteButtonTemplate() {
		global $themeData;
		$themeData['story_upvote_voted'] = '';
		if ($this->isVoted() && $this->getCurrentVote() == 1) {
	        $themeData['story_upvote_voted'] = 'active';
	    }

	    return \SocialKit\UI::view('story/upvote-button');
	}

	public function getDownvoteButtonTemplate() {
		global $themeData;
		$themeData['story_downvote_voted'] = '';
		if ($this->isVoted() && $this->getCurrentVote() == -1) {
	        $themeData['story_downvote_voted'] = 'active';
	    }

	    return \SocialKit\UI::view('story/downvote-button');
	}

	public function getVoteButtonsTemplate() {
		global $themeData;
		$themeData['story_upvote_button'] = $this->getUpvoteButtonTemplate();
		$themeData['story_downvote_button'] = $this->getDownvoteButtonTemplate();

		return \SocialKit\UI::view('story/vote-buttons-container');
	}

	public function getControlButtonTemplate() {
		
		if (isLogged()) {
			global $themeData;

			// Remove Button
        	$themeData['story_moderate_button'] = $this->getModerateButtonTemplate();

        	// Remove Button
        	$themeData['story_remove_button'] = $this->getRemoveButtonTemplate();

        	// Edit Button
        	$themeData['story_edit_button'] = $this->getEditButtonTemplate();

        	// Report Button
        	$themeData['story_report_button'] = $this->getReportButtonTemplate();

		    // Like Button
        	$themeData['story_like_button'] = $this->getLikeButtonTemplate();

	        // Share Button
	        $themeData['story_share_button'] = $this->getShareButtonTemplate();

		    // Notification Button
		    $themeData['story_notification_button'] = $this->getFollowButtonTemplate();

		    return \SocialKit\UI::view('story/control-buttons');
        }
	}

	public function getTextTemplate() {
		global $themeData;

		if (! empty($this->data['text'])) {
			if($this->data['text'] == "no entry" || $this->data['text'] == '.') {
				$themeData['story_text'] = "";
			} else {
				$themeData['story_text'] = $this->data['text'];
				if (isset($this->data['link_preview_data']['id'])) {
					$themeData['story_text'] = ($this->data['text'] == '.') ? '' : $this->data['text'];
				} 
				if($this->convertURLToEmbeded($this->data['editable_text'], $this->data['post_id'])!=""){
					//find urls in text and replace it with embed
					$themeData['story_text'] = $this->convertURLToEmbeded($this->data['editable_text'], $this->data['post_id']);
				}

			}
        	return \SocialKit\UI::view('story/story-text');
        }
	}

	public function getMediaTemplate()
	{
		global $themeData;

		if ($this->data['media'] != false)
		{
        	if ($this->data['media']['type'] == "photos")
        	{
        		$photo_class = 'width-' . $this->data['media']['num'];
	            
	            if ($this->data['media']['num'] >= 3)
	            {
	                $photo_class = 'width-3';
	            }
	            
	            $listPhotos = '';

	            if (is_array($this->data['media']['each']))
	            {
	            	foreach ($this->data['media']['each'] as $photo)
	            	{
		                $themeData['list_photo_class'] = $photo_class;
		                $themeData['list_photo_url'] = $photo['url'];
		                $themeData['list_photo_story_id'] = $photo['post_id'];
		                $themeData['list_photo_album_id'] = $photo['album_id'];

		                $listPhotos .= \SocialKit\UI::view('story/list-photo-each');
		            }
	            }

	            $themeData['list_photos'] = $listPhotos;
	            return \SocialKit\UI::view('story/photos-html');

        	} elseif ($this->data['media']['type'] == "soundcloud") {
        		$themeData['media_url'] = $this->data['media']['each'][0]['url'];
        		return \SocialKit\UI::view('story/soundcloud-html');

        	} elseif ($this->data['media']['type'] == "youtube") {

        		$themeData['media_id'] = $this->data['media']['each'][0]['id'];
        		return \SocialKit\UI::view('story/youtube-html');

        	}
        } elseif ($this->data['location'] != false)
        {
        	$themeData['story_location_name'] = $this->data['location']['name'];
        	return \SocialKit\UI::view('story/map-html');
        }
	}

	public function getLocationTemplate() {
		if (! empty ($this->data['location']))
		{
			$themeData['story_location_name'] = $this->data['location']['name'];
        	return \SocialKit\UI::view('story/location-html');
        }
	}

	public function getLikeActivityTemplate() {
		global $themeData;

		$themeData['story_likes_num'] = $this->numLikes();
        return \SocialKit\UI::view('story/like-activity');
	}

	public function getCommentActivityTemplate() {
		global $themeData;

		$themeData['story_comments_num'] = $this->numComments();
        return \SocialKit\UI::view('story/comment-activity');
	}

	public function getShareActivityTemplate() {
		global $themeData;

		$themeData['story_shares_num'] = $this->numShares();
        return \SocialKit\UI::view('story/share-activity');
	}

	public function getFollowActivityTemplate() {
		global $themeData;

		$themeData['story_followers_num'] = $this->numFollowers();
        return \SocialKit\UI::view('story/follow-activity');
	}

	public function getUpvoteActivityTemplate() {
		global $themeData;

		$themeData['story_upvotes_num'] = $this->numVotes(0, 1, true);

        return \SocialKit\UI::view('story/upvote-activity');
	}

	public function getDownvoteActivityTemplate() {
		global $themeData;

		$themeData['story_downvotes_num'] = $this->numVotes(0, -1, true);

        return \SocialKit\UI::view('story/downvote-activity');
	}

	public function getVoteActivitiesTemplate() {
		global $themeData;

		$themeData['story_upvote_activity'] = $this->getUpvoteActivityTemplate();
		$themeData['story_downvote_activity'] = $this->getDownvoteActivityTemplate();

		return \SocialKit\UI::view('story/vote-activities-container');
	}

	public function getViaTemplate() {
		global $themeData;

		if (! empty ($this->via)) {
        	$themeData['story_via_id'] = $this->via['id'];
        	$themeData['story_via_url'] = $this->via['url'];
        	$themeData['story_via_username'] = $this->via['username'];
        	$themeData['story_via_name'] = $this->via['name'];
        	
        	if ($this->via['type'] == "like") {
        		$themeData['via_html'] = \SocialKit\UI::view('story/via-like-html');

        	} elseif ($this->via['type'] == "share") {
        		$themeData['via_html'] = \SocialKit\UI::view('story/via-share-html');

        	} elseif ($sk['story']['via_type'] == "tag") {
        		$themeData['via_html'] = \SocialKit\UI::view('story/via-tag-html');
        	}

        	return \SocialKit\UI::view('story/via-html');
        }
	}

	public function getLikesTemplate($offset=0,$limit=0) {
		global $themeData, $config;
		$i = 0;
		$listLikes = '';

		$themeData['story_id'] = $this->id;
		$likesObj = $this->getLikes($offset,$limit);

		if( $offset > 0 ) {

			foreach ($likesObj as $likerId)
	        {
	        	$likerObj = new \SocialKit\User();
	        	$likerObj->setId($likerId);
	        	$liker = $likerObj->getRows();

	            $themeData['list_liker_id'] = $liker['id'];
	            $themeData['list_liker_url'] = $liker['url'];
	            $themeData['list_liker_username'] = $liker['username'];
	            $themeData['list_liker_name'] = $liker['name'];
	            $themeData['list_liker_thumbnail_url'] = $liker['thumbnail_url'];

	            $themeData['list_liker_button'] = $likerObj->getFollowButton();

	            $listLikes .= \SocialKit\UI::view('story/list-view-likes-each');
	        }

	        return $listLikes;

		} else {

			foreach ($likesObj as $likerId)
	        {
	        	$likerObj = new \SocialKit\User();
	        	$likerObj->setId($likerId);
	        	$liker = $likerObj->getRows();

	            $themeData['list_liker_id'] = $liker['id'];
	            $themeData['list_liker_url'] = $liker['url'];
	            $themeData['list_liker_username'] = $liker['username'];
	            $themeData['list_liker_name'] = $liker['name'];
	            $themeData['list_liker_thumbnail_url'] = $liker['thumbnail_url'];

	            $themeData['list_liker_button'] = $likerObj->getFollowButton();

	            $listLikes .= \SocialKit\UI::view('story/list-view-likes-each');
	            $i++;
	        }

	        if ($i < 1) {
	            $listLikes .= \SocialKit\UI::view('story/view-likes-none');
	        }
	        
	        $themeData['list_likes'] = $listLikes;
	        $themeData['hex_loader'] = '<img class="hex-loader-'.$this->id.'" style="display:none;width:25px;" src="'.$config['theme_url'].'/images/hex-loader-colored.gif">';
	        return \SocialKit\UI::view('story/view-likes');

		}

	}

	public function getSharesTemplate() {
		global $themeData;
		$i = 0;
		$listShares = '';

        foreach ($this->getShares() as $sharerId)
        {
            $sharerObj = new \SocialKit\User();
        	$sharerObj->setId($sharerId);
        	$sharer = $sharerObj->getRows();

            $themeData['list_sharer_id'] = $sharer['id'];
            $themeData['list_sharer_url'] = $sharer['url'];
            $themeData['list_sharer_username'] = $sharer['username'];
            $themeData['list_sharer_name'] = $sharer['name'];
            $themeData['list_sharer_thumbnail_url'] = $sharer['thumbnail_url'];

            $themeData['list_sharer_button'] = $sharerObj->getFollowButton();

            $listShares .= \SocialKit\UI::view('story/list-view-shares-each');
            $i++;
        }

        if ($i < 1) {
            $listShares .= \SocialKit\UI::view('story/view-shares-none');
        }

        $themeData['list_shares'] = $listShares;
        return \SocialKit\UI::view('story/view-shares');
	}

	public function getApproveUnapproveTemplate() {
		global $themeData;

		$themeData['story_moderate_button_text'] = empty($this->data['active']) ? 'Approve' : 'Unapprove';
		$themeData['story_moderate_button_val'] = empty($this->data['active']) ? 1 : 0;

		return \SocialKit\UI::view('story/view-approve-unapprove');
	}

	public function getRemoveTemplate() {
		return \SocialKit\UI::view('story/view-remove');
	}

	public function getRemoveTemplatePage() {
		return \SocialKit\UI::view('more/view-remove');
	}

	public function getRemoveTemplateGroup() {
		return \SocialKit\UI::view('more/view-remove-group');
	}

	public function getRemoveTemplateCommunity() {
		return \SocialKit\UI::view('more/view-remove-community');
	}

	public function setPageId($page_id) {
		$this->data['page_id'] = $page_id;

		global $themeData;

		$themeData['page_id'] = $this->data['page_id'];

	}

	public function setCommunityId($community_id) {
		$this->data['community_id'] = $community_id;

		global $themeData;

		$themeData['community_id'] = $this->data['community_id'];

	}

	public function getEditTemplate() {
		return \SocialKit\UI::view('story/view-edit');
	}

	public function getStoryShareTemplate()	{

		global $themeData;
		
		$this->getTemplate();

		$emoticons = getEmoticons();
		$emoticonLists = '';

		if (is_array($emoticons)) {

			foreach ($emoticons as $emo_code => $emo_icon) {
				$emoticonLists .= '<img src="' . $emo_icon . '" width="16px" onclick="addEmoToInput(\'' . $emo_code . '\',\'.window-wrapper textarea\');" style="margin:auto 3px">';
			}
		}
		$themeData['list_emoticons_share'] = $emoticonLists;
		return \SocialKit\UI::view('story/view-share-post');
	}
	public function getRowsOriginal() {

		if($this->data['parent_id'] > 0) {
			$query = $this->getConnection()->query("SELECT * FROM " . DB_POSTS . " WHERE id=" . $this->data['parent_id'] . " AND active=1");

			if ($query->num_rows == 1) {
				$this->post_origin = $query->fetch_array(MYSQLI_ASSOC);
				$userObj = new \SocialKit\User($this->getConnection());
				$this->post_origin['timeline'] = $userObj->getById($this->post_origin['timeline_id']);
				unset($this->post_origin['timeline_id']);

				// Get recipient, if available
				$this->post_origin['recipient'] = $this->getOriginalRecipient();

				// Emoticons
				$this->post_origin['original_text'] = $this->post_origin['text'];
				$this->post_origin['editable_text'] = str_replace("<br>", '&#10;' , trim($this->post_origin['text']));
				$this->post_origin['editable_text'] = $this->escapeObj->getEditHashtags($this->post_origin['editable_text']);
				$this->post_origin['editable_text'] = $this->escapeObj->getEditMentions($this->post_origin['editable_text']);
				$this->post_origin['editable_text'] = $this->escapeObj->getEditLinks($this->post_origin['editable_text']);
				$this->post_origin['text'] = $this->escapeObj->getEmoticons($this->post_origin['text']);
				$this->post_origin['text'] = $this->escapeObj->getLinks($this->post_origin['text']);
				$this->post_origin['text'] = $this->escapeObj->getHashtags($this->post_origin['text']);
				$this->post_origin['text'] = $this->escapeObj->getMentions($this->post_origin['text']);


				// Media, if available
				$this->post_origin['media'] = $this->getOriginalMedia();

				
				// Location
				$this->post_origin['location'] = $this->getOriginalLocation();

				if ( isset($this->post_origin['id']))
				{
					//Link preview if any
					$this->post_origin['link_preview_data'] = $this->buzzer->get_latest_links(array(
						'id_post' => $this->post_origin['id'],
						'limit' => 1
					));

					if ( $this->post_origin['link_preview_data'])
					{
						$this->post_origin['link_preview_data'] = array_shift($this->post_origin['link_preview_data']);
					}
				}

				$this->getOriginalTemplateData();
				
				return $this->post_origin;
			}

		} else {
			return false;
		}
		
	}

	public function getSharedTemplate(){
		global $themeData;
		if(!$this->getTemplate()) return false;
		$themeData['original_story_html'] = $this->getOriginalStoryTemplate();
		$this->template = \SocialKit\UI::view('story/content-shared');
		return $this->template;
	}

	public function getSharedTemplateModal(){
		global $themeData;
		if(!$this->getTemplate()) return false;
		$themeData['original_story_html'] = $this->getOriginalStoryTemplate();
		$this->template = \SocialKit\UI::view('story/content-shared-modal');
		return $this->template;
	}

	public function getOriginalStoryTemplate(){

		//if (! is_array($this->post_origin))
		//{
			$this->getRowsOriginal();

		//}

		if (! isset($this->post_origin['id']))
		{
			return false;
		}

		global $themeData;

		/*Original Media */
		$themeData['original_media_html'] = $this->getOriginalMediaTemplate();

		// Original Template Data
		$this->getOriginalTemplateData();

		// Recipient Template Data
		$this->getOriginalRecipientTemplate();

		/* Location */
		$themeData['original_story_location_name'] = '';
		if (! empty ($this->post_origin['location']))
		{
			$themeData['original_story_location_name'] = $this->post_origin['location']['name'];
		}

		$themeData['original_story_location_html'] = $this->getOriginalLocationTemplate();


		/* Text */
		$themeData['original_story_text_html'] = $this->getOriginalText();

		return \SocialKit\UI::view('story/story-original/content');
	}

	public function getOriginalMedia() {

		{
			$get = false;

			if ($this->post_origin['media_id'] > 0) //getMedia start
			{
				$get = array();
				$get['type'] = 'photos';
				$mediaObj = new \SocialKit\Media();
				$media = $mediaObj->getById($this->post_origin['media_id']);

				if ($media['type'] == "photo") {
					$get = $media;
					$get['type'] = 'photos';
					$get['each'][0]['url'] = SITE_URL . '/' . $get['each'][0]['url'] . '.' . $get['each'][0]['extension'];
					$get['each'][0]['post_id'] = $this->post_origin['id'];
					$get['each'][0]['post_url'] = smoothLink('index.php?tab1=story&id=' . $this->post_origin['id']);
				} elseif ($media['type'] == "album") {
					$get = $media;
					$get['type'] = 'photos';
					$get['each'] = array();

					if ($get['temp'] == 0) {
						for ($each_i = 0; $each_i < 6; $each_i++) {
							if (isset($media['each'][$each_i]) && is_array($media['each'][$each_i])) {
								$get['each'][$each_i] = $media['each'][$each_i];
								$get['each'][$each_i]['url'] = SITE_URL . '/' . $media['each'][$each_i]['url'] . '_100x100.' . $media['each'][$each_i]['extension'];
							}
						}
					} else {
						$get['each'] = $media['each'];

						foreach ($media['each'] as $each_i => $each_v) {
							$get['each'][$each_i]['url'] = SITE_URL . '/' . $each_v['url'] . '_100x100.' . $each_v['extension'];
						}
					}
				}

				unset($this->post_origin['media_id']);
			}
			elseif
			(!empty($this->post_origin['soundcloud_uri']))
			{
				$get = array();
				$get['type'] = 'soundcloud';
				$get['each'][]['url'] = $this->post_origin['soundcloud_uri'];
				unset($this->post_origin['soundcloud_uri']);
			}
			elseif (!empty($this->post_origin['youtube_video_id']))
			{
				$get = array();
				$get['type'] = 'youtube';
				$get['each'][]['id'] = $this->post_origin['youtube_video_id'];
				unset($this->post_origin['youtube_video_id']);
			}
			return $get;
		}//getMedia End
	}

	public function getOriginalMediaTemplate() {
		global $themeData;

		if ($this->post_origin['media'] != false)
		{
			if ($this->post_origin['media']['type'] == "photos")
			{
				$photo_class = 'width-' . $this->post_origin['media']['num'];

				if ($this->post_origin['media']['num'] >= 3)
				{
					$photo_class = 'width-3';
				}

				$listPhotos = '';

				if (is_array($this->post_origin['media']['each']))
				{
					foreach ($this->post_origin['media']['each'] as $photo)
					{
						$themeData['original_list_photo_class'] = $photo_class;
						$themeData['original_list_photo_url'] = $photo['url'];
						$themeData['original_list_photo_story_id'] = $photo['post_id'];

						$listPhotos .= \SocialKit\UI::view('story/story-original/list-photo-each');
					}
				}

				$themeData['original_list_photos'] = $listPhotos;
				return \SocialKit\UI::view('story/story-original/photos-html');

			} elseif ($this->post_origin['media']['type'] == "soundcloud") {
				$themeData['original_media_url'] = $this->post_origin['media']['each'][0]['url'];
				return \SocialKit\UI::view('story/story-original/soundcloud-html');

			} elseif ($this->post_origin['media']['type'] == "youtube") {

				$themeData['original_media_id'] = $this->post_origin['media']['each'][0]['id'];
				return \SocialKit\UI::view('story/story-original/youtube-html');

			}
		} elseif ($this->post_origin['location'] != false) {
			$themeData['original_story_location_name'] = $this->post_origin['location']['name'];
			return \SocialKit\UI::view('story/story-original/map-html');
		}
	}
	public function getOriginalLocation() {
		$location = false;

		if (! empty($this->post_origin['google_map_name'])) {
			$location = array(
				'name' => $this->post_origin['google_map_name']
			);
		}

		return $location;
	}
	public function getOriginalLocationTemplate() {
		if (! empty ($this->post_origin['location']))
		{
			$themeData['original_story_location_name'] = $this->post_origin['location']['name'];
			return \SocialKit\UI::view('story/story-original/location-html');
		}
	}

	public function getOriginalRecipient() {

		{
			$original_recipient = false;
			if ($this->post_origin['recipient_id'] > 0) {
				$recipientObj = new \SocialKit\User($this->getConnection());
				$original_recipient = $recipientObj->getById($this->post_origin['recipient_id']);

			}

			unset($this->post_origin['recipient_id']);
			return $original_recipient;

		}
	}
	public function getOriginalRecipientTemplate() {
			global $themeData;
		$themeData['original_story_recipient_html'] = '';
		if (isset($this->post_origin['recipient']['id']))
		{
			$themeData['original_story_recipient_id'] = $this->post_origin['recipient']['id'];
			$themeData['original_story_recipient_url'] = $this->post_origin['recipient']['url'];
			$themeData['original_story_recipient_username'] = $this->post_origin['recipient']['username'];
			$themeData['original_story_recipient_name'] = $this->post_origin['recipient']['name'];
			//$themeData['original_story_recipient_thumbnail_url'] = $this->post_origin['recipient']['thumbnail_url'];

			$themeData['original_story_recipient_html'] = \SocialKit\UI::view('story/story-original/original-recipient-html');
		}
	}
	public function getOriginalTemplateData() {
		global $themeData, $config;
		// Basic Template Data
		$themeData['original_story_id'] = $this->post_origin['id'];
		$themeData['original_location'] = $this->post_origin['google_map_name'];
		$themeData['original_editable_text'] = $this->post_origin['editable_text'];
		//$themeData['original_story_activity_text'] = $this->data['activity_text'];
		$themeData['original_story_time'] = date('c', $this->post_origin['time']);

		$themeData['original_story_timeline_id'] = $this->post_origin['timeline']['id'];
		$themeData['original_story_timeline_url'] = $this->post_origin['timeline']['url'];
		$themeData['original_story_timeline_username'] = $this->post_origin['timeline']['username'];
		$themeData['original_story_timeline_name'] = $this->post_origin['timeline']['name'];
		$themeData['original_story_timeline_thumbnail_url'] = $this->post_origin['timeline']['thumbnail_url'];

		$themeData['original_story_link_preview_html'] = '';

		if ( isset($this->post_origin['link_preview_data']['id']))
		{

			$parsed_url = parse_url($this->post_origin['link_preview_data']['url']);
			$media_preview = $this->buzzer->get_latest_media_preview($this->post_origin['link_preview_data']['id']);

			if($media_preview['media_id']!=NULL)
			{
				$this->post_origin['link_preview_data']['image'] = SITE_URL.'/'.$media_preview['url'].'.'.$media_preview['extension'];
			}

			/*if ( !@getimagesize($this->post_origin['link_preview_data']['image']))
			{
				$this->post_origin['link_preview_data']['image'] = $config['theme_url']. '/images/no-image.jpg';
			}*/ //darwin.28aug2017: we should not do getimagesize to a remote url (via http/s) if we have a local copy of it. doing so is very slow

			$this->post_origin['link_preview_data']['title'] = htmlspecialchars_decode(addslashes($this->post_origin['link_preview_data']['title']));
			$this->post_origin['link_preview_data']['title'] = strip_tags($this->post_origin['link_preview_data']['title']);
			$this->post_origin['link_preview_data']['description'] = htmlspecialchars_decode(addslashes($this->post_origin['link_preview_data']['description']));
			$this->post_origin['link_preview_data']['description'] = strip_tags($this->post_origin['link_preview_data']['description']);

			$article_base_slug = 'shares';
    		if( isset($this->post_origin['recipient']['type']) && $this->post_origin['recipient']['type'] == 'ads')
    		{
    			$article_base_slug = 'vote';
    		}

    		$this->data['link_preview_data']['description'] = $this->escapeObj->getEmoticons($this->data['link_preview_data']['description']);
		    $this->data['link_preview_data']['description'] = $this->escapeObj->getLinks($this->data['link_preview_data']['description']);
		    $this->data['link_preview_data']['description'] = $this->escapeObj->getHashtags($this->data['link_preview_data']['description']);
		    $this->data['link_preview_data']['description'] = $this->escapeObj->getMentions($this->data['link_preview_data']['description']);

			$themeData['original_story_link_preview_url'] = '/'.$article_base_slug.'/'.$this->post_origin['link_preview_data']['id'].'/'.create_slug(htmlspecialchars_decode($this->post_origin['link_preview_data']['title']));
			$themeData['original_story_link_preview_image'] = $this->post_origin['link_preview_data']['image'];
			$themeData['original_story_link_preview_title'] = $this->post_origin['link_preview_data']['title'];
			$themeData['original_story_link_preview_description'] = $this->post_origin['link_preview_data']['description'];
			$themeData['original_story_link_preview_domain'] = $parsed_url['host'];

			if ( strlen($this->post_origin['link_preview_data']['title']) > 31)
			{
				$this->post_origin['link_preview_data']['title'] = trim(substr($this->post_origin['link_preview_data']['title'], 0,31)).'...';
			}
			$themeData['original_story_link_preview_title'] = $this->post_origin['link_preview_data']['title'];
			if ( strlen($this->post_origin['link_preview_data']['description']) > 56)
			{
				$this->post_origin['link_preview_data']['description'] = trim(substr($this->post_origin['link_preview_data']['description'], 0,56)).'...';
			}

			$this->data['link_preview_data']['description'] = $this->escapeObj->getEmoticons($this->data['link_preview_data']['description']);
		    $this->data['link_preview_data']['description'] = $this->escapeObj->getLinks($this->data['link_preview_data']['description']);
		    $this->data['link_preview_data']['description'] = $this->escapeObj->getHashtags($this->data['link_preview_data']['description']);
		    $this->data['link_preview_data']['description'] = $this->escapeObj->getMentions($this->data['link_preview_data']['description']);
		    
			$themeData['original_story_link_preview_description'] = $this->post_origin['link_preview_data']['description'];

			$themeData['original_story_link_padding_bottom'] = '30%';
			$themeData['original_story_link_preview_html'] = \SocialKit\UI::view('story/story-original/link-preview');

		}
	}

	public function getOriginalText() {
		global $themeData;

		if (! empty($this->post_origin['text'])) {
			$themeData['original_story_text'] = $this->post_origin['text'];
			if ( isset($this->post_origin['link_preview_data']['id']))
			{
				$themeData['original_story_text'] = ($this->post_origin['text'] == '.') ? '' : $this->post_origin['text'];
			}

			return \SocialKit\UI::view('story/story-original/story-text');
		}

	}
	function getCount()
	{
		$query = $this->getConnection()->query("SELECT COUNT(parent_id) AS count FROM " . DB_POSTS . " WHERE parent_id=$this->data['parent_id'] AND active=1");
		$fetch = $query->fetch_array(MYSQLI_ASSOC);
		return $fetch['count'];
	}

	public function setPhotosNew($a='',$albumID = 0)
    {
        global $user;
        if (is_array($a))
        {
            $this->photos = $a;
            $count = count($this->photos['name']);
            if ($_SESSION['images_per_day'] != 0) {
                $pictures_count = $this->getConnection()->query("SELECT `id` FROM " . DB_MEDIA . " WHERE `timeline_id` = '" . $user['id'] . "' AND extension <> 'none' AND DATE(`timestamp`) = CURRENT_DATE");

                // Limit the post images to 25 per user per day
                if($pictures_count->num_rows > $_SESSION['images_per_day'])
                {
                    return ['status' => 201,'error'=>'Posting images limit exceeded'];
                }
            }

            $media_collection = []; //store all uploaded images details
            if ($albumID == 0) {
                $query = $this->getConnection()->query("INSERT INTO " . DB_MEDIA . " (timeline_id,active,name,type) VALUES (" . $this->timelineId . ",1,'temp_" . generateKey() . "','album')");
                $albumID = $query ? $this->getConnection()->insert_id : $albumID;
            }
            
            $this->mediaId = $albumID;
            $this->mediaExists = true;
            $_SESSION['album_id'] = $albumID;

            for ($i = 0; $i < $count; $i++){
                $params = array(
                    'tmp_name' => $this->photos['tmp_name'][$i],
                    'name' => $this->photos['name'][$i],
                    'size' => $this->photos['size'][$i]
                );
                $media = registerMedia($params, $this->mediaId);

                if(array_key_exists('status', $media) && $media['status'] == 201)
                {
                    return $media;
                }
                $query2 = $this->getConnection()->query("INSERT INTO " . DB_POSTS . " (active,google_map_name,hidden,media_id,time,timeline_id,recipient_id) VALUES (1,'" . $this->mapName . "',1," . $media['id'] . "," . time() . "," . $this->timelineId . "," . $this->recipientId . ")");
                if ($query2)
                {
                    $mediaPostId = $this->getConnection()->insert_id;
                    $this->getConnection()->query("UPDATE " . DB_POSTS . " SET post_id=id WHERE id=$mediaPostId");
                    $this->getConnection()->query("UPDATE " . DB_MEDIA . " SET post_id=".$mediaPostId." WHERE id=" . $media['id']);
                }
                $media_collection[] = $media;
            }
            return ['collection'=>$media_collection,'album_id'=>$albumID, 'prev_uploaded'=>$this->prev_uploaded];
        }
        return ['status'=>200, 'error'=>'not array']; //empty
    }

	/*could be album id for multiple upload or media id for single upload*/
    public function setMedia($media_id=0)
    {
        $this->mediaId = $media_id;
        $this->mediaExists = true;
    }

    public function setTimeline($id=0)
    {
        $this->timelineId = (int) $id;
        $this->timelineObj = new \SocialKit\User();
        $this->timelineObj->setId($this->timelineId);
        
        if (! $this->timelineObj->isAdmin())
        {
            $this->timeline = $this->timelineObj->getRows();
        }
    }

	public function setTimelineId($id) {
        $this->timelineId = (int) $id;
    }

    public function isOwner($id=0)
    {
    	if ( !empty($id))
    	{
    		$this->id = (int) $id;
    	}

    	if ( empty($this->id))
    	{
    		return false;
    	}
    	
    	if ( empty($this->timelineId))
    	{
    		return false;
    	}

    	$storyData = $this->getRows();

    	if ( isset($storyData['timeline']['id'])
    		&& $storyData['timeline']['id'] == $this->timelineId)
    	{
    		return true;
    	}

    	return false;

    }

    public function getTaggedTeam( $filters)
    {
    	$sql = "SELECT football_team_id";
		if ( isset($filters['total_count']) && $filters['total_count'] === true)
		{
			$sql = "SELECT COUNT(id) as total_count";
		}
		$sql .= " FROM ".DB_POST_LINK_PREVIEW_TAG_FOOTBALL_TEAM." WHERE id <> 0";

		if ( isset($filters['id'])
			&& is_numeric($filters['id']))
		{
			$filters['id'] = (int) $filters['id'];
			$sql .= ' AND id='.$filters['id'];
		}

		if ( isset($filters['post_id'])
			&& is_numeric($filters['post_id']))
		{
			$filters['post_id'] = (int) $filters['post_id'];
			$sql .= ' AND post_id='.$filters['post_id'];
		}

		if ( isset($filters['url_id'])
			&& is_numeric($filters['url_id']))
		{
			$filters['url_id'] = (int) $filters['url_id'];
			$sql .= ' AND url_id='.$filters['url_id'];
		}

		if ( !empty($filters['limit']))
		{
			$limit = (int) $filters['limit'];
			$sql .= " LIMIT $limit";
		}

		if ( !empty($filters['offset']))
		{
			$offset = (int) $filters['offset'];
			$sql .= " OFFSET $offset";
		}
		
		
		$query = $this->conn->query($sql);


		//get total count
		if ( isset($filters['total_count']) && $filters['total_count'] === true)
		{
			$row = $query->fetch_array(MYSQLI_ASSOC);

			return ( isset($row['total_count']) && $row['total_count'] > 0) ? $row['total_count'] : 0; 
		}
		else
		{
			$teams = array();

			if ( $query->num_rows)
			{
				while($row = $query->fetch_array(MYSQLI_ASSOC))
				{
					$teams[] = $row;
				}
			}

			return $teams;	
		}
    }

    public function is_blog_post( $story_id)
    {
    	if ( !is_numeric($story_id)) { return false; }
    	if ( empty($story_id)) { return false; }

    	$sql = "SELECT id_post FROM ".DB_BLOG_POSTS." WHERE id_post={$story_id} LIMIT 1";

    	$query = $this->conn->query($sql);

    	if ( $query)
    	{	
    		$row = $query->fetch_array(MYSQLI_ASSOC);

    		return (isset($row['id_post'])) ? true : false;
    	}

    	return false;
    }

    public function get_blog_post( $story_id)
    {
    	if ( !is_numeric($story_id)) { return false; }
    	if ( empty($story_id)) { return false; }

    	$sql = "SELECT * FROM ".DB_BLOG_POSTS." WHERE id_post={$story_id} LIMIT 1";

    	$query = $this->conn->query($sql);

    	if ( $query)
    	{	
    		$row = $query->fetch_array(MYSQLI_ASSOC);

    		return $row;
    	}

    	return false;
    }

    public function getBlogPostLink()
	{
		global $themeData, $config;

		$themeData['story_blog_post_link_html'] = '';
		$themeData['story_blog_post_preview']   = '';
		if ( $this->id > 0)
		{
			if ( $this->is_blog_post($this->id))
			{
				$blog_post_info = $this->get_blog_post($this->id);

				if ( $blog_post_info)
				{
					
					$published_date = date('l, F d, Y',strtotime($blog_post_info['created']));

					$timelineObj = new \SocialKit\User();
					$blogPostObj = new \SocialKit\BlogPost();
			        $timelineObj->setId($blog_post_info['id_author']);
			        $timeline = $timelineObj->getRows();
			       
			        
					$themeData['story_blog_post_link_html'] = '<div style="padding: 10px;"><h2><a href="/blog/'.$timeline['username'].'/'.$blog_post_info['slug'].'">Article: '.$blog_post_info['title'].'</a></h2><p>Published on: '.$published_date.'</p></div>';	
					$themeData['story_blog_post_preview'] = $blogPostObj->get_blog_images_from_string($blog_post_info['content'],true,'img-fluid');
				}
				
			}
		}

	}

	public function setShowTimelinePostsForce( $bool)
	{

		if ( !is_bool($bool)) { return false; }
		$this->showTimelinePostsForce = $bool;
	}

	public function createEmbedURL($text){
		//** this is to check if text is youtube
		$regex_pattern = "/(youtube.com|youtu.be)\/(watch)?(\?v=)?(\S+)?/";
		$match;
		if(preg_match($regex_pattern, $text, $match)){
			 preg_match("/\s*[a-zA-Z\/\/:\.]*youtu(be.com\/watch\?v=|.be\/)([a-zA-Z0-9\-_]+)([a-zA-Z0-9\/\*\-\_\?\&\;\%\=\.]*)/",$text, $id);
			 $url = 'https://www.youtube.com/embed/'.$id[2].'?rel=0&autoplay=1';
		}

		//** this is to check if text is vimeo
		$regex_pattern = "/(vimeo.com)/";
		$match;
		if(preg_match($regex_pattern, $text, $match)){
			 preg_match('#https?://(www\.)?vimeo\.com/(\d+)#',$text, $id); 			 
		     $url = "https://player.vimeo.com/video/".$id[2]."?autoplay=1";
		}

		//** this is to check if text is dailymotion
		$regex_pattern = "/(dai.ly)/";
		$match;
		if(preg_match($regex_pattern, $text, $match)){
			 preg_match('#https?://(www\.)?dai\.ly/([a-zA-Z0-9\-_]+)#',$text, $id); 
		     $url = "//www.dailymotion.com/embed/video/".$id[2]."?autoPlay=1";		      
		}

		//** this is to check if text is dailymotion
		$regex_pattern = "/(dailymotion.com)/";
		$match;
		if(preg_match($regex_pattern, $text, $match)){
			 preg_match('#https?://(www\.)?dailymotion\.com/([a-zA-Z0-9\-_]+)/([a-zA-Z0-9\-_]+)#',$text, $id);
			 $url = "//www.dailymotion.com/embed/video/".$id[3]."?autoPlay=1";
		}

		//** this is to check if text is facebook
		$regex_pattern = "/(facebook.com)/";
		$match;

		if(preg_match($regex_pattern, $text, $match)){
		preg_match('#https?://(www\.)?facebook\.com\/([a-zA-Z0-9.\-_]+)\/([a-zA-Z0-9.\-_]+)\/([a-zA-Z0-9.\-_]+)#',$text, $id);
		$info = json_decode(@file_get_contents("https://graph.facebook.com/".$id[4]."?fields=description,icon,title,permalink_url,format,picture,source&access_token=1125938517542774|hXTYufukq04f5yYim3skJ8EihM0"), true); 

		if($info!=null && $info!="" ):
		     $url = 'https://www.facebook.com/plugins/video.php?href=https%3A%2F%2Fwww.facebook.com%2F'.urlencode($info['permalink_url']).'&width=130&autoplay=1';
		 else:
 			 $url = 'https://www.facebook.com/plugins/video.php?href=https%3A%2F%2Fwww.facebook.com%2F'.$id[2].'%2F'.$id[3].'%2F'.$id[4].'%2F&show_text=0';
		 endif;
		}

		return $url;
	}

	public function convertURLToEmbeded($text, $post_id){

		$story_text = "";

		//** this is to check if text is youtube
		$regex_pattern = "/(youtube.com|youtu.be)\/(watch)?(\?v=)?(\S+)?/";
		$match;
		if(preg_match($regex_pattern, $text, $match)){
			 preg_match("/\s*[a-zA-Z\/\/:\.]*youtu(be.com\/watch\?v=|.be\/)([a-zA-Z0-9\-_]+)([a-zA-Z0-9\/\*\-\_\?\&\;\%\=\.]*)/",$text, $id);
			 $info = json_decode(file_get_contents("https://www.googleapis.com/youtube/v3/videos?part=snippet%2CcontentDetails%2Cstatistics&id=".$id[2]."&key=AIzaSyDuxM64-OwCmXxKolbgyfIYwZc-FELE1Yo"));
			 $info = (array) $info; 
 			 $info = (array) $info[items][0]; 
 			 $vid = $info['id'];
 			 $info = (array) $info['snippet'];  
 			 $thumb = (array) $info;
 			 $info = (array) $info['localized'];  
 			 $thumb = (array) $thumb['thumbnails'];
 			 $thumb = (array) $thumb['default']; 

		     $story_text = preg_replace("/\s*[a-zA-Z\/\/:\.]*youtu(be.com\/watch\?v=|.be\/)([a-zA-Z0-9\-_]+)([a-zA-Z0-9\/\*\-\_\?\&\;\%\=\.]*)/",'<div class="embededmediathumb" data-target="https://www.youtube.com/embed/'.$vid.'?rel=0&autoplay=1">
			     	<img src="'.$thumb['url'].'" class="embededimg" />
			     	<div class="embededtitle">'.$info['title'].'</div>
			     	<div class="embededdescription">'.implode(' ', array_slice(str_word_count($info['description'], 2), 0, 7)).'...</div>
			     	<a href="https://www.youtube.com/watch?v='.$vid.'" target="_new" class="embededlink">YOUTUBE.COM</a>
		     	</div>',$text);
		}

		//** this is to check if text is vimeo
		$regex_pattern = "/(vimeo.com)/";
		$match;
		if(preg_match($regex_pattern, $text, $match)){
			 preg_match('#https?://(www\.)?vimeo\.com/(\d+)#',$text, $id); 			 
		     $info = json_decode(file_get_contents("http://vimeo.com/api/v2/video/".$id[2].".json"));
		     $info = (array) $info[0]; 
		     $story_text = preg_replace('#https?://(www\.)?vimeo\.com/(\d+)#','<div class="embededmediathumb" data-target="https://player.vimeo.com/video/$2?autoplay=1">
			     	<img src="'.$info['thumbnail_medium'].'" class="embededimg" />
			     	<div class="embededtitle">'.$info['title'].'</div>
			     	<div class="embededdescription">'.implode(' ', array_slice(str_word_count($info['description'], 2), 0, 7)).'...</div>
			     	<a href="'.$info['url'].'" target="_new" class="embededlink">VIMEO.COM</a>
		     	</div>',$text);
		}

		//** this is to check if text is vimeo
		$regex_pattern = "/(dai.ly)/";
		$match;
		if(preg_match($regex_pattern, $text, $match)){
			 preg_match('#https?://(www\.)?dai\.ly/([a-zA-Z0-9\-_]+)#',$text, $id); 
		     $info = json_decode(file_get_contents("http://www.dailymotion.com/services/oembed?format=json&url=http://www.dailymotion.com/embed/video/$id[2]"), true);
		     $story_text = preg_replace('#https?://(www\.)?dai\.ly/([a-zA-Z0-9\-_]+)#','
		     	<div class="embededmediathumb" data-target="//www.dailymotion.com/embed/video/$2?autoPlay=1">
			     	<img src="'.$info['thumbnail_url'].'" class="embededimg" />
			     	<div class="embededtitle">'.$info['title'].'</div>
			     	<div class="embededdescription">'.implode(' ', array_slice(str_word_count($info['description'], 2), 0, 7)).'...</div>
			     	<a href="'.$info['url'].'" target="_new" class="embededlink">DAILYMOTION.COM</a>
		     	</div>',$text);			      
		}

		//** this is to check if text is vimeo
		$regex_pattern = "/(dailymotion.com)/";
		$match;
		if(preg_match($regex_pattern, $text, $match)){
			 preg_match('#https?://(www\.)?dailymotion\.com/([a-zA-Z0-9\-_]+)/([a-zA-Z0-9\-_]+)#',$text, $id);
			 $info = json_decode(file_get_contents("http://www.dailymotion.com/services/oembed?format=json&url=http://www.dailymotion.com/embed/video/$id[3]"), true);
		     $story_text = preg_replace('#https?://(www\.)?dailymotion\.com/([a-zA-Z0-9\-_]+)/([a-zA-Z0-9\-_]+)#','
		     	<div class="embededmediathumb" data-target="//www.dailymotion.com/embed/video/$3?autoPlay=1">
			     	<img src="'.$info['thumbnail_url'].'" class="embededimg" />
			     	<div class="embededtitle">'.$info['title'].'</div>
			     	<div class="embededdescription">'.implode(' ', array_slice(str_word_count($info['description'], 2), 0, 7)).'...</div>
			     	<a href="'.$info['url'].'" target="_new" class="embededlink">DAILYMOTION.COM</a>
		     	</div>',$text);
		}

		//** this is to check if text is facebook
		$regex_pattern = "/(facebook.com)/";
		$match;

		if(preg_match($regex_pattern, $text, $match)){
		preg_match('#https?://(www\.)?facebook\.com\/([a-zA-Z0-9.\-_]+)\/([a-zA-Z0-9.\-_]+)\/([a-zA-Z0-9.\-_]+)#',$text, $id);
		$info = json_decode(@file_get_contents("https://graph.facebook.com/".$id[4]."?fields=description,icon,title,permalink_url,format,picture,source&access_token=1125938517542774|hXTYufukq04f5yYim3skJ8EihM0"), true); 

		if($info!=null && $info!="" ):
		     $story_text = preg_replace('#https?://(www\.)?facebook\.com\/([a-zA-Z0-9.\-_]+)\/([a-zA-Z0-9.\-_]+)\/([a-zA-Z0-9.\-_]+)\/#','
		     	<div class="embededmediathumb" data-target="https://www.facebook.com/plugins/video.php?href=https%3A%2F%2Fwww.facebook.com%2F'.urlencode($info['permalink_url']).'&width=130&autoplay=1">
			     	<img src="'.$info['picture'].'" class="embededimg" />
			     	<div class="embededtitle">'.$info['title'].'</div>
			     	<div class="embededdescription">'.implode(' ', array_slice(str_word_count($info['description'], 2), 0, 7)).'...</div>
			     	<a href="'.$info['url'].'" target="_new" class="embededlink">FACEBOOK.COM</a>
		     	</div>',$text);
		 else:
 
			$story_text = preg_replace('#https?://(www\.)?facebook\.com\/([a-zA-Z0-9.\-_]+)\/([a-zA-Z0-9.\-_]+)\/([a-zA-Z0-9.\-_]+)\/#','<div id="fb-root"  class="embededmediaframes"></div>
			  <script>(function(d, s, id) {
			    var js, fjs = d.getElementsByTagName(s)[0];
			    if (d.getElementById(id)) return;
			    js = d.createElement(s); js.id = id;
			    js.src = "//connect.facebook.net/en_US/sdk.js#xfbml=1&version=v2.6";
			    fjs.parentNode.insertBefore(js, fjs);
			  }(document, "script", "facebook-jssdk"));</script><br /><div class="fb-video" data-href="https://www.facebook.com/$2/$3/$4" data-show-text="false"></div>',$text);

		 endif;
		}

 

		return $story_text;

	}
}