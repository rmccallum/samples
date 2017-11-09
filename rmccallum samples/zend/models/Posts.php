<?php

class Posts extends Base
{
    protected $_name = 'posts';
    //to use "magic" getters on rowsets we want to modify Zend_Db_Table_Row_Abstract an example can be found in row/User.php
    protected $_rowClass = 'Row_Post';
    private $_meta = array();
    private $_post = array();
    private $_allowed_formats = array('mp4','mpeg','mov','wav','mpg');
    //private $_newPostId;
    protected $_dependentTables = array('PostLikes', 'PostComments', 'PostMeta');
    //parent table...
    protected $_referenceMap = array(
        'Hub' => array(
            'columns' => 'hub_id',
            'refTableClass' => 'Hub',
            'refColumns' => 'id'
        )
    );

    public function generateDataFromObject($story)
    {
        if (property_exists($story, 'id')) {
            $value = array(
                'id' => $story->id,
                'hub_type'  => $story->hub_type,
                'hub_id'  => $story->hub_id,
                'title'  => $story->title,
                'content' => $story->content,
                'score' => $story->content,
                'name' => $story->name,
                'birthday' => $story->birthday,
                'source' => $story->source,
                'thumbnail' => $story->thumbnail,
                'likes' => property_exists($story, 'likes') ? $story->likes : 0,
                'comments' => property_exists($story, 'comments') ? $story->comments : 0,
                'liked' => $story->liked,
                'media_attachments' => ($story->hub_type === 'youtube') ? $story->media_attachments : '',
                'commented' => (isset($story->commented) && !is_null($story->commented)) ? ' is-commented' : '',
                'uri' => $story->uri
            );
        } else {
            $value = array(
                'id'=>$story['id'],
                'hub_type'=>$story['hub_type'],
                'hub_id'=>$story['hub_id'],
                'title'=>$story['title'],
                'content'=>$story['content'],
                'score'=>$story['score'],
                'name'=>$story['name'],
                'birthday'=>$story['birthday'],
                'source'=>$story['source'],
                'thumbnail'=>$story['thumbnail'],
                'likes'=> key_exists('likes', $story) ? $story['likes'] : 0,
                'comments'=> key_exists('comments', $story) ? $story['comments'] : 0,
                'liked'=>$story['liked'],
                'media_attachments'=>$story['media_attachments'],
                'commented' => (isset($story['commented']) && !is_null($story['commented'])) ? ' is-commented' : '',
                'uri' => $story['uri']
                );
        }
        return $value;
    }

    /**
     * $raw - wtf is this I could ask... Its an array of whatever, method that does everything. Bad practice.
     * Good  practice is when a method with descriptive name does one task. 1 method does all = bad... Anyway what assignCommunityForHashtagPosts means? What is more... WHY this method returns posts?
     * Ehh, I believe this method is responsible for assigning a community to a post BUT idfk!
     * From now, when you want to assign community to a post please use method: associateCommunityToPost($postId, $communityId)
     * The method returns array with parameter: success (either true or false, when false - message)
     * @param $raw
     * @return array
     */
    public function assignCommunityForHashtagPosts($raw){
        $posts = [];
        $pM = new PostMeta;
        foreach ($raw as $r){
            $community = $pM->getAssociatedMetaType($r['id'],'community');
            if ($community !== false){
                $r['community'] = $community;
            }
            $posts[] = $r;
        }
        return $posts;
    }

    public function associateCommunityToPost($postId, $communityId)
    {
        if (!is_numeric($postId) || !is_numeric($communityId)) {
            throw new Exception('PostId and CommunityId has to be numeric');
        }
        $postMetaM = new PostMeta();
        $communityM = new Community();
        //check if post and community exist
        if (!$this->countById($postId) > 0 || !$community = $communityM->getById($communityId)) {
            return array(
                'success' => false,
                'message' => 'Post or Community does not exist'
            );
        }
        //post and community exist, now check if community is already assigned to the post or not, if not - assign it to the post
        //now, it is complex, post meta type can be whatever, when associated then content is a json string and it is listed in post header
        //when type is community, the post will appear when we visit the community...
        //user id is added, set in saveMeta
        //update the associated key, so the list of associated communities is correct (post header)
        $associatedCommunitiesSelect = $postMetaM->select()
            ->where('post_id = ?', $postId)
            ->where('type = ?', 'associated');
        $associatedCommunitiesRow = $postMetaM->fetchRow($associatedCommunitiesSelect);
        if ($associatedCommunitiesRow) {
            $associatedCommunitiesArray = Zend_Json::decode($associatedCommunitiesRow->content);
            if (!in_array($community->url, $associatedCommunitiesArray)) {
                $associatedCommunitiesArray[] = $community->url;
                $associatedKeyUpdated = $postMetaM->save(array(
                    'id' => $associatedCommunitiesRow->id,
                    'content' => Zend_Json::encode($associatedCommunitiesArray)
                ));
            } else {
                //community already associated
                $associatedKeyUpdated = true;
            }
        } else {
            //no post meta row, create new one
            $associatedKeyUpdated = $postMetaM->save(array(
                'post_id' => $postId,
                'user_id' => Zend_Auth::getInstance()->getIdentity()->id,
                'type' => 'associated',
                'content' => Zend_Json::encode(array($community->url)),
                'created' => date('Y-m-d H:i:s')
            ));
        }
        //update community key, so post will show when we visit the community
        $communityKeyAdded = false;
        /*
         * ok, actually we don't want to do this because when we do it post's title inherits a community we're currently on...
        if ($postMetaM->getPostIdsFromAttributes('community', $communityId)->count() == 0) {
            $communityKeyAdded = $postMetaM->saveMeta($postId, 'community', $communityId);
        }*/
        //if either $associatedKeyUpdated or $communityKeyAdded - success
        if ($associatedKeyUpdated || $communityKeyAdded) {
            return array('success' => true);
        } else {
            return array(
                'success' => false,
                'message' => 'Something went wrong when updating associated communities for the post'
            );
        }
    }

    /**
     * Returns the top $limit trending post_ids on the Dyadey network
     */
    protected function _getDyadeyPlatformTrendingPosts($communityId, $timeFrameHours = '24', $limit = 3){

        // weight the value of the action
        $multiplier = array(
            'visits' => 1,
            'likes' => 1,
            'shares' => 5,
            'comments' => 3
        );

        $timeAgo = time() - ($timeFrameHours * 60 * 60);
        $db = Zend_Registry::get('db');
        $sql = "
                SELECT visit_post_id, COUNT(*) as count
                FROM activity_data as ad
                WHERE ad.modified >= $timeAgo
                AND visit_post_id IS NOT NULL
                GROUP BY visit_post_id
                ORDER BY count DESC
                LIMIT $limit
            ";
        $visits = $db->query($sql);
        foreach ($visits as $k => $v){
            $trends[] = array('post_id' => $v['visit_post_id'], 'score' => (float) $v['count'] * $multiplier['visits']);
        }
        $sql = "
                SELECT post_id, COUNT(*) as count
                FROM post_likes as pl
                WHERE pl.modified >= $timeAgo
                GROUP BY post_id
                ORDER BY count DESC
                LIMIT $limit
            ";
        $likes = $db->query($sql);
        foreach ($likes as $k => $v){
            $trends[] = array('post_id' => $v['post_id'], 'score' => (float) $v['count'] * $multiplier['likes']);
        }
        $sql = "
                SELECT post_id, COUNT(*) as count
                FROM post_shares as pl
                WHERE pl.modified >= $timeAgo
                GROUP BY post_id
                ORDER BY count DESC
                LIMIT $limit
            ";
        $shares = $db->query($sql);
        foreach ($shares as $k => $v){
            $trends[] = array('post_id' => $v['post_id'], 'score' => (float) $v['count'] * $multiplier['shares']);
        }
        $sql = "
                SELECT post_id, COUNT(*) as count
                FROM post_comments as pc
                WHERE pc.modified >= $timeAgo
                GROUP BY post_id
                ORDER BY count DESC
                LIMIT $limit
            ";
        $comments = $db->query($sql);
        foreach ($comments as $k => $v){
            $trends[] = array('post_id' => $v['post_id'], 'score' => (float) $v['count'] * $multiplier['comments']);
        }

        if (isset($trends)){
            usort($trends, function($a, $b) {
                return $b['score'] - $a['score'];
            });

            //top $limit likes, shares, comments and visits combined scores
            $topTrends = array();
            foreach ($trends as $data){
                if (array_key_exists($data['post_id'],$topTrends)){
                    $topTrends[$data['post_id']] += $data['score'];
                } else {
                    $topTrends[$data['post_id']] = $data['score'];
                }
            }
            arsort($topTrends);
            $creamOnly = array_slice($topTrends, 0, 3, $limit);
            return $creamOnly;
            }
        return array();
    }

