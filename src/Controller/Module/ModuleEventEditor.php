<?php

namespace Diversworld\CalendarEditorBundle\Controller\Module;

use Contao\BackendTemplate;
use Contao\CalendarEventsModel;
use Contao\CalendarModel;
use Contao\Config;
use Contao\ContentModel;
use Contao\CoreBundle\Controller\FrontendModule\AbstractFrontendModuleController;
use Contao\CoreBundle\Twig\FragmentTemplate;
use Contao\CoreBundle\DependencyInjection\Attribute\AsFrontendModule;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Routing\ScopeMatcher;
use Contao\Date;
use Contao\Email;
use Contao\FormCaptcha;
use Contao\FormCheckbox;
use Contao\FormRadio;
use Contao\FormSelect;
use Contao\FormText;
use Contao\FormTextarea;
use Contao\FrontendTemplate;
use Contao\FrontendUser;
use Contao\ModuleModel;
use Contao\PageModel;
use Contao\StringUtil;
use Contao\System;
use Diversworld\CalendarEditorBundle\Models\CalendarModelEdit;
use Diversworld\CalendarEditorBundle\Services\CheckAuthService;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

#[AsFrontendModule('EventEditor', category: 'calendar', template: 'frontend_module/event_edit_default')]
class ModuleEventEditor extends AbstractFrontendModuleController
{
    /**
     * @var array
     */
    protected $cal_calendar;

    /**
     * @var FrontendUser|null
     */
    protected $User;

    /**
     * @var FragmentTemplate
     */
    protected $Template;

    /**
     * @var string
     */
    protected $rteFields;

    /**
     * @var string
     */
    protected $language;

    /**
     * @var int|string
     */
    protected $id;

    /**
     * @var string
     */
    protected $headline;

    /**
     * @var string
     */
    protected $name;

    /**
     * @var string
     */
    protected $type;

    /**
     * @var string
     */
    protected $customTpl;
    protected $caledit_sendMail;

    /**
     * @var bool
     */
    protected $caledit_allowPublish;

    /**
     * @var string
     */
    protected $caledit_mailRecipient;

    /**
     * @var string
     */
    protected $caledit_mailSubject;

    /**
     * @var string
     */
    protected $caledit_mailTemplate;

    /**
     * @var string|array
     */
    protected $caledit_mandatoryfields;

    /**
     * @var int
     */
    protected $caledit_add_jumpTo;

    /**
     * @var string
     */
    protected $caledit_template;

    /**
     * @var string
     */
    protected $caledit_clone_template;

    /**
     * @var string
     */
    protected $caledit_delete_template;

    /**
     * @var string
     */
    protected $caledit_tinMCEtemplate;

    /**
     * @var string
     */
    protected $caledit_alternateCSSLabel;

    /**
     * @var bool
     */
    protected $caledit_usePredefinedCss;

    /**
     * @var string|array
     */
    protected $caledit_cssValues;

    /**
     * @var bool
     */
    protected $caledit_showDeleteLink;

    /**
     * @var bool
     */
    protected $caledit_showCloneLink;

    /**
     * @var bool
     */
    protected $caledit_useDatePicker;

    /**
     * @var string
     */
    protected $caledit_dateDirection;

    /**
     * @var string
     */
    protected $caledit_dateIncludeCSSTheme;

    /**
     * @var bool
     */
    protected $caledit_dateImage;

    /**
     * @var string
     */
    protected $caledit_dateImageSRC;

    /**
     * @var bool
     */
    protected $caledit_allowDelete;

    /**
     * @var bool
     */
    protected $caledit_allowClone;

    /**
     * @var int|string
     */
    protected $jumpTo;

    /**
     * @var string
     */
    protected $cal_template;

    /**
     * @var string|array
     */
    protected $cal_holidayCalendar;

    /**
     * @var \Contao\CoreBundle\Security\Authentication\Token\TokenChecker|null
     */
    protected $tokenChecker;

    /**
     * @var CheckAuthService|null
     */
    protected $checkAuthService;

    /**
     * @var ModuleModel|null
     */
    protected $model;

    /**
     * @var array
     */
    protected $allowedCalendars;

    /**
     * @var string
     */
    protected $errorString = '';

    public function __construct(
        private CheckAuthService|ModuleModel|null $calEditCheckAuthService = null,
        private ContaoFramework|string|null       $framework = null,
        private ?Security                         $security = null,
        private ?ScopeMatcher                     $scopeMatcher = null,
        private ?RequestStack                     $requestStack = null,
        private ?Connection                       $connection = null,
        ModuleModel|null                          $model = null,
    )
    {
        if ($this->calEditCheckAuthService instanceof ModuleModel) {
            $model = $this->checkAuthService = $this->calEditCheckAuthService;
            $this->calEditCheckAuthService = null;
        }

        if (is_string($this->framework)) {
            $this->framework = null;
        }

        if ($model !== null) {
            // Do not call parent::__construct($model) if $model is NOT a ModuleModel
            // although it is typed, Contao might pass something else if we are not careful
            // but here we check for instanceof ModuleModel above.

            // AbstractFrontendModuleController does not have a constructor that takes arguments.
            // It has a $model property.
            $this->model = $model;
        }

        $this->initializeServices();
    }

    protected function getResponse(FragmentTemplate $template, ModuleModel $model, Request $request): Response
    {
        $this->initializeServices();
        $this->model = $model;

        // Map model properties to $this for internal method access
        $this->id = $model->id;
        $this->name = $model->name;
        $this->headline = $model->headline;
        $this->type = $model->type;
        $this->cal_calendar = $model->cal_calendar;
        $this->customTpl = $model->customTpl;
        $this->caledit_sendMail = $model->caledit_sendMail;
        $this->caledit_allowPublish = $model->caledit_allowPublish;
        $this->caledit_mailRecipient = $model->caledit_mailRecipient;
        $this->caledit_mailSubject = $model->caledit_mailSubject;
        $this->caledit_mailTemplate = $model->caledit_mailTemplate;
        $this->caledit_usePredefinedCss = $model->caledit_usePredefinedCss;
        $this->caledit_cssValues = $model->caledit_cssValues;
        $this->caledit_alternateCSSLabel = $model->caledit_alternateCSSLabel;
        $this->caledit_template = $model->caledit_template;
        $this->caledit_delete_template = $model->caledit_delete_template;
        $this->caledit_clone_template = $model->caledit_clone_template;
        $this->caledit_allowDelete = $model->caledit_allowDelete;
        $this->caledit_allowClone = $model->caledit_allowClone;
        $this->caledit_useDatePicker = $model->caledit_useDatePicker;
        $this->caledit_mandatoryfields = $model->caledit_mandatoryfields;
        $this->caledit_dateIncludeCSSTheme = $model->caledit_dateIncludeCSSTheme;
        $this->caledit_dateDirection = $model->caledit_dateDirection;
        $this->caledit_dateImage = $model->caledit_dateImage;
        $this->caledit_dateImageSRC = $model->caledit_dateImageSRC;
        $this->caledit_add_jumpTo = $model->caledit_add_jumpTo;
        $this->caledit_tinMCEtemplate = $model->caledit_tinMCEtemplate;
        $this->caledit_showDeleteLink = $model->caledit_showDeleteLink;
        $this->caledit_showCloneLink = $model->caledit_showCloneLink;
        $this->cal_template = $model->cal_template;
        $this->cal_holidayCalendar = $model->cal_holidayCalendar;
        $this->jumpTo = $model->jumpTo;

        $this->Template = $template;

        $cssID = StringUtil::deserialize($model->cssID, true);
        $this->Template->class = trim('mod_' . $model->type . ' ' . ($model->class ?: '') . ' ' . ($cssID[1] ?? ''));
        $this->Template->cssID = $cssID[0] ?? '';
        $this->Template->type = $model->type;

        // Ensure variables from getResponse argument $template are copied to $this->Template if it was changed
        if ($this->Template !== $template) {
            $this->Template->headline = $template->headline;
            $this->Template->hl = $template->hl;
            $this->Template->InfoMessage = $template->InfoMessage;
            $this->Template->FatalError = $template->FatalError;
            $this->Template->CurrentEventLink = $template->CurrentEventLink;
            $this->Template->CurrentTitle = $template->CurrentTitle;
            $this->Template->CurrentDate = $template->CurrentDate;
            $this->Template->CurrentPublishedInfo = $template->CurrentPublishedInfo;
        }

        // Map headline and hl to template
        $headline = StringUtil::deserialize($model->headline);
        $headlineText = is_array($headline) ? $headline['value'] : $model->headline;
        $this->Template->headline = [
            'text' => $headlineText,
            'unit' => $model->hl ?: 'h1'
        ];
        $this->Template->hl = $model->hl ?: 'h1';
        $this->Template->InfoMessage = '';
        $this->Template->FatalError = '';
        $this->Template->classList = '';
        $this->Template->ContentWarning = '';
        $this->Template->ImageWarning = '';
        $this->Template->action = $request->getUri();
        $this->Template->messages = '';
        $this->Template->submit = $GLOBALS['TL_LANG']['MSC']['caledit_saveData'] ?? 'Submit';
        $this->Template->requestToken = System::getContainer()->get('contao.csrf.token_manager')->getDefaultTokenValue();

        // Deserialisieren und Kalender filtern
        $this->cal_calendar = $this->sortOutProtected(StringUtil::deserialize($this->cal_calendar, true));
        $this->cal_calendar = array_map('\intval', $this->cal_calendar);

        if (!is_array($this->cal_calendar) || count($this->cal_calendar) < 1) {
            return new Response('');
        }

        $this->allowedCalendars = $this->getCalendars($this->User);

        if ($request->query->has('edit') || $request->request->has('edit')) {
            $editID = $request->query->get('edit') ?: $request->request->get('edit');
            /** @var CalendarEventsModel $adapter */
            $adapter = $this->framework->getAdapter(CalendarEventsModel::class);
            $currentEventObject = $adapter->findByPk($editID);
            $response = $this->handleEdit($editID, $currentEventObject);
        } elseif ($request->query->has('delete') || $request->request->has('delete')) {
            $deleteID = $request->query->get('delete') ?: $request->request->get('delete');
            /** @var CalendarEventsModel $adapter */
            $adapter = $this->framework->getAdapter(CalendarEventsModel::class);
            $currentEventObject = $adapter->findByPk($deleteID);
            $response = $this->handleDelete($currentEventObject);
        } elseif ($request->query->has('clone') || $request->request->has('clone')) {
            $cloneID = $request->query->get('clone') ?: $request->request->get('clone');
            /** @var CalendarEventsModel $adapter */
            $adapter = $this->framework->getAdapter(CalendarEventsModel::class);
            $currentEventObject = $adapter->findByPk($cloneID);
            $response = $this->handleClone($currentEventObject);
        } else {
            // Standardmäßig handleEdit ohne ID (für Neuanlage)
            $response = $this->handleEdit('', null);
        }

        if ($response instanceof Response) {
            return $response;
        }

        return $this->Template->getResponse();
    }

