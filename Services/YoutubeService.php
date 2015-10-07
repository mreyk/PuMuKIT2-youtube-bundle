<?php

namespace Pumukit\YoutubeBundle\Services;

use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Routing\Router;
use Symfony\Component\HttpKernel\Log\LoggerInterface;
use Symfony\Component\Translation\TranslatorInterface;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Services\TagService;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\NotificationBundle\Services\SenderService;

class YoutubeService
{
    const YOUTUBE_PLAYLIST_URL = 'https://www.youtube.com/playlist?list=';
    const PUB_CHANNEL_YOUTUBE = 'PUCHYOUTUBE';
    const METATAG_PLAYLIST_COD = 'YOUTUBE';

    private $dm;
    private $router;
    private $tagService;
    private $logger;
    private $senderService;
    private $translator;
    private $youtubeRepo;
    private $tagRepo;
    private $mmobjRepo;
    private $pythonDirectory;
    private $playlistPrivacyStatus;

    public function __construct(DocumentManager $documentManager, Router $router, TagService $tagService, LoggerInterface $logger, SenderService $senderService, TranslatorInterface $translator, $playlistPrivacyStatus)
    {
        $this->dm = $documentManager;
        $this->router = $router;
        $this->tagService = $tagService;
        $this->logger = $logger;
        $this->senderService = $senderService;
        $this->translator = $translator;
        $this->youtubeRepo = $this->dm->getRepository('PumukitYoutubeBundle:Youtube');
        $this->tagRepo = $this->dm->getRepository('PumukitSchemaBundle:Tag');
        $this->mmobjRepo = $this->dm->getRepository('PumukitSchemaBundle:MultimediaObject');
        $this->pythonDirectory = __DIR__.'/../Resources/data/pyPumukit';
        $this->playlistPrivacyStatus = $playlistPrivacyStatus;
    }

    /**
     * Upload
     * Given a multimedia object,
     * upload one track to Youtube.
     *
     * @param MultimediaObject $multimediaObject
     * @param int              $category
     * @param string           $privacy
     * @param bool             $force
     *
     * @return int
     */
    public function upload(MultimediaObject $multimediaObject, $category = 27, $privacy = 'private', $force = false)
    {
        $track = null;
        $opencastId = $multimediaObject->getProperty('opencast');
        if ($opencastId !== null) {
            $track = $multimediaObject->getFilteredTrackWithTags(array(), array('sbs'), array('html5'), array(), false);
        } //Or array('sbs','html5') ??
        else {
            $track = $multimediaObject->getTrackWithTag('html5');
        }
        if (null === $track) {
            $track = $multimediaObject->getTrackWithTag('master');
        }
        if (null === $track) {
            $errorLog = __CLASS__.' ['.__FUNCTION__
              ."] Error, the Multimedia Object with id '"
              .$multimediaObject->getId()."' has no master.";
            $this->logger->addError($errorLog);
            throw new \Exception($errorLog);
        }
        $trackPath = $track->getPath();
        if (!file_exists($trackPath)) {
            $errorLog = __CLASS__.' ['.__FUNCTION__
              .'] Error, there is no file '.$trackPath;
            $this->logger->addError($errorLog);
            throw new \Exception($errorLog);
        }
        if (null === $youtubeId = $multimediaObject->getProperty('youtube')) {
            $youtube = new Youtube();
            $youtube->setMultimediaObjectId($multimediaObject->getId());
            $this->dm->persist($youtube);
            $this->dm->flush();
            $multimediaObject->setProperty('youtube', $youtube->getId());
            $this->dm->persist($multimediaObject);
            $this->dm->flush();
        } else {
            $youtube = $this->youtubeRepo->find($youtubeId);
        }

        $title = $this->getTitleForYoutube($multimediaObject);
        $description = $this->getDescriptionForYoutube($multimediaObject);
        $tags = $this->getTagsForYoutube($multimediaObject);
        $dcurrent = getcwd();
        chdir($this->pythonDirectory);
        $pyOut = exec('python upload.py --file '.$trackPath.' --title "'.addslashes($title).'" --description "'.addslashes($description).'" --category '.$category.' --keywords "'.$tags.'" --privacyStatus '.$privacy, $output, $return_var);
        chdir($dcurrent);
        $out = json_decode($pyOut, true);
        if ($out['error']) {
            $youtube->setStatus(Youtube::STATUS_ERROR);
            $this->dm->persist($youtube);
            $this->dm->flush();
            $errorLog = __CLASS__.' ['.__FUNCTION__
              .'] Error in the upload: '.$out['error_out'];
            $this->logger->addError($errorLog);
            throw new \Exception($errorLog);
        }
        $youtube->setYoutubeId($out['out']['id']);
        $youtube->setLink('https://www.youtube.com/watch?v='.$out['out']['id']);
        $multimediaObject->setProperty('youtubeurl', $youtube->getLink());
        $this->dm->persist($multimediaObject);
        if ($out['out']['status'] == 'uploaded') {
            $youtube->setStatus(Youtube::STATUS_PROCESSING);
        }

        $code = $this->getEmbed($out['out']['id']);
        $youtube->setEmbed($code);
        $youtube->setForce($force);
        $this->dm->persist($youtube);
        $this->dm->flush();
        $youtubeTag = $this->tagRepo->findOneByCod(self::PUB_CHANNEL_YOUTUBE);
        if (null != $youtubeTag) {
            $addedTags = $this->tagService->addTagToMultimediaObject($multimediaObject, $youtubeTag->getId());
        } else {
            $errorLog = __CLASS__.' ['.__FUNCTION__
              .'] There is no Youtube tag defined with code PUCHYOUTUBE.';
            $this->logger->addError($errorLog);
            throw new \Exception($errorLog);
        }

        return 0;
    }

