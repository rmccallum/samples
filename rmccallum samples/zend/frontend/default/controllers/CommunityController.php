<?php

class CommunityController extends Dyadey_Controller_Action
{

    public $postPagination = 16;
    public $maxPosts = 1920;
    public $max_associations = 20;

    public function preDispatch()
    {
        $this->_helper->contextSwitch()
            ->addActionContext('hasjoined', 'json', 'search', 'paginateposts')
            ->setAutoJsonSerialization(true)
            ->initContext();

        if ($this->_response->isException()) {
            $this->_forward('error', 'error', 'default', ['error_handler' => $this->_response->getException()]);
        }

        // set some default view vars for Ajax sections
        $this->view->editORaddPost = 'Add';
    }

    public function indexAction()
    {
        $this->view->commName = $this->_request->getParam('community');
        $communityM = new Community;
        $categoryM = new Category;
        $categoryRelationshipsM = new CategoryRelationships;
        $postsM = new Posts;
        $userCommunityM = new CommunityUser;
        $community = $communityM->getIdByUrl($this->_request->getParam('community'));

        if (is_null ($community)) {
            $comm = $communityM->getCommunityById($this->_request->getParam('community'));
            if (!is_null($comm)) {
                $id = $comm['id'];
            } else {
                $this->_redirect('/');
            }
        } else {
            $id = $community;
        }

        //@todo: validate id in db... router takes care of it but just in case
        //@todo: encapsulate user logged in/not logged in logic
        $u = new Users;
        if (Zend_Auth::getInstance()->hasIdentity()) {
            $user = Zend_Auth::getInstance()->getIdentity();
            $this->view->user = $user;
            $picFromDb = $u->getPicToUse($user->id);
            if ($picFromDb !== false) {
                $this->view->socialpic = $picFromDb;
            }
            //moved from down below...
            //is user assigned to community?
            $notAssigned = $userCommunityM->notAssigned($id, $user->id);
            $this->view->notAssigned = $notAssigned;
        } else {
            $user = false;
            $this->view->user = null;
            $this->view->notAssigned = true;
        }

        $community_image = $communityM->_getLatestImage($id, true);
        $this->view->community_image = $community_image;

        if (empty($this->view->errors)) {
            $cache = Zend_Registry::get('cache');
            $cacheID = "comm_$id" . (isset($user->id) ? '_' . $user->id : '');
            $cacheTags = ['comm_' . $id];
            if ($user && isset($user->id)) {
                $cacheTags[] = 'user_' . $user->id;
            }
            // clear cache for this community only for debugging purposes by seeting ?u at end of URL
            if (isset($_GET['u'])) {
                $cache->clean(Zend_Cache::CLEANING_MODE_MATCHING_TAG, $cacheTags);
                $this->_redirect('/community/' . $this->view->commName . '/');
            }
            if (!$viewData = $cache->load($cacheID)) {
                $viewData = new stdClass;
                $viewData->maxPosts = $max = $this->maxPosts;

                $community = $communityM->getById($id);
                $commPostsCache = new Zend_Session_Namespace('community_posts');

                $tmp_posts = [];
                $commPostsCache->$id = [];
                if (isset($commPostsCache->$id) && !empty($commPostsCache->$id)) {
                    $posts = array_slice($commPostsCache->$id, 0, $this->postPagination);
                } else {
                    //getComPosts now accepts 5 params: id of a community, id of post to exclude, page number (default 1), results per page, and max
                    $posts = $postsM->getComPosts($id, NULL, 1, $this->postPagination, $max);
                }
                if (count($posts) > 0) {
                    foreach ($posts as $key => $story) {
                        $tmp_posts[$key] = $postsM->_augmentModel($story, $id,
                            [
                                'badge_override' => false,
                                'profile_pic_badge' => true,
                                'show_hubtype' => true
                            ]
                        );
                    }
                }
                $viewData->posts = $tmp_posts;
                $viewData->community = $community;

                //add/overwrite dir var to apply sticky header
                $viewData->dir = $this->view->dir . ' community-page';

                // generate a 2D matrix of categories
                $base_categories = $categoryRelationshipsM->getCategoryIds($community->id, 0);

                $matrix = [];
                foreach ($base_categories as $key => $value) {
                    // get path up to root for each
                    $matrix[] = $categoryM->traceBackToRoot($value);
                }

                $resultingCategories = $categoryM->generateUniqueListByMatrix($matrix);
                // Generate a simple associated Communities relationship
                $relatedCommunities = $categoryRelationshipsM->getAssociationsFromMatrix($community->id, $resultingCategories);

                if (count($relatedCommunities) > $this->max_associations) {
                    $relatedCommunities = array_slice($relatedCommunities, 0, $this->max_associations);
                }

                $related = [];
                foreach ($relatedCommunities as $key => $value) {
                    $comm = $communityM->getCommunityById($value);
                    if (!is_null($comm)) {
                        $related[] = $comm;
                    }
                }

                $viewData->relatedCommunities = $related;
                $cache->save($viewData, $cacheID, $cacheTags);
            }
            // load each view data variable from cache
            foreach ($viewData as $key => $value) {
                $this->view->$key = $value;
            }
        }
    }