    protected function initializeServices(): void
    {
        if ($this->calEditCheckAuthService instanceof CheckAuthService && $this->framework instanceof ContaoFramework && $this->User instanceof FrontendUser && $this->tokenChecker !== null && isset($this->container)) {
            return;
        }

        $container = System::getContainer();

        if (!isset($this->container)) {
            $this->setContainer($container);
        }

        $this->calEditCheckAuthService = $container->get('caledit.service.auth');
        $this->framework = $container->get('contao.framework');
        $this->security = $container->get('security.helper');
        $this->scopeMatcher = $container->get('contao.routing.scope_matcher');
        $this->requestStack = $container->get('request_stack');
        $this->connection = $container->get('database_connection');
        $this->tokenChecker = $container->get('contao.security.token_checker');

        $user = $this->security->getUser();
        if ($user instanceof FrontendUser) {
            $this->User = $user;
        }
    }

    /**
     * Sort out protected calendars
     *
     * @param array $arrCalendars
     *
     * @return array
     */
    protected function sortOutProtected(array $arrCalendars): array
    {
        if (empty($arrCalendars)) {
            return [];
        }

        $this->initializeServices();
        $arrResult = [];

        /** @var CalendarModelEdit $calendarAdapter */
        $calendarAdapter = $this->framework->getAdapter(CalendarModelEdit::class);
        $objCalendars = $calendarAdapter->findByIds($arrCalendars);

        if ($objCalendars === null) {
            return [];
        }

        foreach ($objCalendars as $objCalendar) {
            if (!$objCalendar->protected || ($this->User !== null && count(array_intersect(StringUtil::deserialize($objCalendar->groups, true), $this->User->groups)) > 0)) {
                $arrResult[] = $objCalendar->id;
            }
        }

        return $arrResult;
    }

    /**
     * generate Module
     */
    public function generate(?ModuleModel $model = null): string
    {
        $this->initializeServices();

        if ($model === null) {
            $model = $this->model;
        }

        if ($model === null) {
            return '';
        }

        $request = $this->requestStack->getCurrentRequest();

        if ($request === null) {
            return '';
        }

        $template = $model->customTpl ?: ($model->caledit_template ?: 'frontend_module/event_edit_default');

        // Ensure prefix for bundle templates if missing
        if ($template && !str_contains($template, '/') && (str_starts_with($template, 'event_edit_') || str_starts_with($template, 'mail_event_'))) {
            $template = 'frontend_module/' . $template;
        }

        // Set fragment options to avoid automatic type derivation from class name
        $this->setFragmentOptions([
            'type' => 'EventEditor',
            'template' => $template
        ]);

        return $this->__invoke($request, $model, 'main')->getContent();
    }

    public function addTinyMCE($configuration): void
    {
        if (!empty($configuration)) {
            $this->rteFields = 'ctrl_details,ctrl_teaser,teaser';
            // Fallback to English if the user language is not supported
            $this->language = 'en';

            $rootDir = System::getContainer()->getParameter('kernel.project_dir');

            $file = sprintf('%s/vendor/diversworld/contao-calendar-editor/contao/tinyMCE/%s.php', $rootDir, $configuration);
            if (file_exists($rootDir . '/assets/tinymce4/js/langs/' . $GLOBALS['TL_LANGUAGE'] . '.js')) {
                $this->language = $GLOBALS['TL_LANGUAGE'];
            }

            if (!file_exists($file)) {
                echo(sprintf('Cannot find rich text editor configuration file "%s"', $file));
            } else {
                ob_start();
                include($file);
                $GLOBALS['TL_HEAD'][] = ob_get_contents();
                ob_end_clean();
            }
        }
    }
    /**
     * Get the calendars the user is allowed to edit
     * These calendars will appear in the selection-field in the edit-form (if there is not only one)
     */
    public function getCalendars($user): array
    {
        $this->initializeServices();
        // get all the calendars supported by this module
        $calendarModels = CalendarModelEdit::findByIds($this->cal_calendar);
        // Check these calendars, whether the current user is allowed to edit them
        $calendars = [];

        if (null === $calendarModels) {
            // return the empty array
            return $calendars;
        } else {
            // fill the Allowed-Calendars-Array with proper calendars
            foreach ($calendarModels as $calendarModel) {
                if ($this->calEditCheckAuthService->isUserAuthorized($calendarModel, $user)) {
                    $calendars[] = $calendarModel;
                }
            }
        }
        return $calendars;
    }
    /**
     * Check user rights for editing on different stages of the formular
     * The first step is always to get an Calendar-object frome the array of calendars by the
     * current events Pid (= the ID of the calendar)
     **/
    public function getCalendarObjectFromPID($pid) : ?CalendarModel
    {
        foreach ($this->allowedCalendars as $objCalendar) {
            if ($objCalendar->id == $pid) {
                return $objCalendar;
            }
        }
        return NULL;
    }

    public function UserIsToAddCalendar($user, $pid) : bool
    {
        $objCalendar = $this->getCalendarObjectFromPID($pid);

        if (NULL === $objCalendar) {
            return false;
        } else {
            return $this->calEditCheckAuthService->isUserAuthorized($objCalendar, $user);
        }
    }

    public function checkValidDate($calendarID, $objStart, $objEnd) : bool
    {
        $objCalendar = $this->getCalendarObjectFromPID($calendarID);
        if (NULL === $objCalendar) {
            return false;
        }
        $startVal = $objStart->__get('value');
        $tmpStartDate = is_numeric($startVal) ? (int)$startVal : strtotime($startVal);
        $endVal = $objEnd->__get('value');
        $tmpEndDate = is_numeric($endVal) ? (int)$endVal : strtotime($endVal);
        if ($tmpEndDate === false) $tmpEndDate = null;

        if ((!$objCalendar->caledit_onlyFuture) || $this->calEditCheckAuthService->isUserAdmin($objCalendar, $this->User)) {
            // elapsed events can be edited, or user is an admin
            return true;
        } else {
            // editing elapsed events is denied and user is not an admin
            //$isValid = ($newDate >= time());
            $isValid = $this->calEditCheckAuthService->isDateNotElapsed($tmpStartDate, $tmpEndDate);
            if (!$isValid) {
                if (!$tmpEndDate && ($this->calEditCheckAuthService->getMidnightTime() > $tmpStartDate)) {
                    $objStart->addError($GLOBALS['TL_LANG']['MSC']['caledit_formErrorElapsedDate']);
                }
                if ($tmpEndDate && ($this->calEditCheckAuthService->getMidnightTime() > $tmpEndDate)) {
                    $objEnd->addError($GLOBALS['TL_LANG']['MSC']['caledit_formErrorElapsedDate']);
                }
            }
            return $isValid;
        }
    }

    public function allDatesAllowed($calendarID) : bool
    {
        $objCalendar = $this->getCalendarObjectFromPID($calendarID);
        if (NULL === $objCalendar) {
            return false;
        }

        if ((!$objCalendar->caledit_onlyFuture) || ($this->calEditCheckAuthService->isUserAdmin($objCalendar, $this->User))) {
            // elapsed events can be edited, or user is an admin
            return true;
        } else {
            return false;
        }
    }