    /**
     *
     * @param $id
     * @return null|Zend_Db_Table_Row_Abstract
     */
    public function getById($id) {
        //we check if there is an authenticated user... used to show if user liked a post...
        $userLiked = (Zend_Auth::getInstance()->hasIdentity()) ? Zend_Auth::getInstance()->getIdentity()->id : 0;

        $select = $this->select()->setIntegrityCheck(false)->from('posts', array(
            'id', 'hub_type', 'hub_id', 'title', 'content', 'score','score_before_override','score_override_start','score_override_time_period', 'name', 'uri', 'location', 'modified', 'hidden', 'media_attachments', 'draft', 'source',
            'COALESCE(birthday, created) AS birthday',
            'COALESCE(thumbnail, (SELECT post_meta.content FROM post_meta WHERE post_meta.post_id = posts.id AND post_meta.type = \'image\' LIMIT 0,1), NULL) AS thumbnail',
            '(SELECT COUNT(*) FROM post_likes WHERE post_id = posts.id) as likes',
            '(SELECT COUNT(*) FROM post_comments WHERE post_id = posts.id) as comments',
            'COALESCE((SELECT post_likes.id FROM post_likes WHERE post_likes.user_id = ' . $userLiked . ' AND post_likes.post_id = posts.id LIMIT 0,1), NULL) AS liked',
            'COALESCE((SELECT post_comments.id FROM post_comments WHERE post_comments.user_id = ' . $userLiked . ' AND post_comments.post_id = posts.id LIMIT 0,1), NULL) AS commented',
        ))
            ->where('posts.id = ?', $id);
        $result = $this->fetchRow($select);
        return $result;
    }

    public function getByIds($idsStr) {
        //we check if there is an authenticated user... used to show if user liked a post...
        $userLiked = (Zend_Auth::getInstance()->hasIdentity()) ? Zend_Auth::getInstance()->getIdentity()->id : 0;
        $db = Zend_Registry::get('db');
        $select = $db->select()->from('posts', array(
            'id', 'hub_type', 'hub_id', 'title', 'content', 'score','score_before_override','score_override_start','score_override_time_period', 'name', 'uri', 'location', 'modified', 'hidden', 'media_attachments', 'draft',
            'COALESCE(birthday, created) AS birthday',
            'COALESCE(thumbnail, (SELECT post_meta.content FROM post_meta WHERE post_meta.post_id = posts.id AND post_meta.type = \'image\' LIMIT 0,1), NULL) AS thumbnail',

            //'SELECT post_meta.content FROM post_meta, posts WHERE post_meta.post_id = posts.id AND post_meta.type = \'community\' AS comm_id',

            '(SELECT COUNT(*) FROM post_likes WHERE post_id = posts.id) as likes',
            '(SELECT COUNT(*) FROM post_comments WHERE post_id = posts.id) as comments',
            'COALESCE((SELECT post_likes.id FROM post_likes WHERE post_likes.user_id = ' . $userLiked . ' AND post_likes.post_id = posts.id  LIMIT 0,1), NULL) AS liked',
        ))->where('posts.id IN (?)', $idsStr)->where('posts.hub_type = ?', 'dyadey');

        $result = $db->fetchAll($select);

//        echo $select;


        return (isset($result[0]) && !empty($result[0])) ? $result : false;
    }

    public function getAll($order = 'modified desc'){
        $select = $this->select()->order($order);
        return $this->fetchAll($select);
    }

    public function findByFingerprint($fingerprint = ''){
        $select = $this->select()->where('fingerprint = ?',$fingerprint);
        return $this->fetchRow($select);
    }

    public function findCountByFingerprint($fingerprint = ''){
        $select = $this->select()->from($this->_name, 'count(*) as count')->where('fingerprint = ?',$fingerprint);
        $res = $this->fetchRow($select);

        return ($res['count'] === '0') ? 0 : 1;
    }

    public function findByHubType($type){
        $select = $this->select()->where('hub_type = ?',$type);
        return $this->fetchAll($select);
    }

    public function getTitleTypeAndIds($ids, $flat = 1){
        $select = $this->select()->from($this->_name, array('id','title','hub_type'))
            ->where('id IN (?)',$ids);
        $res = $this->fetchAll($select);
        if ($flat && !empty($res[0])){
            foreach ($res as $p){
                $ps[$p->id] = array('title' => $p->title, 'hub_type' => $p->hub_type);
            }
            return $ps;
        }
        return (!empty($res)) ? $res : null;
    }

    /**
     * Sort meta for Write a Post
     */
    private function _sortPostMeta(){
        // split the meta from the post
        if (isset($this->_post['image'])){
            $this->_meta['image'] = $this->_post['image'];
            unset($this->_post['image']);
        }
        if (isset($this->_post['link'])){
            $this->_meta['link'] = $this->_post['link'];
            unset($this->_post['link']);
        }
        if (isset($this->_post['media'])){
            $this->_meta['media'] = $this->_post['media'];
            unset($this->_post['media']);
        }
        if (isset($this->_post['keywords'])){
            $this->_meta['keywords'] = $this->_post['keywords'];
            unset($this->_post['keywords']);
        }
        if (isset($this->_post['post-type'])){
            $this->_meta['post-type'] = $this->_post['post-type'];
            unset($this->_post['post-type']);
        }
        if (isset($this->_post['community'])){
            $this->_meta['community'] = $this->_post['community'];
            unset($this->_post['community']);
        }

        if (isset($this->_post['associated'])) {
            $this->_meta['associated'] = $this->_post['associated'];
            unset($this->_post['associated']);
        }
    }

    /**
     * sort meta for Upload Media
     */
    private function _sortPostMediaMeta(){
        $this->_sortPostMeta();
    }

    /**
     * sort meta for Shar a Link
     */
    private function _sortPostLinkMeta(){
        $this->_sortPostMeta();
    }

    /**
     *
     * Called by saveDyadeyPost()
     * Save post, keywords relationships and meta
     */
    private function _savePost()
    {
        // adding test comment
        $psM = new PostScoringParams;
        $param = $psM->getParams();
        // start transaction
        $this->getAdapter()->beginTransaction();
        try {
            $this->_post['draft'] = ($this->_post['draft'] == 1) ? 1 : null;
            $this->_post['score'] = $param['dyadey_post_base_score'];

            $hashtags = $this->_post['hashtags'];
            unset($this->_post['hashtags']);

            $res = $this->save($this->_post);
            // if the old post id was 0 then update post id with new id
            if ($this->_post['id'] < 1){
                $this->_post['id'] = $res;
            }

            // save any hashtags
            // if (is_array($hashtags) && !empty($hashtags)){
            //     $htM = new Hashtags;
            //     $htM->saveHashtags($hashtags, $this->_post['id']);
            // }

            $pmM = new PostMeta;
            // get any postmeta
            // override with any new data and resave?
            $postmeta = $pmM->getAllMeta($this->_post['id']);

            $meta = array();
            foreach($postmeta as $km => $vm):
                $meta[$vm['type']] = $vm['content'];
            endforeach;

            foreach($this->_meta as $km => $vm):
                $meta[$km] = $vm;
            endforeach;

            // This deletes existing postmeta which means we lose the media from the first post on edit
            $pmM->deleteAssociatedMeta($this->_post['id']);
            foreach ($meta as $type => $content){
                if ($type == 'keywords'){
                    $this->_savePostKeywords($content);
                } else if ($type === 'associated') {
                    $pmM->saveMeta($this->_post['id'], $type, json_encode($content));
                } else {
                    $pmM->saveMeta($this->_post['id'], $type, $content);
                }
            }
            // commit transaction
            $this->getAdapter()->commit();
            // destroy the posts cache so that THIS post will display on the timeline
            Zend_Session::namespaceUnset('community_posts');

        } catch (exception $e) {
            $this->getAdapter()->rollback();
            return json_encode($e);
        }
        return $this->_post['id'];
    }