    /**
     * Move to list.
     *
     * @param MultimediaObject $multimediaObject
     * @param string           $playlistTagId
     *
     * @return int
     */
    public function moveToList(MultimediaObject $multimediaObject, $playlistTagId)
    {
        $youtube = $this->getYoutubeDocument($multimediaObject);

        $dcurrent = getcwd();
        chdir($this->pythonDirectory);
        if (null === $playlistTag = $this->tagRepo->find($playlistTagId)) {
            $errorLog = __CLASS__.' ['.__FUNCTION__
              ."] Error! The tag with id '".$playlistTagId
              ."' for Youtube Playlist does not exist";
            $this->logger->addError($errorLog);
            throw new \Exception($errorLog);
        }
        if (null === $playlistId = $playlistTag->getProperty('youtube')) {
            $pyOut = exec('python createPlaylist.py --title "'.$playlistTag->getTitle().'" --privacyStatus "'.$this->playlistPrivacyStatus.'"', $output, $return_var);
            $out = json_decode($pyOut, true);
            if ($out['error']) {
                $errorLog = __CLASS__.' ['.__FUNCTION__
                  ."] Error in creating in Youtube the playlist from tag with id '"
                  .$playlistTagId."': ".$out['error_out'];
                $this->logger->addError($errorLog);
                throw new \Exception($errorLog);
            } elseif ($out['out'] != null) {
                $infoLog = __CLASS__.' ['.__FUNCTION__
                  ."] Created Youtube Playlist '".$out['out']
                  ."' for Tag with id '".$playlistTagId."'";
                $this->logger->addInfo($infoLog);
                $playlistId = $out['out'];
                $playlistTag->setProperty('youtube', $playlistId);
                $playlistTag->setProperty('customfield', 'youtube:text');
                $this->dm->persist($playlistTag);
                $this->dm->flush();
            } else {
                $errorLog = __CLASS__.' ['.__FUNCTION__
                  ."] Error! Creating the playlist from tag with id '"
                  .$playlistTagId."'";
                $this->logger->addError($errorLog);
                throw new \Exception($errorLog);
            }
        }
        $pyOut = exec('python insertInToList.py --videoid '.$youtube->getYoutubeId().' --playlistid '.$playlistId, $output, $return_var);
        chdir($dcurrent);
        $out = json_decode($pyOut, true);
        if ($out['error']) {
            $errorLog = __CLASS__.' ['.__FUNCTION__
              ."] Error in moving the Multimedia Object '".$multimediaObject->getId()
              ."' to Youtube playlist with id '".$playlistId."': ".$out['error_out'];
            $this->logger->addError($errorLog);
            throw new \Exception($errorLog);
        }
        if ($out['out'] != null) {
            $youtube->setPlaylist($playlistId, $out['out']);
            if (!$multimediaObject->containsTagWithCod($playlistTag->getCod())) {
                $addedTags = $this->tagService->addTagToMultimediaObject($multimediaObject, $playlistTag->getId(), false);
            }
            $this->dm->persist($youtube);
            $this->dm->flush();
        } else {
            $errorLog = __CLASS__.' ['.__FUNCTION__
              ."] Error in moving the Multimedia Object '".$multimediaObject->getId()
              ."' to Youtube playlist with id '".$playlistId."'";
            $this->logger->addError($errorLog);
            throw new \Exception($errorLog);
        }

        return 0;
    }

