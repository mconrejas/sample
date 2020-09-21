<?php
namespace SocialKit;

/*error_reporting(E_ALL);
ini_set('display errors', 1);*/
/**
* 
*/
class ActivityLog
{
	private $id;
	private $user_id;
	private $conn;
	private $escapeObj;
	private $db;
	private $data;
	private $moduleId;
	private $plp;
	private $currentCount = 0;
	private $host_url = '';

	function __construct()
	{
		global $conn;
		$this->conn = $conn;
		$this->escapeObj = new \SocialKit\Escape();
		require_once(ROOT_DIR."/classes/Database.class.php");
		$this->db = new \Database();
		$this->setHostUrl();
	}

	public function setConnection(\mysqli $conn)
	{
		$this->escapeObj->setConnection($conn);
		$this->conn = $conn;
		return $this;
	}

	protected function getConnection()
	{
		return $this->conn;
	}

	public function getRows($offset=0, $year=0)
	{
		global $config;

		$whereWithYear = ($year > 0) ? "year(ual.created) = $year AND" : "";

		$sql  = "SELECT ual.*,
                    ba.name as buzzer_name,
                    ba.status,
                    user1.name as user1_name,
                    user1.username as user1_username,
                    user1.type as user1_type,
                    user2.name as user2_name,
                    user2.username as user2_username,
                    user2.type as user2_type
				FROM user_activity_logger ual
				LEFT JOIN buzzer_activities ba ON ba.activity_id = ual.buzzer_activities_id
				LEFT JOIN accounts user1 ON user1.id = ual.user1_id
				LEFT JOIN accounts user2 ON user2.id = ual.user2_id
				WHERE $whereWithYear ual.user1_id=$this->user_id
				ORDER BY ual.id DESC LIMIT 30";

		if($offset > 0) $sql .= " OFFSET $offset";

		$query = $this->getConnection()->query($sql);
		if(false === $query) echo die(htmlspecialchars($this->getConnection()->error));

		if ($query->num_rows > 0)
		{
			$this->setCurrentCount($query->num_rows);

			$data = array(
				'data' => '',
				'admin_data' => ''
			);
			
			while ($fetch = $query->fetch_array(MYSQLI_ASSOC))
			{
				//$this->data .= convertUserActivityToText($fetch, $this->id);
				//$this->setModuleId($fetch['module_id']);
				$data['data'] .= $this->convertText($fetch);
			}

			$this->data = $data;
		}
		return $this->data;
	}

	public function getYearSelection() {
		global $themeData;

		$sql  = "SELECT DATE_FORMAT(created, '%Y') as year
				FROM user_activity_logger
				WHERE user1_id=$this->user_id OR user2_id=$this->user_id
				GROUP BY DATE_FORMAT(created, '%Y')
				ORDER BY DATE_FORMAT(created, '%Y') DESC";

		$query = $this->getConnection()->query($sql);
		$years = '';

		if ($query->num_rows > 0)
		{
			while ($fetch = $query->fetch_array(MYSQLI_ASSOC))
			{
				$themeData['year'] = $fetch['year'];
				$years .= \SocialKit\UI::view('activity_log/years-selection-each');
			}
		}

		$themeData['activity_year_selection'] = $years;

		return \SocialKit\UI::view('activity_log/years-selection');;
	}

	public function setId($id) {
		$this->id = (int) $id;
	}

	public function setUserId($id) {
		$this->user_id = (int) $id;
	}

	private function setCurrentCount($cnt=0) {
		$this->currentCount = (int) $cnt;
	}

	private function setHostUrl() {
		if((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)) {
			return $this->host_url = 'https://' . $_SERVER['HTTP_HOST'];
		} else {
			return (isset($_SERVER['HTTP_HOST'])) ? $this->host_url = 'http://' . $_SERVER['HTTP_HOST'] : false;
		}
	}

	private function setPostLinkPreview($params) {
		$this->plp = $params;
	}

	private function setModuleId($id) {
		$this->moduleId = (int) $id;
	}

	private function setTitle($title) {
        $title = strip_tags(htmlspecialchars_decode(trim($title)));
        $title = $this->escapeObj->getEmoticons($title);
        $title = $this->escapeObj->getLinks($title);
        $title = $this->escapeObj->getHashtags($title);
        $title = $this->escapeObj->getMentions($title);

		return $title;
	}

	public function getActivityByName($name='') {
		$query = $this->getConnection()->query("SELECT activity_id,name,status FROM buzzer_activities WHERE name='$name'");
		if( $query ) return $query->fetch_array(MYSQLI_ASSOC);
		return false;
	}