    private function _savePostKeywords($keywords = ''){
        // first remove any previously associated keywords
        $kwrM = new KeywordsRelationships;
        // post id depends whether we are updating an existing post, or inserting a new one.
        //$post_id = ($this->_newPostId) ? $this->_newPostId : $this->_post['id'];
        $where = $this->getAdapter()->quoteInto('post_id = ?', $this->_post['id']);
        $where .= ' AND keyword_id is NOT NULL ';
        $kwrM->delete($where);

        // then save new keyword--post relationships
        if (!empty($keywords)){
            // keywords coming from the posts forms are an array of 1 containing a csv string,
            // in which case flatten the array to a string
            if (is_array($keywords) && count($keywords) == 1){
                $keywords = $keywords[0];
            }
            $tmp = explode(',',$keywords);
            foreach ($tmp as $t){
                $kws[] = trim($t);
            }
            return $kwrM->insertRelationships($this->_post['id'], $kws, $relType = 'post');
        }
    }

    /**
     *
     * Save a post
     *
     * @param string $postType
     */
    public function saveDyadeyPost($post){
        $this->_post = $post;
        $this->_sortPostMeta();
        $res = $this->_savePost();

        return $res;
    }

    protected function _findRealTimeKeywords($activities, $excludePostId, $minsAgo = 3){
        if ($excludePostId == null){
            // I am visiting another community, so check last $minAgo mins keyword history
            $keywordsInTimeRange = array();
            foreach ($activities as $act){
                // get posts to upgrade their score based on real-time user keyword activity
                $crawlLastModified = (isset($act['crawl_post_visits']['last_modified'])) ? $act['crawl_post_visits']['last_modified'] : array();
                foreach ($crawlLastModified as $keywordId => $lm){
                    if (time() - strtotime($lm) <= ($minsAgo * 60)){
                        if (!in_array($keywordId,$keywordsInTimeRange)){
                            $keywordsInTimeRange[] = $keywordId;
                        }
                    }
                }
                $dyadeyLastModified = (isset($act['dyadey_post_visits']['last_modified'])) ? $act['dyadey_post_visits']['last_modified'] : array();
                foreach ($dyadeyLastModified as $lm){
                    if (time() - strtotime($lm) <= ($minsAgo * 60)){
                        if (!in_array($keywordId,$keywordsInTimeRange)){
                            $keywordsInTimeRange[] = 1;
                        }
                    }
                }
            }
            return (isset($keywordsInTimeRange)) ? $keywordsInTimeRange : array();
        }
        return array();
    }

    protected function _postScoreLog($post)
    {
        $select = $this->select()->from($this->_name, array('score'))->where('id = ?', $post['id'])
            ->order('modified DESC')->limit(1);
        $res = $this->fetchAll($select);
        foreach ($res as $r){
            $score = $r['score'];
        }
        $update = (isset($score) && $score != $post['score']) ? $this->save($post) : false;
    }

    /**
     *
     * Degrade post's base score (not active score) according to creation date
     * After n days the base score will be zero
     *
     * The post
     *
     */
    protected function _postAgeProcessing($score, $duration, $max_percentage = 100, $created){
        $now = time();
        $daySeconds = 24*3600;
        $days = $duration;
        $percentageToRemoveEachDurationPeriod = ($max_percentage / $days) / 100;
        $postCreated = strtotime($created);
        $postAgeDays = (float) floor((time() - $postCreated) / $daySeconds);
        $thisPeriodPercentageToRemove = $postAgeDays * (float) $percentageToRemoveEachDurationPeriod;
        if ($thisPeriodPercentageToRemove >= 1){
            $amountToRemove = $score;
        } else {
            $amountToRemove = ceil((float)$score * $thisPeriodPercentageToRemove);
        }
        return $amountToRemove;
    }

    protected function _liveScoring(&$posts, $excludePostId, $commId){
        $user = Zend_Auth::getInstance()->getIdentity();
        if (empty($posts) || empty($user))
            return false;

        // get parameter variables for scoring, from DB
        $pspM = new PostScoringParams;
        $scoringParams = $pspM->getParams();

        // initialise model for saving scores
        $psM = new PostScoring;

        $pl = new PostLocations;
        $myPopularLocations = $pl->getUserLocationActivity();

        $h = new Action_Helper_UserActivity;
        $userActivity = $h->direct($user->id);

        $a = new Accreditation;
        $accreditedUserPostIds = $a->getAccreditedUsersPostIds();

        $keywordDensities = isset($userActivity[1][$user->id]['keywords']) ? $userActivity[1][$user->id]['keywords'] : array();
        $activities = array_shift($userActivity);
        $keywordsInTimeRange = $this->_findRealTimeKeywords($activities, $excludePostId);
        $kwr = new KeywordsRelationships;
        // get all keywords so we don't need to go to the db for each post
        $postKeywords = [];
        foreach($posts as $p){
            $myPosts[] = $p->toArray();
            // if these are NOT dyadey posts, then where do we get the dyadey post keywords?
            //$postKeywords[] = $kwr->getPostKeywords($p->id);
        }

        $postScoreDatabase = array(); // store all postScores to send one giant query to DB at end of processing

        // iterate the posts
        foreach ($myPosts as $k => $post){

            $postScore = array();// gonna save to the post_scoring table
            $currentScore = $post['score'];

            goto we_have_bipassed_live_score;

        /**********************  upgrade the score based on same country as user *********************/
            if (isset($post['location_country']) && !empty($post['location_country'])){
                if (isset($this->userLocation) && !empty($this->userLocation)){
                    if ($this->userLocation == $post['location_country']){
                        $postScore['user_post_location'] =  $scoringParams['user_post_location'];
                        $myPosts[$k]['score'] = (string)((float)$posts[$k]['score'] + $scoringParams['user_post_location']);
                    }
                }
            }
        /***************  upgrade the score based on popular locations I'm reading from ***************/
            if (!empty($myPopularLocations)){
                $postLocations = explode(' ', $post['location']);
                if (!empty($postLocations[0])){
                    foreach ($postLocations as $pl){
                        if (in_array(trim($pl), $myPopularLocations)){
                            $postScore['user_popular_location'] =  $scoringParams['user_popular_location'];
                            $myPosts[$k]['score'] = (string)((float)$posts[$k]['score'] + $scoringParams['user_popular_location']);
                        }
                    }
                }
            }

            // iterate ALL post keywords to find keywords for THIS post
            foreach ($postKeywords as $pkwA) {
                if ($post['id'] == $pkwA['post_id']){
                    // we have found this post's keywords, now iterate them
                    foreach ($pkwA['keywords'] as $kwId){
        /********************  upgrade the score based on user's keyword density **********************/
                        $upgraded = 0;
                        if (array_key_exists($kwId, $keywordDensities)){
                            $x = $posts[$k]['score'];
                            $y = (float)$keywordDensities[$kwId];
                            $newScore = ceil($x * $y);
                            $postScore['user_keyword_density_upgrade'] =  $newScore - $x;
                            $myPosts[$k]['score'] = $newScore;
                            $upgraded = 1;
                        }
        /***********************  upgrade score based on real-time user activity **********************/
                        if (in_array($kwId, $keywordsInTimeRange)){
                            $x = ($upgraded) ? $myPosts[$k]['score'] : $posts[$k]['score'];
                            $myPosts[$k]['score'] = ($x + $scoringParams['user_realtime_activity']);
                            $postScore['user_realtime_activity'] =  $scoringParams['user_realtime_activity'];
                        }
                    }
                    break;
                }
            }
        /***************************  upgrade score based on Accredited User **************************/
        if (in_array($post['id'], $accreditedUserPostIds)){
            $postScore['user_accredited'] =  $scoringParams['user_accredited'];
            $myPosts[$k]['score'] += $scoringParams['user_accredited'];
        }
        /***********************  upgrade score based on Trending Post on Dyadey **********************/
            $trending = $this->_getDyadeyPlatformTrendingPosts($commId);
            if (array_key_exists($post['id'], $trending)){
                $postScore['trending'] =  $scoringParams['trending'];
                $myPosts[$k]['score'] += $scoringParams['trending'];
            }

            // log score change - not used at the moment because score changes are specific to user
            if (1==2 && $myPosts[$k]['score'] != $currentScore){
                $data = array('id' => $post['id'], 'score' => $myPosts[$k]['score']);
                $this->_postScoreLog($data);
            }

        /************************  degrade passive score based on creation date of  ***********************/
            // as the upgrades above use read-only $posts object we need to use non-upgraded $currentScore
            $degradedBaseScore = $this->_postAgeProcessing($myPosts[$k]['score'], $scoringParams['age_degrade_duration'], $scoringParams['age_degrade_max_percentage'], $post['birthday']);
            $postScore['age_degraded_amount'] = $degradedBaseScore;
            $myPosts[$k]['score'] = $myPosts[$k]['score'] - $degradedBaseScore;

        /***********************************************************************/

            we_have_bipassed_live_score:

            // finally, save the post score to database for reporting
            $postScore['passive_score'] = $currentScore;
            $postScore['user_id'] = $user->id;
            $postScore['post_id'] = $post['id'];
            $postScore['network'] = $post['hub_type'];
            $postScore['final_score'] = $myPosts[$k]['score'];
            $postScore['created'] = date('Y-m-d H:i:s');

            $postScoreDatabase[] = $postScore;
        }

        $psM->saveAll($postScoreDatabase);

        // *** NOTE: we are now sorting by birthday, which effectively negates all the live scoring *** //
        usort($myPosts, function($a, $b) {
            return strtotime($b['birthday']) - strtotime($a['birthday']);
        });

        // replace the $posts object with $myPosts array
        $posts = $myPosts;

        // cache posts for THIS user...
        $commPostsCache = new Zend_Session_Namespace('community_posts');
        if (!isset($commPostsCache->$commId) || empty($commPostsCache->$commId)){
            // if this community does not have cached posts then cache them for 10 minutes
            $commPostsCache->$commId = $posts;
            $commPostsCache->setExpirationSeconds(300,$commId);
        }
    }