    /**
     * Delete.
     *
     * @param MultimediaObject $multimediaObject
     *
     * @return int
     */
    public function delete(MultimediaObject $multimediaObject)
    {
        $youtube = $this->getYoutubeDocument($multimediaObject);

        foreach ($youtube->getPlaylists() as $playlistId => $playlistItem) {
            $this->deleteFromList($playlistItem, $youtube, $playlistId);
        }
        $dcurrent = getcwd();
        chdir($this->pythonDirectory);
        $pyOut = exec('python deleteVideo.py --videoid '.$youtube->getYoutubeId(), $output, $return_var);
        chdir($dcurrent);
        $out = json_decode($pyOut, true);
        if ($out['error']) {
            $errorLog = __CLASS__.' ['.__FUNCTION__
              ."] Error in deleting the YouTube video with id '".$youtube->getYoutubeId()
              ."' and mongo id '".$youtube->getId()."': ".$out['error_out'];
            $this->logger->addError($errorLog);
            throw new \Exception($errorLog);
        }
        $youtube->setStatus(Youtube::STATUS_REMOVED);
        $youtube->setForce(false);
        $this->dm->persist($youtube);
        $this->dm->flush();
        $youtubeEduTag = $this->tagRepo->findOneByCod(self::PUB_CHANNEL_YOUTUBE);
        $youtubeTag = $this->tagRepo->findOneByCod(self::PUB_CHANNEL_YOUTUBE);
        if (null != $youtubeTag) {
            if ($multimediaObject->containsTag($youtubeEduTag)) {
                $this->tagService->removeTagFromMultimediaObject($multimediaObject, $youtubeEduTag->getId());
            }
        } else {
            $errorLog = __CLASS__.' ['.__FUNCTION__
              ."] There is no Youtube tag defined with code '".self::PUB_CHANNEL_YOUTUBE."'";
            $this->logger->addError($errorLog);
            throw new \Exception($errorLog);
        }

        return 0;
    }

    /**
     * Delete orphan.
     *
     * @param Youtube $youtube
     *
     * @return int
     */
    public function deleteOrphan(Youtube $youtube)
    {
        foreach ($youtube->getPlaylists() as $playlistId => $playlistItem) {
            $this->deleteFromList($playlistItem, $youtube, $playlistId);
        }
        $dcurrent = getcwd();
        chdir($this->pythonDirectory);
        $pyOut = exec('python deleteVideo.py --videoid '.$youtube->getYoutubeId(), $output, $return_var);
        chdir($dcurrent);
        $out = json_decode($pyOut, true);
        if ($out['error']) {
            $errorLog = __CLASS__.' ['.__FUNCTION__
              ."] Error in deleting the YouTube video with id '".$youtube->getYoutubeId()
              ."' and mongo id '".$youtube->getId()."': ".$out['error_out'];
            $this->logger->addError($errorLog);
            throw new \Exception($errorLog);
        }
        $youtube->setStatus(Youtube::STATUS_REMOVED);
        $youtube->setForce(false);
        $this->dm->persist($youtube);
        $this->dm->flush();

        return 0;
    }