	public function getActivityById($id=0) {
		$id = (int) $id;
		$query = $this->getConnection()->query("SELECT activity_id,name,status FROM buzzer_activities WHERE activity_id=$id");
		if( $query ) return $query->fetch_array(MYSQLI_ASSOC);
		return false;
	}

	public function getPostObject($post_id=0) {
		$post_id = (int) $post_id;
		$postObjs = new \SocialKit\Story();
		$postObjs->setId($post_id);
		$postObjs = $postObjs->getRows();

		/*$postObjs['text'] = (strlen($postObjs['text']) > 50*2) ? trim(substr($postObjs['text'], 0,50*2)).'...' : $postObjs['text'];*/

		if($postObjs['text'] == '.' && empty($postObjs['link_preview_data'])) {
			$query = $this->getConnection()->query("SELECT * FROM blog_posts WHERE id_post=".$postObjs['id']);
			if( $query ) $postObjs['blog_posts'] = $query->fetch_array(MYSQLI_ASSOC);
		}

		if($postObjs['parent_id'] > 0) {
			$query = $this->getConnection()->query("SELECT * FROM posts_link_previews WHERE id_post=".$postObjs['parent_id']);
			if( $query ) $postObjs['link_preview_data'] = $query->fetch_array(MYSQLI_ASSOC);
		}

        return $postObjs;
	}

	public function getPostLinkPreview($data) {

		global $themeData;
			
		$data['title'] = htmlspecialchars_decode(addslashes($data['title']));
		$data['title'] = strip_tags($data['title']);
		$data['description'] = htmlspecialchars_decode(addslashes($data['description']));
		$data['description'] = strip_tags($data['description']);

		$article_base_slug = 'shares';

		/*$data['description'] = $this->escapeObj->getEmoticons($data['description']);
	    $data['description'] = $this->escapeObj->getLinks($data['description']);
	    $data['description'] = $this->escapeObj->getHashtags($data['description']);
	    $data['description'] = $this->escapeObj->getMentions($data['description']);*/

	    if ( strlen($data['title']) > 60*2)
		{
			$data['title'] = trim(substr($data['title'], 0,60*2)).'...';
		}

		if (empty($data['description']))
		{
			$data['description'] = ($data['text'] != '.') ? $data['text'] : '' ;
		}

		if ( strlen($data['description']) > 50*2)
		{
			$data['description'] = trim(substr($data['description'], 0,50*2)).'...';
		}

		$data['description'] = remove_non_utf8_chars($data['description']);
		$data['description'] = $this->escapeObj->getEmoticons($data['description']);
	    $data['description'] = $this->escapeObj->getLinks($data['description']);
	    $data['description'] = $this->escapeObj->getHashtags($data['description']);
	    $data['description'] = $this->escapeObj->getMentions($data['description']);

		$themeData['story_link_preview_title'] = remove_non_utf8_chars($data['title']);
	    $themeData['story_link_preview_id'] = $data['id'];
		$themeData['story_link_preview_image'] = $data['image'];
		//$themeData['story_link_preview_title'] = $data['title'];
		//$themeData['story_link_preview_description'] = $data['description'];
		$themeData['story_link_preview_domain'] = parse_url($data['url']);
	    $themeData['story_link_preview_description'] = $data['description'];
		$themeData['story_link_padding_bottom'] = '30%';
		$themeData['story_link_preview_url'] = "/$article_base_slug/".$data['id'].'/'.create_slug(htmlspecialchars_decode($data['title']));

		$story_link_preview_file = 'story/link-preview';

		return \SocialKit\UI::view($story_link_preview_file);
	}

	private function getCategory($cat_id=0) {
		$cat_id = (int) $cat_id;

		$query = $this->getConnection()->query("SELECT * FROM buzzer_categories WHERE category_id=$cat_id");
		if( $query ) return $query->fetch_array(MYSQLI_ASSOC);
		return false;
	}