    public function paginatepostsAction()
    {

        // Testing
        // $commId = '38';
        // $index = '2';
        // $current = '48';

        $this->_helper->viewRenderer->setNoRender(true);
        $this->_helper->getHelper('layout')->disableLayout();
        $cur = $this->_request->getParam('current');
        $current = $this->_request->getParam('current') * $this->postPagination;
        $commId = $this->_request->getParam('comm-id');
        $index = (int)$this->_request->getParam('last-index');
        $offset = $current * $this->postPagination;
        $cache = Zend_Registry::get('cache');
        $cacheID = "commpaging_{$commId}_{$current}_{$index}";
        $cacheTags = ['comm_' . $commId];
        if (!$pagingData = $cache->load($cacheID)) {
            $pagingData = new stdClass;
            $postsM = new Posts;
            $posts = $postsM->getComPosts($commId, NULL, $current, $this->postPagination, $this->maxPosts, false, $index, $cur);
            $pagingData->postcall = $postsM->getComPosts($commId, NULL, $current, $this->postPagination, $this->maxPosts, true);
            $pagingData->results = [];
            foreach ($posts as $key => $story) {
                // Variable declaration for tile and view management
                $story = $postsM->_augmentModel(
                    $story,
                    $commId,
                    [
                        'badge_override' => false,
                        'profile_pic_badge' => true,
                        'show_hubtype' => true
                    ]
                );
                // assign back
                $this->view->story = $story;
                $pagingData->results[] = $this->view->render('partials/tile.phtml');
            }
            $cache->save($pagingData, $cacheID, $cacheTags);
        }
        echo json_encode(['success' => 1, 'data' => $pagingData->results, 'current' => $current, 'postcall' => $pagingData->postcall]);
        exit;
    }

    // Ajax - assign user to community
    public function communityuserAction()
    {
        $this->_helper->viewRenderer->setNoRender();
        $this->_helper->getHelper('layout')->disableLayout();
        $action = $this->getRequest()->getParam('do_action');
        $communityID = $this->getRequest()->getParam('community_id');
        $userID = $this->getRequest()->getParam('user_id');
        $res = '0';
        if ((int)$communityID != 0 && (int)$userID != 0) {
            $communityUser = new CommunityUser;
            if ($action == 'assign') {
                $res = $communityUser->assign($communityID, $userID);
            } elseif ($action == 'unassign') {
                $res = $communityUser->unassign($communityID, $userID);
            }
        }
        echo $res;
    }

    public function ismemberAction()
    {
        // $this->_helper->viewRenderer->setNoRender();
        $this->_helper->getHelper('layout')->disableLayout();
        echo empty($this->view->userCommunities) ? '0' : '1';
    }

    public function hasjoinedAction()
    {
        $this->view->clearVars();
        $cM = new CommunityUser;
        $communityId = $this->getRequest()->getParam('community_id');

        $data['success'] = $cM->notAssigned($communityId) ? 0 : 1;
        $this->view->assign($data);
    }

    public function addFavourite()
    {
    }

