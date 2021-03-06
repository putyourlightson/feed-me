<?php
namespace verbb\feedme\controllers;

use verbb\feedme\FeedMe;
use verbb\feedme\helpers\BaseHelper;
use verbb\feedme\models\FeedModel;
use verbb\feedme\queue\jobs\FeedImport;

use Craft;
use craft\helpers\StringHelper;
use craft\web\Controller;

use Cake\Utility\Hash;

class FeedsController extends Controller
{
    // Properties
    // =========================================================================

    protected $allowAnonymous = ['run-task'];


    // Public Methods
    // =========================================================================

    public function actionFeedsIndex()
    {
        $variables['feeds'] = FeedMe::$plugin->feeds->getFeeds();

        return $this->renderTemplate('feed-me/feeds/index', $variables);
    }

    public function actionEditFeed($feedId = null, $feed = null)
    {
        $variables = [];

        if (!$feed) {
            if ($feedId) {
                $variables['feed'] = FeedMe::$plugin->feeds->getFeedById($feedId);
            } else {
                $variables['feed'] = new FeedModel();
                $variables['feed']->passkey = StringHelper::randomString(10);
            }
        } else {
            $variables['feed'] = $feed;
        }

        $variables['dataTypes'] = FeedMe::$plugin->data->dataTypesList();
        $variables['elements'] = FeedMe::$plugin->elements->getRegisteredElements();

        return $this->renderTemplate('feed-me/feeds/_edit', $variables);
    }

    public function actionElementFeed($feedId = null, $postData = null)
    {
        $variables = [];

        $feed = FeedMe::$plugin->feeds->getFeedById($feedId);

        if ($postData) {
            $feed = Hash::merge($feed, $postData);
        }

        $variables['primaryElements'] = $feed->getFeedNodes();
        $variables['feed'] = $feed;

        return $this->renderTemplate('feed-me/feeds/_element', $variables);
    }

    public function actionMapFeed($feedId = null, $postData = null)
    {
        $variables = [];

        $feed = FeedMe::$plugin->feeds->getFeedById($feedId);

        if ($postData) {
            $feed = Hash::merge($feed, $postData);
        }

        $variables['feedMappingData'] = $feed->getFeedMapping();
        $variables['feed'] = $feed;

        return $this->renderTemplate('feed-me/feeds/_map', $variables);
    }

    public function actionRunFeed($feedId = null)
    {
        $request = Craft::$app->getRequest();

        $feed = FeedMe::$plugin->feeds->getFeedById($feedId);

        $variables['feed'] = $feed;
        $variables['task'] = $this->_runImportTask($feed);

        if ($request->getParam('direct')) {
            // If the user triggers this from the control panel (maybe for testing), triggering a task immediately will 
            // lock up the browser session while it runs. In that case, we use JS to trigger the task (in _direct template)
            //
            // However, when triggering via Cron, run the task immediately, as Cron doesn't trigger JS (there's no browser)
            // Best way to check if its being run from a non-browser, as each server is different, so can't be sure what they trigger with
            $browser = BaseHelper::getBrowserName($request->getUserAgent());

            if ($browser == 'Other') {
                Craft::$app->getQueue()->run();
                return $this->asJson(Craft::t('feed-me', '{name} has completed processing', [ 'name' => $feed->name ] ));
            }

            $view = $this->getView();
            $view->setTemplateMode($view::TEMPLATE_MODE_CP);

            return $this->renderTemplate('feed-me/feeds/_direct', $variables);
        } else {
            return $this->redirect($request->getReferrer());
        }
    }

    public function actionStatusFeed($feedId = null)
    {
        $feed = FeedMe::$plugin->feeds->getFeedById($feedId);

        $variables['feed'] = $feed;

        return $this->renderTemplate('feed-me/feeds/_status', $variables);
    }

    public function actionSaveFeed()
    {
        $feed = $this->_getModelFromPost();

        return $this->_saveAndRedirect($feed, 'feed-me/feeds/', true);
    }

    public function actionSaveAndElementFeed()
    {
        $feed = $this->_getModelFromPost();

        return $this->_saveAndRedirect($feed, 'feed-me/feeds/element/', true);
    }

    public function actionSaveAndMapFeed()
    {
        $feed = $this->_getModelFromPost();

        return $this->_saveAndRedirect($feed, 'feed-me/feeds/map/', true);
    }

    public function actionSaveAndReviewFeed()
    {
        $feed = $this->_getModelFromPost();

        return $this->_saveAndRedirect($feed, 'feed-me/feeds/status/', true);
    }

    public function actionSaveAndDuplicateFeed()
    {
        $request = Craft::$app->getRequest();

        $feedId = $request->getParam('feedId');
        $feed = FeedMe::$plugin->feeds->getFeedById($feedId);

        FeedMe::$plugin->feeds->duplicateFeed($feed);

        Craft::$app->getSession()->setNotice(Craft::t('feed-me', 'Feed duplicated.'));

        return $this->redirect('feed-me/feeds');
    }