    /**
     * Update Metadata.
     *
     * @param MultimediaObject $multimediaObject
     *
     * @return int
     */
    public function updateMetadata(MultimediaObject $multimediaObject)
    {
        $youtube = $this->getYoutubeDocument($multimediaObject);

        if (Youtube::STATUS_PUBLISHED === $youtube->getStatus()) {
            $title = $this->getTitleForYoutube($multimediaObject);
            $description = $this->getDescriptionForYoutube($multimediaObject);
            $tags = $this->getTagsForYoutube($multimediaObject);
            $dcurrent = getcwd();
            chdir($this->pythonDirectory);
            $pyOut = exec('python updateVideo.py --videoid '.$youtube->getYoutubeId().' --title "'.addslashes($title).'" --description "'.addslashes($description).'" --tag "'.$tags.'"', $output, $return_var);
            chdir($dcurrent);
            $out = json_decode($pyOut, true);
            if ($out['error']) {
                $errorLog = __CLASS__.' ['.__FUNCTION__
                  ."] Error in updating metadata for Youtube video with id '"
                  .$youtube->getId()."': ".$out['error_out'];
                $this->logger->addError($errorLog);
                throw new \Exception($errorLog);
            }
            $youtube->setSyncMetadataDate(new \DateTime('now'));
            $this->dm->persist($youtube);
            $this->dm->flush();
        }

        return 0;
    }

    /**
     * Update Status.
     *
     * @param Youtube $youtube
     *
     * @return int
     */
    public function updateStatus(Youtube $youtube)
    {
        $multimediaObject = $this->mmobjRepo->find($youtube->getMultimediaObjectId());
        if (null == $multimediaObject) {
            // TODO remove Youtube Document ?????
            $errorLog = __CLASS__.' ['.__FUNCTION__
              ."] Error, there is no MultimediaObject referenced from YouTube document with id '"
              .$youtube->getId()."'";
            $this->logger->addError($errorLog);
            throw new \Exception($errorLog);
        }
        $dcurrent = getcwd();
        chdir($this->pythonDirectory);
        $pyOut = exec('python updateSatus.py --videoid '.$youtube->getYoutubeId(), $output, $return_var);
        chdir($dcurrent);
        $out = json_decode($pyOut, true);
        // NOTE: If the video has been removed, it returns 404 instead of 200 with 'not found Video'
        if ($out['error']) {
            if (strpos($out['error_out'], 'was not found.')) {
                $data = array('multimediaObject' => $multimediaObject, 'youtube' => $youtube);
                $this->sendEmail('status removed', $data, array(), array());
                $youtube->setStatus(Youtube::STATUS_REMOVED);
                $this->dm->persist($youtube);
                $youtubeEduTag = $this->tagRepo->findOneByCod(self::PUB_CHANNEL_YOUTUBE);
                if (null !== $youtubeEduTag) {
                    if ($multimediaObject->containsTag($youtubeEduTag)) {
                        $this->tagService->removeTagFromMultimediaObject($multimediaObject, $youtubeEduTag->getId());
                    }
                } else {
                    $errorLog = __CLASS__.' ['.__FUNCTION__
                      ."] There is no Youtube tag defined with code '".self::PUB_CHANNEL_YOUTUBE."'";
                    $this->logger->addWarning($errorLog);
                    /*throw new \Exception($errorLog);*/
                }
                $this->dm->flush();

                return 0;
            } else {
                $errorLog = __CLASS__.' ['.__FUNCTION__
                  ."] Error in verifying the status of the video from youtube with id '"
                  .$youtube->getYoutubeId()."' and mongo id '".$youtube->getId()
                  ."':  ".$out['error_out'];
                $this->logger->addError($errorLog);
                throw new \Exception($errorLog);
            }
        }
        if (($out['out'] == 'processed') && ($youtube->getStatus() == Youtube::STATUS_PROCESSING)) {
            $youtube->setStatus(Youtube::STATUS_PUBLISHED);
            $this->dm->persist($youtube);
            $this->dm->flush();
            $data = array('multimediaObject' => $multimediaObject, 'youtube' => $youtube);
            $this->sendEmail('finished publication', $data, array(), array());
        } elseif ($out['out'] == 'uploaded') {
            $youtube->setStatus(Youtube::STATUS_PROCESSING);
            $this->dm->persist($youtube);
            $this->dm->flush();
        } elseif (($out['out'] == 'rejected') && ($out['rejectedReason'] == 'duplicate') && ($youtube->getStatus() != Youtube::STATUS_DUPLICATED)) {
            $youtube->setStatus(Youtube::STATUS_DUPLICATED);
            $this->dm->persist($youtube);
            $this->dm->flush();
            $data = array('multimediaObject' => $multimediaObject, 'youtube' => $youtube);
            $this->sendEmail('duplicated', $data, array(), array());
        }

        return 0;
    }