	private function getTimeline($id=0) {
		$id = (int) $id;

		global $themeData;

		$timelineObjs = new \SocialKit\User();
		$timelineObjs->setId($id);
		$timelineObj = $timelineObjs->getRows();

	    $themeData['popup_cover']   = $timelineObj['cover_url']; 
	    $themeData['popup_avatar']  = $timelineObj['thumbnail_url'];
	    $themeData['popup_name']    = htmlspecialchars_decode($timelineObj['name']); 

	    if (isLogged()) {
	        $themeData['popup_button']  = $timelineObjs->getFollowButton();
	    }

	    /*if($themeData['popup_button'] == '' && $timelineObjs->isFollowRequested()) {
	    	//SK_acceptFollowRequest(10076);
	    	//SK_declineFollowRequest(10076);
	    	//<a class="follow-51 btn btn-xs btn-default no-border-radius" onclick="SK_registerFollow(51);" data-follow-id="51">
	    	$themeData['popup_button'] = '<a class="btn btn-xs btn-default no-border-radius accept-btn" value="Accept" onclick="SK_acceptFollowRequest('.$timelineObj['id'].');" style="width:49%; margin-right:5px;">
	    		<i data-icon="ok" class="icon-ok progress-icon"></i>Accept
	    		<i class="remove icon-remove-sign"></i></a>
    			<a class="btn btn-xs btn-default no-border-radius decline-btn" value="Decline" onclick="SK_declineFollowRequest('.$timelineObj['id'].');" style="width:49%;">
    			<i data-icon="ok" class="icon-ok progress-icon"></i>Decline
	    		<i class="remove icon-remove-sign"></i></a>';
	    }*/

	    $timelineObj['timeline_cover']   = \SocialKit\UI::view('global/popover/popover-container');

		if($timelineObj['type'] == 'group'){
			$timelineObj['groupAdmins'] = $timelineObjs->getGroupAdmins();
		}

		return $timelineObj;
	}

	public function getCurrentCount() {
		return $this->currentCount;
	}

	private function getMedia($mediaId) {
		global $themeData;

		$mediaObj = new \SocialKit\Media();
		$mediaObj->setId($mediaId);
		$mediaObj = $mediaObj->getRows();

		$photo_class = 'width-3';
                
        $listPhotos = '';

        if (is_array($mediaObj['each']))
        {
        	$ctr=0;
        	foreach ($mediaObj['each'] as $photo)
        	{
                $list_photo_url =  "" . $photo['url'] . "." . $photo['extension'];
                $list_photo_story_id = $photo['post_id'];
                $list_photo_comment_id = $photo['id'];
                $list_photo_album_id = $photo['album_id'];

                if($list_photo_story_id){
                	$themeData['list_photos'] = "<img class='$photo_class' src='$list_photo_url' onerror='imgError_noimg(this);' alt='Photo' onclick='javascript:SK_preview_image_post($list_photo_story_id);'>";
                } else {

                	if($this->plp['media_id']){
                		$themeData['list_photos'] = "<a onclick='viewPost(".$this->plp['id'].");' href='shares/".$this->plp['id']."/".create_slug($this->plp['title'])."' target='_blank'><img class='$photo_class' src='$list_photo_url' onerror='imgError_noimg(this);' alt='Photo'></a>";
                	} else {
                		$themeData['list_photos'] = "<img class='$photo_class' src='$list_photo_url' onerror='imgError_noimg(this);' alt='Photo' onclick='javascript:SK_preview_image_comment($list_photo_album_id, $list_photo_comment_id, $ctr);' data-src='$list_photo_url'>";
                	}                	
                }
                $ctr++;

                $listPhotos .= \SocialKit\UI::view('activity_log/list-photo-each');
            }
        }

        $themeData['list_photos'] = $listPhotos;
        return \SocialKit\UI::view('activity_log/photos-html');
		//return 
	}

	private function getComment($comment_id) {
		$commentObjs = new \SocialKit\Comment();
		$commentObjs->setId($comment_id);
		$commentObj = $commentObjs->getRows();

		$storyObjs = new \SocialKit\Story();
		$storyObjs->setId($commentObj['post_id']?$commentObj['post_id']:$comment_id);
		$storyObj = $storyObjs->getRows();

		$userObjs = new \SocialKit\User();
		$userObjs->setId($storyObj['timeline']['id']);
		$userObj = $userObjs->getRows();

		if($storyObj['text'] == '.' && empty($storyObj['link_preview_data'])) {
			$query = $this->getConnection()->query("SELECT * FROM blog_posts WHERE id_post=".$storyObj['id']);
			if( $query ) $storyObj['blog_posts'] = $query->fetch_array(MYSQLI_ASSOC);
		}

		$data = array(
			'comment_objs' => (!empty($commentObj)) ? $commentObj : array(),
			'user_objs' => (!empty($userObj)) ? $userObj : array(),
			'story_objs' => (!empty($storyObj)) ? $storyObj : array()
		);

        if(!empty($data)) {
        	return $data;
        }
        return false;//echo die(htmlspecialchars($this->db->error));
	}

	public function putLog($params) {
		if(false !== $this->db->db_insert($params,"user_activity_logger")) return $this->db->insert_id();
		return false;
	}

	public function removeLog($params) {
		unset($params['time']);

		if(false !== $this->db->db_delete("user_activity_logger",$params)) return true;
		return false;
	}

	public function removeDuplicateLog($params) {
		unset($params['buzzer_activities_id']);
		unset($params['time']);

		$query = $this->db->db_get("user_activity_logger",$params);

		if($query) {
			if($query->num_rows > 0) {
				while ($fetch = $query->fetch_array(MYSQLI_ASSOC))
				{
					$this->db->db_delete("user_activity_logger","WHERE id=".$fetch['id']);
				}
			}
		}
	}