    /**
     * check, whether the user is allowed to edit the specified Event
     * This is called when the user has general access to at least one calendar
     * But: We need to check whether he is allowed to edit this special event
     *       - is he in the group/admingroup in the event's calendar?
     *       - is he the owner of the event or !caledit_onlyUser
     * used in the compile-method at the beginning
     */
    public function checkUserEditRights($user, $eventID, $currentObjectData): bool
    {
        $this->initializeServices();

        // if no event is specified: ok, FE user can add new events :D
        if (!$eventID) {
            return true;
        }

        $objCalendar = $this->getCalendarObjectFromPID($currentObjectData->pid);

        if (NULL === $objCalendar) {
            $this->errorString = $GLOBALS['TL_LANG']['MSC']['caledit_unexpected'] . $currentObjectData->pid;
            return false; // Event not found or something else is wrong
        }

        if ($objCalendar->AllowEdit !== '1') {
            $this->errorString = $GLOBALS['TL_LANG']['MSC']['caledit_NoEditAllowed'] . '(checkUserEditRights)';
            return false;
        }

        // check calendar settings
        if ($this->calEditCheckAuthService->isUserAuthorized($objCalendar, $user)) {
            // if the editing is disabled in the BE: Deny editing in the FE
            if ($currentObjectData->disable_editing) {
                $this->errorString = $GLOBALS['TL_LANG']['MSC']['caledit_DisabledEvent'];
                return false;
            }

            $userIsAdmin = $this->calEditCheckAuthService->isUserAdmin($objCalendar, $user);
            //if (!$userIsAdmin && ($CurrentObjectData->startTime <= time()) && ($objCalendar->caledit_onlyFuture)){
            if (!$userIsAdmin
                && (!$this->calEditCheckAuthService->isDateNotElapsed($currentObjectData->startTime, $currentObjectData->endTime))
                //($CurrentObjectData->startTime <= time())
                && ($objCalendar->caledit_onlyFuture)) {
                $this->errorString = $GLOBALS['TL_LANG']['MSC']['caledit_NoPast'];
                return false;
            }
            $hasFrontendUser =  $this->tokenChecker->hasFrontendUser();

            $result = ((!$objCalendar->caledit_onlyUser) || (($hasFrontendUser) && ($userIsAdmin || ($user->id == $currentObjectData->fe_user))));
            if (!$result) {
                $this->errorString = $GLOBALS['TL_LANG']['MSC']['caledit_OnlyUser'];
            }

            return $result;
        } else {
            $this->errorString = $GLOBALS['TL_LANG']['MSC']['caledit_UnauthorizedUser'];
            return false; // user is not allowed to edit events here
        }
    }

    public function generateRedirect(string $userSetting, ?int $DBid): Response
    {
        $this->initializeServices();
        $currentRequest = $this->requestStack->getCurrentRequest();

        // Abrufen der aktuellen URL ohne Query-Parameter
        $currentUrl = $currentRequest->getUri();
        $jumpTo = preg_replace('/\?.*$/i', '', $currentUrl);

        switch ($userSetting) {
            case "":
                if ($this->jumpTo) {
                    $pageModel = PageModel::findByPk($this->jumpTo);
                    if ($pageModel !== null) {
                        $urlGenerator = System::getContainer()->get('contao.routing.content_url_generator');
                        $jumpTo = $urlGenerator->generate($pageModel);
                    }
                }
                break;

            case "new":
                $jumpTo .= '?new=true';
                break;

            case "view":
                $currentEventObject = CalendarEventsModel::findByIdOrAlias($DBid);
                if ($currentEventObject !== null) {
                    if ($currentEventObject->published) {
                        // URL-Generator verwenden
                        $urlGenerator = System::getContainer()->get('contao.routing.content_url_generator');
                        $jumpTo = $urlGenerator->generate($currentEventObject);
                    } else {
                        $jumpTo = '?edit=' . $DBid; // Event nicht veröffentlicht, Bearbeitungslink
                    }
                } else {
                    throw new RuntimeException("Event with ID $DBid not found.");
                }
                break;

            case "edit":
                $jumpTo .= '?edit=' . $DBid;
                break;

            case "clone":
                $jumpTo .= '?clone=' . $DBid;
                break;

            default:
                throw new InvalidArgumentException("Unknown userSetting: $userSetting");
        }

        // Redirect ausführen
        return $this->redirect($jumpTo, 303);
    }

    public function getContentElements($eventID, &$contentID, &$contentData): void
    {
        // get Content Elements
        $objElement = ContentModel::findPublishedByPidAndTable($eventID, 'tl_calendar_events');

        // analyse content elements:
        // we will use the first element of type "text", discard the others (but set a warning in the template)
        $this->Template->ContentWarning = '';
        $this->Template->ImageWarning = '';
        if ($objElement !== null) {
            $ContentCount = 0;
            $TextFound = false;
            while ($objElement->next()) {
                $ContentCount++;
                if (($objElement->type == 'text') and (!$TextFound)) {
                    $contentData['text'] = $objElement->text;
                    $contentID = $objElement->id;
                    $TextFound = true;
                    if ($objElement->addImage) {
                        // we cannot modify "add image" with this module.
                        // note: A "headline" will be deleted without warning.
                        $this->Template->ImageWarning = $GLOBALS['TL_LANG']['MSC']['caledit_ContentElementWithImage'];
                    }
                }
            }
            if ($ContentCount > 1) {
                $this->Template->ContentWarning = $GLOBALS['TL_LANG']['MSC']['caledit_MultipleContentElements'];
            }
        }
    }

    public function getEventInformation($currentEventObject, &$newEventData): void
    {
        $this->initializeServices();

        // Map essential template variables from $currentEventObject
        $this->Template->CurrentTitle = $currentEventObject->title;
        $this->Template->CurrentDate = Date::parse(Config::get('dateFormat'), $currentEventObject->startDate);
        $this->Template->CurrentPublished = $currentEventObject->published;

        if ($currentEventObject->published) {
            $this->Template->CurrentEventLink = '';
            $this->Template->CurrentPublishedInfo = $GLOBALS['TL_LANG']['MSC']['caledit_publishedEvent'];
        } else {
            $this->Template->CurrentEventLink = '';
            $this->Template->CurrentPublishedInfo = $GLOBALS['TL_LANG']['MSC']['caledit_unpublishedEvent'];
        }

        // Fill fields with data from $currentEventObject
        $newEventData['startDate'] = Date::parse(Config::get('dateFormat'), $currentEventObject->startDate);
        $newEventData['endDate'] = Date::parse(Config::get('dateFormat'), $currentEventObject->endDate);

        if ($currentEventObject->addTime) {
            $newEventData['startTime'] = $currentEventObject->startTime;
            $newEventData['endTime'] = $currentEventObject->endTime;
            if ($newEventData['startTime'] == $newEventData['endTime']) {
                $newEventData['endTime'] = '';
            }
        } else {
            $newEventData['startTime'] = '';
            $newEventData['endTime'] = '';
        }

        $newEventData['title'] = $currentEventObject->title;
        $newEventData['teaser'] = $currentEventObject->teaser;
        $newEventData['location'] = $currentEventObject->location;
        $newEventData['cssClass'] = $currentEventObject->cssClass;
        $newEventData['pid'] = $currentEventObject->pid;
        $newEventData['published'] = $currentEventObject->published;
        $newEventData['alias'] = $currentEventObject->alias;

        $this->Template->CurrentTitle = $currentEventObject->title;
        $this->Template->CurrentDate = Date::parse(Config::get('dateFormat'), $currentEventObject->startDate);

        $this->Template->CurrentPublished = $currentEventObject->published;
    }

    public function addDatePicker(&$field): void
    {
        $field['inputType'] = 'calendarfield';
        if (strlen($this->caledit_dateIncludeCSSTheme) > 0) {
            $field['eval']['dateIncludeCSS'] = '1';
            $field['eval']['dateIncludeCSSTheme'] = $this->caledit_dateIncludeCSSTheme;
        } else {
            $field['eval']['dateIncludeCSS'] = '0';
            $field['eval']['dateIncludeCSSTheme'] = '';
        }
        $field['eval']['dateDirection'] = $this->caledit_dateDirection;
        if ($this->caledit_dateImage) {
            $field['eval']['dateImage'] = '1';
        }
        if ($this->caledit_dateImageSRC) {
            $field['eval']['dateImageSRC'] = $this->caledit_dateImageSRC;
        }
    }

    public function aliasExists(string $suggestedAlias): bool
    {
        $this->initializeServices();
        $query = $this->connection->createQueryBuilder()
            ->select('id')
            ->from('tl_calendar_events')
            ->where('alias = :alias')
            ->setParameter('alias', $suggestedAlias);

        $result = $query->executeQuery();

        return $result->rowCount() > 0;
    }