    public function getlocationAction()
    {
        $this->_helper->viewRenderer->setNoRender();
        if ($this->_request->isXmlHttpRequest()) {
            if ($this->_request->getParam('pc')) {
                $pc = $this->_request->getParam('pc');
                if (preg_match('/[A-Z0-9]/', $pc) != false) {
                    // add a space in front of 3rd char from end
                    $pcWithSpace = substr($pc, 0, -3) . ' ' . substr($pc, -3);
                    $json = $this->getGoogleGeocode($pcWithSpace);
                    $loc = (isset($json['results'][0]['geometry']['location'])) ? $json['results'][0]['geometry']['location'] : NULL;
                    if (isset($loc['lat']) && isset($loc['lng'])) {
                        echo "{'latitude':{$loc['lat']},'longitude':{$loc['lng']}}";
                    } else {
                        echo '0';
                    }
                }
            }
        }
    }

    public function loginAction()
    {
        $auth = Zend_Auth::getInstance()->setStorage(new Zend_Auth_Storage_Session('member'));
        if ($auth->getIdentity()) {
            $this->_redirect('/account/');
        }
    }

    public function logoutAction()
    {
        Zend_Auth::getInstance()->clearIdentity();
        setcookie('user_cookie', 'true', time() + (86400 * 30), "/");
        $this->_redirect('./');
    }

    public function profileAction()
    {
        //temp my communities...
        $communityM = $this->loadModel('Community');
        $this->view->communities = $communityM->getAll('name asc');
    }

    public function runheartbeatAction()
    {
        Zend_Controller_Action_HelperBroker::getStaticHelper('Logger')->direct("Starting " . __FUNCTION__);
        $heartbeat_time = 15;
        // Todo:
        // Schedule this to run every 15 minutes
        // Maximum number of communities to scrape every 15 minutes
        // divide by 15 to get number of communities to set up per minute
        // increase the minute feed queue time

        $this->_helper->viewRenderer->setNoRender();
        $this->_helper->getHelper('layout')->disableLayout();

        $queueM = new Queue;
        $socialfeedM = new SocialFeed;
        $socialfeedorderM = new SocialFeedOrder;
        $hubM = new Hub;

        // foreach social feed generate a new list of queued community hubs
        // based on last community scraped and current order position
        $feeds = $socialfeedM->getAllSocialFeeds();

        foreach ($feeds as $key => $feed) {

            // get next communities for this feed
            $next_communities = $socialfeedorderM->getNextFeedCommunities(
                $feed->feed_id,
                $feed->last_community_scraped,
                $feed->communities_per_heartbeat
            );

            $max_per_miniute = (floor($feed->communities_per_heartbeat / $heartbeat_time)) ? floor($feed->communities_per_heartbeat / $heartbeat_time) : 1;
            $counter = 0;
            $minute_incrementer = 1;

            // foreach next communities generate a queue for them
            foreach ($next_communities as $key2 => $community_id) {

                if ($counter >= $max_per_miniute) {
                    $minute_incrementer++;
                    $counter = 0; // reset counter for next max per minute
                }

                $hub = $hubM->getByTypeCommunity($feed->feed_name, $community_id);

                $timePlusOne = date("Y-m-d H:i:s", strtotime(date('Y-m-d H:i:s')) + (60 * $minute_incrementer));

                if (isset($hub->id) && !is_null($hub->id)) {

                    $options = [
                        'feed_name' => $feed->feed_name,
                        'social_feed_id' => $feed->feed_id,
                        'community_id' => $community_id,
                        'hub_id' => $hub->id,
                        'endpoint' => $hub->uri,
                        'run_at' => $timePlusOne,
                        'max_calls' => $feed->requests_max
                    ];

                    try {
                        $queueM->addQueue($options);
                    } catch (Exception $e) {
                        Zend_Controller_Action_HelperBroker::getStaticHelper('Logger')->direct(__FUNCTION__ . ' could not add queue: ' . json_encode($e->getMessage()));
                    }
                } else {
                    Zend_Controller_Action_HelperBroker::getStaticHelper('Logger')->direct(__FUNCTION__ . ' could not add queue: Hub is NULL: Feed: ' . $feed->feed_name . ' community: ' . $community_id);
                }

                $counter++;

            }   // end inner foreach

        }   // end outer foreach
        echo 'Completed ' . __FUNCTION__;

    }