    public function getPostsByHashtags($tagsStr, $excludePostId = null, $page = 1, $perPage = 180){
        $db = Zend_Registry::get('db');
        $sql = "
            SELECT post_id
            FROM hashtags_lookup
            WHERE hashtag_id IN (
                SELECT id from hashtags where tag IN ($tagsStr)
            )
        ";
        $posts = [];
        $postRes = $db->query($sql);
        $pidsStr = '';
        $postIdsA = [];
        foreach ($postRes as $pr){
            $postIdsA[] = $pr['post_id'];
        }
        return (!empty($postIdsA)) ? $this->getByIds($postIdsA) : array();
    }



    /**
      * We get n = $max posts and cache them in session in _liveScoring(). We then use CommunityController->paginatePosts() to pull back
     *  the next set of posts from session cache.
     *  If session has expired, and we are calling this method from paginatePosts(), we need to get n= $max posts again and return a
     *  selection starting from $current -1.
     *  We propagate the $current variable in js global scope in lazyload-posts.js
     */
    public function getComPosts($id, $excludePostId = null, $current = 0, $perPage = 16, $max = 1920, $callback = false, $last_index = 0, $cur = 0) {

        //we check if there is an authenticated user... used to show if user liked a post...
        $userId = (Zend_Auth::getInstance()->hasIdentity()) ? Zend_Auth::getInstance()->getIdentity()->id : 0;
        $communityM = new Community;


        //all posts assigned to a community
        $keywords = $communityM->getComKeywords($id, true);


            foreach ($keywords as $keywordId => $keywordName) {
                //if ($keywordId == 286) continue;
                $tmp[] = $keywordId;
            }

            /************* Changing logic here: getting hub_id directly from hubs instead of by keyword **************/
            /*$select = $this->select()->setIntegrityCheck(false)->from('keywords_relationships','hub_id')
                ->where('keyword_id IN (?)', $tmp)
                ->where('hub_id IS NOT NULL')
                ->group(['hub_id']);
            $hub_ids = $this->fetchAll($select);
            $y = $select->__toString();
             */

            $select = $this->select()->setIntegrityCheck(false)->from('hubs',array('id'))
                ->where('community_id = ?', $id);
            $hub_ids = $this->fetchAll($select);
            $y = $select->__toString();

            //dyadey posts
            // $select = $this->select()->setIntegrityCheck(false)->from('keywords_relationships','post_id')
            //     ->where('keyword_id IN (?)', $tmp)
            //     ->where('post_id IS NOT NULL')
            //     ->group(['post_id']);
            // $dyadey_post_ids = $this->fetchAll($select);

            // THIS IS BATSHIT CRAZY AND USE A KEYWORD RELATIONSHIP MODEL WHICH IS NOW ARACHAIC
            // GET ALL POST IDS FROM POST META WHERE COMMUNITY = ID


            unset($tmp);
            /**
             * Things I've used here...
             *
             * Because dyadey post is different that other posts... We have posts_meta table where we store post specific meta things I've used
             * mySql COALESCE function in conjunction with sub selects. For instance dyadey post will have empty birthday column in posts table + empty thumbnail.
             * Birthday: If birthday is null then we use created
             * Thumbnail: if is empty then we select post_meta.content where type is image + post_id match current record; last COALESCE value is null so if thumbnail is null and no image is assigned to a post then we return NULL...
             *
             */
            if (count($hub_ids) > 0) {
                foreach ($hub_ids as $obj) {
                    $theHubId = (isset($obj->id)) ? $obj->id : $obj->hub_id;
                    $tmp2[] = $theHubId;
                }
                $world = true;
            }
            // if (count($dyadey_post_ids) > 0) {
            //     foreach ($dyadey_post_ids as $obj) $tmp3[] = $obj->post_id;
            //     $dyadey = true;
            // }

            // get POST IDs where meta type = community AND content = community ID
            $postMetaM = new PostMeta;
            $ids = $postMetaM->getPostIdsFromAttributes('community',$id);

            foreach ($ids as $key => $value) {
                $tmp3[] = $value->post_id;
                $dyadey = true;
            }


            if (isset($world) || isset($dyadey)) {


                $select = $this->select()->setIntegrityCheck(false)->from('posts', array(
                    'id', 'hub_type', 'title', 'hub_id', 'uri' ,'content', 'score', 'source' ,'name', 'location', 'location_country', 'modified', 'hidden', 'media_attachments',
                    'COALESCE(birthday, created) AS birthday',
                    'COALESCE(thumbnail, (SELECT post_meta.content FROM post_meta WHERE post_meta.post_id = posts.id AND post_meta.type = \'image\' LIMIT 0,1), NULL) AS thumbnail',
                    '(SELECT COUNT(*) FROM post_likes WHERE post_id = posts.id) as likes',
                    '(SELECT COUNT(*) FROM post_comments WHERE post_id = posts.id) as comments',
                    'COALESCE((SELECT post_likes.id FROM post_likes WHERE post_likes.user_id = ' . $userId . ' AND post_likes.post_id = posts.id LIMIT 0,1), NULL) AS liked',
                    'COALESCE((SELECT post_comments.id FROM post_comments WHERE post_comments.user_id = ' . $userId . ' AND post_comments.post_id = posts.id LIMIT 0,1), NULL) AS commented',
                ));


                if (isset($world) && isset($dyadey)) {
                    //posts from world and dyadey
                    $select = $select->where('(hub_id IN (?)', $tmp2)
                        ->orWhere('id IN(?))', $tmp3);
                } else if (isset($world)) {
                    //worlds posts only
                    $select = $select->where('hub_id IN (?)', $tmp2);
                } else {
                    //dyadey posts only
                    $select = $select->where('id IN (?)', $tmp3);
                }

                //exclude post... For post specific page
                if ($excludePostId) {
                    $select = $select->where('id NOT IN (?)', $excludePostId);
                }


                //exclude hidden and low score posts
                $select = $select->where('score > 1')
                    ->where('(hidden IS NULL')
                    ->orWhere('hidden = 0)');
                // $order = new Zend_Db_Expr($this->getAdapter()->quoteInto("posts.hub_type <> ?", 'dyadey') .", posts.modified DESC");
                $select = $select
                    // ->order($order)
                    ->order('posts.birthday DESC')
                    ->limitPage($cur, $perPage)
                ;


                if ($callback) {
                    $off = ($current > 0) ? $current : 0;
                    return $select->__toString() . ':::offset='.$off;
                }
                //$select->group('hub_id');

                $posts = $this->fetchAll($select);
                $x = $select->__toString();
                if (count($posts) > 0){
                    //return $posts->toArray();
                    $this->_liveScoring($posts, $excludePostId, $id);
                } else {
                    return null;
                }

                $offset = ($current > 0) ? $current : 0;
                // return array_slice($posts, $offset, $perPage);
                return $posts;
            }
            // no hubs allocated to this community... @todo: this could be a warning
            return null;
        // }

        // else {
        //     // no posts allocated to this community...
        //     return null;
        // }
    }