    public function generateAlias($value): string
    {
        // Maximum length of alias in the DB: 128 chars
        // We use only 110 chars here, as we may add "-<ID>" in case of a collision
        $value = substr(StringUtil::standardize($value), 0, 110);

        if ($this->aliasExists($value)) {
            // Alias already exists, we have to modify it.
            // 1st try: Add the ID of the event (which is currently not in the DB, therefore +1 at the end)
            $query = $this->connection->createQueryBuilder()
                ->select('MAX(id) AS id')
                ->from('tl_calendar_events');

            $result = $query->executeQuery()->fetchAssociative();

            $newID = ($result['id'] ?? 0) + 1;
            $value .= '-' . $newID;

            // If even this modified alias exists: use random alias, with ID as prefix
            while ($this->aliasExists($value)) {
                $randID = mt_rand();
                $value = $newID . '-' . $randID;
            }
        }

        return $value;
    }

    public function saveToDB($eventData, $oldId, array $contentData, $oldContentId) : int
    {
        $this->initializeServices();
        if ($oldId === '') {
            // create new alias
            $eventData['alias'] = $this->generateAlias($eventData['title']);
        }

        // important (otherwise details/teaser will be mixed up in calendars or event lists)
        $eventData['source'] = 'default';

        // needed later!
        $startDate = new Date($eventData['startDate'], Config::get('dateFormat'));
        $eventData['tstamp'] = time();

        // Dealing with empty enddates, Start/endtimes ...
        if (trim($eventData['endDate']) != '') {
            $endDateStr = $eventData['endDate'];
            $endDate = new Date($eventData['endDate'], Config::get('dateFormat'));
        } else {
            $endDateStr = $eventData['startDate'];
            $endDate = $startDate;
        }

        $startTimeStr = $eventData['startTime'];
        $endTimeStr = $eventData['endTime'];

        if (trim($startTimeStr) == '') {
            $eventData['addTime'] = '';
            $eventData['startTime'] = $startDate->tstamp;
            $eventData['endTime'] = $endDate->tstamp;
        } else {
            $eventData['addTime'] = '1';
            $startTime = new Date($eventData['startDate'] . ' ' . $startTimeStr, Config::get('dateFormat') . ' ' . Config::get('timeFormat'));
            $eventData['startTime'] = $startTime->tstamp;

            if (trim($endTimeStr) == '') {
                $endTimeStr = $startTimeStr;
            }

            $endTime = new Date($endDateStr . ' ' . $endTimeStr, Config::get('dateFormat') . ' ' . Config::get('timeFormat'));
            $eventData['endTime'] = $endTime->tstamp;
        }

        $eventData['startDate'] = $startDate->tstamp;
        $eventData['endDate'] = $endDate->tstamp;


        // Hier: Hooks mit $eventData aufrufen
        if (array_key_exists('prepareCalendarEditData', $GLOBALS['TL_HOOKS']) && is_array($GLOBALS['TL_HOOKS']['prepareCalendarEditData'])) {
            foreach ($GLOBALS['TL_HOOKS']['prepareCalendarEditData'] as $callback) {
                $this->import($callback[0]);
                $eventData = $this->{$callback[0]}->{$callback[1]}($eventData);
            }
        }

        if (empty($oldId)) {
            // Neuer Eintrag
            $this->connection->insert('tl_calendar_events', $eventData);
            $newCid = (int) $this->connection->lastInsertId();
            $contentData['pid'] = $newCid;
            $returnID = $newCid;
        } else {
            // Vorhandenen Eintrag aktualisieren
            $this->connection->update('tl_calendar_events', $eventData, ['id' => $oldId]);
            $contentData['pid'] = $oldId;
            $returnID = $oldId;
        }

        $contentData['ptable'] = 'tl_calendar_events';
        $contentData['type'] = 'text';
        // Setze die Überschrift im Content-Element auf ""
        $contentData['headline'] = serialize(['unit' => 'h1', 'value' => '']);

        if (isset($contentData['text'])) {
            // 'text' ist gesetzt, daher in die Datenbank schreiben
            if (empty($oldContentId)) {
                // Neuer Eintrag
                $contentData['tstamp'] = time();
                $this->connection->insert('tl_content', $contentData);
            } else {
                // Vorhandenen Eintrag aktualisieren
                $this->connection->update('tl_content', $contentData, ['id' => $oldContentId]);
            }
        } else {
            // 'text' ist leer, vorhandenes Content-Element löschen
            if (!empty($oldContentId)) {
                $this->connection->delete('tl_content', ['id' => $oldContentId]);
            }
        }

        // Kalender-Feed aktualisieren
        //$this->calendar->generateFeed($eventData['pid']);

        return $returnID;
    }