    public function testhubAction()
    {
        $hub_id = $this->_request->getParam('hub_id');

        $hub = new Hub;
        $hub->manualFeedsOnly = 1;
        $hub->hubLimits = 7;
        $hub->hubId = $hub_id;
        $hub->since = null;
        $postParams = array('limit' => 20);
        $hubParams = array('limit' => 20);
        $networks = array(strtolower('google'));
        // perform the crawl
        $hub->crawl('crawlForPostsByHub', $postParams, $networks);


    }

    public function runsinglequeueAction()
    {
        $queue_id = $this->_request->getParam('queue_id');
        Zend_Controller_Action_HelperBroker::getStaticHelper('Logger')->direct("Starting " . __FUNCTION__);

        $this->_helper->viewRenderer->setNoRender();
        $this->_helper->getHelper('layout')->disableLayout();
        $queueM = new Queue;

        $queue_row = $queueM->getById($queue_id);

        // generate a new Hub model
        // scrape the Hub and store the responses
        $hub = new Hub;
        $hub->manualFeedsOnly = 1;
        $hub->hubLimits = 7;
        $hub->hubId = $queue_row->hub_id;
        $hub->since = $queueM->getSinceParameter($queue_row);

        $postParams = array('limit' => $queue_row->max_calls, 'dumpData'=>false);
        $hubParams = array('limit' => $hub->hubLimits);
        $networks = array(strtolower($queue_row->feed_name));
        // perform the crawl
        $hub->crawl('crawlForPostsByHub', $postParams, $networks);

        $queueM->updateResponse($queue_row->queue_id, $hub);

        // set Last community run in social feed table
        $socialfeedM = new SocialFeed;
        $socialfeedM->setLastCommunityScraped($queue_row->social_feed_id, $queue_row->community_id);

        exit('Completed Feeding Queue: ' . $queue_id);
    }
    public function runfeedingqueueAction()
    {
        Zend_Controller_Action_HelperBroker::getStaticHelper('Logger')->direct("Starting " . __FUNCTION__);

        $this->_helper->viewRenderer->setNoRender();
        $this->_helper->getHelper('layout')->disableLayout();
        $queueM = new Queue;
        $queued_hubs = $queueM->getQueuesNow();

        // var_dump(count($queued_hubs));exit;

        if (count($queued_hubs) > 0) :
            foreach ($queued_hubs as $key => $queue_row) {
                // generate a new Hub model
                // scrape the Hub and store the responses
                $hub = new Hub;
                $hub->manualFeedsOnly = 1;
                $hub->hubLimits = 7;
                $hub->hubId = $queue_row->hub_id;
                $hub->since = $queueM->getSinceParameter($queue_row);

            $postParams = array('limit' => $queue_row->max_calls, 'dumpData'=>false);
            $hubParams = array('limit' => $hub->hubLimits);
            $networks = array(strtolower($queue_row->feed_name));
            // perform the crawl
            $hub->crawl('crawlForPostsByHub', $postParams, $networks);

                $queueM->updateResponse($queue_row->queue_id, $hub);

                // set Last community run in social feed table
                $socialfeedM = new SocialFeed;
                $socialfeedM->setLastCommunityScraped($queue_row->social_feed_id, $queue_row->community_id);
            }
        else:
            echo 'No queues here<br />';
        endif;

        exit('Completed Feeding Queue');
    }

    public function checkfeedsAction()
    {
        $community_id = $this->_request->getParam('communityId');
        $hubM = new Hub;
        $results = $hubM->insertManualFeeds($community_id);
        var_dump($results);
        exit;

        // $tmp = array();
        // foreach ($feeds as $key => $value) {
        //     $tmp[] = array('id'=>$value->id,'community_id'=>$value->community_id, 'endpoint'=>$value->endpoint, 'hub_type'=>$value->hub_type);
        // }

        // foreach ($feeds as $key => $feed) {
        //     echo 'Feed: ' . $feed['id'] . ' Community ID: ' . $feed['community_id'] . ' Hub Type: ' . $feed['hub_type'] . ' End Point: ' . $feed['endpoint'] . "<br />";
        //     $hub = $hubM->getByTypeCommunity(strtolower($feed['hub_type']),$feed['community_id']);
        //     if ($hub !== 0) {
        //         // if hubs don't match need to update the hub table with URI and fingerprint
        //         if ($hub['uri'] !== $feed['endpoint']) {
        //             echo 'NEED to change the URI in HUBS' . "<br />";
        //             $data = array('hub_type'=>strtolower($feed['hub_type']),'endpoint'=>$feed['endpoint']);
        //             $data = $hubM->retrieveEndpointData($data);

        //             var_dump($data);exit;
        //         }
        //     }
        // }
        // exit;
    }