    /**
     * @param $profile_id - user id
     * @param int $page
     * @param int $perPage
     * @return null|string|Zend_Db_Table_Rowset_Abstract
     */
    public function getUserPosts($profile_id, $drafts = false, $page = 1, $perPage = 0) {

        $posts = array();
        $tmp = array();

        if ($profile_id) {



                $offset = ($page > 1) ? (1+(($page-1)*$perPage)) : 0;
                $viewer_id = (Zend_Auth::getInstance()->hasIdentity()) ? Zend_Auth::getInstance()->getIdentity()->id : 0;

                //posts ids from post_meta
                $select = $this->select()->setIntegrityCheck(false)->from('post_meta','DISTINCT(post_id)')
                    ->where('user_id = ?', $profile_id);

                $posts_ids = $this->fetchAll($select);

                foreach ($posts_ids as $obj) {
                    $tmp[] = $obj->post_id;
                }

                if (count($tmp)>0) {

                    $select = $this->select()->setIntegrityCheck(false)->from('posts', array(
                        'id', 'hub_id', 'hub_type', 'title', 'content', 'thumbnail', 'source', 'created AS birthday', 'media_attachments', 'score', 'name', 'uri',
                        '(SELECT post_meta.content FROM post_meta WHERE post_meta.post_id = posts.id AND post_meta.type = \'image\' LIMIT 0,1) AS thumbnail2',
                        '(SELECT post_meta.content FROM post_meta WHERE post_meta.post_id = posts.id AND post_meta.type = \'community\' LIMIT 0,1) AS community_id',
//                    '(SELECT COUNT(*) FROM post_likes WHERE post_id = posts.id) AS likes',
//                    '(SELECT COUNT(*) FROM post_comments WHERE post_id = posts.id) AS comments',
                        '(SELECT communities.name FROM communities WHERE communities.id = community_id) AS community_name',
                        '(SELECT communities.url FROM communities WHERE communities.id = community_id) AS community_url',
                        'COALESCE((SELECT post_likes.id FROM post_likes WHERE post_likes.user_id = ' . $viewer_id . ' AND post_likes.post_id = posts.id LIMIT 0,1), NULL) AS liked',
                        'COALESCE((SELECT post_comments.id FROM post_comments WHERE post_comments.user_id = ' . $viewer_id . ' AND post_comments.post_id = posts.id LIMIT 0,1), NULL) AS commented',
                    ))
                        ->where('id IN (?)', $tmp);

                    $select = $select->order('score DESC')
                        ->order('posts.created DESC')
                        ->limit($perPage, $offset);

                    $posts = $this->fetchAll($select);

                } else {
                    $posts = array();
                }
            }

            return $posts;

    }

    public function getPostsLikedByUser($profile_id, $page = 1, $perPage = 0)
    {

        $posts = array();



        if ($profile_id) {


                $offset = ($page > 1) ? (1 + (($page - 1) * $perPage)) : 0;

                $viewer_id = (Zend_Auth::getInstance()->hasIdentity()) ? Zend_Auth::getInstance()->getIdentity()->id : 0;

                //posts ids from post_meta
                // $select = $this->select()->setIntegrityCheck(false)->from('post_likes','DISTINCT(post_id)')
                //     ->where('user_id = ?', $id);

                // $posts_ids = $this->fetchAll($select);
                $postlikeM = new PostLikes;
                $post_likes = $postlikeM->getUserLikesByDate($profile_id);

                $posts = array();

                if (count($post_likes) > 0) {

                    foreach ($post_likes as $key => $value) {
                        $posts[$key] = $value;

                        $select = $this->select()->setIntegrityCheck(false)->from('posts', array(
                            'id', 'hub_id', 'hub_type', 'title', 'content', 'source', 'created AS birthday', 'media_attachments', 'score', 'name', 'uri',
                            'COALESCE(thumbnail, (SELECT post_meta.content FROM post_meta WHERE post_meta.post_id = posts.id AND post_meta.type = \'image\' LIMIT 0,1), NULL) AS thumbnail',
                            'COALESCE((SELECT post_meta.content FROM post_meta WHERE post_meta.post_id = posts.id AND post_meta.type = \'community\' LIMIT 0,1), (SELECT post_likes.community_id FROM post_likes WHERE post_likes.post_id = posts.id AND post_likes.user_id = ' . $profile_id . ' LIMIT 0,1), NULL) AS community_id',
                            '(SELECT COUNT(*) FROM post_likes WHERE post_id = posts.id) AS likes',
                            '(SELECT COUNT(*) FROM post_comments WHERE post_id = posts.id) AS comments',
                            '(SELECT communities.name FROM communities WHERE communities.id = community_id) AS community_name',
                            '(SELECT communities.url FROM communities WHERE communities.id = community_id) AS community_url',
                            'COALESCE((SELECT post_likes.id FROM post_likes WHERE post_likes.user_id = ' . $viewer_id . ' AND post_likes.post_id = posts.id LIMIT 0,1), NULL) AS liked',
                            'COALESCE((SELECT post_comments.id FROM post_comments WHERE post_comments.user_id = ' . $viewer_id . ' AND post_comments.post_id = posts.id LIMIT 0,1), NULL) AS commented',
                        ))
                            ->where('id =?', $value['post_id']);
                        $this_post = $this->fetchRow($select);

                        foreach ($this_post as $key2 => $value2) {
                            $posts[$key][$key2] = $value2;
                        }
                    }
                }



            return $posts;
        } else {
            return array();
        }
    }

    private function sortIntoBatches(Zend_Db_Table_Rowset $posts)
    {
        $batches = null;
        $now = new DateTime();

        // loop
        foreach ($posts as $key => $value) {
            $postArray = $posts->current()->toArray();
            $postArray['community_uri'] = str_replace(' ', '-', strtolower($postArray['community_name'])) . '/' . $postArray['community_id'] . '/';
            $postArray['time'] = $this->_WhenAgo($postArray['created']);

            $batchIndicator = date('F Y', strtotime($postArray['created']));
            if (!isset($batches[$batchIndicator])) {
                $batches[$batchIndicator] = array(
                    'header' => $batchIndicator,
                    'posts' => array()
                );
                $batches[$batchIndicator]['posts'][] = $postArray;
            } else {
                $batches[$batchIndicator]['posts'][] = $postArray;
            }
        }

        return $batches;
    }

    public function _getTileTitle($story)
    {
        $title = '';
        if($story['hub_type'] === 'instagram' && !is_null($story['content'])) {
            $title = $story['content'];
        } else {
            $title = '';
            $title = (empty($story['title'])) ? $story['content'] : $story['title'];
        }

        if (isset($story['og_link']) && isset($story['og_link']['url'])) {
            if (str_replace(['https:','http:'],'',$story['og_link']['url']) === str_replace(['https:','http:'],'',$story['content'])) {
                // The only content in the post is the link
                // Issue: 8040 add OG Title to the tile title
                $title = !empty($story['og_link']['title']) ? ($story['og_link']['title'] . ' ' . $title) : $title;
            }
        }

        $title_segments = explode(' ', $title);
        // var_dump($title_segments);
        // exit;
        $tmp_title = '';
        $counter = 25;

        if (count($title_segments) > $counter) {
            for($i = 0; $i < $counter ; $i++) {
                $tmp_title .= $title_segments[$i];
                if ($i < $counter-1) {
                    $tmp_title .= ' ';
                } else {
                    $tmp_title .= '...';
                }
            }
            $title = $tmp_title;
        }

        return $title;
    }

    public function _getSourceText($story) {
        $sourceText = '';

        if ($story['hub_type'] == 'googlerss') {
            $sourceText = (!empty($story['name'])) ? $story['name'] : '';
        }

        return $sourceText;
    }

    public function _getGoogleRSSSource($story) {
        $googleRSSsource = '';

        if ($story['hub_type'] == 'googlerss') {
            $googleRSSsource = (!empty($story['name'])) ? (' data-source="' . ucwords($story['name']) . '" ') : ' data-source="Google RSS Feed" ';
        }

        return $googleRSSsource;
    }

    public function _hideMedia($story) {
        $hideMedia = '';

        $hideMedia = (empty($story['inline'])) ? (' class=" c-card state-hidden hide-media ' . $story['hub_type'] . '"') : (' class=" c-card state-hidden ' . $story['hub_type'] . '"');

        return $hideMedia;
    }