    protected function handleEdit($editID, $currentEventObject): ?Response
    {
        $this->initializeServices();

        // Input über den Symfony-DI-Container beziehen
        $currentRequest = $this->requestStack->getCurrentRequest();

        // Initialize all template variables to avoid Twig errors
        $this->Template->fields = [];
        $this->Template->deleteRef = '';
        $this->Template->deleteLabel = '';
        $this->Template->deleteTitle = '';
        $this->Template->cloneRef = '';
        $this->Template->cloneLabel = '';
        $this->Template->cloneTitle = '';
        $this->Template->editRef = '';
        $this->Template->editLabel = '';
        $this->Template->editTitle = '';
        $this->Template->CurrentEventLink = '';
        $this->Template->CurrentTitle = '';
        $this->Template->CurrentDate = '';
        $this->Template->CurrentPublishedInfo = '';
        $this->Template->InfoMessage = '';
        $this->Template->FatalError = '';
        $this->Template->classList = '';
        $this->Template->ContentWarning = '';
        $this->Template->ImageWarning = '';
        $this->Template->action = $currentRequest->getUri();
        $this->Template->messages = '';
        $this->Template->submit = $GLOBALS['TL_LANG']['MSC']['caledit_saveData'] ?? 'Submit';
        $headline = StringUtil::deserialize($this->model->headline);
        $headlineText = is_array($headline) ? $headline['value'] : $this->model->headline;
        $this->Template->headline = [
            'text' => $headlineText,
            'unit' => $this->model->hl ?: 'h1'
        ];
        $this->Template->hl = $this->model->hl ?: 'h1';
        $this->Template->requestToken = System::getContainer()->get('contao.csrf.token_manager')->getDefaultTokenValue();

        // 1. Get Data from post/get
        $newDate = $currentRequest->query->get('add');

        $newEventData = [];
        $NewContentData = [];
        $newEventData['startDate'] = $newDate;

        $published = $currentEventObject?->published;

        // Abrufen der aktuellen URL
        $currentUrl = $currentRequest->getUri();

        if ($editID) {
            // get a proper Content-Element
            $contentID = '';
            $this->getContentElements($editID, $contentID, $NewContentData);

            // get the rest of the event data
            $this->getEventInformation($currentEventObject, $newEventData);

            if ($this->caledit_allowDelete) {
                // add a "Delete this event"-Link
                $del = str_replace('?edit=', '?delete=', $currentUrl);
                $this->Template->deleteRef = $del;
                $this->Template->deleteLabel = $GLOBALS['TL_LANG']['MSC']['caledit_deleteLabel'];
                $this->Template->deleteTitle = $GLOBALS['TL_LANG']['MSC']['caledit_deleteTitle'];
            }

            if ($this->caledit_allowClone) {
                $cln = str_replace('?edit=', '?clone=', $currentUrl);
                $this->Template->cloneRef = $cln;
                $this->Template->cloneLabel = $GLOBALS['TL_LANG']['MSC']['caledit_cloneLabel'];
                $this->Template->cloneTitle = $GLOBALS['TL_LANG']['MSC']['caledit_cloneTitle'];
            }

            $this->Template->CurrentPublished = $published;

            if ($published && !$this->caledit_allowPublish) {
                // editing a published event with no publish-rights
                // will hide the event again
                $published = '';
            }
        } else {
            $this->Template->CurrentPublishedInfo = $GLOBALS['TL_LANG']['MSC']['caledit_newEvent'];
        }

        $saveAs = '0';
        $jumpToSelection = '';

        // after this: Overwrite it with the post data
        if ($currentRequest->request->get('FORM_SUBMIT') == 'caledit_submit') {

            $newEventData['startDate'] = $currentRequest->request->get('startDate');
            $newEventData['endDate'] = $currentRequest->request->get('endDate');
            $newEventData['startTime'] = $currentRequest->request->get('startTime');
            $newEventData['endTime'] = $currentRequest->request->get('endTime');
            $newEventData['title'] = $currentRequest->request->get('title');
            $newEventData['location'] = $currentRequest->request->get('location');
            $newEventData['teaser'] = $currentRequest->request->get('teaser', true);
            $NewContentData['text'] = $currentRequest->request->get('details', true);
            $newEventData['cssClass'] = $currentRequest->request->get('cssClass');
            $newEventData['pid'] = $currentRequest->request->get('pid');
            $newEventData['published'] = $currentRequest->request->get('published') ? 1 : 0;
            $saveAs = $currentRequest->request->get('saveAs') ?? 0;
            $jumpToSelection = $currentRequest->request->get('jumpToSelection');

            if ($published && !$this->caledit_allowPublish) {
                // this should never happen, except the FE user is manipulating
                // the POST-Data with some evil HackerToolz ;-)
                $fatalError = $GLOBALS['TL_LANG']['MSC']['caledit_NoPublishAllowed'] . ' (POST data invalid)';
                $this->Template->FatalError = $fatalError;
                return null;
            }

            if (empty($newEventData['pid'])) {
                // set default value
                $newEventData['pid'] = $this->allowedCalendars[0]->id;
            }

            if (!$this->UserIsToAddCalendar($this->User, $newEventData['pid'])) {
                // this should never happen, except the FE user is manipulating
                // the POST with some evil HackerToolz. ;-)
                $fatalError = $GLOBALS['TL_LANG']['MSC']['caledit_NoEditAllowed'] . ' (POST data invalid)';
                $this->Template->FatalError = $fatalError;
                return null;
            }
        }

        $mandfields = StringUtil::deserialize($this->caledit_mandatoryfields);
        $mandTeaser = (is_array($mandfields) && in_array('teaser', $mandfields));
        $mandLocation = (is_array($mandfields) && in_array('location', $mandfields));
        $mandDetails = (is_array($mandfields) && in_array('details', $mandfields));
        $mandStarttime = (is_array($mandfields) && in_array('starttime', $mandfields));
        $mandCss = (is_array($mandfields) && in_array('css', $mandfields));

        // fill template with fields ...
        $fields = [];

        if (!empty($newEventData['startTime']) && is_numeric($newEventData['startTime'])) {
            $objTime = new Date($newEventData['startTime']);
            $newEventData['startTime'] = $objTime->time; // Konvertiere Timestamp zu "HH:mm"
        }

        if (!empty($newEventData['endTime']) && is_numeric($newEventData['endTime'])) {
            $objTime = new Date($newEventData['endTime']);
            $newEventData['endTime'] = $objTime->time; // Konvertiere Timestamp zu "HH:mm"
        }

        if ($this->caledit_useDatePicker) {
            $fields['startDate'] = [
                'name' => 'startDate',
                'id'    => 'startDate',
                'label' => $GLOBALS['TL_LANG']['MSC']['caledit_startdate'],
                'inputType' => 'text',
                'value' => $newEventData['startDate'],
                'eval' => [
                    'rgxp' => 'date',
                    'mandatory' => true,
                    'decodeEntities' => true,
                    'datepicker' => true
                ]
            ];

            $fields['endDate'] = [
                'name' => 'endDate',
                'id'    => 'endDate',
                'label' => $GLOBALS['TL_LANG']['MSC']['caledit_enddate'],
                'inputType' => 'text',
                'value' => $newEventData['endDate'] ?? null,
                'eval' => [
                    'rgxp' => 'date',
                    'mandatory' => false,
                    'maxlength' => 128,
                    'decodeEntities' => true,
                    'datepicker' => true
                ]
            ];
        } else {
            $fields['startDate'] = [
                'name' => 'startDate',
                'id'    => 'startDate',
                'label' => $GLOBALS['TL_LANG']['MSC']['caledit_startdate'],
                'inputType' => 'text',
                'value' => $newEventData['startDate'],
                'eval' => [
                    'rgxp' => 'date',
                    'mandatory' => true,
                    'decodeEntities' => true
                ]
            ];

            $fields['endDate'] = [
                'name' => 'endDate',
                'id'    => 'endDate',
                'label' => $GLOBALS['TL_LANG']['MSC']['caledit_enddate'],
                'inputType' => 'text',
                'value' => $newEventData['endDate'] ?? null,
                'eval' => [
                    'rgxp' => 'date',
                    'mandatory' => false,
                    'maxlength' => 128,
                    'decodeEntities' => true
                ]
            ];
        }

        $fields['startTime'] = [
            'name' => 'startTime',
            'id'    => 'startTime',
            'label' => $GLOBALS['TL_LANG']['MSC']['caledit_starttime'],
            'inputType' => 'text',
            'value' => $newEventData['startTime'] ?? '',
            'eval' => [
                'rgxp' => 'time',
                'mandatory' => $mandStarttime,
                'maxlength' => 128,
                'decodeEntities' => true
            ]
        ];

        $fields['endTime'] = [
            'name' => 'endTime',
            'id'    => 'endTime',
            'label' => $GLOBALS['TL_LANG']['MSC']['caledit_endtime'],
            'inputType' => 'text',
            'value' => $newEventData['endTime'] ?? '',
            'eval' => [
                'rgxp' => 'time',
                'mandatory' => false,
                'maxlength' => 128,
                'decodeEntities' => true
            ]
        ];

        $fields['title'] = [
            'name' => 'title',
            'id'    => 'title',
            'label' => $GLOBALS['TL_LANG']['MSC']['caledit_title'],
            'inputType' => 'text',
            'value' => $newEventData['title'] ?? '',
            'eval' => [
                'mandatory' => true,
                'maxlength' => 255,
                'decodeEntities' => true
            ]
        ];

        $fields['location'] = [
            'name' => 'location',
            'id'    => 'location',
            'label' => $GLOBALS['TL_LANG']['MSC']['caledit_location'],
            'inputType' => 'text',
            'value' => $newEventData['location'] ?? '',
            'eval' => [
                'mandatory' => $mandLocation,
                'maxlength' => 255,
                'decodeEntities' => true
            ]
        ];

        $fields['teaser'] = [
            'name' => 'teaser',
            'id'    => 'teaser',
            'label' => $GLOBALS['TL_LANG']['MSC']['caledit_teaser'],
            'inputType' => 'textarea',
            'value' => $newEventData['teaser'] ?? '',
            'eval' => [
                'mandatory' => $mandTeaser,
                'rte' => 'tinyMCE',
                'allowHtml' => true
            ]
        ];

        $fields['details'] = [
            'name' => 'details',
            'id'    => 'details',
            'label' => $GLOBALS['TL_LANG']['MSC']['caledit_details'],
            'inputType' => 'textarea',
            'value' => $NewContentData['text'] ?? '',
            'eval' => [
                'mandatory' => $mandDetails,
                'rte' => 'tinyMCE',
                'allowHtml' => true
            ]
        ];

        if (count($this->allowedCalendars) > 1) {
            $pref = [];
            $popt = [];
            foreach ($this->allowedCalendars as $cal) {
                $popt[] = $cal->id;
                $pref[$cal->id] = $cal->title;
            }
            $fields['pid'] = [
                'name' => 'pid',
                'id'    => 'pid',
                'label' => $GLOBALS['TL_LANG']['MSC']['caledit_pid'],
                'inputType' => 'select',
                'options' => $popt,
                'value' => $newEventData['pid'] ?? ($this->allowedCalendars[0]->id),
                'reference' => $pref,
                'eval' => [
                    'mandatory' => true
                ]
            ];
        }

        $xx = $this->caledit_alternateCSSLabel;
        $cssLabel = (empty($xx)) ? $GLOBALS['TL_LANG']['MSC']['caledit_css'] : $this->caledit_alternateCSSLabel;

        if ($this->caledit_usePredefinedCss) {
            $cssValues = StringUtil::deserialize($this->caledit_cssValues);
            if (!is_array($cssValues)) {
                $cssValues = [];
            }

            $ref = [];
            $opt = [];
            foreach ($cssValues as $cssv) {
                if (isset($cssv['value'], $cssv['label'])) {
                    $opt[] = [
                        'value' => $cssv['value'],
                        'label' => $cssv['label']
                    ];
                    $ref[$cssv['value']] = $cssv['label'];
                }
            }

            $fields['cssClass'] = [
                'name'        => 'cssClass',
                'id'          => 'cssClass',
                'label'       => $cssLabel,
                'inputType'   => 'select',
                'options'     => $opt,
                'value' => $newEventData['cssClass'] ?? '',
                'reference'   => $ref,
                'eval'        => [
                    'mandatory'          => $mandCss,
                    'includeBlankOption' => true,
                    'maxlength'          => 128,
                    'decodeEntities'     => true
                ]
            ];
        } else {
            $fields['cssClass'] = [
                'name'      => 'cssClass',
                'id'        => 'cssClass',
                'label'     => $cssLabel,
                'inputType' => 'text',
                'value'     => $newEventData['cssClass'] ?? '',
                'eval'      => [
                    'mandatory'      => $mandCss,
                    'maxlength'      => 128,
                    'decodeEntities' => true
                ]
            ];
        }

        if ($this->caledit_allowPublish) {
            $fields['published'] = [
                'name' => 'published',
                'id'    => 'published',
                'label' => $GLOBALS['TL_LANG']['MSC']['caledit_published'],
                'inputType' => 'checkbox',
                'value' => $newEventData['published'] ?? '',
                'options' => [
                    ['value' => 1, 'label' => $GLOBALS['TL_LANG']['MSC']['caledit_published']]
                ],
                'eval' => ['mandatory' => false]
            ];
        }

        if ($editID) {
            $fields['saveAs'] = [
                'name' => 'saveAs',
                'id'    => 'saveAs',
                'label' => $GLOBALS['TL_LANG']['MSC']['caledit_saveAs'],
                'inputType' => 'checkbox',
                'value' => $saveAs,
                'options' => [['value' => 1, 'label' => $GLOBALS['TL_LANG']['MSC']['caledit_saveAs']]]
            ];
        }

        if (!$this->tokenChecker->hasFrontendUser()) {
            $fields['captcha'] = [
                'name' => 'captcha',
                'inputType' => 'captcha',
                'eval' => ['mandatory' => true, 'customTpl' => 'form_captcha_calendar-editor']
            ];
        }

        $fields['jumpToSelection'] = [
            'name' => 'jumpToSelection',
            'id'    => 'jumpToSelection',
            'label' => $GLOBALS['TL_LANG']['MSC']['caledit_JumpWhatsNext'],
            'inputType' => 'select',
            'options' => [
                ['value' => '', 'label' => '-'],
                ['value' => 'new', 'label' => $GLOBALS['TL_LANG']['MSC']['caledit_JumpToNew']],
                ['value' => 'view', 'label' => $GLOBALS['TL_LANG']['MSC']['caledit_JumpToView']],
                ['value' => 'edit', 'label' => $GLOBALS['TL_LANG']['MSC']['caledit_JumpToEdit']],
                ['value' => 'clone', 'label' => $GLOBALS['TL_LANG']['MSC']['caledit_JumpToClone']]
            ],
            'value' => '',
            'eval' => ['mandatory' => true, 'includeBlankOption' => true]
        ];

        if (array_key_exists('buildCalendarEditForm', $GLOBALS['TL_HOOKS']) && is_array($GLOBALS['TL_HOOKS']['buildCalendarEditForm'])) {
            foreach ($GLOBALS['TL_HOOKS']['buildCalendarEditForm'] as $callback) {
                $this->import($callback[0]);
                $arrResult = $this->{$callback[0]}->{$callback[1]}($newEventData, $fields, $currentEventObject, $editID);
                if (is_array($arrResult) && count($arrResult) > 1) {
                    $newEventData = $arrResult['NewEventData'];
                    $fields = $arrResult['fields'];
                }
            }
        }

        $arrWidgets = [];
        $doNotSubmit = false;
        foreach ($fields as $field) {
            $field['eval']['required'] = $field['eval']['mandatory'] ?? false;
            if ($currentRequest->request->get('FORM_SUBMIT') == 'caledit_submit') {
                $rgxp = $field['eval']['rgxp'] ?? '';
                if (($rgxp == 'date' || $rgxp == 'time' || $rgxp == 'datim') && $currentRequest->request->get($field['name']) != '') {
                    $objDate = new Date($currentRequest->request->get($field['name']), Config::get($rgxp . 'Format'));
                    $field['value'] = $objDate->tstamp;
                }
            }

            $objWidget = match ($field['inputType']) {
                'checkbox' => new FormCheckbox($field),
                'radio' => new FormRadio($field),
                'select' => new FormSelect($field),
                'text' => new FormText($field),
                'textarea' => new FormTextarea($field),
                'captcha' => new FormCaptcha($field),
                default => throw new \InvalidArgumentException("Ungültiger inputType: " . $field['inputType']),
            };

            if ($currentRequest->request->get('FORM_SUBMIT') == 'caledit_submit') {
                $objWidget->validate();
                if ($objWidget->hasErrors()) {
                    $doNotSubmit = true;
                }
            }
            $arrWidgets[$field['name']] = $objWidget;
        }

        $validDate = $this->checkValidDate($newEventData['pid'] ?? 0, $arrWidgets['startDate'], $arrWidgets['endDate']);
        if (!$validDate) {
            $doNotSubmit = true;
        }

        if ((!$doNotSubmit) && ($currentRequest->request->get('FORM_SUBMIT') == 'caledit_submit')) {
            $newEventData['fe_user'] = $this->tokenChecker->hasFrontendUser() ? $this->User->id : '';
            $newEventData['published'] = $newEventData['published'] ?? 0;
            $newEventData['location'] = $newEventData['location'] ?? '';

            if ($saveAs === 0) {
                $dbId = $this->saveToDB($newEventData, '', $NewContentData, '');
            } else {
                $dbId = $this->saveToDB($newEventData, $editID, $NewContentData, $contentID ?? '');
            }

            if ($this->caledit_sendMail) {
                $this->sendNotificationMail($newEventData, $saveAs ? '' : $editID, $this->User->username, '');
            }

            return $this->generateRedirect($jumpToSelection, (int)$dbId);
        } else {
            if ($currentRequest->request->get('FORM_SUBMIT') == 'caledit_submit') {
                $this->Template->InfoClass = 'tl_error';
                $this->Template->InfoMessage = $this->Template->InfoMessage ?: $GLOBALS['TL_LANG']['MSC']['caledit_error'];
            }
            $this->Template->fields = $arrWidgets;
        }

        return null;
    }