	public function checkLog($params) {
		unset($params['time']);

		if(false !== $this->db->db_get("user_activity_logger",$params)) return true;
		return false;
	}

	public function convertText($value) {
		global $themeData, $user;

	    $user1_id = $value['user1_id'];
	    $user2_id = $value['user2_id'];
	    $user1_name = $value['user1_name'];
	    $user2_name = $value['user2_name'];
	    $user1_username = $value['user1_username'];
	    $user2_username = $value['user2_username'];
	    $status = $value['status'];
	    $buzzer_name = $value['buzzer_name'];
	    $host_url = $this->setHostUrl();

	    if($status == 1){
	    	$icon = $this->setHostUrl() . '';
	    	$list_photos = '';
	        $text_left = '';
	        $text_right = '';
	        $cat1 = '';
	        $cat2 = '';
	        $title_link = '';
	        $timeline_link_user1 = smoothLink("timeline/$user1_username");
	        $timeline_link_user1 = "<a href='$timeline_link_user1'>$user1_name</a>";
	        $timeline_link_user2 = smoothLink("timeline/$user2_username");
	        $timeline_link_user2 = "<a href='$timeline_link_user2'>$user2_name</a>";

	        if($buzzer_name == "unfollowuser" && $user2_id == $this->id) return false; 

	        switch ($buzzer_name) {
	        	case 'buzznewpost':
	        	case 'newpost':
	        			$postObj = $this->getPostObject($value['module_id']);
	        			$title = (!empty($postObj['blog_posts'])) ? $postObj['blog_posts']['title'] : $postObj['link_preview_data']['title'];

	        			$icon = $this->setHostUrl() . "/themes/grape/images/logo.png";
	        			$text_left .= ($user1_id == $this->id) ? "You" : $timeline_link_user1;

	        			if(!empty($postObj['blog_posts'])) {
	        				$text_left .= " <a href='story/".$postObj['id']."' target='_blank'>posted</a>";
	        			}
	        			else
	        			{
	        				$text_left .= ($postObj['link_preview_data']['id']) ? " <a onclick='viewPost(".$postObj['link_preview_data']['id'].");' href='shares/".$postObj['link_preview_data']['id']."/".create_slug($title)."' target='_blank'>posted</a>" : " <a href='story/".$postObj['id']."'>posted</a>";
	        			}
		                
		                if(!empty($postObj['blog_posts'])) {
		                	$parsedown = new \Parsedown();
				        	$content = $parsedown->text($postObj['blog_posts']['content']);

				        	$text_right .= ( preg_match('/(<img[^>]+>)/i', $content) || preg_match('/(<a[^>]+>)/i', $content) ) ? substr($content, 0, 250) : substr($content, 0, 100);
				        	$text_right .= " ... <a href='blog/$user1_username/".$postObj['blog_posts']['slug']."' target='_blank'>Read More</a>";
		                }
		                else
		                {
		                	if($postObj['text'] != '.') $text_right .= " " . $postObj['text'] . "<br/><br/>";

		                	$text_right .= ($postObj['link_preview_data']['id']) ? $this->getPostLinkPreview($postObj['link_preview_data']) : '';
		                	$text_right .= ($postObj['media']['id']) ? $this->getMedia($postObj['media']['id']) : '';
		                }

		                if($user2_username != 'adzbuzz_maintimeline') {
		                	$text_left .= ($user2_id == $this->id) ? " in your" : " in $timeline_link_user2&apos;s";
		                	$text_left .= " timeline.";
		                }
		                else
		                {
		                	$text_left .= " in the main timeline";
		                }
		                

		                /*if(!empty($postObj['blog_posts'])) {
		                	$text_left .= " <br/>&#34;<a href='blog/$user1_username/".$postObj['blog_posts']['slug']."' target='_blank'>".$postObj['blog_posts']['title']."</a>&#34;";
		                }*/
		            break;

	        	case 'buzzsharepost':
	        	case 'sharepost':
	        			/*$postObj = $this->getPostObject($value['module_id']);
	        			$title = (!empty($postObj['title'])) ? $postObj['title'] : $postObj['text'];
	        			$title = $this->setTitle($title);*/

	        			$postObj = $this->getPostObject($value['module_id']);
	        			$title = $postObj['link_preview_data']['title'];

	        			$icon = $this->setHostUrl() . "/themes/grape/images/share.png";
	        			$text_left .= ($user1_id == $this->id) ? "You" : $timeline_link_user1;
		                $text_left .= " shared";
		                $text_left .= ($user2_id == $this->id) ? " your" : " $timeline_link_user2&apos;s";
		                
		                $text_right .= " " . $postObj['text'];
		                $text_right .= ($postObj['link_preview_data']['id']) ? $this->getPostLinkPreview($postObj['link_preview_data']) : '';
		                $text_right .= ($postObj['media']['id']) ? $this->getMedia($postObj['media']['id']) : '';

		                $text_left .= ($postObj['link_preview_data']['id']) ? " <a onclick='viewPost(".$postObj['link_preview_data']['id'].");' href='shares/".$postObj['link_preview_data']['id']."/".create_slug($title)."' target='_blank'>post</a>" : " <a href='story/".$postObj['id']."' target='_blank'>post.</a>";
		            break;

	        	case 'buzzlikepost':
	        	case 'likepost':
	        			$postObj = $this->getPostObject($value['module_id']);
	        			$title = $postObj['link_preview_data']['title'];

	        			$icon = $this->setHostUrl() . "/themes/grape/images/like.png";
	        			$text_left .= ($user1_id == $this->id) ? "You" : $timeline_link_user1;
		                $text_left .= " liked";
		                $text_left .= ($user2_id == $this->id) ? " your" : " $timeline_link_user2&apos;s";
		                
		                /*$text_right .= " " . $postObj['text'];
		                $text_right .= ($postObj['link_preview_data']['id']) ? $this->getPostLinkPreview($postObj['link_preview_data']) : '';
		                $text_right .= ($postObj['media']['id']) ? $this->getMedia($postObj['media']['id']) : '';*/

		                if(!empty($postObj['blog_posts'])) {
		                	$text_left .= " <a href='story/".$postObj['id']."' target='_blank'>post</a>.";
	        				$text_left .= " <br/>&#34;<a href='blog/$user1_username/".$postObj['blog_posts']['slug']."' target='_blank'>".$postObj['blog_posts']['title']."</a>&#34;";
	        			}
	        			else
	        			{
	        				$text_left .= ($postObj['link_preview_data']['id']) ? " <a onclick='viewPost(".$postObj['link_preview_data']['id'].");' href='shares/".$postObj['link_preview_data']['id']."/".create_slug($title)."' target='_blank'>posted</a>" : " <a href='story/".$postObj['id']."'>posted</a>";
	        			}
		                
		                if(!empty($postObj['blog_posts'])) {
		                	$parsedown = new \Parsedown();
				        	$content = $parsedown->text($postObj['blog_posts']['content']);

				        	$text_right .= ( preg_match('/(<img[^>]+>)/i', $content) || preg_match('/(<a[^>]+>)/i', $content) ) ? substr($content, 0, 250) : substr($content, 0, 100);
				        	$text_right .= " ... <a href='blog/$user1_username/".$postObj['blog_posts']['slug']."' target='_blank'>Read More</a>";
		                }
		                else
		                {
		                	if($postObj['text'] != '.') $text_right .= " " . $postObj['text'] . "<br/><br/>";

		                	$text_right .= ($postObj['link_preview_data']['id']) ? $this->getPostLinkPreview($postObj['link_preview_data']) : '';
		                	$text_right .= ($postObj['media']['id']) ? $this->getMedia($postObj['media']['id']) : '';
		                }
		            break;

	        	case 'buzzcommentpost':
	        	case 'commentpost':
	        			$obj = $this->getComment($value['module_id']);
	        			/*echo "<pre>";
	        			print_r($obj);
	        			exit();*/

	        			$title = ($obj['comment_objs']['text']) ? $obj['comment_objs']['text'] : $obj['post_text'];
	        			$title = $this->setTitle($title);

	        			$list_photos .= ($obj['comment_objs']['media_id'] > 0) ? $this->getMedia($obj['comment_objs']['media_id']) : '';
	        			$icon = $this->setHostUrl() . "/themes/grape/images/comment.png";
	        			$text_left .= ($user1_id == $this->id) ? "You" : $timeline_link_user1;
		                $text_left .= " commented on";
		                $text_left .= ($obj['story_objs']['timeline']['id'] == $this->id) ? " your" : " $timeline_link_user2&apos;s";

		                /*$storyId = ($obj['comment_objs']['post_id']) ? $obj['comment_objs']['post_id'] : $value['module_id'];
	                	$text_left .= " <a href='story/". $storyId . "#comment_".$obj['comment_objs']['id']."' target='_blank'>post.</a>";
	                	$text_left .= " <br/>&#34;<a href='story/". $storyId . "#comment_".$obj['comment_objs']['id']."' target='_blank'>post.</a>&#34;";*/
		                
		                //$text_right .= " " . $obj['comment_objs']['text'];

		                if(!empty($obj['story_objs']['blog_posts'])) {
		                	$storyId = ($obj['comment_objs']['post_id']) ? $obj['comment_objs']['post_id'] : $value['module_id'];
		                	$text_left .= " <a href='story/". $storyId . "#comment_".$obj['comment_objs']['id']."' target='_blank'>post.</a>";
		                	$text_left .= " <br/>&#34;$title&#34;";
	        			}
	        			else
	        			{
	        				$text_left .= ($obj['link_preview_data']['id']) ? " <a onclick='viewPost(".$obj['link_preview_data']['id'].");' href='shares/".$obj['link_preview_data']['id']."/".create_slug($title)."' target='_blank'>posted</a>" : " <a href='story/".$obj['id']."'>posted</a>";
	        			}
		                
		                if(!empty($obj['story_objs']['blog_posts'])) {
		                	$parsedown = new \Parsedown();
				        	$content = $parsedown->text($obj['story_objs']['blog_posts']['content']);

				        	$text_right .= ( preg_match('/(<img[^>]+>)/i', $content) || preg_match('/(<a[^>]+>)/i', $content) ) ? substr($content, 0, 250) : substr($content, 0, 100);
				        	$text_right .= " ... <a href='blog/$user1_username/".$obj['story_objs']['blog_posts']['slug']."' target='_blank'>Read More</a>";
		                }
		                else
		                {

		                	if($obj['story_objs']['text'] != '.') $text_right .= " " . $obj['story_objs']['text'] . "<br/><br/>";

		                	$text_right .= ($obj['story_objs']['link_preview_data']['id']) ? $this->getPostLinkPreview($obj['story_objs']['link_preview_data']) : '';
		                	$text_right .= ($obj['story_objs']['media']['id']) ? $this->getMedia($obj['story_objs']['media']['id']) : '';
		                }

		                $text_right .= ($list_photos) ? $list_photos : "";

		                /*$storyId = ($obj['comment_objs']['post_id']) ? $obj['comment_objs']['post_id'] : $value['module_id'];
		                $text_left .= " <a href='story/". $storyId . "#comment_".$obj['comment_objs']['id']."' target='_blank'>post.</a>";*/
	        		break;

	        	case 'buzzviewpost':
	        	case 'viewpost':
	        			$postObj = $this->getPostObject($value['module_id']);
	        			$title = $postObj['link_preview_data']['title'];

	        			$cat1 = ($postObj['link_preview_data']['media']['publisher_category1_id'] > 0) ? $this->getCategory($postObj['link_preview_data']['media']['publisher_category1_id']) : $this->getCategory($postObj['link_preview_data']['media']['sharer_category1_id']);
	                	$cat2 = ($postObj['link_preview_data']['link_preview_data']['media']['publisher_category2_id'] > 0) ? $this->getCategory($postObj['link_preview_data']['media']['publisher_category2_id']) : $this->getCategory($postObj['link_preview_data']['media']['sharer_category2_id']);

	                	$icon = $this->setHostUrl() . "/themes/grape/images/logo.png";
	        			$text_left .= ($user1_id == $this->id) ? "You" : $timeline_link_user1;
		                $text_left .= " viewed";
		                $text_left .= ($user2_id == $this->id) ? " your" : " $timeline_link_user2&apos;s";
		                
		                $text_left .= ($postObj['link_preview_data']['id']) ? " <a onclick='viewPost(".$postObj['link_preview_data']['id'].");' href='shares/".$postObj['link_preview_data']['id']."/".create_slug($title)."' target='_blank'>post</a>" : " <a href='story/".$postObj['id']."'>post</a>";

		                if($cat1['name']){
		                    $text_left .= " under the category of <a href='".smoothLink("category/".$cat1['name'])."'>".ucfirst($cat1['name'])."</a>";
		                }

		                if($cat1['name'] && $cat2['name']){
		                    $text_left .= " and <a href='".smoothLink("category/".$cat2['name'])."'>".ucfirst($cat2['name'])."</a>";
		                }
		                elseif(!$cat1['name'] && $cat2['name'])
		                {
		                	$text_left .= " under the category of <a href='".smoothLink("category/".$cat2['name'])."'>".ucfirst($cat2['name'])."</a>";
		                }

		                if($postObj['text'] != '.') $text_right .= " " . $postObj['text'] . "<br/><br/>";

		                $text_right .= ($postObj['link_preview_data']['id']) ? $this->getPostLinkPreview($postObj['link_preview_data']) : '';
		                $text_right .= ($postObj['media']['id']) ? $this->getMedia($postObj['media']['id']) : '';
	        		break;

	        	case 'buzzrequestgroup':
	        	case 'requestgroup':
	        			$groupObj = $this->getTimeline($value['module_id']);
			            $group_username = $groupObj['username'];
			            $group_name_text = ucwords($groupObj['name']);
			            $group_name_link = smoothLink("timeline/$group_username");

			            $icon = $this->setHostUrl() . "/themes/grape/images/group.png";
			            $text_left .= ($user1_id == $this->id) ? "You" : $timeline_link_user1;
			            $text_left .= " requested to join";
			            $text_left .= ($user2_id == $this->id) ? " your" : " $timeline_link_user2&apos;s";
			            $text_left .= " <a href='$group_name_link'>$group_name_text</a> group.";

			            $text_right .= ($groupObj['timeline_cover']) ? $groupObj['timeline_cover'] : '';
	        		break;

	        	case 'buzzacceptgroup':
	        	case 'acceptgroup':
	        			$groupObj = $this->getTimeline($value['module_id']);
			            $group_username = $groupObj['username'];
			            $group_name_text = ucwords($groupObj['name']);
			            $group_name_link = smoothLink("timeline/$group_username");

			            $icon = $this->setHostUrl() . "/themes/grape/images/group.png";
		                $text_left .= ($user1_id == $this->id) ? "You" : $timeline_link_user1;
			            $text_left .= " accepted";
			            $text_left .= ($user2_id == $this->id) ? " your" : " $timeline_link_user2&apos;s";
			            $text_left .= " requested to join";
			            $text_left .= " <a href='$group_name_link'>$group_name_text</a> group.";

		                $text_right .= ($groupObj['timeline_cover']) ? $groupObj['timeline_cover'] : '';
	        		break;

	        	case 'buzzjoingroup':
	        	case 'joingroup':
	        			$groupObj = $this->getTimeline($value['module_id']);
			            $group_username = $groupObj['username'];
			            $group_name_text = ucwords($groupObj['name']);
			            $group_name_link = smoothLink("timeline/$group_username");

			            $icon = $this->setHostUrl() . "/themes/grape/images/group.png";
			            $text_left .= ($user1_id == $this->id) ? "You" : $timeline_link_user1;
			            $text_left .= " joined";
			            $text_left .= ($user2_id == $this->id) ? " your" : " $timeline_link_user2&apos;s";
			            $text_left .= " <a href='$group_name_link'>$group_name_text</a> group.";

			            $text_right .= ($groupObj['timeline_cover']) ? $groupObj['timeline_cover'] : '';
	        		break;

	        	case 'buzzaddgroup':
	        	case 'addgroup':
	        			$groupObj = $this->getTimeline($value['module_id']);
			            $group_username = $groupObj['username'];
			            $group_name_text = ucwords($groupObj['name']);
			            $group_name_link = smoothLink("timeline/$group_username");

			            $icon = $this->setHostUrl() . "/themes/grape/images/group.png";

			            $text_left .= ($user1_id == $this->id) ? "You" : $timeline_link_user1;
			            $text_left .= " added";
			            $text_left .= ($user2_id == $this->id) ? " you" : " $timeline_link_user2&apos;s";

			            $userObj = new \SocialKit\User();
			            $userObj->setId($user1_id);
			            $userObj = $userObj->getRows();

			            if($user1_id == $this->id) {
			            	$affiliation = "your";
			            } else {
			            	$affiliation = ($userObj['gender'] == 'male') ? "his" : "her";
			            }

			            $text_left .= " to $affiliation <a href='$group_name_link'>$group_name_text</a> group.";

			            $text_right .= ($groupObj['timeline_cover']) ? $groupObj['timeline_cover'] : '';
	        		break;

	        	case 'buzzjoincommunity':
	        	case 'joincommunity':
	        			$communityObj = $this->getTimeline($value['module_id']);
			            $community_username = $communityObj['username'];
			            $community_name_text = ucwords($communityObj['name']);
			            $community_name_link = smoothLink("timeline/$community_username");

			            $icon = $this->setHostUrl() . "/themes/grape/images/group.png";
			            $text_left .= ($user1_id == $this->id) ? "You" : $timeline_link_user1;
			            $text_left .= " joined the <a href='$community_name_link'>$community_name_text</a> community.";

			            $text_right .= ($communityObj['timeline_cover']) ? $communityObj['timeline_cover'] : '';
	        		break;

	        	case 'buzzfollowuser':
	        	case 'followuser':
	        			$id = ($this->id == $value['module_id']) ? $user1_id : $value['module_id'];
	        			$userObj = $this->getTimeline($id);
	        			
	        			$icon = $this->setHostUrl() . "/themes/grape/images/follow.png";
			            $text_left .= ($user1_id == $this->id) ? "You are" : "$timeline_link_user1 is";
			            $text_left .= " now following";
			            $text_left .= ($user2_id == $this->id) ? " you" : " $timeline_link_user2";

					    $text_right .= ($userObj['timeline_cover']) ? $userObj['timeline_cover'] : '';
	        		break;

	        	case 'buzzunfollow':
	        	case 'unfollowuser':
	        			$userObj = $this->getTimeline($value['module_id']);
	        			
	        			$icon = $this->setHostUrl() . "/themes/grape/images/unfollow.png";
			            $text_left .= ($user1_id == $this->id) ? "You" : $timeline_link_user1;
			            $text_left .= " unfollowed $timeline_link_user2";			            

			            $text_right .= ($userObj['timeline_cover']) ? $userObj['timeline_cover'] : '';
	        		break;

	        	case 'buzzrequestfollow':
	        	case 'requestfollow':
	        			$id = ($this->id == $value['module_id']) ? $user1_id : $value['module_id'];
	        			$userObj = $this->getTimeline($id);

	        			$icon = $this->setHostUrl() . "/themes/grape/images/follow.png";
			            $text_left .= ($user1_id == $this->id) ? "You" : $timeline_link_user1;
			            $text_left .= " requested to follow";
			            $text_left .= ($user2_id == $this->id) ? " You" : " $timeline_link_user2";

			            $text_right .= ($userObj['timeline_cover']) ? $userObj['timeline_cover'] : '';
	        		break;

	        	case 'buzzacceptfollow':
	        	case 'acceptfollow':
	        			$id = ($this->id == $value['module_id']) ? $user1_id : $value['module_id'];
	        			$userObj = $this->getTimeline($id);

	        			$icon = $this->setHostUrl() . "/themes/grape/images/follow.png";
			            $text_left .= ($user1_id == $this->id) ? "You" : $timeline_link_user1;
			            $text_left .= " accepted";
			            $text_left .= ($user2_id == $this->id) ? " your" : " $timeline_link_user2&apos;s";
			            $text_left .= " requested to follow";

			            $userObj = new \SocialKit\User();
			            $userObj->setId($user1_id);
			            $userObj = $userObj->getRows();

			            if($user1_id == $this->id) {
			            	$text_left .= " you";
			            } else {
			            	$text_left .= ($userObj['gender'] == 'male') ? " him" : " her";
			            }

			            $text_right .= ($userObj['timeline_cover']) ? $userObj['timeline_cover'] : '';
	        		break;

	        	case 'buzzlikecomment':
	        	case 'likecomment':
	        			$obj = $this->getComment($value['module_id']);

	        			$timeline_name = $obj['user_objs']['name'];
	        			$timeline_link = smoothLink("timeline/".$obj['user_objs']['username']);
	        			$timeline_link = "<a href='$timeline_link'>$timeline_name</a>";

	        			$icon = $this->setHostUrl() . "/themes/grape/images/like.png";
			            $text_left .= ($user1_id == $this->id) ? "You" : $timeline_link_user1;
			            $text_left .= " liked";
			            $text_left .= ($user2_id == $this->id) ? " your" : ($user1_id == $this->user_id) ? " your" : " $timeline_link_user2&apos;s";
			            
			            $text_left .= " <a href='story/".$obj['comment_objs']['post_id']."#comment_".$obj['comment_objs']['id']."' target='_blank'>comment</a> on";
			            $text_left .= ($obj['story_objs']['timeline']['id'] == $this->id) ? " your" : " $timeline_link&apos;s";
			            $text_left .= " <a href='story/".$obj['comment_objs']['post_id']."' target='_blank'>post</a>.";

			            $text_right .= $obj['comment_objs']['text'];
	        		break;

	        	case 'buzzlikepage':
	        	case 'likepage':
	        			$pageObj = $this->getTimeline($value['module_id']);
	        			$timeline_name = $pageObj['name'];
	        			$timeline_link = smoothLink("timeline/".$pageObj['username']);
	        			$timeline_link = "<a href='$timeline_link'>$timeline_name</a>";

	        			$icon = $this->setHostUrl() . "/themes/grape/images/like.png";
	        			$text_left .= ($user1_id == $this->id) ? "You" : $timeline_link_user1;
			            $text_left .= " liked";
			            $text_left .= ($user2_id == $this->id) ? " your" : " $timeline_link_user2&apos;s";
			            $text_left .= " $timeline_link page";

			            $text_right .= ($pageObj['timeline_cover']) ? $pageObj['timeline_cover'] : '';
	        		break;
	        }

	        $themeData['created'] = date("M d, Y H:m", strtotime($value['created']));
	        $themeData['icon_url'] = $icon;
	        $themeData['list_photos'] = $list_photos;
	        $themeData['text_left'] = $text_left;
	        $themeData['text_right'] = $text_right;
	        $themeData['activity_id'] = $value['id'];
	    }

	    return \SocialKit\UI::view('activity_log/list-activities');
	}	
}