    /**
     * Update playlists.
     *
     * @param MultimediaObject $multimediaObject
     * @paran string $playlistTagId
     *
     * @return int
     */
    public function updatePlaylist(MultimediaObject $multimediaObject)
    {
        //TODO:
        //If after updating, the playlist list is empty AND the 'default playlist' option is activated, moveToDefaultList.
        $playlistsToUpdate = $this->getPlaylistsToUpdate($multimediaObject);
        if (count($playlistsToUpdate) == 0) {
            return 0;
        }

        $youtube = $this->getYoutubeDocument($multimediaObject);
        $youtube->setUpdatePlaylist(true);
        foreach ($playlistsToUpdate as $playlistId) {
            $playlistTag = $this->getTagByYoutubeProperty($playlistId);
            if ($playlistTag === null) {
                $errorLog = sprintf('%s [%s] Error! The tag with id %s for Youtube Playlist does not exist', __CLASS__, __FUNCTION__, $playlistTagId);
                $this->logger->addError($errorLog);
                throw new \Exception($errorLog);
            }
            if (!array_key_exists($playlistId, $youtube->getPlaylists())) {
                $playlistTag = $this->getTagByYoutubeProperty($playlistId);
                $this->moveToList($multimediaObject, $playlistTag->getId());
            } elseif (!$multimediaObject->containsTagWithCod($playlistTag->getCod())) {
                $playlistItem = $youtube->getPlaylist($playlistId);
                if ($playlistItem === null) {
                    $errorLog = sprintf('%s [%s] Error! The Youtube document with id %s does not have a playlist item for Playlist %s', __CLASS__, __FUNCTION__, $youtube->getId(), $playlistId);
                    $this->logger->addError($errorLog);
                    throw new \Exception($errorLog);
                }
                $this->deleteFromList($playlistItem, $youtube, $playlistId, false);
            }
        }
        $youtube->setUpdatePlaylist(false);
        $this->dm->persist($youtube);
        $this->dm->flush();

        return 0;
    }

    /**
     * Returns array of playlistsIds to update.
     * 
     * @param MultimediaObject $multimediaObject
     *
     * @return array
     */
    private function getPlaylistsToUpdate(MultimediaObject $multimediaObject)
    {
        $playlistsIds = array();
        $youtube = $this->getYoutubeDocument($multimediaObject);
        if ($youtube->getStatus() !== Youtube::STATUS_PUBLISHED) {
            return $playlistsIds;
        }
        foreach ($multimediaObject->getTags() as $embedTag) {
            if (!$embedTag->isDescendantOfByCod(self::METATAG_PLAYLIST_COD)) {
                //This is not the tag you are looking for
                continue;
            }
            $playlistTag = $this->tagRepo->findOneByCod($embedTag->getCod());
            $playlistId = $playlistTag->getProperty('youtube');
            if (!array_key_exists($playlistId, $youtube->getPlaylists())) {
                //If the tag doesn't exist on youtube playlists
                if (!in_array($playlistId, $playlistsIds)) {
                    $playlistsIds[] = $playlistId;
                }
            }
        }
        foreach ($youtube->getPlaylists() as $playlistId => $playlistRel) {
            $playlistTag = $this->getTagByYoutubeProperty($playlistId);
            if ($playlistTag === null
                               || !$multimediaObject->containsTagWithCod($playlistTag->getCod())) {
                //If the tag doesn't exist in PuMuKIT or If the mmobj doesn't have this tag
                if (!in_array($playlistId, $playlistsIds)) {
                    $playlistsIds[] = $playlistId;
                }
            }
        }

        return $playlistsIds;
    }