    public function checkallfeedsAction()
    {

        $community_id = $this->_request->getParam('communityId');
        $start = $this->_request->getParam('start');
        $max = $this->_request->getParam('max');
        $hub_type = $this->_request->getParam('hubType');

        $this->_helper->viewRenderer->setNoRender();
        $this->_helper->getHelper('layout')->disableLayout();

        $feedM = new SocialFeed;
        $orderM = new SocialFeedOrder;
        $hubM = new Hub;
        $counter = 0;

        $max = $start + $max;
        $facebookHubs = $hubM->getByHubtype($hub_type);

        $communities_to_add = [];

        echo '<table><tr><th>Community ID</th><th>Endpoint</th><th>Hub</th><th>Hub ID</th><th>Fingerprint</th></tr>';
        if (!is_null($facebookHubs)) {
            foreach ($facebookHubs as $key => $value) {
                if ($counter >= $start && $counter < $max) {
                    $fingerprint = $hubM->updateHubManualFeed($value['hub_type'], $value['community_id']);
                    echo '<tr><td><a target="_blank" href="http://dyadey.com/admin/community/edit/' . $value['community_id'] . '">' . $value['community_id'] . '</a></td><td>' . $value['uri'] . '</td><td>' . $value['hub_type'] . '</td><td>' . $value['id'] . '</td>';
                    if (!is_null($fingerprint)) {
                        echo '<td>' . $fingerprint . " ### Please Update this community ###</td></tr>";
                        $feed_system = $feedM->getFeedByName($hub_type);
                        if (!is_null($feed_system)) {
                            $order_row = $orderM->checkFeedExists($value['community_id'], $feed_system['feed_id'], false);
                            if (is_null($order_row)) {
                                $communities_to_add[] = ['community_id' => $value['community_id'], 'feed_id' => $feed_system['feed_id']];
                            }
                        }
                    } else {
                        // check if hub fingerprint already exists
                        if (!is_null($value['fingerprint'])) {
                            echo '<td>' . $value['fingerprint'] . ' has been found in the Hub table</td></tr>';
                        } else {
                            echo '<td>## EXCEPTION ##</td></tr>';
                        }
                    }
                }
                $counter++;
            }
        }
        echo '</table><br />';

        echo "insert into social_feed_order values<br />";
        $output_text = '';
        foreach ($communities_to_add as $key => $value) {
            $output_text .= "(0,'" . $value['community_id'] . "'," . $value['feed_id'] . ",10,NOW(),NOW())," . PHP_EOL;
        }
        $length = strlen($output_text) - 2;
        $output_text = substr($output_text, 0, $length);
        echo $output_text;
        exit;
    }

    public function sitemapAction()
    {
        $sitemap = realpath($_SERVER['DOCUMENT_ROOT']) . DIRECTORY_SEPARATOR . 'sitemap.xml';
        if (file_exists($sitemap)) {
            $xml = simplexml_load_file($sitemap);
            $this->view->xml = $xml;
        }
        $this->view->title = 'Sitemap';
        $Articles = $this->loadModel('Articles');
        $this->view->footerarticles = $Articles->getLatest(1);
        $Testimonials = $this->loadModel('Testimonials');
        $this->view->footertestimonials = $Testimonials->getLatest(1);
        //echo '<pre>' . print_r($this->view->menus, 1) . '</pre>';
    }