    protected function handleDelete($currentEventObject): ?Response
    {
        $this->initializeServices();

        $currentRequest = $this->requestStack->getCurrentRequest();

        $templateName = $this->caledit_delete_template ?: 'frontend_module/event_edit_delete';
        $this->Template->setName($templateName);

        // Initialize all template variables to avoid Twig errors
        $this->Template->fields = [];
        $this->Template->deleteRef = '';
        $this->Template->deleteLabel = '';
        $this->Template->deleteTitle = '';
        $this->Template->cloneRef = '';
        $this->Template->cloneLabel = '';
        $this->Template->cloneTitle = '';
        $this->Template->editRef = '';
        $this->Template->editLabel = '';
        $this->Template->editTitle = '';
        $this->Template->CurrentEventLink = '';
        $this->Template->CurrentTitle = '';
        $this->Template->CurrentDate = '';
        $this->Template->CurrentPublishedInfo = '';
        $this->Template->InfoMessage = '';
        $this->Template->FatalError = '';
        $this->Template->classList = '';
        $this->Template->ContentWarning = '';
        $this->Template->ImageWarning = '';
        $this->Template->action = $currentRequest->getUri();
        $this->Template->messages = '';
        $this->Template->submit = $GLOBALS['TL_LANG']['MSC']['caledit_deleteData'] ?? 'Delete';
        $headline = StringUtil::deserialize($this->model->headline);
        $headlineText = is_array($headline) ? $headline['value'] : $this->model->headline;
        $this->Template->headline = [
            'text' => $headlineText,
            'unit' => $this->model->hl ?: 'h1'
        ];
        $this->Template->hl = $this->model->hl ?: 'h1';
        $this->Template->requestToken = System::getContainer()->get('contao.csrf.token_manager')->getDefaultTokenValue();

        if (!$this->caledit_allowDelete) {
            $this->Template->FatalError = $GLOBALS['TL_LANG']['MSC']['caledit_NoDelete'];
            return null;
        }

        // Edit- und Clone-Links erstellen
        $currentUrl = $currentRequest->getUri();
        $this->Template->editRef = str_replace('?delete=', '?edit=', $currentUrl);
        $this->Template->editLabel = $GLOBALS['TL_LANG']['MSC']['caledit_editLabel'];
        $this->Template->editTitle = $GLOBALS['TL_LANG']['MSC']['caledit_editTitle'];

        if ($this->caledit_allowClone) {
            $this->Template->cloneRef = str_replace('?delete=', '?clone=', $currentUrl);
            $this->Template->cloneLabel = $GLOBALS['TL_LANG']['MSC']['caledit_cloneLabel'];
            $this->Template->cloneTitle = $GLOBALS['TL_LANG']['MSC']['caledit_cloneTitle'];
        }

        // Event-Daten in das Template übergeben
        $this->Template->CurrentPublishedInfo = $currentEventObject->published
            ? $GLOBALS['TL_LANG']['MSC']['caledit_publishedEvent']
            : $GLOBALS['TL_LANG']['MSC']['caledit_unpublishedEvent'];

        // Captcha-Feld initialisieren
        $captchaField = [
            'name' => 'captcha',
            'inputType' => 'captcha',
            'eval' => ['mandatory' => true, 'customTpl' => 'form_captcha_calendar-editor'],
        ];

        $arrWidgets = [];
        $doNotSubmit = false;

        $objWidget = new FormCaptcha($captchaField);
        if ($currentRequest->request->get('FORM_SUBMIT') === 'caledit_submit') {
            if ($objWidget->hasErrors()) {
                $doNotSubmit = true;
            }
        }

        $arrWidgets[$captchaField['name']] = $objWidget;

        // Template für Delete-Hinweise und -Buttons befüllen
        $this->Template->deleteHint     = $GLOBALS['TL_LANG']['MSC']['caledit_deleteHint'];
        $this->Template->submit         = $GLOBALS['TL_LANG']['MSC']['caledit_deleteData'];
        $this->Template->deleteWarning  = $GLOBALS['TL_LANG']['MSC']['caledit_deleteWarning'];

        // Löschvorgang
        if (!$doNotSubmit && $currentRequest->request->get('FORM_SUBMIT') === 'caledit_submit') {
            $oldEventData = [
                'startDate' => Date::parse(Config::get('dateFormat'), $currentEventObject->startDate),
                'title' => $currentEventObject->title,
                'published' => $currentEventObject->published,
            ];

            // Inhalte löschen
            $this->connection->createQueryBuilder()
                ->delete('tl_content')
                ->where('ptable = :ptable')
                ->andWhere('pid = :pid')
                ->setParameter('ptable', 'tl_calendar_events')
                ->setParameter('pid', $currentEventObject->id)
                ->executeStatement();

            // Event selbst löschen
            $this->connection->createQueryBuilder()
                ->delete('tl_calendar_events')
                ->where('id = :id')
                ->setParameter('id', $currentEventObject->id)
                ->executeStatement();

            // Benachrichtigungs-Mail senden
            if ($this->caledit_sendMail) {
                $this->sendNotificationMail($oldEventData, -1, $this->User->username, '');
            }

            return $this->generateRedirect('', 0); // Weiterleitung auf Standardseite
        } else {
            if ($currentRequest->request->get('FORM_SUBMIT') === 'caledit_submit') {
                $this->Template->InfoClass = 'tl_error';
                $this->Template->InfoMessage = $GLOBALS['TL_LANG']['MSC']['caledit_error'];
            }
        }

        $this->Template->fields = $arrWidgets;

        return null;
    }