    public function _getInline($story) {
        $inline = null;
        $classes = 'tile-image';

        if (in_array(strtolower(pathinfo($story['thumbnail'], PATHINFO_EXTENSION)),$this->_allowed_formats) || strstr($story['source'],'facebook.com/video')) {
            // this could be used to get a thumbnail based on the video uploaded
            $inline = !empty($story['thumbnail']) ? (' <img class="' . $classes . '" src=\'/timthumb.php?src=/img/facebooklive.png&w=540&h=434&zc=1&q=100\'>') : '';
        } else
            if ($story['hasImage']) {
            $inline = !empty($story['thumbnail']) ? (' <img class="' . $classes . '" src=\'' . $story['thumbnail'] . '\'>') : '';
        }

        return $inline;
    }

    private function _getAuthorDetails($post)
    {
        $author = array();

        switch($post['hub_type']):
            case 'dyadey':
                $usersM = new Users();
                $postMetaM = new PostMeta;
                $postMeta = $postMetaM->getAllMeta($post['id']);
                $q = $usersM->select()->from('_users', array('username', 'first_name', 'surname'))->where('id = ?', $postMeta[0]->user_id);
                $res = $usersM->fetchRow($q);

                $author = array(
                    'username' =>$res->username,
                    'first_name' => $res->first_name,
                    'surname' => $res->surname,
                    'id' => $postMeta[0]->user_id
                );

                break;
            default:
                break;
        endswitch;

        return (object)$author;
    }

    public function getAuthor($post)
    {
        $author = '';

        switch($post['hub_type']):
            case 'dyadey':
                $usersM = new Users();
                $postMetaM = new PostMeta;
                $postMeta = $postMetaM->getAllMeta($post['id']);
                $q = $usersM->select()->from('_users', array('username', 'first_name', 'surname'))->where('id = ?', $postMeta[0]->user_id);
                $res = $usersM->fetchRow($q);
                $origin = empty($res->username) ? ($res->first_name . ' ' . $res->surname) : $res->username;
                $author = $res->first_name . ' ' . $res->surname;
                break;
            default:
                $commM = new Community;
                $community = $commM->getCommunityById($post['community_id']);
                $author = $community->name;
        endswitch;

        return $author;
    }

    public function getAuthorUsername($post)
    {
        $author = '';

        switch($post['hub_type']):
            case 'dyadey':

                $usersM = new Users();
                $postMetaM = new PostMeta;
                $postMeta = $postMetaM->getAllMeta($post['id']);
                $q = $usersM->select()->from('_users', array('username', 'first_name', 'surname'))->where('id = ?', $postMeta[0]->user_id);
                $res = $usersM->fetchRow($q);
                $origin = empty($res->username) ? ($res->first_name . ' ' . $res->surname) : $res->username;
                $author = $res->username;
                break;
            default:
                $commM = new Community;
                $community = $commM->getCommunityById($post['community_id']);
                $author = $community->name;
        endswitch;

        return $author;
    }

    public function _augmentModel($story, $commId = null, $options = array())
    {
        $pM = new PostMeta;
        $communityM = new Community;
        $hubM = new Hub;

        $meta = $pM->getAllMeta($story['id']);

        $badge_override = array_key_exists('badge_override', $options) ? $options['badge_override'] : false;
        $square_badge = array_key_exists('square_badge', $options) ? $options['square_badge'] : false;
        $profile_pic_badge = array_key_exists('profile_pic_badge', $options) ? $options['profile_pic_badge'] : false;
        $badge_name_override = array_key_exists('badge_name_override', $options) ? $options['badge_name_override'] : false;
        $date_tile_override = array_key_exists('date_tile_override', $options) ? $options['date_tile_override'] : false;
        $date_tile_text = array_key_exists('date_tile_text', $options) ? $options['date_tile_text'] : '';
        $show_hubtype = array_key_exists('show_hubtype', $options) ? $options['show_hubtype'] : false;


        if (is_object($story)) {
            $value = $this->generateDataFromObject($story);
        } else {
            $value = $story;
        }


        if (is_null($commId)) {
            // get community ID from the post meta
            foreach ($meta as $key => $val) {
                if ($val['type'] === 'community') {
                    $commId = $val['content'];
                }
            }

            if (is_null($commId)) {
                // try getting communityID from Hub ID
                $hub = $hubM->getById($value['hub_id']);
                $commId = $hub->community_id;
            }
        }

        foreach ($meta as $key => $val) {
            $value['meta_'.$val['type']] = $val['content'];
        }

        // var_dump($value);exit;
        $community = $communityM->getCommunityById($commId);
        $value['community_name'] = $community['name'];

        $value['community_url'] = $community['url'];
        $value['community_id'] = $commId;
        $value['commId'] = $commId;
        $value['likes'] = $this->_getPostLikes($value['id']);
        $value['comments'] = $this->_getPostComments($value['id']);
        $value['time'] = $this->_WhenAgo($value['birthday']);
        if ($date_tile_override):
            $value['time'] = $this->_WhenAgo($story[$date_tile_override]);
        endif;
        $value['hideMedia'] = $this->_hideMedia($value);
        $value['isVideo'] = $this->_isVideoContent($value);
        $value['classes'] = ($value['isVideo']) ? 'video-overlay' : '';
        $value['googleRSSsource'] = $this->_getGoogleRSSSource($value);
        $value['sourceText'] = $this->_getSourceText($value);
        $value['og_link'] = $this->_getOgLink($value,$meta);
        $value['title'] = $this->_getTileTitle($value);
        $value['liked'] = ($value['liked']) ? ' is-liked' : '';
        $value['commented'] = ($value['commented']) ? ' is-commented' : '';
        $value['userInCommunity'] = $this->_isUserInCommunity($commId);
        $value['author'] = $this->getAuthor($value);
        $value['author_username'] = $this->getAuthorUsername($value);
        $value['authorDetails'] = $this->_getAuthorDetails($value);
        $value['author_link'] = $this->_authorLink($value);
        $value['author_image'] = $this->_authorImage($value,true);
        $value['community_badge'] = $this->_getCommunityBadge($commId);
        $value['hub_icon'] = '<img class="post-which-social-network-from u-m-xauto" src="/img/post-types/v2/'.$value['hub_type'].'.svg">';
        $value['content'] = $this->_getContent($value);
        $value['square_badge'] = ($value['hub_type'] === 'dyadey' && !$square_badge) ? false: $square_badge;


        if ($badge_override) {
            if ($profile_pic_badge) {
                $value['badge'] = '/timthumb.php?src=' . $value['author_image'] . '&w=48&h=48&zc=1&q=100';
            } else {
                $value['badge'] = $this->_getCommunityBadge($commId);
            }
        } else {
            if ($profile_pic_badge && $value['hub_type'] === 'dyadey') {
                $value['badge'] = '/timthumb.php?src=' . $value['author_image'] . '&w=48&h=48&zc=1&q=100';
            } else {
                $value['badge'] = $this->_getHubBadge($value['hub_type'], $value);
            }
        }

        if ($badge_name_override) :
            $value['author_username'] = $value['community_name'];
        endif;

        $value['media'] = $this->_getMedia($value,$meta);
        $value['hasImage'] = $this->_getHasImage($value);
        $value['inline'] = $this->_getInline($value);
        $value['og_thumbnail'] = $this->_getOgThumbnail($value, $meta);

        $value['doesUserFollow'] = $this->_doesUserFollow($value);
        $value['is_self'] = $this->_isPostByUser($value);
        $value['date_tile_text'] = $date_tile_text;
        $value['show_hubtype'] = $show_hubtype;

        return $value;
    }

    private function _getHasImage($value)
    {
        return (!empty($value['thumbnail']) || !empty($value['media']) )? 1 : 0;
    }

    private function _getPostLikes($post_id)
    {
        $postlikeM = new PostLikes;
        return $postlikeM->getCountPostLikes($post_id);
    }

    private function _getPostComments($post_id)
    {
        $postcommentM = new PostComments();
        return $postcommentM->getCountPostComments($post_id);
    }

    private function _isVideoContent($post)
    {
        $retval = false;
        switch($post['hub_type']):
            case 'dyadey':
                if (
                    (array_key_exists('meta_image',$post) && strpos($post['meta_image'],'mp4') !== false) // meta content has video
                    || (array_key_exists('meta_media',$post) && strpos($post['meta_media'],'www.youtube.com/watch?') !== false) // meta is media and youtube link exists
                ) {
                    $retval = true;
                }
                break;
            case 'youtube':
                $retval = true;
                break;
            case 'twitter':
            case 'instagram':
            case 'google':
            case 'facebook':
                if(
                    isset($post['source']) && (strpos($post['source'],'video') !== false || strpos($post['source'],'.mp4') !== false || strpos($post['source'], 'youtube') !== false ) ) {
                    $retval = true;
                }
                break;
            default:
                break;
        endswitch;
        // var_dump($post);
        // exit;
        return $retval;
    }

