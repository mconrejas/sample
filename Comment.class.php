<?php

namespace SocialKit;

class Comment {
	use \SocialTrait\Extension;

	private $id;
	private $orig_id;
	private $conn;
	private $timelineObj;
	private $recipientObj;
	public $data;
	public $themeData;
	public $template;
	private $comment_mentions;
	private $escapeObj;
	private $mediaId = 0;
    private $photos = array();
	private $timelineId;
	private $cache;
	private $activity;

	function __construct()
	{
		global $conn;
		$this->conn = $conn;
		$this->escapeObj = new \SocialKit\Escape();
		$this->cache = \ADZbuzzCore\Cache::get_instance();
		$this->activity = new \SocialKit\ActivityLog();
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

	public function getRemovableRows($set_template = true)
	{
		$this->id = (int) $this->id;

		$query1 = $this->getConnection()->query("SELECT * FROM " . DB_COMMENTS . " WHERE id=" . $this->id);

		if ($query1->num_rows == 1)
		{
			$this->data = $query1->fetch_array(MYSQLI_ASSOC);

			/* Timeline */
			$this->data['timeline'] = $this->getTimeline();

			// Get recipient, if available
			$this->data['recipient'] = $this->getRecipient();

			/* Text */
			$this->data['text'] = $this->escapeObj->getEmoticons($this->data['text']);
	        $this->data['text'] = $this->escapeObj->getLinks($this->data['text']);
	        $this->data['text'] = $this->escapeObj->getHashtags($this->data['text']);
	        $this->data['text'] = $this->escapeObj->getMentions($this->data['text']);

			/* Admin */
			$this->data['admin'] = $this->isAdmin();

			//set template
			if ( $set_template)
			{
				/* Basic Template Data */
				$this->getBasicTemplate();	
			}
			

			/* Return result */
			return $this->data;
		}
	}

	public function getRows($set_template = true)
	{
		$this->id = (int) $this->id;

		$key = DB_COMMENTS . ".id=" . $this->id;
		$this->data = (false !== $this->cache->exists(__FUNCTION__, $key)) ? json_decode($this->cache->get(__FUNCTION__, $key), true) : array();

		if(empty($this->data)){
			$query1 = $this->getConnection()->query("SELECT * FROM " . DB_COMMENTS . " WHERE id=" . $this->id . " AND active=1");

			if ($query1->num_rows == 1)
			{
				$this->data = $query1->fetch_array(MYSQLI_ASSOC);
				$this->cache->set(__FUNCTION__, $key, json_encode($this->data), 600);			
			}
		}

		/* Timeline */
		$this->data['timeline'] = $this->getTimeline();

		// Get recipient, if available
		$this->data['recipient'] = $this->getRecipient();

		$this->data['orig_text'] = $this->data['text'];
		/* Text */
		$this->data['text'] = $this->escapeObj->getEmoticons($this->data['text']);
        $this->data['text'] = $this->escapeObj->getLinks($this->data['text']);
        $this->data['text'] = $this->escapeObj->getHashtags($this->data['text']);
        $this->data['text'] = $this->escapeObj->getMentions($this->data['text']);

        // Media, if available
		$this->data['media'] = $this->getMedia();

		/* Admin */
		$this->data['admin'] = $this->isAdmin();

		//set template
		if ( $set_template)
		{
			/* Basic Template Data */
			$this->getBasicTemplate();	
		}
		
		/* Return result */
		return $this->data;
	}

	public function getById($id) {
		$this->setId($id);
		return $this->getTemplate();
	}

	public function getRecipient() {
		$recipient = false;
		
		if ($this->timelineId > 0) {
			$recipientObj = new \SocialKit\User($this->getConnection());
			$recipient = $recipientObj->getById($this->timelineId);
			$this->recipientObj = $recipientObj;
			
		}

		return $recipient;
	}

	function isLiked($timeline_id=0) {
	    if (! isLogged()) {
	        return false;
	    }
	    
	    global $user;
	    $timeline_id = (int) $timeline_id;

	    if ($timeline_id == 0) {
	        $timeline_id = $user['id'];
	    }
	    
	    $count = 0;
	    $key = DB_COMMENTLIKES . ".post_id=" . $this->id . ".timeline_id=" . $timeline_id;
		if(false !== $this->cache->exists(__FUNCTION__, $key)) $count = $this->cache->get(__FUNCTION__, $key);
		if($count > 0) return true;

	    $query = $this->getConnection()->query("SELECT id FROM " . DB_COMMENTLIKES . " WHERE post_id=" . $this->id . " AND timeline_id=$timeline_id AND active=1");
	    
	    if ($query->num_rows == 1) {
	    	$this->cache->set(__FUNCTION__, $key, $query->num_rows, 600);
	        return true;
	    }
	}

	public function isReported() {
	    if (! isLogged())
	    {
			return false;
		}
		
		global $user;

		$count = 0;
	    $key = DB_REPORTS . ".post_id=" . $this->data['id'] . ".reporter_id=" . $user['id'];
		if(false !== $this->cache->exists(__FUNCTION__, $key)) $count = $this->cache->get(__FUNCTION__, $key);
		if($count > 0) return true;

		$query = $this->getConnection()->query("SELECT id FROM " . DB_REPORTS . " WHERE reporter_id=" . $user['id'] . " AND post_id=" . $this->data['id'] . " AND type='comment'");

		if ($query->num_rows == 1) {
			$this->cache->set(__FUNCTION__, $key, $query->num_rows, 600);
			return true;
		}
	}

	public function isAdmin()
	{
		global $user;
		$admin = false;

		if ($this->timelineObj->isAdmin())
        {
			$admin = true;
		}

        return $admin;
	}

	public function numLikes()
	{
		$count = 0;
	    $key = DB_COMMENTLIKES . ".post_id=" . $this->id;
		if(false !== $this->cache->exists(__FUNCTION__, $key)) $count = $this->cache->get(__FUNCTION__, $key);
		if($count > 0) return $count;

	    $query = $this->getConnection()->query("SELECT COUNT(id) AS count FROM " . DB_COMMENTLIKES . " WHERE post_id=" . $this->id . " AND active=1");
	    $fetch = $query->fetch_array(MYSQLI_ASSOC);
	    $this->cache->set(__FUNCTION__, $key, $fetch['count'], 600);

	    return $fetch['count'];
	}

	public function getLikesById($comment_id, $offset=0, $limit=0)
	{	
		$keyText = '';
		$queryText = "SELECT id,timeline_id FROM " . DB_COMMENTLIKES . " WHERE post_id=" . $comment_id . " AND active=1 ORDER BY id DESC";

		if( $limit > 0 ) {
			$queryText .= " LIMIT $limit";
			$keyText .= ".limit=$limit";
		}

		if( $offset > 0 ) {
			$queryText .= " OFFSET $offset";
			$keyText .= ".offset=$offset";
		}

		$key = DB_COMMENTLIKES . ".post_id=" . $comment_id . "$keyText";
		$get = (false !== $this->cache->exists(__FUNCTION__, $key)) ? json_decode($this->cache->get(__FUNCTION__, $key), true) : array();

		if(empty($get)) {

			$query = $this->getConnection()->query($queryText);
		    
		    if ($query->num_rows > 0)
		    {
		        while ($fetch = $query->fetch_array(MYSQLI_ASSOC))
		        {
		        	$get[] = $fetch['timeline_id'];
		        }
		        $this->cache->set(__FUNCTION__, $key, json_encode($get), 600);
		    }
		}

	    return $get;
	}

	public function getLikes($offset=0, $limit=0)
	{	
		$keyText = '';
		$queryText = "SELECT id,timeline_id FROM " . DB_COMMENTLIKES . " WHERE post_id=" . $this->id . " AND active=1 ORDER BY id DESC";

		if( $limit > 0 ) {
			$queryText .= " LIMIT $limit";
			$keyText .= ".limit=$limit";
		}

		if( $offset > 0 ) {
			$queryText .= " OFFSET $offset";
			$keyText .= ".offset=$offset";
		}

		$key = DB_COMMENTLIKES . ".post_id=" . $this->id . "$keyText";
		$get = (false !== $this->cache->exists(__FUNCTION__, $key)) ? json_decode($this->cache->get(__FUNCTION__, $key), true) : array();

		if(empty($get)) {

			$query = $this->getConnection()->query($queryText);
		    
		    if ($query->num_rows > 0)
		    {
		        while ($fetch = $query->fetch_array(MYSQLI_ASSOC))
		        {
		        	$get[] = $fetch['timeline_id'];
		        }
		        $this->cache->set(__FUNCTION__, $key, json_encode($get), 600);
		    }
		}

	    return $get;
	}

	public function getTimeline()
	{
		$this->timelineObj = new \SocialKit\User($this->getConnection());
		$this->timelineObj->setId($this->data['timeline_id']);
		$timeline = $this->timelineObj->getRows();

		unset($this->data['timeline_id']);
		return $timeline;
	}

	public function getMedia()
	{
		$get = false;
		
		if($this->data['media_id'] > 0)
		{
			$get = "";
			$query = $this->getConnection()->query("SELECT * FROM " . DB_MEDIA . " WHERE id=" . $this->data['media_id'] . " OR album_id=" . $this->data['media_id']);

			if($query->num_rows > 0)
			{
				$ctr = 1;
				while ($media = $query->fetch_array(MYSQLI_ASSOC))
		        {
		        	if ($media['type'] === "photo")
					{
						$get .= "<img width='120' onerror='imgError_noimg(this);' src='" . SITE_URL . "/" . $media['url'] . '.' . $media['extension'] . "' onclick='SK_preview_image_comment(".$this->data['media_id'].",".$media['id'].",".$ctr.");' data-src='" . SITE_URL . "/" . $media['url'] . '.' . $media['extension'] . "' style='cursor:pointer;'> ";
						$ctr++;
					}
		        }

				
			}
		}

		return $get;
	}

	public function setId($id)
	{
		$this->id = (int) $id;
	}

	/* Sets the original id of the comment */
	public function setOrigId($id)
	{
		$id = (int) $id;

		$query = $this->getConnection()->query("SELECT * FROM " . DB_COMMENTS . " WHERE id=" . $id . " ");
	    $orig_id = $query->fetch_array(MYSQLI_ASSOC)['orig_id'];

	    if($orig_id) {
	    	$this->orig_id = (int) $orig_id;
	    } else {
	    	$this->orig_id = $id;
	    }
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
	        $this->getConnection()->query("DELETE FROM " . DB_COMMENTLIKES . " WHERE post_id=" . $this->id . " AND timeline_id=" . $user['id'] . " AND active=1");
	    }
	    else
	    {
	        $this->getConnection()->query("INSERT INTO " . DB_COMMENTLIKES . " (timeline_id,active,post_id,time) VALUES (" . $user['id'] . ",1," . $this->id . "," . time() . ")");
	    }

	    $this->cache->delete("isLiked", DB_COMMENTLIKES . ".post_id=" . $this->id . ".timeline_id=" . $user['id']);
	    $this->cache->delete("numLikes", DB_COMMENTLIKES . ".post_id=" . $this->id);
	    $this->cache->multi_delete("getLikes", DB_COMMENTLIKES . ".post_id=" . $this->id); //Deleting multiple keys

	    $activityObj = $this->activity->getActivityByName('likecomment');

		if( $activityObj['status'] == 1) {

			$query = $this->getConnection()->query("SELECT post_id,timeline_id FROM " . DB_COMMENTS . " WHERE id=$this->id");
			if( $query ) {
				$timeline = $query->fetch_assoc();

				$buzz_data = array(
					'buzzer_activities_id' => $activityObj['activity_id'],
					'module_id' => $this->id,
					'user1_id' => $user['id'],
					'user2_id' => $timeline['timeline_id'],
					'time' => time()
				);

				$this->activity->removeDuplicateLog($buzz_data);

				if ($this->activity->checkLog($buzz_data) == false)
				{
					$activity_id = $this->activity->putLog($buzz_data);
					$buzz_data['activity_name'] = $activityObj['name'];
					pushNotify($buzz_data);
					$this->putNotification('like');
				}
				else
				{
					$this->activity->removeLog($buzz_data);
				}
			}
		}

	    return true;
	}

