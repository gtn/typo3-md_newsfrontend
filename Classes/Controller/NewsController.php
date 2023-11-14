<?php

declare(strict_types=1);

namespace Mediadreams\MdNewsfrontend\Controller;

/**
 *
 * This file is part of the "News frontend" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * (c) 2019 Christoph Daecke <typo3@mediadreams.org>
 *
 */

use DateTime;
use GeorgRinger\News\Domain\Model\Link;
use GeorgRinger\News\Domain\Repository\LinkRepository;
use Gtn\Eeducation\Lib;
use Mediadreams\MdNewsfrontend\Domain\Model\News;
use Mediadreams\MdNewsfrontend\Event\CreateActionAfterPersistEvent;
use Mediadreams\MdNewsfrontend\Event\CreateActionBeforeSaveEvent;
use Mediadreams\MdNewsfrontend\Event\DeleteActionBeforeDeleteEvent;
use Mediadreams\MdNewsfrontend\Event\UpdateActionBeforeSaveEvent;
use Mediadreams\MdNewsfrontend\Service\NewsSlugHelper;
use TYPO3\CMS\Core\Messaging\AbstractMessage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;
use TYPO3\CMS\Extbase\Annotation\Inject as inject;

/**
 * Class NewsController
 * @package Mediadreams\MdNewsfrontend\Controller
 */
class NewsController extends BaseController
{
    /**
     * persistenceManager
     *
     * @var PersistenceManager
     */
    protected $persistenceManager = null;

    /**
     * @param PersistenceManager $persistenceManager
     */
    public function injectPersistenceManager(PersistenceManager $persistenceManager)
    {
        $this->persistenceManager = $persistenceManager;
    }

    /**
     * linkRepository
     *
     * @var LinkRepository
     * @inject
     */
    public $linkRepository = NULL;

    /**
     * action list
     *
     * @return void
     */
    public function listAction()
    {
        if ((int)$this->feuserUid > 0) {
            $news = $this->newsRepository->findByFeuserId($this->feuserUid, (int)$this->settings['allowNotEnabledNews']);

            // foreach ($news as $newsItem) {
            //     $newsItem->canEdit = Utilities::canEditNews($newsItem);
            //     var_dump(count($newsItem->getRelatedLinks()));
            // }

            $this->assignPagination(
                $news,
                (int)$this->settings['paginate']['itemsPerPage'],
                (int)$this->settings['paginate']['maximumNumberOfLinks']
            );
        }
    }

    /**
     * action new
     *
     * @return void
     */
    public function newAction()
    {
        $this->view->assignMultiple(
            [
                'user' => $this->feuserObj,
                'showinpreviewOptions' => $this->getValuesForShowinpreview()
            ]
        );
    }

    /**
     * Initialize create action
     * Add custom validator for file upload
     *
     * @return void
     */
    public function initializeCreateAction()
    {
        $this->initializeCreateUpdate(
            $this->request->getArguments(),
            $this->arguments['newNews']
        );
    }

    /**
     * action create
     *
     * @param News $newNews
     * @return void
     */
    public function createAction(News $newNews)
    {
        $arguments = $this->request->getArgument('newNews');

        // if no value is provided for field datetime, use current date
        if (!isset($arguments['datetime']) || empty($arguments['datetime'])) {
            $newNews->setDatetime(new DateTime()); // make sure, that you have set the correct timezone for $GLOBALS['TYPO3_CONF_VARS']['SYS']['phpTimeZone']
        }

        $newNews->setTxMdNewsfrontendFeuser($this->feuserObj);

        $newNews->setHidden(1);

        // add signal slot BeforeSave
        // @deprecated will be removed in TYPO3 v12.0. Use PSR-14 based events and EventDispatcherInterface instead.
        $this->signalSlotDispatcher->dispatch(
            __CLASS__,
            __FUNCTION__ . 'BeforeSave',
            [$newNews, $this]
        );

        // PSR-14 Event
        $this->eventDispatcher->dispatch(new CreateActionBeforeSaveEvent($newNews, $this));

        $this->newsRepository->add($newNews);

        // persist news entry in order to get the uid of the entry
        $this->persistenceManager->persistAll();

        // generate and set slug for news record
        $slugHelper = GeneralUtility::makeInstance(NewsSlugHelper::class);
        $slug = $slugHelper->getSlug($newNews);
        $newNews->setPathSegment($slug);
        $this->newsRepository->update($newNews);

        $requestArguments = $this->request->getArguments();

        // handle the fileupload
        $this->initializeFileUpload($requestArguments, $newNews);

        $this->handleGtnFields($requestArguments, $newNews);

        // add signal slot AfterPersist
        // @deprecated will be removed in TYPO3 v12.0. Use PSR-14 based events and EventDispatcherInterface instead.
        $this->signalSlotDispatcher->dispatch(
            __CLASS__,
            __FUNCTION__ . 'AfterPersist',
            [$newNews, $this]
        );

        // PSR-14 Event
        $this->eventDispatcher->dispatch(new CreateActionAfterPersistEvent($newNews, $this));

        $this->clearNewsCache($newNews->getUid(), $newNews->getPid());

        $this->addFlashMessage(
            LocalizationUtility::translate('controller.new_success', 'md_newsfrontend'),
            '',
            AbstractMessage::OK
        );

        $this->redirect('list');
    }

    /**
     * initializeEditAction
     *
     * This is needed in order to get disabled news as well!
     */
    public function initializeEditAction(): void
    {
        $this->setEnableFieldsTypeConverter('news');
    }