    protected function handleClone($currentEventObject): ?Response
    {
        $this->initializeServices();
        $currentRequest = $this->requestStack->getCurrentRequest();

        $templateName = $this->caledit_clone_template ?: 'frontend_module/event_edit_duplicate';
        $this->Template->setName($templateName);

        // Initialize all template variables to avoid Twig errors
        $this->Template->fields = [];
        $this->Template->deleteRef = '';
        $this->Template->deleteLabel = '';
        $this->Template->deleteTitle = '';
        $this->Template->cloneRef = '';
        $this->Template->cloneLabel = '';
        $this->Template->cloneTitle = '';
        $this->Template->editRef = '';
        $this->Template->editLabel = '';
        $this->Template->editTitle = '';
        $this->Template->CurrentEventLink = '';
        $this->Template->CurrentTitle = '';
        $this->Template->CurrentDate = '';
        $this->Template->CurrentPublishedInfo = '';
        $this->Template->InfoMessage = '';
        $this->Template->FatalError = '';
        $this->Template->classList = '';
        $this->Template->ContentWarning = '';
        $this->Template->ImageWarning = '';
        $this->Template->action = $currentRequest->getUri();
        $this->Template->messages = '';
        $this->Template->submit = $GLOBALS['TL_LANG']['MSC']['caledit_saveData'] ?? 'Submit';
        $headline = StringUtil::deserialize($this->model->headline);
        $headlineText = is_array($headline) ? $headline['value'] : $this->model->headline;
        $this->Template->headline = [
            'text' => $headlineText,
            'unit' => $this->model->hl ?: 'h1'
        ];
        $this->Template->hl = $this->model->hl ?: 'h1';
        $this->Template->requestToken = System::getContainer()->get('contao.csrf.token_manager')->getDefaultTokenValue();

        $currentID = $currentEventObject->id;
        $currentEventData = array();
        $currentContentData = array();
        $contentID = '';

        // Abrufen der aktuellen URL
        $currentUrl = $currentRequest->getUri();


        // add a "Edit this event"-Linkpublished
        $del = str_replace('?clone=', '?edit=', $currentUrl);
        $this->Template->editRef = $del;
        $this->Template->editLabel = $GLOBALS['TL_LANG']['MSC']['caledit_editLabel'];
        $this->Template->editTitle = $GLOBALS['TL_LANG']['MSC']['caledit_editTitle'];

        if ($this->caledit_allowDelete) {
            // add a "Delete this event"-Link
            $del = str_replace('?clone=', '?delete=', $currentUrl);
            $this->Template->deleteRef = $del;
            $this->Template->deleteLabel = $GLOBALS['TL_LANG']['MSC']['caledit_deleteLabel'];
            $this->Template->deleteTitle = $GLOBALS['TL_LANG']['MSC']['caledit_deleteTitle'];
        }

        // get a proper Content-Element
        $this->getContentElements($currentID, $contentID, $currentContentData);
        // get all the data from the current event...
        $this->getEventInformation($currentEventObject, $currentEventData);

        $this->Template->CloneWarning = $GLOBALS['TL_LANG']['MSC']['caledit_CloneWarning'];

        // Event-Daten in das Template übergeben
        $this->Template->CurrentPublishedInfo = $currentEventObject->published
            ? $GLOBALS['TL_LANG']['MSC']['caledit_publishedEvent']
            : $GLOBALS['TL_LANG']['MSC']['caledit_unpublishedEvent'];

        // publishing information
        $published = $currentEventObject->published;
        $this->Template->CurrentPublished = $published;

        if ($published && !$this->caledit_allowPublish) {
            // cloning a published event without publish-rights will result in a lot of unpublished events
            $published = '';
        }

        // current event stored - prepare the formular
        $newDates = [];
        $fields = [];
        $jumpToSelection = '';

        if ($currentRequest->request->get('FORM_SUBMIT') == 'caledit_submit') {
            for ($i = 1; $i <= 10; $i++) {
                $newDates['start' . $i] = $currentRequest->request->get('start' . $i);
                $newDates['end' . $i] = $currentRequest->request->get('end' . $i);
            }
            $jumpToSelection = $currentRequest->request->get('jumpToSelection');
        } else {
            for ($i = 1; $i <= 10; $i++) {
                $newDates['start' . $i] = '';
                $newDates['end' . $i] = '';
            }
        }

        // create fields
        for ($i = 1; $i <= 10; $i++) {
            // start dates
            $fields['start' . $i] = array(
                'name' => 'start' . $i,
                'label' => $GLOBALS['TL_LANG']['MSC']['caledit_startdate'],
                'inputType' => 'text',
                'value' => $newDates['start' . $i],
                'eval' => array('rgxp' => 'date', 'mandatory' => false, 'maxlength' => 128, 'decodeEntities' => true)
            );
            // end dates
            $fields['end' . $i] = array(
                'name' => 'end' . $i,
                'label' => $GLOBALS['TL_LANG']['MSC']['caledit_enddate'],
                'inputType' => 'text',
                'value' => $newDates['end' . $i],
                'eval' => array('rgxp' => 'date', 'mandatory' => false, 'maxlength' => 128, 'decodeEntities' => true)
            );

            /*if ($this->caledit_useDatePicker) {
                $this->addDatePicker($fields['start' . $i]);
                $this->addDatePicker($fields['end' . $i]);
            }*/
        }

        $hasFrontendUser =  $this->tokenChecker->hasFrontendUser();

        if (!$hasFrontendUser) {
            $fields['captcha'] = [
                'name' => 'captcha',
                'inputType' => 'captcha',
                'eval' => ['mandatory' => true, 'customTpl' => 'form_captcha_calendar-editor']
            ];
        }

        // create jump-to-selection
        $fields['jumpToSelection'] = [
            'name' => 'jumpToSelection',
            'label' => $GLOBALS['TL_LANG']['MSC']['caledit_JumpWhatsNext'],
            'inputType' => 'select',
            // arrOptions wird direkt verwendet
            'options' => [
                ['value' => '', 'label' => '-'],
                ['value' => 'new', 'label' => $GLOBALS['TL_LANG']['MSC']['caledit_JumpToNew']],
                ['value' => 'view', 'label' => $GLOBALS['TL_LANG']['MSC']['caledit_JumpToView']],
                ['value' => 'edit', 'label' => $GLOBALS['TL_LANG']['MSC']['caledit_JumpToEdit']],
                ['value' => 'clone', 'label' => $GLOBALS['TL_LANG']['MSC']['caledit_JumpToClone']]
            ],
            'reference' => [
                ['value' => '', 'label' => '-'],
                ['value' => 'new', 'label' => $GLOBALS['TL_LANG']['MSC']['caledit_JumpToNew']],
                ['value' => 'view', 'label' => $GLOBALS['TL_LANG']['MSC']['caledit_JumpToView']],
                ['value' => 'edit', 'label' => $GLOBALS['TL_LANG']['MSC']['caledit_JumpToEdit']],
                ['value' => 'clone', 'label' => $GLOBALS['TL_LANG']['MSC']['caledit_JumpToClone']]
            ],
            'value' => '', // Vorausgewählter Wert
            'eval' => [
                'mandatory' => true,
                'includeBlankOption' => true,
            ]
        ];

        // here: CALL Hooks with $NewEventData, $currentEventObject, $fields
        if (array_key_exists('buildCalendarCloneForm', $GLOBALS['TL_HOOKS']) && is_array($GLOBALS['TL_HOOKS']['buildCalendarCloneForm'])) {
            foreach ($GLOBALS['TL_HOOKS']['buildCalendarCloneForm'] as $callback) {
                $this->import($callback[0]);
                $arrResult = $this->{$callback[0]}->{$callback[1]}($newDates, $fields, $currentEventObject, $currentID);
                if (is_array($arrResult) && count($arrResult) > 1) {
                    $newDates = $arrResult['newDates'];
                    $fields = $arrResult['fields'];
                }
            }
        }

        // Initialize widgets
        $arrWidgets = array();
        $doNotSubmit = false;
        foreach ($fields as $field) {
            $field['eval']['required'] = $field['eval']['mandatory'];

            // from http://pastebin.com/HcjkHLQK
            // via https://github.com/contao/core/issues/5086
            // Convert date formats into timestamps (check the eval setting first -> #3063)
            if ($currentRequest->request->get('FORM_SUBMIT') === 'caledit_submit') {
                $rgxp = $field['eval']['rgxp'] ?? '';
                if (($rgxp == 'date' || $rgxp == 'time' || $rgxp == 'datim') && $field['value'] != '') {
                    $objDate = new Date($currentRequest->request->get($field['name']), Config::get($rgxp . 'Format'));
                    $field['value'] = $objDate->tstamp;
                }
            }

            $objWidget = match ($field['inputType']) {
                'checkbox' => new FormCheckbox($field),
                'radio' => new FormRadio($field),
                'select' => new FormSelect($field),
                'text' => new FormText($field),
                'textarea' => new FormTextarea($field),
                default => throw new \InvalidArgumentException("Ungültiger inputType: " . $field['inputType']),
            };

            // Validate widget
            if ($currentRequest->request->get('FORM_SUBMIT') == 'caledit_submit') {
                $objWidget->validate();
                if ($objWidget->hasErrors()) {
                    $doNotSubmit = true;
                }
            }
            $arrWidgets[$field['name']] = $objWidget;
        }

        // Contao 4.4+: The CalendarFields need to be parsed to activate JS
        for ($i = 1; $i <= 10; $i++) {
            $arrWidgets['start' . $i]->parse();
            $arrWidgets['end' . $i]->parse();
        }

        $allDatesAllowed = $this->allDatesAllowed($currentEventData['pid']);
        for ($i = 1; $i <= 10; $i++) {
            // check the 10 startdates
            $val = $arrWidgets['start' . $i]->__get('value');
            $newDate = is_numeric($val) ? (int)$val : strtotime($val);

            if ((!$allDatesAllowed) and ($newDate) and ($newDate < time())) {
                $arrWidgets['start' . $i]->addError($GLOBALS['TL_LANG']['MSC']['caledit_formErrorElapsedDate']);
                $doNotSubmit = true;
            }
        }

        $this->Template->submit = $GLOBALS['TL_LANG']['MSC']['caledit_saveData'];

        $hasFrontendUser = $this->tokenChecker->hasFrontendUser();

        if ((!$doNotSubmit) && ($currentRequest->request->get('FORM_SUBMIT') == 'caledit_submit')) {
            // everything seems to be ok, so we can add the POST Data
            // into the Database
            if (!$hasFrontendUser) {
                $currentEventData['fe_user'] = ''; // no user
            } else {
                $currentEventData['fe_user'] = $this->User->id; // set the FE_user here
            }

            // for the notification E-Mail
            $originalStart = $currentEventData['startDate'];
            $originalEnd = $currentEventData['endDate'];
            $newDatesMail = '';

            // overwrite User
            if (!$hasFrontendUser) {
                $currentEventData['fe_user'] = ''; // no user
            } else {
                $currentEventData['fe_user'] = $this->User->id; // set the FE_user here
            }
            // Set Publish-Value
            $currentEventData['published'] = $published;
            if (is_null($currentEventData['published'])) {
                $currentEventData['published'] = '';
            }

            // convert the existing timestamps into Strings, so that PutinDB can use them again
            if ($currentEventData['startTime']) {
                $currentEventData['startTime'] = date(Config::get('timeFormat'), $currentEventData['startTime']);
            }
            if ($currentEventData['endTime']) {
                $currentEventData['endTime'] = date(Config::get('timeFormat'), $currentEventData['endTime']);
            }

            for ($i = 1; $i <= 10; $i++) {
                // Sicherstellen, dass 'published' immer einen gültigen Wert hat
                if (empty($currentEventData['published'])) {
                        $currentEventData['published'] = 0; // Standardwert: nicht veröffentlicht
                }

                if ($newDates['start' . $i]) {
                    $currentEventData['startDate'] = $newDates['start' . $i];
                    $currentEventData['endDate'] = $newDates['end' . $i];

                    $newDatesMail .= $currentEventData['startDate'];
                    if ($currentEventData['endDate']) {
                        $newDatesMail .= "-" . $currentEventData['endDate'] . " \n";
                    } else {
                        $newDatesMail .= " \n";
                    }
                    $DBid = $this->saveToDB($currentEventData, '', $currentContentData, '');
                }
            }

            // restore values
            $currentEventData['startDate'] = $originalStart;
            $currentEventData['endDate'] = $originalEnd;
            // Send Notification EMail
            if ($this->caledit_sendMail) {
                $this->sendNotificationMail($currentEventData, $currentID, $this->User->username, $newDatesMail);
            }

            // after this: jump to "jumpTo-Page"
            return $this->generateRedirect($jumpToSelection, (int)$DBid);
        } else {
            // Do NOT Submit
            if ($currentRequest->request->get('FORM_SUBMIT') == 'caledit_submit') {
                $this->Template->InfoClass = 'tl_error';
                $this->Template->InfoMessage = $GLOBALS['TL_LANG']['MSC']['caledit_error'];
            }
            $this->Template->fields = $arrWidgets;
        }

        return null;
    }