    /**
     * Returns a Tag whose youtube property 'youtube' has a $playlistId value.
     *
     * @param string $playlistId
     *
     * return Tag
     */
    private function getTagByYoutubeProperty($playlistId)
    {
        //return $this->tagRepo->getTagByProperty('youtube', $playlistId); //I like this option more (yet unimplemented)
        return $this->tagRepo->createQueryBuilder()
                    ->field('properties.youtube')->equals($playlistId)
                    ->getQuery()->getSingleResult();
    }

    /**
     * Send email.
     *
     * @param string $cause
     * @param array  $succeed
     * @param array  $failed
     * @param array  $errors
     *
     * @return int|bool
     */
    public function sendEmail($cause = '', $succeed = array(), $failed = array(), $errors = array())
    {
        if ($this->senderService->isEnabled()) {
            $subject = $this->buildEmailSubject($cause);
            $body = $this->buildEmailBody($cause, $succeed, $failed, $errors);
            if ($body) {
                $error = $this->getError($errors);
                $emailTo = $this->senderService->getSenderEmail();
                $template = 'PumukitNotificationBundle:Email:notification.html.twig';
                $parameters = array('subject' => $subject, 'body' => $body, 'sender_name' => $this->senderService->getSenderName());
                $output = $this->senderService->sendNotification($emailTo, $subject, $template, $parameters, $error);
                if (0 < $output) {
                    $infoLog = __CLASS__.' ['.__FUNCTION__
                      .'] Sent notification email to "'.$emailTo.'"';
                    $this->logger->addInfo($infoLog);
                } else {
                    $infoLog = __CLASS__.' ['.__FUNCTION__
                      .'] Unable to send notification email to "'
                      .$emailTo.'", '.$output.'email(s) were sent.';
                    $this->logger->addInfo($infoLog);
                }

                return $output;
            }
        }

        return false;
    }

    private function buildEmailSubject($cause = '')
    {
        $subject = ucfirst($cause).' of YouTube video(s)';

        return $subject;
    }

    private function buildEmailBody($cause = '', $succeed = array(), $failed = array(), $errors = array())
    {
        $statusUpdate = array('finished publication', 'status removed', 'duplicated');
        $body = '';
        if (!empty($succeed)) {
            if (in_array($cause, $statusUpdate)) {
                $body = $this->buildStatusUpdateBody($cause, $succeed);
            } else {
                $body = $body.'<br/>The following videos were '.$cause.(substr($cause, -1) === 'e') ? '' : 'e'.'d to Youtube:<br/>';
                foreach ($succeed as $mm) {
                    if ($mm instanceof MultimediaObject) {
                        $body = $body.'<br/> -'.$mm->getId().': '.$mm->getTitle().' '.$this->router->generate('pumukit_webtv_multimediaobject_index', array('id' => $mm->getId()), true);
                    } elseif ($mm instanceof Youtube) {
                        $body = $body.'<br/> -'.$mm->getId().': '.$mm->getLink();
                    }
                }
            }
        }
        if (!empty($failed)) {
            $body = $body.'<br/>The '.$cause.' of the following videos has failed:<br/>';
            foreach ($failed as $key => $mm) {
                if ($mm instanceof MultimediaObject) {
                    $body = $body.'<br/> -'.$mm->getId().': '.$mm->getTitle().'<br/>';
                } elseif ($mm instanceof Youtube) {
                    $body = $body.'<br/> -'.$mm->getId().': '.$mm->getLink();
                }
                if (array_key_exists($key, $errors)) {
                    $body = $body.'<br/> With this error:<br/>'.$errors[$key].'<br/>';
                }
            }
        }

        return $body;
    }

