<?php

namespace Diversworld\CalendarEditorBundle\Controller\Module;

use Contao\BackendTemplate;
use Contao\Events;
use Contao\Input;
use Contao\StringUtil;
use Contao\System;
use Diversworld\CalendarEditorBundle\Models\CalendarModelEdit;
use Diversworld\CalendarEditorBundle\Services\CheckAuthService;
use Contao\FrontendTemplate;
use Psr\Log\LoggerInterface;
use Contao\FrontendUser;
class ModuleEventReaderEdit extends Events
{
    private LoggerInterface $logger;

    /**
     * Template
     * @var string
     */
    protected $strTemplate = 'mod_event_ReaderEditLink';

    private $scopeMatcher;

    public function isBackend(): bool
    {
        if ($this->scopeMatcher === null) {
            $this->scopeMatcher = System::getContainer()->get('contao.routing.scope_matcher');
        }

        return $this->scopeMatcher->isBackendRequest(System::getContainer()->get('request_stack')->getCurrentRequest());
    }

    public function isFrontend(): bool
    {
        if ($this->scopeMatcher === null) {
            $this->scopeMatcher = System::getContainer()->get('contao.routing.scope_matcher');
        }

        return $this->scopeMatcher->isFrontendRequest(System::getContainer()->get('request_stack')->getCurrentRequest());
    }

    public function generate() : string
    {
        $this->logger = System::getContainer()->get('monolog.logger.contao.general');

        if ($this->isBackend()) {
            $objTemplate = new BackendTemplate('be_wildcard');

            $objTemplate->wildcard = '### EVENT READER EDIT LINK ###';
            $objTemplate->title = $this->headline;
            $objTemplate->id = $this->id;
            $objTemplate->link = $this->name;
            $objTemplate->href = 'contao/main.php?do=themes&amp;table=tl_module&amp;table=tl_module&amp;act=edit&amp;id=' . $this->id;

            return $objTemplate->parse();
        }

        //Return if no event has been specified
        if (!Input::get('auto_item')) {
            return '';
        }

        $this->cal_calendar = $this->sortOutProtected(StringUtil::deserialize($this->cal_calendar));

        // Return if there are no calendars
        if (!is_array($this->cal_calendar) || count($this->cal_calendar) < 1)
        {
            return '';
        }
        return parent::generate();
    }