    private function _isPostByUser($post)
    {
        $is_self = false;
        switch($post['hub_type']):
            case 'dyadey':
                // this user
                $user_id = (Zend_Auth::getInstance()->hasIdentity()) ? Zend_Auth::getInstance()->getIdentity()->id : 0;
                if ($user_id === $post['authorDetails']->id) {
                    $is_self = true;
                }
                break;
            default:
        endswitch;
        return $is_self;
    }

    private function _getContent($post)
    {
        $thecontent = ($post['title'] != $post['content']) ? preg_replace('@<script.*?>.+?</script>@is','',$post['content']) : $post['content'];
        /* if (isset($extraContent)) {
             $thecontent .= '<br />' . preg_replace('@<script.*?>.+?</script>@is','', $extraContent);
         }
        */
        if ($post['hub_type'] === 'instagram') {
            $gtlds = ['\.com', '\.co\.uk', '\.org', '\.org\.uk', '\.co', '\.info', '\.biz', '\.net'];
            $str_gtlds = implode('|', $gtlds);
            $pattern = '/(\s)(https?:\/\/)?([-A-Za-z0-9]+(' . $str_gtlds . ')(\/?[-A-Za-z0-9\._~:\/\?#\[\]@!\$&\(\)*\+,;=]+)?)/i';
            $replacement = '$1<a href="http://$3">$3</a>';
            $thecontent = preg_replace($pattern, $replacement, $thecontent);
        }

        //http://youtu.be/xyz
        preg_match_all("/(.*youtu\.be\/)([\w\-]+)/", $thecontent, $matches, PREG_SET_ORDER);
        //https://www.youtube.com/watch?v=xyz
        if (empty($matches)) {
            preg_match_all("/(.*?youtube\.com\/watch\?v\=)([\w\-]+)/", $thecontent, $matches, PREG_SET_ORDER);
        }

        $n = count($matches);
        for ($i=0; $i < $n; $i++){
            if (isset($matches[$i][2])) {
                $videoid = $matches[$i][2];
                $media = "<div class='position-container'><div class='embed-container'><iframe src='//www.youtube.com/embed/{$videoid}?showinfo=0' frameborder='0' allowfullscreen></iframe></div></div>";
                $thecontent = preg_replace('@<a\s.*?href="(.*?youtube\.com\/watch\?v\=)'.$videoid.'(&.+?)?".*?>(.+?)</a>@', '', $thecontent);
            }
        }

        return $thecontent;
    }

    private function _isUserInCommunity($community_id)
    {
        $cM = new CommunityUser;
        return !$cM->notAssigned($community_id);
    }

    private function _getMedia($post,$meta)
    {
        $media = '';
        switch ($post['hub_type']) {
            case 'dyadey':
                $media = $this->_getDyadeyMedia($post,$meta);
                break;
            case 'youtube':
                $media = $this->_getYoutubeMedia($post);
                break;
            case 'google':
                $media = $this->_getGoogleMedia($post);
                break;
            case 'instagram':
            case 'tumblr':
                $media = '<img src="' . $post['thumbnail'] . '">';
            default:
                $media = $this->_getDefaultMedia($post);
                break;
        }
        return $media;
    }

    private function _getDefaultMedia($post)
    {
        $media = null;
        $source = $post['source'];
        if (!is_null($source) &&
        (
            stristr($source, 'video') ||
            stristr($source,'scontent') ||
            stristr($source, 'periscope') ||
            stristr($source, 'amp.twimg')
        )
        ) {
            if ($post['hub_type'] === 'facebook' && stristr($source, 'iframe') ) {
                $video_class = '';
                $width = 4;
                $height = 3;

                // Handle unusual sized iframes
                preg_match('/((\swidth=\")([0-9+])(\"))/', $source, $width_matches);
                preg_match('/((\sheight=\")([0-9+])(\"))/', $source, $height_matches);

                if (count($width_matches) > 3) {
                    $width = $width_matches[3];
                }

                if (count($height_matches) > 3) {
                    $height = $height_matches[3];
                }

                if ($width/$height > 0.75) {
                    $video_class = 'unusual_video';
                }

                if ($height/$width > 0.75) {
                    $video_class = 'strange_video';
                }

                $media = "<div class='position-container " . $video_class . "'><div class='embed-container'>" . $source . '</div></div>';
            } else {
                $media = '<video controls src="' . $source . '"></video>';
            }
        } else if(!is_null($post['thumbnail'])){
            $media = '<img src="' . str_replace('http:', 'https:',$post['thumbnail']) . '">';
        }

        return $media;
    }

    private function _getYoutubeMedia($post)
    {
        $mediaAtt = Zend_Json::decode($post['media_attachments']);
        $videoid = $mediaAtt['resourceId']['videoId'];
        $media = "<div class='position-container'><div class='embed-container'><iframe src='//www.youtube.com/embed/{$videoid}?showinfo=0' frameborder='0' allowfullscreen></iframe></div></div>";
        return $media;
    }

    private function _getGoogleMedia($post)
    {
        $mediaAtt = Zend_Json::decode($post['media_attachments']);
        $media = null;

        if (count($mediaAtt) > 0) {
            $url = $mediaAtt[0]['url'];
            if (preg_match('@v=(.+)&@U', $url, $matches)) {
                $videoid = $matches[1];
                $media = "<div class='position-container'><div class='embed-container'><iframe src='//www.youtube.com/embed/{$videoid}?showinfo=0' frameborder='0' allowfullscreen></iframe></div></div>";
            } else {
                $data_page = file_get_contents($url);
                preg_match('/(data-dlu=")([-a-zA-Z0-9:\/\._]+)(")/',$data_page, $matches);

                if (count($matches) > 3) {
                    $source = $matches[2];
                    $media = "<video controls src=" . $source . "></video>";
                }

            }
        }

        return $media;
    }

    private function _doesUserFollow($post)
    {
        $doesFollow = false;
        $user_id = (Zend_Auth::getInstance()->hasIdentity()) ? Zend_Auth::getInstance()->getIdentity()->id : 0;
        switch($post['hub_type']):
            case 'dyadey':
                $followuserM = new FollowUser;
                $res = $followuserM->doIFollow($post['authorDetails']->id,$user_id);
                $doesFollow = $res;
                break;
            default:
                // It has to be a post from a hub related to a community
                // Get the community ID
                $communityM = new CommunityUser;
                $res = $communityM->notAssigned($post['community_id'],$user_id);
                $doesFollow = !$res;
                break;
        endswitch;

        return $doesFollow;
    }

    private function _getOgThumbnail($post, $postmeta)
    {
        if (isset($post['og_link']) && isset($post['og_link']['image']) && $post['og_link']['image']) {
            $inline = '';
            $classes = 'tile-image';

            $inline = !empty($post['og_link']['image']) ? ('<img class="' . $classes . '" src=\'' . $post['og_link']['image'] . '\'>') : null;
            return $inline;
        }
        return null;
    }