    private function buildStatusUpdateBody($cause = '', $succeed = array())
    {
        $body = '';
        if ((array_key_exists('multimediaObject', $succeed)) && (array_key_exists('youtube', $succeed))) {
            $multimediaObject = $succeed['multimediaObject'];
            $youtube = $succeed['youtube'];
            if ($cause === 'finished publication') {
                if ($multimediaObject instanceof MultimediaObject) {
                    $body = $body.'<br/>The video "'.$multimediaObject->getTitle().'" has been successfully published into YouTube.<br/>';
                }
                if ($youtube instanceof Youtube) {
                    $body = $body.'<br/>'.$youtube->getLink().'<br/>';
                }
            } elseif ($cause === 'status removed') {
                if ($multimediaObject instanceof MultimediaObject) {
                    $body = $body.'<br/>The following video has been removed from YouTube: "'.$multimediaObject->getTitle().'"<br/>';
                }
                if ($youtube instanceof Youtube) {
                    $body = $body.'<br/>'.$youtube->getLink().'<br/>';
                }
            } elseif ($cause === 'duplicated') {
                if ($multimediaObject instanceof MultimediaObject) {
                    $body = $body.'<br/>YouTube has rejected the upload of the video: "'.$multimediaObject->getTitle().'"</br>';
                    $body = $body.'because it has been published previously.<br/>';
                }
                if ($youtube instanceof Youtube) {
                    $body = $body.'<br/>'.$youtube->getLink().'<br/>';
                }
            }
        }

        return $body;
    }

    private function getError($errors = array())
    {
        if (!empty($errors)) {
            return true;
        }

        return false;
    }

    /**
     * Get title for youtube.
     */
    private function getTitleForYoutube(MultimediaObject $multimediaObject)
    {
        $title = $multimediaObject->getTitle();

        if (strlen($title) > 60) {
            while (strlen($title) > 55) {
                $pos = strrpos($title, ' ', 61);
                if ($pos !== false) {
                    $title = substr($title, 0, $pos);
                } else {
                    break;
                }
            }
        }
        while (strlen($title) > 55) {
            $title = substr($title, 0, strrpos($title, ' '));
        }
        if (strlen($multimediaObject->getTitle()) > 55) {
            $title = $title.'(...)';
        }

        return $title;
    }

    /**
     * Get description for youtube.
     */
    private function getDescriptionForYoutube(MultimediaObject $multimediaObject)
    {
        $appInfoLink = $this->router->generate('pumukit_webtv_multimediaobject_index', array('id' => $multimediaObject->getId()), true);
        $series = $multimediaObject->getSeries();
        $break = array('<br />', '<br/>');
        $description = strip_tags($series->getTitle().' - '.$multimediaObject->getTitle()."\n".$multimediaObject->getSubtitle()."\n".str_replace($break, "\n", $multimediaObject->getDescription()).'<br /> Video available at: '.$appInfoLink);

        return $description;
    }

    /**
     * Get tags for youtube.
     */
    private function getTagsForYoutube(MultimediaObject $multimediaObject)
    {
        $numbers = array('1', '2', '3', '4', '5', '6', '7', '8', '9', '0');
        // TODO CMAR
        //$tags = str_replace($numbers, '', $multimediaObject->getKeyword()) . ', CMAR, Mar, Galicia, Portugal, Eurorregión, Campus, Excelencia, Internacional';
        $tags = str_replace($numbers, '', $multimediaObject->getKeyword());

        return $tags;
    }

    /**
     * GetYoutubeDocument
     * returns youtube document associated with the multimediaObject. 
     * If it doesn't exists, it tries to recreate it and logs an error on the output.
     * If it can't, throws an exception with the error.
     *
     * @param MultimediaObject $multimediaObject
     *
     * @return Youtube
     */
    private function getYoutubeDocument(MultimediaObject $multimediaObject)
    {
        $youtube = $this->youtubeRepo->findOneByMultimediaObjectId($multimediaObject->getId());
        if ($youtube === null) {
            $youtube = $this->fixRemovedYoutubeDocument($multimediaObject);
            $trace = debug_backtrace();
            $caller = $trace[1];
            $errorLog = 'Error, there was no YouTube data of the Multimedia Object '
                      .$multimediaObject->getId().' Created new Youtube document with id "'
                      .$youtube->getId().'"';
            $errorLog = __CLASS__.' ['.__FUNCTION__."] <-Called by: {$caller['function']}".$errorLog;
            $this->logger->addWarning($errorLog);
        }

        return $youtube;
    }