    protected function compile(): void
    {
        $this->logger = System::getContainer()->get('monolog.logger.contao.general');

        $this->Template = new FrontendTemplate($this->strTemplate);
        $this->Template->editRef = '';

        // FE user is logged in
        $token = System::getContainer()->get('security.token_storage')->getToken();

        // Prüfen, ob ein Token gesetzt ist und ein Benutzer vorhanden ist
        if ($token !== null && $token->getUser() instanceof FrontendUser) {
            return;
        }
        $time = time();

        $hasBackendUser = System::getContainer()->get('contao.security.token_checker')->hasBackendUser();

        // Get current event
        if (!$hasBackendUser) {
            // Zusatzbedingungen für Frontend-Benutzer vorhanden
            $objEvent = $this->Database->prepare(
                "SELECT *,
                author AS authorId,
                (SELECT title FROM tl_calendar WHERE tl_calendar.id = tl_calendar_events.pid) AS calendar,
                (SELECT name FROM tl_user WHERE id = author) author
                FROM tl_calendar_events
                WHERE pid IN(" . implode(',', array_map('intval', $this->cal_calendar)) . ")
                AND (id = ? OR alias = ?)
                AND (start='' OR start<?)
                AND (stop='' OR stop>?)
                AND published = 1"
                    )
                    ->limit(1)
                    ->execute(
                        (is_numeric(Input::get('auto_item')) ? Input::get('auto_item') : 0),
                        Input::get('auto_item'),
                        $time,
                        $time
                    );
        } else {
            // Ohne Zusatzbedingungen für Backend-Benutzer
            $objEvent = $this->Database->prepare(
                "SELECT *,
                author AS authorId,
                (SELECT title FROM tl_calendar WHERE tl_calendar.id = tl_calendar_events.pid) AS calendar,
                (SELECT name FROM tl_user WHERE id = author) author
                FROM tl_calendar_events
                WHERE pid IN(" . implode(',', array_map('intval', $this->cal_calendar)) . ")
                AND (id = ? OR alias = ?)"
                    )
                    ->limit(1)
                    ->execute(
                        (is_numeric(Input::get('auto_item')) ? Input::get('auto_item') : 0),
                        Input::get('auto_item')
                    );
        }

        $this->logger->info('INFO: objEvent '.$objEvent->numRows.' - - ' . print_r($objEvent,true) .' - ', ['module' => $this->name]);

        if ($objEvent->numRows < 1) {
            $this->Template->error = $GLOBALS['TL_LANG']['MSC']['caledit_NoEditAllowed'];
            $this->Template->error_class = 'error';
            return;
        }

        // get Calender with PID
        $calendarModel = CalendarModelEdit::findByPk($objEvent->pid);

        $this->logger->info('INFO: calendarModel ' . print_r($calendarModel,true) .' - ', ['module' => $this->name]);

        if ($calendarModel === null) {
            return;
        }

        $this->logger->info('INFO: allowEdit:'.$calendarModel->AllowEdit.' - ', ['module' => $this->name]);

        if ($calendarModel->AllowEdit) {
            // Calendar allows editing
            // check user rights
            /** @var CheckAuthService $checkAuthService */
            $checkAuthService = System::getContainer()->get('Diversworld\CalendarEditorBundle\Services\CheckAuthService');

            if (System::getContainer()->get('contao.security.token_checker')->hasFrontendUser()) {
                $frontendUser = FrontendUser::getInstance(); // Hole den aktuellen User
            } else {
                $frontendUser = null; // Kein Benutzer vorhanden
            }
            $this->logger->info('INFO: frontendUser ' . print_r($frontendUser,true) .' - ', ['module' => $this->name]);
            $this->logger->info('INFO: calendarModel ' . print_r($calendarModel,true) .' - ', ['module' => $this->name]);

            // Rufe die Methode nur auf, wenn ein Frontend-User existiert
            if ($frontendUser !== null) {
                $isAuthorized = $checkAuthService->isUserAuthorized($calendar, $frontendUser);
            } else {
                // Kein Benutzer vorhanden, also nicht autorisiert
                $isAuthorized = false;
            }

            $isUserAdmin = $checkAuthService->isUserAdmin($calendarModel, $this->User);

            $authorizedElapsedEvents = $checkAuthService->isUserAuthorizedElapsedEvents($calendarModel, $this->User);
            $areEditLinksAllowed = $checkAuthService->areEditLinksAllowed($calendarModel, $objEvent->row(), $this->User->id, $isUserAdmin, $isUserAuthorized);
            $this->logger->info('INFO: isUserAdmin:'.$isUserAdmin.' - ', ['module' => $this->name]);
            $this->logger->info('INFO: isUserAuthorized:'.$isUserAuthorized.' - ', ['module' => $this->name]);
            $this->logger->info('INFO: authorizedElapsedEvents:'.$authorizedElapsedEvents.' - ', ['module' => $this->name]);
            $this->logger->info('INFO: areEditLinksAllowed:'.$areEditLinksAllowed.' - ', ['module' => $this->name]);

            $strUrl = '';
            if ($areEditLinksAllowed) {
                // get the JumpToEdit-Page for this calendar
                $objPage = $this->Database->prepare("SELECT * FROM tl_page WHERE id=(SELECT caledit_jumpTo FROM tl_calendar WHERE id=?)")
                    ->limit(1)
                    ->execute($calendarModel->id);
                if ($objPage->numRows) {
                    $strUrl = $this->generateFrontendUrl($objPage->row(), '');
                }

                $this->logger->info('INFO: strUrl ' . $strUrl .' - ', ['module' => $this->name]);
                $this->logger->info('INFO: editRef ' . $strUrl.'?edit='.$objEvent->id .' - ', ['module' => $this->name]);

                $this->Template->editRef = $strUrl.'?edit='.$objEvent->id;
                $this->Template->editLabel = $GLOBALS['TL_LANG']['MSC']['caledit_editLabel'];
                $this->Template->editTitle = $GLOBALS['TL_LANG']['MSC']['caledit_editTitle'];

                if ($this->caledit_showCloneLink) {
                    $this->Template->cloneRef = $strUrl.'?clone='.$objEvent->id;
                    $this->Template->cloneLabel = $GLOBALS['TL_LANG']['MSC']['caledit_cloneLabel'];
                    $this->Template->cloneTitle = $GLOBALS['TL_LANG']['MSC']['caledit_cloneTitle'];
                }
                if ($this->caledit_showDeleteLink) {
                    $this->Template->deleteRef = $strUrl.'?delete='.$objEvent->id;
                    $this->Template->deleteLabel = $GLOBALS['TL_LANG']['MSC']['caledit_deleteLabel'];
                    $this->Template->deleteTitle = $GLOBALS['TL_LANG']['MSC']['caledit_deleteTitle'];
                }

            } else {
                if (!$isUserAuthorized) {
                    $this->Template->error_class = 'error';
                    $this->Template->error = $GLOBALS['TL_LANG']['MSC']['caledit_UnauthorizedUser'];
                    return;
                }

                if ($objEvent->disable_editing) {
                    // the event is locked in the backend
                    $this->Template->error = $GLOBALS['TL_LANG']['MSC']['caledit_DisabledEvent'];
                    $this->Template->error_class = 'error';
                } else {
                    if (!$authorizedElapsedEvents) {
                        // the user is authorized, but the event has elapsed
                        $this->Template->error = $GLOBALS['TL_LANG']['MSC']['caledit_NoPast'];
                    } else {
                        // the user is NOT authorized at all (reason: only the creator can edit it)
                        $this->Template->error = $GLOBALS['TL_LANG']['MSC']['caledit_OnlyUser'];
                    }
                    $this->Template->error_class = 'error';
                }
            }
        } else {
            $this->Template->error_class = 'error';
            $this->Template->error = $GLOBALS['TL_LANG']['MSC']['caledit_NoEditAllowed'];
        }
    }
}

?>