    /**
     * Because file_get_contents is stupid (openssl issues on dev machine...)
     * @param $url
     * @return bool|mixed
     */
    protected function curl_file_get_contents($url) {
        $fakeAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.36';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL,            $url);
        curl_setopt($ch, CURLOPT_USERAGENT,      $fakeAgent);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT,        3);
        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            return false;
        } else {
            curl_close($ch);
            return $response;
        }
    }

    private function _getOgLink($post, $postmeta)
    {
        $link = null;
        $matches=null;
        $og_tags = array();

        /*$context = stream_context_create(
            array(
                'http' => array(
                    'follow_location' => true
                )
            )
        );*/

        switch($post['hub_type']):

            case 'dyadey':
                //map postMeta object to get post type and link if set
                foreach ($postmeta as $meta) {
                    switch ($meta->type) {
                        case 'link':
                            $link = $meta->content;
                            break;

                    }
                }
                if (!is_null($link)) {

                    //$site_html=  file_get_contents($link, false, $context);
                    $site_html = $this->curl_file_get_contents($link);
                    preg_match_all('~<\s*meta\s+property="(og:[^"]+)"\s+content="([^"]*)~i',     $site_html,$matches);
                    for($i=0;$i<count($matches[1]);$i++)
                    {
                        $og_tags[str_replace('og:','',$matches[1])[$i]]=$matches[2][$i];
                    }

                    $link = $og_tags;
                }
                break;
            default:
                // check content to see if http or https exists
                preg_match('/(http)(s)*(:\/\/)([-a-zA-z0-9\.\/]+)(S)*/', $post['content'], $matches);
                if (count($matches) === 0) {
                    break; // no link found
                }
                //$site_html=  file_get_contents($matches[0], false, $context);
                $site_html = $this->curl_file_get_contents($matches[0]);
                preg_match_all('~<\s*meta\s+property="(og:[^"]+)"\s+content="([^"]*)~i',     $site_html,$matches2);
                for($i=0;$i<count($matches2[1]);$i++)
                {
                    $og_tags[str_replace('og:','',$matches2[1])[$i]]=$matches2[2][$i];
                }

                if (!isset($og_tags['url'])) {
                    $og_tags['url'] = $matches[0];
                }

                $link = $og_tags;

                break;

        endswitch;
        
        return $link;
    }

    private function _getDyadeyMedia($post,$postmeta)
    {
        $media = null;
        $postType = '';
        $link = '';
        $pmeta = array();

        // exit('1');

        //map postMeta object to get post type and link if set
        foreach ($postmeta as $meta) {
            switch ($meta->type) {
                case 'post-type':
                    $postType = $meta->content;
                    break;
                case 'media':
                    $postType = 'media';
                    $link = $meta->content;
                    break;
                case 'link':
                    $link = $meta->content;
                    break;
                case 'media':
                    $link = $meta->content;
                    break;
            }
        }

        foreach ($postmeta as $key => $value) {
            $pmeta[$value['type']] = $value['content'];
        }

        switch ($postType) {
            case 'post':
                //todo: check if link is an image and exist...
                //if post-type is post and we have link - we want to use link as thumbnail...
                $media = (isset($link) && empty($post['thumbnail'])) ? '<img src="' . $link . '">' : '<img src="' . $post['thumbnail'] . '">';
                break;

            case 'media':
                //if post-type media and we have link - we want to use it as youtube video...
                //we assume link is a youtube...
                if (isset($link) && $link !== '') {
                    //http://youtu.be/xyz
                    preg_match_all("/(.*youtu\.be\/)([\w\-]+)/", $link, $matches, PREG_SET_ORDER);
                    //https://www.youtube.com/watch?v=xyz
                    if (empty($matches)) {
                        preg_match_all("/(.*youtube\.com\/watch\?v\=)([\w\-]+)/", $link, $matches, PREG_SET_ORDER);
                    }

                    if (isset($matches[0][2])) {
                        $videoid = $matches[0][2];
                        $media = "<div class='position-container'><div class='embed-container'><iframe src='//www.youtube.com/embed/{$videoid}?showinfo=0' frameborder='0' allowfullscreen></iframe></div></div>";
                    } else {
                        //incorrect youtube link...
                        $media = '<p>Dyadey couldn\'t parse media link... Link: ' . $link . '</p>';
                    }
                } else {
                    //we use image :)
                    if (in_array(strtolower(pathinfo($post['thumbnail'], PATHINFO_EXTENSION)),$this->_allowed_formats) ) {
                        $media = '<video controls src="' . $post['thumbnail'] . '"></video>';
                    } else {
                        $media = '<img dat="1" src="' . $post['thumbnail'] . '">';
                    }

                }
                break;

            case 'link':
                //if post-type link we want to show it in content...
                $media = '';//we could use som kind of link preview...
                $extraContent = '<h4><a href="' . $link . '" target="_blank">' . ((mb_strlen($link) > 55) ? mb_substr($link, 0, 55) . '...' : $link) . '</a></h4>';
                break;
            default:
                if (in_array(strtolower(pathinfo($post['thumbnail'], PATHINFO_EXTENSION)),$this->_allowed_formats)){
                    $media = '<video controls src="' . $post['thumbnail'] . '"></video>';
                } else if ( array_key_exists('image', $pmeta) && in_array(strtolower(pathinfo($pmeta['image'], PATHINFO_EXTENSION)),$this->_allowed_formats)) {
                    $media = '<video controls src="' . $pmeta['image'] . '"></video>';
                } else if ($post['thumbnail']) {
                    $media = '<img dat="1" src="' . $post['thumbnail'] . '">';
                }
        }
        return $media;
    }

    private function _getHubBadge($hub_type, $post)
    {
        $badge = '';
        switch ($hub_type) {
            case 'dyadey':
                $badge = $this->_authorImage($post);
                break;
            case 'facebook':
                $badge = '/img/post-types/tile/facebook.svg';
                break;
            case 'twitter':
                $badge = '/img/post-types/tile/twitter.svg';
                break;
            case 'youtube':
                $badge = '/img/post-types/tile/youtube.svg';
                break;
            case 'tumblr':
                $badge = '/img/post-types/tile/tumblr.svg';
                break;
            case 'google':
                $badge = '/img/post-types/tile/google.svg';
                break;
            case 'instagram':
                $badge = '/img/post-types/tile/instagram.svg';
                break;
            default:
                $badge = '/img/svg/icn-dyadey.png';
                break;
        }
        return $badge;
    }

    private function _getCommunityBadge($community_id)
    {
        $communityM = new Community;
        $badge = $communityM->_getLatestImage($community_id,true);
        if ($badge === '') :
            $badge = '/img/communityPlaceholder.png';
        else:
            $badge = '/media/images/community/'.$community_id.'/thumbs/' . $badge;
        endif;
        return '/timthumb.php?src='. $badge . '&w=64&h=64&q=100';
    }

    private function _authorLink($post)
    {
        $link = '';
        switch($post['hub_type']):
            case 'dyadey':
                $usersM = new Users();
                $postMetaM = new PostMeta;
                $postMeta = $postMetaM->getAllMeta($post['id']);
                $q = $usersM->select()->from('_users', array('username', 'username'))->where('id = ?', $postMeta[0]->user_id);
                $res = $usersM->fetchRow($q);
                $origin = empty($res->username) ? ($res->first_name . ' ' . $res->surname) : $res->username;
                $link = '/user/'.$res->username;
                break;
            default:
                $commM = new Community;
                $community = $commM->getCommunityById($post['community_id']);
                $link = '/community/'.$community->url;
        endswitch;

        return $link;
    }

    private function _authorImage($post)
    {
        $image = '';
        switch ($post['hub_type']) {
            case 'dyadey':
                // get User profile pic
                $usersM = new Users();
                $postMetaM = new PostMeta;

                $postMeta = $postMetaM->getAllMeta($post['id']);
                $q = $usersM->select()->from('_users')->where('id = ?', $postMeta[0]->user_id);
                $res = $usersM->fetchRow($q);
                $image = $usersM->getPicToUse($res->id);
                break;
            default:
                # code...
                $communityM = new Community;
                $image = '/media/images/community/' . $post['community_id'] . '/' . $communityM->_getLatestImage($post['community_id'], true);
                break;
        }
        return $image;
    }

    public function _WhenAgo($birthDate)
    {
        $now = new DateTime();
        $birth = new DateTime($birthDate);
        $interval = $now->diff($birth);
        // suffix
        if ($interval->y > 0) {
            $time = $interval->y . ' year' . (($interval->y > 1) ? 's' : '');
        } else {
            if ($interval->m > 0) {
                $time = $interval->m . ' month' . (($interval->m > 1) ? 's' : '');
            } else {
                if ($interval->d > 0) {
                    $time = $interval->d . ' day' . (($interval->d > 1) ? 's' : '');
                } else {
                    if ($interval->h > 0) {
                        $time = $interval->h . ' hour' . (($interval->h > 1) ? 's' : '');
                    } else {
                        if ($interval->i > 0) {
                            $time = $interval->i . ' minute' . (($interval->i > 1) ? 's' : '') ;
                        } else {
                            $time = 'a minute';
                        }
                    }
                }
            }
        }
        return $time . ' ago';
    }

    public function getLocation($postId){
        $select = $this->select()->from($this->_name, array('location'))->where('id = ?', $postId);
        $res = $this->fetchAll($select);
        return (isset($res[0]['location'])) ? $res[0]['location'] : null;
    }

    public function getLastPostForHub($hub_id)
    {
        $select = $this->select()->from($this->_name)->where('hub_id = ?', $hub_id)->order('birthday DESC')->limit(1);
        $row = $this->fetchRow($select);
        return $row;
    }
}