    /**
     * action edit
     *
     * @param News $news
     * @TYPO3\CMS\Extbase\Annotation\IgnoreValidation("news")
     * @return void
     */
    public function editAction(News $news)
    {
        $this->checkAccess($news);

        $this->view->assignMultiple(
            [
                'news' => $news,
                'showinpreviewOptions' => $this->getValuesForShowinpreview()
            ]
        );
    }

    /**
     * Initialize update action
     * Add custom validator for file upload
     *
     * @return void
     */
    public function initializeUpdateAction()
    {
        $this->setEnableFieldsTypeConverter('news');

        $this->initializeCreateUpdate(
            $this->request->getArguments(),
            $this->arguments['news']
        );
    }

    /**
     * action update
     *
     * @param News $news
     * @return void
     */
    public function updateAction(News $news)
    {
        $this->checkAccess($news);

        // If archive date was deleted
        if ($news->getArchive() == null) {
            $news->setArchive(0);
        }

        $requestArguments = $this->request->getArguments();

        // Remove file relation from news record
        foreach ($this->uploadFields as $fieldName) {
            if ($requestArguments[$fieldName]['delete'] == 1) {
                $removeMethod = 'remove' . ucfirst($fieldName);
                $getFirstMethod = 'getFirst' . ucfirst($fieldName);

                $news->$removeMethod($news->$getFirstMethod());
            }
        }


        // handle the fileupload
        $this->initializeFileUpload($requestArguments, $news);

        $this->handleGtnFields($requestArguments, $news);

        // add signal slot BeforeSave
        // @deprecated will be removed in TYPO3 v12.0. Use PSR-14 based events and EventDispatcherInterface instead.
        $this->signalSlotDispatcher->dispatch(
            __CLASS__,
            __FUNCTION__ . 'BeforeSave',
            [$news, $this]
        );

        // PSR-14 Event
        $this->eventDispatcher->dispatch(new UpdateActionBeforeSaveEvent($news, $this));

        $this->newsRepository->update($news);
        $this->clearNewsCache($news->getUid(), $news->getPid());

        $this->addFlashMessage(
            LocalizationUtility::translate('controller.edit_success', 'md_newsfrontend'),
            '',
            AbstractMessage::OK
        );

        $this->redirect('list');
    }

    /**
     *
     * @param array $requestArguments
     * @param News $news
     * @return void
     */
    protected function handleGtnFields($requestArguments, $news) {
        $oldRelatedLinks = $news->getRelatedLinks()->toArray();

        foreach ($requestArguments['related_links'] as $link) {
            if (!trim($link['uri'])) {
                continue;
            }

            /** @var $relatedLink Link */
            // overwrite existing link or create new one
            $relatedLink = array_shift($oldRelatedLinks) ?: $this->objectManager->get(\GeorgRinger\News\Domain\Model\Link::class);
            $relatedLink->setUri($link['uri']);
            $relatedLink->setTitle($link['title']);
            $relatedLink->setDescription($link['description']);
            $relatedLink->setPid($news->getPid());

            if (!$relatedLink->getUid()) {
                // if not already saved to database (new object), then add it to news
                $news->addRelatedLink($relatedLink);
            }
        }

        // remove all unwanted old links
        foreach ($oldRelatedLinks as $relatedLink) {
            $this->linkRepository->remove($relatedLink);
        }

        if ($requestArguments['submit_for_review']) {
            // submitted for review!

            $news->setTxMdNewsfrontendSubmittime(time());

	        $username = Lib::getLoginUsername();

	        $email = Lib::email();
	        $email->setSubject('Neue News wurde eingereicht')
		        ->html("Uid: {$news->getUid()}<br/>Titel: {$news->getTitle()}<br/>User: {$username}<br/><br/>News befindet sich im Ordner 'News -> BLK Frontend News'");

	        $email->setTo([
                'alexandra.scharl@eeducation.at' => 'Alexandra Scharl',
				'andreas.riepl@eeducation.at' => 'Andreas Riepl',
				'office@eeducation.at' => 'eEducation Office',
				'christoph.froschauer@eeducation.at' => 'Christoph Froschauer',
				'd@pro-web.at' => 'Daniel Prieler',
	        ])->send();

	        $this->addFlashMessage(
		        'Vielen Dank für Ihren News Beitrag. Nach einer Prüfung werden wir diesen online schalten.',
		        '',
		        AbstractMessage::OK
	        );
        }
    }

    public function initializeDeleteAction()
    {
        $this->setEnableFieldsTypeConverter('news');
    }

    /**
     * action delete
     *
     * @param News $news
     * @return void
     */
    public function deleteAction(News $news)
    {
        $this->checkAccess($news);

        // add signal slot BeforeSave
        // @deprecated will be removed in TYPO3 v12.0. Use PSR-14 based events and EventDispatcherInterface instead.
        $this->signalSlotDispatcher->dispatch(
            __CLASS__,
            __FUNCTION__ . 'BeforeDelete',
            [$news, $this]
        );

        // PSR-14 Event
        $this->eventDispatcher->dispatch(new DeleteActionBeforeDeleteEvent($news, $this));

        $this->newsRepository->remove($news);

        $this->clearNewsCache($news->getUid(), $news->getPid());

        $this->addFlashMessage(
            LocalizationUtility::translate('controller.delete_success', 'md_newsfrontend'),
            '',
            AbstractMessage::OK
        );

        $this->redirect('list');
    }
}