    protected function sendNotificationMail($NewEventData, $editID, $User, $cloneDates) : void
    {
        $this->initializeServices();
        $currentRequest = $this->requestStack->getCurrentRequest();

        $notification = new Email();
        $notification->from = $GLOBALS['TL_ADMIN_EMAIL'];
        $hasFrontendUser = $this->tokenChecker->hasFrontendUser();

        // Abrufen der aktuellen URL
        $host = $currentRequest->getHost();

        $mailSubject = $this->caledit_mailSubject;
        // Template-Name basierend auf Aktion bestimmen
        $templateName = $this->caledit_mailTemplate ?: 'mail_event_notification';

        if ($editID) {
            if ($editID == -1) {
                // Wenn ein Event gelöscht wird
                $templateName = 'frontend_module/mail_event_subject_delete';
                $notification->subject = sprintf($GLOBALS['TL_LANG']['MSC']['caledit_MailSubjectDelete'], $host);
            } else {
                // Wenn ein Event geändert wird
                $templateName = 'frontend_module/mail_event_subject_edit';
                $notification->subject = sprintf($GLOBALS['TL_LANG']['MSC']['caledit_MailSubjectEdit'], $host);
            }
        } else {
            // Wenn ein Event erstellt wird
            $notification->subject = $mailSubject;
        }

        // Template laden und rendern mit Contao-Template-System
        $templateContent = '';
        $renderData = [
            'host' => $host,
            'hasFrontendUser' => $hasFrontendUser,
            'user' => $User,
            'startDate' => $NewEventData['startDate'],
            'endDate' => $NewEventData['endDate'] ?? '',
            'startTime' => $NewEventData['startTime'] ?? '',
            'endTime' => $NewEventData['endTime'] ?? '',
            'title' => $NewEventData['title'] ?? 'x',
            'published' => $NewEventData['published'] ?? '0',
            'cloneDates' => $cloneDates,
            'allowPublish' => $this->caledit_allowPublish,
        ];

        try {
            $objTemplate = new FrontendTemplate($templateName);
            $objTemplate->setData($renderData);
            $templateContent = $objTemplate->parse();
        } catch (\Exception $e) {
            // Fallback für den Fall, dass frontend_module/ fehlt
            if (!str_starts_with($templateName, 'frontend_module/')) {
                try {
                    $objTemplate = new FrontendTemplate('frontend_module/' . $templateName);
                    $objTemplate->setData($renderData);
                    $templateContent = $objTemplate->parse();
                } catch (\Exception $e2) {
                    throw new RuntimeException('Could not find or render template ' . $templateName, 0, $e2);
                }
            } else {
                throw new RuntimeException('Could not find or render template ' . $templateName, 0, $e);
            }
        }

        $notification->text = $templateContent;

        // Empfänger aufteilen
        $arrRecipients = StringUtil::trimsplit(',', $this->caledit_mailRecipient);

        // Mail versenden
        foreach ($arrRecipients as $rec) {
            $notification->sendTo($rec);
        }
    }

    /**
     * Generate module
     */
    protected function compile() : void
    {
    }

/**
 * Import a class
 *
 * @param string $strClass
 */
protected
function import($strClass): void
{
    $this->$strClass = System::importStatic($strClass);
    }
}