    public function subpageAction()
    {
        $Pages = $this->loadModel('Pages');
        $page = $Pages->getPage();
        if (!$page) {
            throw new Flint_Exception(self::PageNotFound);
        }
        $parent = $Pages->getRootParent();

        $this->view->item = $page;
        $this->view->subpage = true;
        $this->view->subdir = $this->_request->getParam('subdir');
        $this->view->title = $page['title'];
        $this->view->menu_name = (strpos($this->_request->getRequestUri(), '/about-us/') != false)
            ? 'About Us'
            : (($page['id'] == $page['parent_id']) ? $page['menu_name'] : $parent['menu_name']);

        // get submenu if needed
        $children = $Pages->fetchAll($Pages->select()->where('parent_id=?', $parent['id']));
        if (count($children) > 0) {
            $this->view->submenuDetails = $parent;
            $this->getHelper('Menus')->direct($parent['id']);
        }
        if (file_exists($_SERVER['DOCUMENT_ROOT'] . "/../frontend/templates/default/subpages/{$page['path']}.tpl")) {
            $this->_processForm($page);
            $this->render("subpages/{$page['path']}");
        }
    }

    public function teamAction()
    {
        $Teams = $this->loadModel('Teams');
        //$this->view->featured_testimonials = $Testimonials->getFeatured(1);
        $offices = $Teams->getAll();
        $this->view->offices = $offices;
        $this->view->title = 'Office Locations and Team';
        $this->view->menu_name = 'About Us';
        $Pages = $this->loadModel('Pages');
        $parent = $Pages->getRootParent();
        $children = $Pages->fetchAll($Pages->select()->where('parent_id=?', $parent['id']));
        $this->view->submenuDetails = $parent;
        $this->getHelper('Menus')->direct($parent['id']);
    }

    public function testimonialsAction()
    {
        $Testimonials = $this->loadModel('Testimonials');
        $this->view->featured_testimonials = $Testimonials->getFeatured(1);
        $select = $Testimonials->getAllPublished(true);
        //$this->_perPage = 4;
        $this->view->testimonials = $this->_paging($Testimonials, $select, 'publish_date DESC');
        $this->view->title = 'Testimonials';
        $this->view->menu_name = 'About Us';
        $Pages = $this->loadModel('Pages');
        $parent = $Pages->getRootParent();
        $children = $Pages->fetchAll($Pages->select()->where('parent_id=?', $parent['id']));
        $this->view->submenuDetails = $parent;
        $this->getHelper('Menus')->direct($parent['id']);
    }

    public function toggleFavAction()
    {
        $this->_helper->viewRenderer->setNoRender();
        $auth = Zend_Auth::getInstance()->setStorage(new Zend_Auth_Storage_Session('member'));
        if ($user = $auth->getIdentity()) {
            if ($this->_request->isXmlHttpRequest()) {
                if ($this->_request->getParam('property')) {
                    $prop_id = $this->_request->getParam('property');
                    if (preg_match('/[0-9]/', $prop_id) != false) {
                        $Fav = $this->loadModel('Favourites');
                        $res = $Fav->saveFavourite($user->id, $prop_id);
                        switch ($res) {
                            case 'added':
                            case 'removed':
                            case 'existing-not-removed':
                                echo $res;
                                exit;
                        }
                    } else {
                        echo "Invalid property ID";
                        exit;
                    }
                }
            }
        } else {
            echo 'no-user';
            exit;
        }
    }

    public function updatePaymentTableAction()
    {
        $this->_helper->viewRenderer->setNoRender();
        if ($this->_request->isXmlHttpRequest()) {
            foreach ($this->_request->getParams() as $key => $var) {
                if ($key == 'ref') {
                    $params['payment_ref'] = $var;
                }
                if ($key == 'amount') {
                    $params['amount'] = $var;
                }
                if ($key == 'total') {
                    $params['total'] = $var;
                }
            }
            if (count($params) == 3) {
                $validationErrors = $this->_validatePayment($params);
                if (empty($validationErrors)) {
                    $params['transaction_charge'] = (float)$params['total'] - $params['amount'];
                    $post = new Payments($params);
                    $res = $post->save();
                    if ($res) {
                        echo json_encode('yes');
                        exit;
                    }
                } else {
                    echo json_encode($validationErrors);
                    exit;
                }
            } else {
                echo "{0:'Please complete the form.'}";
            }
        }
    }