    /**
     * FixRemovedYoutubeDocument
     * returns a Youtube Document generated based on 'youtubeurl' property from multimediaObject
     * if it can't, throws an exception.
     *
     * @param MultimediaObject $multimediaObject
     *
     * @return Youtube
     */
    private function fixRemovedYoutubeDocument(MultimediaObject $multimediaObject)
    {
        //Tries to find the 'youtubeurl' property to recreate the Youtube Document
        $youtubeUrl = $multimediaObject->getProperty('youtubeurl');
        if ($youtubeUrl === null) {
            $errorLog = "PROPERTY 'youtubeurl' for the MultimediaObject id=".$multimediaObject->getId().' DOES NOT EXIST. ¿Is this multimediaObject supposed to be on Youtube?';
            $errorLog = __CLASS__.' ['.__FUNCTION__.'] '.$errorLog;
            $this->logger->addError($errorLog);
            throw new \Exception($errorLog);
        }
        //Tries to get the youtubeId from the youtubeUrl
        $arr = array();
        parse_str(parse_url($youtubeUrl, PHP_URL_QUERY), $arr);
        $youtubeId = isset($arr['v']) ? $arr['v'] : null;

        if ($youtubeId === null) {
            $errorLog = "URL=$youtubeUrl not valid on the MultimediaObject id=".$multimediaObject->getId().' ¿Is this multimediaObject supposed to be on Youtube?';
            $errorLog = __CLASS__.' ['.__FUNCTION__.'] '.$errorLog;
            $this->logger->addError($errorLog);
            throw new \Exception($errorLog);
        }

        //Recreating Youtube Document for the mmobj
        $youtube = new Youtube();
        $youtube->setMultimediaObjectId($multimediaObject->getId());
        $youtube->setLink($youtubeUrl);
        $youtube->setEmbed($this->getEmbed($youtubeId));
        $youtube->setYoutubeId($youtubeId);
        $file_headers = @get_headers($multimediaObject->getProperty('youtubeurl'));
        if ($file_headers[0] === 'HTTP/1.0 200 OK') {
            $youtube->setStatus(Youtube::STATUS_PUBLISHED);
        } else {
            $youtube->setStatus(Youtube::STATUS_REMOVED);
        }
        $this->dm->persist($youtube);
        $this->dm->flush();
        $multimediaObject->setProperty('youtube', $youtube->getId());
        $this->dm->persist($multimediaObject);
        $this->dm->flush();

        return $youtube;
    }

    private function deleteFromList($playlistItem, $youtube, $playlistId, $doFlush = true)
    {
        $dcurrent = getcwd();
        chdir($this->pythonDirectory);
        $pyOut = exec('python deleteFromList.py --id '.$playlistItem, $output, $return_var);
        chdir($dcurrent);
        $out = json_decode($pyOut, true);
        if ($out['error']) {
            $errorLog = __CLASS__.' ['.__FUNCTION__
              ."] Error in deleting the Youtube video with id '".$youtube->getId()
              ."' from playlist with id '".$playlistItem."': ".$out['error_out'];
            $this->logger->addError($errorLog);
            throw new \Exception($errorLog);
        }
        $youtube->removePlaylist($playlistId);
        $this->dm->persist($youtube);
        if ($doFlush) {
            $this->dm->flush();
        }
        $infoLog = __CLASS__.' ['.__FUNCTION__
          ."] Removed playlist with youtube id '".$playlistId
          ."' and relation of playlist item id '".$playlistItem
          ."' from Youtube document with Mongo id '".$youtube->getId()."'";
        $this->logger->addInfo($infoLog);
    }

    /**
     * GetEmbed
     * Returns the html embed (iframe) code for a given youtubeId.
     *
     * @param string youtubeId
     *
     * @return string
     */
    private function getEmbed($youtubeId)
    {
        return '<iframe width="853" height="480" src="http://www.youtube.com/embed/'
          .$youtubeId.'" frameborder="0" allowfullscreen></iframe>';
    }
}