    public function actionDeleteFeed()
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();

        $feedId = $request->getRequiredBodyParam('id');

        FeedMe::$plugin->feeds->deleteFeedById($feedId);

        return $this->asJson(['success' => true]);
    }

    public function actionRunTask()
    {
        $request = Craft::$app->getRequest();

        $feedId = $request->getParam('feedId');

        if ($feedId) {
            $this->actionRunFeed($feedId);
        }

        Craft::$app->end();
    }

    public function actionDebug()
    {
        $request = Craft::$app->getRequest();
        
        $feedId = $request->getParam('feedId');
        $limit = $request->getParam('limit');
        $offset = $request->getParam('offset');

        $feed = FeedMe::$plugin->feeds->getFeedById($feedId);

        ob_start();

        FeedMe::$plugin->process->debugFeed($feed, $limit, $offset);

        return ob_get_clean();
    }


    // Private Methods
    // =========================================================================

    private function _runImportTask($feed)
    {
        $request = Craft::$app->getRequest();

        $direct = $request->getParam('direct');
        $passkey = $request->getParam('passkey');
        $url = $request->getParam('url');

        $limit = $request->getParam('limit');
        $offset = $request->getParam('offset');

        // Are we running from the CP?
        if ($request->getIsCpRequest()) {
            // if not using the direct param for this request, do UI stuff 
            Craft::$app->getSession()->setNotice(Craft::t('feed-me', 'Feed processing started.'));

            // Create the import task
            Craft::$app->getQueue()->delay(0)->push(new FeedImport([
                'feed' => $feed,
            ]));
        }

        // If not, are we running directly?
        if ($direct) {
            // If a custom URL param is provided (for direct-processing), use that instead of stored URL
            if ($url) {
                $feed->feedUrl = $url;
            }

            $proceed = $passkey == $feed['passkey'];

            // Create the import task only if provided the correct passkey
            if ($proceed) {
                Craft::$app->getQueue()->delay(0)->push(new FeedImport([
                    'feed' => $feed,
                    'limit' => $limit,
                    'offset' => $offset,
                ]));
            }

            return $proceed;
        }
    }

    private function _saveAndRedirect($feed, $redirect, $withId = false)
    {
        if (!FeedMe::$plugin->feeds->saveFeed($feed)) {
            Craft::$app->getSession()->setError(Craft::t('feed-me', 'Unable to save feed.'));

            Craft::$app->getUrlManager()->setRouteParams([
                'feed' => $feed,
            ]);

            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('feed-me', 'Feed saved.'));

        if ($withId) {
            $redirect = $redirect . $feed->id;
        }

        return $this->redirect($redirect);
    }

    private function _getModelFromPost()
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();

        if ($request->getBodyParam('feedId')) {
            $feed = FeedMe::$plugin->feeds->getFeedById($request->getBodyParam('feedId'));
        } else {
            $feed = new FeedModel();
        }

        $feed->name = $request->getBodyParam('name', $feed->name);
        $feed->feedUrl = $request->getBodyParam('feedUrl', $feed->feedUrl);
        $feed->feedType = $request->getBodyParam('feedType', $feed->feedType);
        $feed->primaryElement = $request->getBodyParam('primaryElement', $feed->primaryElement);
        $feed->elementType = $request->getBodyParam('elementType', $feed->elementType);
        $feed->elementGroup = $request->getBodyParam('elementGroup', $feed->elementGroup);
        $feed->siteId = $request->getBodyParam('siteId', $feed->siteId);
        $feed->duplicateHandle = $request->getBodyParam('duplicateHandle', $feed->duplicateHandle);
        $feed->passkey = $request->getBodyParam('passkey', $feed->passkey);
        $feed->backup = (bool)$request->getBodyParam('backup', $feed->backup);

        // Don't overwrite mappings when saving from first screen
        if ($request->getBodyParam('fieldMapping')) {
            $feed->fieldMapping = $request->getBodyParam('fieldMapping');
        }

        if ($request->getBodyParam('fieldUnique')) {
            $feed->fieldUnique = $request->getBodyParam('fieldUnique');
        }

        // Check conditionally on Element Group fields - depending on the Element Type selected
        if (isset($feed->elementGroup[$feed->elementType])) {
            $elementGroup = $feed->elementGroup[$feed->elementType];

            if ($feed->elementType == 'craft\elements\Category') {
                if (empty($elementGroup)) {
                    $feed->addError('elementGroup', Craft::t('feed-me', 'Category Group is required'));
                }
            }

            if ($feed->elementType == 'craft\elements\Entry') {
                if (empty($elementGroup['section']) || empty($elementGroup['entryType'])) {
                    $feed->addError('elementGroup', Craft::t('feed-me', 'Entry Section and Type are required'));
                }
            }

            if ($feed->elementType == 'Commerce_Product') {
                if (empty($elementGroup)) {
                    $feed->addError('elementGroup', Craft::t('feed-me', 'Commerce Product Type is required'));
                }
            }
        }

        return $feed;
    }

}