    public function verifyAction()
    {
        $shortCodesDb = new Zend_Db_Table('_short_codes');
        $shortcode = $this->_request->getParam('short_code');
        $result = $shortCodesDb->fetchRow($shortCodesDb->select()->where('short_code=?', $shortcode));
        if (!$result) {
            $this->_helper->Alerts('html', 'Warning', 'Please ensure you have clicked on the correct email verification link.');
            $this->_redirect("{$this->_base_url}/login/");
            return;
        }
        $md5 = $result->string;
        //$conn = ActiveRecordConnectionManager::get_connection();
        //$sqlBuilder = new ActiveRecordSQLBuilder($conn, '_users');
        //$result = $sqlBuilder->where(array("MD5(CONCAT(id, ':', email))" =>  $md5));
        $usersTable = new Zend_Db_Table('_users');
        $result = $usersTable->fetchRow($usersTable->select()->where("MD5(CONCAT(id, ':', email)) = ?", $md5));
        if (!$result) {
            $result = $usersTable->fetchRow($usersTable->select()->where("MD5(CONCAT(id, ':', new_email)) = ?", $md5));
            if (!$result) {
                $this->_helper->Alerts('html', 'Warning', 'Please ensure you have clicked on the correct email verification link.');
                $this->_redirect("{$this->_base_url}/login/");
                return;
            }
        }
        $user = User::find($result->id); //User::find('conditions' => $conditions);
        $user->verify_email($md5);
        $email = "";
        if ($user->verify_email($md5)) {
            $user->save();
            $email = $user->email;
            $shortCodesDb->delete("short_code='{$this->view->short_code}'");
            // get redirect URL if there is one for saving a quote after registration
            $this->_helper->Alerts('html', 'Email Verified', 'Your new email address has been verified', 'confirm');
            $auth = Zend_Auth::getInstance()->setStorage(new Zend_Auth_Storage_Session('member'));
            if (!$auth->getIdentity()) {
                // Try this
                //                $this->adapter->setIdentity($_POST['email'])->setCredential($_POST['password']);
                //                $result = $this->adapter->authenticate();
                // Or this
                // log user in
                //                $adapter = new Zend_Auth_Adapter_DbTable();
                //                $adapter->setTableName('_users')
                //                        ->setIdentityColumn('email')
                //                        ->setCredentialColumn('password');
                //
                //                $adapter->setIdentity($user->email);
                //                $adapter->setCredential($user->password);
                //                $auth   = Zend_Auth::getInstance();
                //                $result = $auth->authenticate($adapter);
                //                if ($result->isValid()) {
                //                    $this->_redirect('/account/');
                //                    return;
                //                }
            } else {
                $this->_redirect('/account/');
                return;
            }
            $this->_redirect('/login/');
        } else {
            $this->_helper->Alerts('html', 'Warning', 'Your new email address could not be verified. Please check link and try again.');
        }
        Zend_Auth::getInstance()->clearIdentity();
        $this->_redirect('/login/');
    }

    /*
     *
     */
    public function updatecategoryrelationshipsAction()
    {
        // Get all communities
        // Get all categories per community
        // For each parent node in the category
        // Add it to the category relationship table
        $communityM = new Community;
        $categoryM = new Category;
        $relationshipM = new CategoryRelationships;

        $all_communities = $communityM->getAllList();
        foreach ($all_communities as $key => $value) {

            $_community = $communityM->getCommunityById($key);
            echo $_community->name . ' : (' . $_community->id . ')<br />';
            $base_categories = $relationshipM->getCategoryIds($_community->id);

            $matrix = [];
            foreach ($base_categories as $key2 => $value2) {
                // get path up to root for each
                $matrix[] = $categoryM->traceBackToRoot($value2);
            }
            $insertions = [];
            // Check if nodes exist on each forloop
            foreach ($matrix as $key2 => $path) {
                foreach ($path as $key3 => $categoryNode) {
                    // check if category node exists for this community
                    if (!$relationshipM->checkNodeExists($_community->id, $categoryNode)) {
                        $insertions[] = $categoryNode;
                    }

                }
            }

            $relationshipM->insertRelationships($_community->id, $insertions, 1);

        }
        echo 'Updated all community categories';
        exit;
    }
}