	public function putReport()
	{
		global $user, $conn;
		
		if (! isLogged())
		{
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

		$query = $this->getConnection()->query("INSERT INTO " . DB_REPORTS . " (active,post_id,reporter_id,type,time,timeline_id,recipient_id, report_details) VALUES (1," . $this->data['id'] ."," . $user['id'] . ",'comment',".time().",".$this->data["timeline"]["id"].",".$this->data["recipient_id"].",'".$reason."')");

		if (! $query) {
			return false;
		}

		$this->cache->delete("isReported", DB_REPORTS . ".post_id=" . $this->data['id'] . ".reporter_id=" . $user['id']);

		return true;
	}

	public function putRemove($timeline_id=0)
	{
		if (! isLogged())
		{
			return false;
		}

		$continue = false;

        //own comment or admin of a group/page/community
        if ($this->timelineObj->isAdmin() || (!is_null($this->recipientObj) && $this->recipientObj->isAdmin()) )

        {
            $continue = true;
        }
        
        if ($continue)
        {
        	global $user;

        	if ( is_null($this->data['orig_id']))
        	{
        		$this->data['orig_id'] = 'NULL';
        	}

        	$isparent = $this->isParentComment($this->id); 
        	//cascade effect for likes and comments sub
        	if($isparent){
        		 $this->getConnection()->query("DELETE FROM " . DB_COMMENTLIKES . " WHERE post_id IN (".$isparent.")");
        		 $this->getConnection()->query("DELETE FROM " . DB_COMMENTS . " WHERE id IN (".$isparent.")");
        	}

        	$this->getConnection()->query("DELETE FROM " . DB_COMMENTS . " WHERE id=" . $this->id);
        	$this->getConnection()->query("DELETE FROM " . DB_COMMENTS . " WHERE orig_id=" . $this->id);
        	$this->getConnection()->query("DELETE FROM " . DB_COMMENTLIKES . " WHERE post_id=" . $this->id);
        	$this->getConnection()->query("INSERT INTO `comments_deleted_rows`(`id`, `active`, `post_id`, `media_id`, `text`, `time`, `timeline_id`, `recipient_id`, `parent_id`, `is_edited`, `orig_id`, `timestamp`, `deleted_by`, `deleted_time`) VALUES ({$this->data['id']},{$this->data['active']},{$this->data['post_id']},{$this->data['media_id']},'{$this->data['text']}',{$this->data['time']},{$this->data['timeline']['id']},{$this->data['recipient_id']},{$this->data['parent_id']},{$this->data['is_edited']},{$this->data['orig_id']},'{$this->data['timestamp']}',{$user['id']},".time().")");

        	$this->cache->delete("numComments", DB_COMMENTS . ".post_id=" . $this->data['post_id']);
        	$this->cache->delete("getFeed", DB_COMMENTS . ".post_id=" . $this->data['post_id']);

        	return true;
        }
	}

	public function isParentComment($id){

		$query1 = $this->getConnection()->query("SELECT * FROM " . DB_COMMENTS . " WHERE (id=" . $this->id . " AND parent_comment_id = 0) ");

		if ($query1->num_rows > 0)
		{	

			$query2 = $this->getConnection()->query("SELECT id FROM " . DB_COMMENTS . " WHERE parent_comment_id=" . $this->id)->fetch_all(MYSQLI_ASSOC);
			if(count($query2)>0){
				$ids = implode(",",array_map(function($a) {return implode("~",$a);},$query2));
  				return $ids;
			}

		}else{
			return false;
		}
	}

	public function putEdit($comment_text,$comment_media = 0)

	{
		if (! isLogged())
		{
			return false;
		}

		$continue = false;
        
        if ($this->timelineObj->isAdmin())
        {
            $continue = true;
        }
        
        if ($continue)
        {
        	if($this->data['orig_id'] > 0){
        		$orig_id = $this->data['orig_id'];
        	}else{
        		$orig_id = $this->id;
        	}
			
			//added by dimar m. [may 2017]
			//$orig_id = $this->id; //UPDATED BY Laurence
			
        	/* Links */
		    $comment_text = $this->escapeObj->createLinks($comment_text);

		    /* Hashtags */
		    $comment_text = $this->escapeObj->createHashtags($comment_text);

		    /* Mentions */
		    $mentions = $this->escapeObj->createMentions($comment_text);
		    $comment_text = $mentions['content'];
		    $this->comment_mentions = $mentions['mentions'];

		    /* Text */
		    $comment_text = $this->escapeObj->postEscape($comment_text);

        	$this->getConnection()->query("INSERT INTO " . DB_COMMENTS . " (timeline_id,active,post_id,parent_id,text,time,orig_id,media_id,parent_comment_id) VALUES (".$this->data['timeline']['id'].",1,".$this->data['post_id'].",".$this->data['parent_id'].",'$comment_text'," . time() . ",".$orig_id.",".$comment_media.",".$this->data['parent_comment_id'].")");

        	$new_id = $this->getConnection()->insert_id;

        	$this->getConnection()->query("UPDATE " . DB_COMMENTS . " SET `is_edited` = 1, `active` = 0 WHERE id=" . $this->id);

        	$text = $this->escapeObj->getEmoticons($comment_text);
	        $text = $this->escapeObj->getLinks($text);
	        $text = $this->escapeObj->getHashtags($text);
	        $text = $this->escapeObj->getMentions($text);

	        $this->cache->delete("getFeed", DB_COMMENTS . ".post_id=" . $this->data['post_id']);

        	return array($new_id,$text);
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

		if ($this->data['timeline']['id'] == $user['id']) {
			return false;
		}

		if ($action == "like")
		{
			$count = $this->numLikes();
	        
	        /*if ($this->isLiked())
	        {
	            $count = $count - 1;
	        }*/
	        
	        if ($count > 1)
	        {
	            $text = str_replace('{count}', ($count-1), $lang['likes_your_comment_plural']);
	            $text = str_replace('{comment}', substr(strip_tags($this->data['text']), 0, 45), $text);
	        }
	        else
	        {
	        	$text = str_replace('{comment}', substr(strip_tags($this->data['text']), 0, 45), $lang['likes_your_comment_singular']);
	        }
	        
	        $query = $this->getConnection()->query("SELECT id FROM " . DB_NOTIFICATIONS . " WHERE timeline_id=" . $this->data['timeline']['id'] . " AND post_id=" . $this->id . " AND (type='like' OR type='likecomment') AND active=1");
			
		    if ($query->num_rows > 0)
		    {
		        $this->getConnection()->query("DELETE FROM " . DB_NOTIFICATIONS . " WHERE timeline_id=" . $this->data['timeline']['id'] . " AND post_id=" . $this->id . " AND (type='like' OR type='likecomment') AND active=1");
		    }
		    
		    $q1 = $this->getConnection()->query("INSERT INTO " . DB_NOTIFICATIONS . " (timeline_id,active,notifier_id,post_id,text,time,type,url) VALUES (" . $this->data['timeline']['id'] . ",1," . $user['id'] . "," . $this->id . ",'$text'," . time() . ",'likecomment','/story/" . $this->data['post_id'] . "#comment_" . $this->id . "')");

	    	if ($q1) {
	        	triggerNotification('like', $this->data['timeline']['id']);
	        }

		    return true;
		}
	}

	/* Template Methods */
	public function getTemplate() {

		if (! is_array($this->data)) {
			$this->getRows();
		}

		global $themeData, $user;

		$themeData['sub_comments'] = "";
		$themeData['view_more_subcomments'] = "";
		$subCommentCount = 0;

		/* Basic Template Data */
		$this->getBasicTemplate();

		/* Control buttons */
		$themeData['comment_control_buttons'] = $this->getControlButtonsTemplate();

		/* Like activity */
		$themeData['comment_like_activity'] = $this->getLikeActivityTemplate();

		/* Like button */
		$themeData['comment_like_button'] = $this->getLikeButtonTemplate();

		/* Reply Button */ 
		$themeData['comment_reply_button'] = $this->getReplyButtonTemplate();

		/* Edited label */
		$themeData['edited'] = $this->getEditedLabel();

		//if(is_array($subComments) && count($subComments)>0){
		if( ($subCommentCount = $this->hasSubComment($this->data['id']) ) ) {

			if( $subCommentCount > 1 ) {
				$themeData['subcomment_count'] = $subCommentCount - 1;
				$themeData['comment_id'] = $this->data['id'];
				$themeData['main'] = 0;
				$themeData['view_more_subcomments'] .= \SocialKit\UI::view('comment/load-more-reply');
			} 

			$subComments = $this->getSubComments(true); //gets the last sub comment only

			foreach ($subComments as $scommentId)
	        {
	        	$scomment = new \SocialKit\CommentSub($this->conn);
	        	$scomment->setId($scommentId['id']);
	        	$themeData['sc_comment_control_buttons'] = $scomment->getControlButtonsTemplate();
	        	$themeData['sub_comments'] .= @$scomment->getTemplate();
	        }
		}else{
			$themeData['sub_comments'] = "";
		}

		$themeData['parent_comment_id'] = $this->data['id'];

		//owner of post
		$postowner = getPostData($this->data['post_id']);
		$postowner = $postowner[0]['timeline_id'];
		$showsubcommentbox = true;


		if( ($postowner!=$user['id']) && $this->data['timeline']['comment_privacy'] == "following" ){

	        $userObject = new \SocialKit\User();
	        $userObject->setId($user['id']);

			if ($userObject->isFollowedBy($postowner)==NULL)
			{
				$showsubcommentbox = false;
			}
		}
 		
		//if user allowed to comment
		if($showsubcommentbox)
			$themeData['sub_comments'] .= @\SocialKit\Story::getCommentBox($this->data['timeline']['id'], $this->data['id'], 1, $subCommentCount);
		
		/* Return template */
		$this->template = \SocialKit\UI::view('comment/content');
		return $this->template;
	}

	public function getBasicTemplate() {
		global $themeData;

		$themeData['comment_id'] = $this->data['id'];
		$themeData['post_id'] = $this->data['post_id'];
		$themeData['orig_id'] = (empty($this->data['orig_id']))?$this->data['id']:$this->data['orig_id'];
		$themeData['comment_text'] = $this->data['text'];
		$themeData['comment_photo'] = $this->data['media'];
		$themeData['comment_media'] = empty($this->data['media_id']) ? 0 : $this->data['media_id'];
		$themeData['edit_comment_text'] = $this->escapeObj->getEditMentions($this->data['orig_text']);
		$themeData['edit_comment_text'] = str_replace(['[a]','[/a]'], '', $themeData['edit_comment_text']);
		// $themeData['edit_comment_text'] = str_replace(['<br>','<br/>','<br />'],"\n", $themeData['edit_comment_text']);// remove on 305 which cause error, check comment/view-edit.phtml for parsing
		$themeData['edit_comment_text'] = $this->escapeObj->getEditHashtags(urldecode($themeData['edit_comment_text']));
		$themeData['comment_time'] = date('c', $this->data['time']);

		/* Timeline */
		$themeData['comment_timeline_id'] = $this->data['timeline']['id'];
		$themeData['comment_timeline_url'] = $this->data['timeline']['url'];
		$themeData['comment_timeline_username'] = $this->data['timeline']['username'];
		$themeData['comment_timeline_name'] = $this->data['timeline']['name'];
		$themeData['comment_timeline_thumbnail_url'] = $this->data['timeline']['thumbnail_url'];
	}

	public function getControlButtonsTemplate() {
		global $themeData;

		if (isLogged())
		{
			if($this->data['orig_id']){
				$themeData['orig_id'] = $this->data['orig_id'];	
			}else{
				$themeData['orig_id'] = $this->data['id'];
			}
			
			$themeData['comment_edit_button'] = $this->getEditButtonTemplate();
			$themeData['comment_remove_button'] = $this->getRemoveButtonTemplate();
			$themeData['comment_report_button'] = $this->getReportButtonTemplate();

			return \SocialKit\UI::view('comment/control-buttons');
		}
	}

	public function getEditButtonTemplate() {
		global $config;
		if (isLogged() && $config['personal_edit_comment'])
		{
			if ($this->data['admin'] == true)
			{
				return \SocialKit\UI::view('comment/edit-button');
			}
		}
	}

	public function getEditedLabel(){
		if($this->data['orig_id'] > 0){
			return \SocialKit\UI::view('comment/edited');
		}else{
			return "";
		}
	}

	public function getRemoveButtonTemplate() {
		if (isLogged())
		{
			
			// if ($this->data['admin'] == true)
			if ($this->data['admin'] == true || isValidCommentMasterAccount($this->id) || (!is_null($this->recipientObj) && $this->recipientObj->isAdmin()) )
			{
				return \SocialKit\UI::view('comment/remove-button');
			}
		}
	}

	public function getReportButtonTemplate() {
		if (isLogged()) {

			if ($this->data['admin'] != true && !$this->isReported() && !isValidCommentMasterAccount($this->id))
			{
				return \SocialKit\UI::view('comment/report-button');
			}
		}
	}

	public function getLikeActivityTemplate() {
		global $themeData;

		$themeData['comment_num_likes'] = $this->numLikes();
		return \SocialKit\UI::view('comment/like-activity');
	}

	public function getLikeButtonTemplate() {
		if (isLogged())
		{
			if ($this->isLiked())
			{
	            return \SocialKit\UI::view('comment/unlike-button');
	        }
	        else
	        {
	            return \SocialKit\UI::view('comment/like-button');
	        }
		}
	}

	public function getReplyButtonTemplate() {
		if (isLogged())
		{
	           return \SocialKit\UI::view('comment/reply-button');
		}
	}

	public function getLikesTemplate($offset=0,$limit=0)
	{
		global $themeData, $config;
		$i = 0;
		$listLikes = '';

		$male_avatar = $config['site_url']."/themes/grape/images/default-male-avatar.png";
		$female_avatar = $config['site_url']."/themes/grape/images/default-female-avatar.png";

        foreach ($this->getLikes($offset, $limit) as $likerId)
        {
        	$likerObj = new \SocialKit\User();
        	$likerObj->setId($likerId);
        	$liker = $likerObj->getRows();

            $themeData['list_liker_id'] = $liker['id'];
            $themeData['list_liker_url'] = $liker['url'];
            $themeData['list_liker_username'] = $liker['username'];
            $themeData['list_liker_name'] = $liker['name'];
            $themeData['list_liker_thumbnail_url'] = $liker['thumbnail_url'];
            
            /*if (!@getimagesize( $liker['thumbnail_url'])) {
                $themeData['list_liker_thumbnail_url'] = ($liker['gender'] == 'male') ? $male_avatar : $female_avatar; 
            }else {
                $themeData['list_liker_thumbnail_url'] = $liker['thumbnail_url'];
            }*/

            $themeData['list_liker_button'] = $likerObj->getFollowButton();

            $listLikes .= \SocialKit\UI::view('comment/list-view-likes-each');
            $i++;
        }

        if ($i < 1) {
            $listLikes .= \SocialKit\UI::view('comment/view-likes-none');
        }

        $themeData['hex_loader'] = '<img class="hex-loader" style="display:none;width:25px;" src="'.$config['theme_url'].'/images/hex-loader-colored.gif">';

        $themeData['list_likes'] = $listLikes;
        $themeData['comment_id'] = $this->id;
        return \SocialKit\UI::view('comment/view-likes');
	}

	public function getRemoveTemplate() {
		return \SocialKit\UI::view('comment/view-remove');
	}

	public function getEditTemplate() {
		return \SocialKit\UI::view('comment/view-edit');
	}

	public function getEditTemplateMain() {
		return \SocialKit\UI::view('comment/view-edit-main');
	}

	public function getLogTemplate() {
		global $themeData;
		$query = $this->getConnection()->query("SELECT * FROM " . DB_COMMENTS . " WHERE orig_id=" . $this->data['id'] . " OR id=". $this->data['id'] . " ORDER BY id DESC");
		
		$logs = $query->fetch_all(MYSQLI_ASSOC);

		$themeData['logs'] = "";
		foreach($logs as $ind => $log){

			$text = "";
			$text = $this->escapeObj->getEmoticons($log['text']);
	        $text = $this->escapeObj->getLinks($text);
	        $text = $this->escapeObj->getHashtags($text);
	        $text = $this->escapeObj->getMentions($text);

			$current = "";
			if($ind == 0){
				$current = " <small style='float: right;'><i>current</i></small>";
			}

			$time = " <small style='float: right;'><i>".date('M. d, Y h:i A', strtotime($log['timestamp']))."</i></small>";

			$themeData['logs'] .= "<p style='padding: 5px; margin: 0; border-bottom: 1px solid #eee;'>".$text.$time."</p>"; 
		}

		return \SocialKit\UI::view('comment/view-logs');
	}

	public function getLogTemplateMain() {
		global $themeData;
		$query = $this->getConnection()->query("SELECT * FROM " . DB_COMMENTS . " WHERE orig_id=" . $this->data['id'] . " OR id=". $this->data['id'] . " ORDER BY id DESC");
		
		$logs = $query->fetch_all(MYSQLI_ASSOC);

		$themeData['logs'] = "";
		foreach($logs as $ind => $log){

			$text = "";
			$text = $this->escapeObj->getEmoticons($log['text']);
	        $text = $this->escapeObj->getLinks($text);
	        $text = $this->escapeObj->getHashtags($text);
	        $text = $this->escapeObj->getMentions($text);

			$current = "";
			if($ind == 0){
				$current = " <small style='float: right;'><i>current</i></small>";
			}

			$time = " <small style='float: right;'><i>".date('M. d, Y h:i A', strtotime($log['timestamp']))."</i></small>";

			$themeData['logs'] .= "<p style='padding: 5px; margin: 0; border-bottom: 1px solid #eee;'>".$text.$time."</p>"; 
		}

		return \SocialKit\UI::view('comment/view-logs-main');
	}


	public function getImages($mediaId){
    	$get = array();
    	$query = $this->getConnection()->query("SELECT * FROM " . DB_MEDIA . " WHERE album_id=" . $mediaId . " OR id=" . $mediaId);
    	if($query->num_rows > 0)
		{
			while ($media = $query->fetch_array(MYSQLI_ASSOC))
	        {
	        	if ($media['type'] === "photo")
				{
					$get[] = $media;
				}
	        }	
		}
		return $get;
	}
	
	public function setTimelineId($id) {
        $this->timelineId = (int) $id;
    }


	function hasSubComment($id) {
	    if (! isLogged()) {
	        return false;
	    }

	    //$id = (int) $id;
	    //$query = $this->getConnection()->query("SELECT * FROM " . DB_COMMENTS . " WHERE id=" . $id . " ");
	    //$orig_id = $query->fetch_array(MYSQLI_ASSOC)['orig_id'];

	    //TO CATER EDITED PARENT COMMENT
	    //if($orig_id) $id = $orig_id;
	   	$query = $this->getConnection()->query("SELECT COUNT(id) as count FROM " . DB_COMMENTS . " WHERE  parent_comment_id=" . $this->orig_id . "  AND active=1");

	   	/*if ($query->num_rows > 0) {
	        return $query->fetch_all(MYSQLI_ASSOC);
	    }*/

	    if($query->num_rows > 0) {
	    	$fetch = $query->fetch_array(MYSQLI_ASSOC);
			return $fetch['count'];
	    }
	}

	public function getSubComments($showLast=false, $offset=0, $limit=0)
	{
		$queryText = "SELECT * FROM " . DB_COMMENTS . " WHERE  parent_comment_id=" . $this->orig_id . "  AND active=1 ORDER BY id DESC";

		if( $limit > 0 ) {
			$queryText .= " LIMIT $limit";
		} else {
			$queryText .= " LIMIT 1";
		}

		if( $offset > 1 ) $queryText .= " OFFSET $offset";

		$query = $this->getConnection()->query($queryText);
		if ($query->num_rows > 0) {
			$fetch = array_reverse($query->fetch_all(MYSQLI_ASSOC));
			if( $offset == 1 ) unset($fetch[count($fetch)-1]);
			
	        return $fetch;
	    }
	}

}