<?php

namespace Diversworld\CalendarEditorBundle\Controller\Module;

use Contao\ModuleModel;
use Contao\BackendTemplate;
use Contao\Date;
use Contao\FrontendUser;
use Contao\PageModel;
use Contao\StringUtil;
use Contao\System;
use Contao\Config;
use Diversworld\CalendarEditorBundle\Models\CalendarModelEdit;
use Diversworld\CalendarEditorBundle\Services\CheckAuthService;
use Contao\CoreBundle\Routing\ScopeMatcher;
use Symfony\Component\HttpFoundation\RequestStack;

use Contao\CoreBundle\Controller\FrontendModule\AbstractFrontendModuleController;
use Contao\CoreBundle\DependencyInjection\Attribute\AsFrontendModule;
use Contao\CoreBundle\Twig\FragmentTemplate;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Contao\CalendarModel;

#[AsFrontendModule('calendarEdit', category: 'calendar', template: 'mod_calendar')]
class ModuleCalendarEdit extends AbstractFrontendModuleController
{
    /**
     * @var ModuleModel|null
     */
    protected $model;

    /**
     * @var array|null
     */
    protected $cal_calendar;

    /**
     * @var int|string|null
     */
    protected $caledit_add_jumpTo;

    /**
     * @var array|null
     */
    protected $cal_holidayCalendar;

    /**
     * @var FrontendUser|null
     */
    protected $User;

    /**
     * @var int
     */
    protected $cal_startDay = 0;

    /**
     * @var string
     */
    protected $cal_format = 'calendar_month';

    /**
     * @var Date
     */
    protected $Date;

    /**
     * @var string|null
     */
    protected $cal_ctemplate;

    /**
     * @var bool
     */
    protected $cal_noSpan = false;

    // variable which indicates whether events can be added or not (on elapsed days)
    protected bool $allowElapsedEvents = false;
    protected bool $allowEditEvents = false;

    private ScopeMatcher $scopeMatcher; // Dependency Injection für ScopeMatcher
    private RequestStack $requestStack; // Dependency Injection für RequestStack
    private ?CheckAuthService $checkAuthService = null;

    public function __construct(ModuleModel|null $model = null, string $strColumn = 'main')
    {
        if ($model !== null) {
            $this->model = $model;
        }
        $this->initializeServices();
    }

    public function generate(): string
    {
        $this->initializeServices();
        $request = $this->requestStack->getCurrentRequest();

        if ($request === null) {
            return '';
        }

        if ($this->scopeMatcher->isBackendRequest($request)) {
            $objTemplate = new BackendTemplate('be_wildcard');

            $objTemplate->wildcard = '### CALENDAR WITH FE EDITING ###';
            $headline = StringUtil::deserialize($this->model->headline);
            $objTemplate->title = is_array($headline) ? $headline['value'] : $this->model->headline;
            $objTemplate->id = $this->model->id;
            $objTemplate->link = $this->model->name;
            $objTemplate->href = 'contao/main.php?do=themes&amp;table=tl_module&amp;act=edit&amp;id=' . $this->model->id;

            return $objTemplate->parse();
        }

        $this->setFragmentOptions([
            'type' => 'calendarEdit',
            'template' => $this->model->customTpl ?: 'mod_calendar'
        ]);

        return $this->__invoke($request, $this->model, 'main')->getContent();
    }

    protected function initializeServices(): void
    {
        $container = System::getContainer();
        $this->checkAuthService = $container->get('caledit.service.auth');
        $this->scopeMatcher = $container->get('contao.routing.scope_matcher');
        $this->requestStack = $container->get('request_stack');

        if ($this->model !== null) {
            foreach ($this->model->row() as $key => $value) {
                if (property_exists($this, $key)) {
                    $this->$key = $value;
                }
            }

            // Special handling for serialized fields
            $this->cal_calendar = StringUtil::deserialize($this->cal_calendar, true);
            $this->cal_calendar = $this->sortOutProtected($this->cal_calendar);
            $this->cal_calendar = array_map('\intval', $this->cal_calendar);

            $this->cal_holidayCalendar = StringUtil::deserialize($this->cal_holidayCalendar, true);
            $this->cal_holidayCalendar = $this->sortOutProtected($this->cal_holidayCalendar);
            $this->cal_holidayCalendar = array_map('\intval', $this->cal_holidayCalendar);
        }
    }

    protected function sortOutProtected($arrCalendars): array
    {
        if (empty($arrCalendars) || !is_array($arrCalendars)) {
            return [];
        }

        $container = System::getContainer();
        $request = $container->get('request_stack')->getCurrentRequest();

        $tokenChecker = $container->get('contao.security.token_checker');

        if ($tokenChecker->hasBackendUser() || ($request && $container->get('contao.routing.scope_matcher')->isFrontendRequest($request) && $tokenChecker->hasFrontendUser() && $container->get('security.authorization_checker')->isGranted('ROLE_ADMIN'))) {
            return $arrCalendars;
        }

        $objCalendar = CalendarModel::findAll();

        if ($objCalendar === null) {
            return [];
        }

        $arrAllowed = [];
        $this->User = FrontendUser::getInstance();

        foreach ($objCalendar as $calendar) {
            if (!$calendar->protected || ($tokenChecker->hasFrontendUser() && is_array($this->User->groups) && count(array_intersect(StringUtil::deserialize($calendar->groups, true), $this->User->groups)) > 0)) {
                $arrAllowed[] = $calendar->id;
            }
        }

        return array_intersect($arrCalendars, $arrAllowed);
    }

    public function getHolidayCalendarIDs($cals): array
    {
        $IDs = array();

        if (is_array($cals)) {
            foreach ($cals as $flupp) {
                $IDs[] = $flupp;
            }
        }
        return $IDs;
    }

    // check whether the current FE User is allowed to edit any of the calendars
    public function checkUserAuthorizations($arrCalendars): void
    {
        $this->User = FrontendUser::getInstance();

        if (empty($arrCalendars) && !empty($this->cal_calendar)) {
            $arrCalendars = $this->cal_calendar;
        }

        $this->allowElapsedEvents = false;
        $this->allowEditEvents = false;

        if (empty($arrCalendars)) {
            return;
        }

        $calendarModels = CalendarModelEdit::findByIds($arrCalendars);

        if ($calendarModels !== null) {
            foreach ($calendarModels as $calendarModel) {
                $this->allowElapsedEvents = ($this->allowElapsedEvents || $this->checkAuthService->isUserAuthorizedElapsedEvents($calendarModel, $this->User));
                $this->allowEditEvents = ($this->allowEditEvents || $this->checkAuthService->isUserAuthorized($calendarModel, $this->User));
            }
        }
    }

    // overwrite the compileWeeks-Method from ModuleCalendar
    protected function compileWeeks(): array
    {
        $request = $this->requestStack->getCurrentRequest();
        $intDaysInMonth = (int)date('t', $this->Date->monthBegin);
        $intFirstDayOffset = (int)(date('w', $this->Date->monthBegin) - $this->cal_startDay);

        if ($intFirstDayOffset < 0) {
            $intFirstDayOffset += 7;
        }

        // Check User Authorization to add Events into (one of) the Calendars used in this module
        // this will set the variables  $this->AllowEditEvents and $this->AllowElapsedEvents
        $this->checkUserAuthorizations($this->cal_calendar);

        $addUrl = '';
        if ($this->allowEditEvents) {
            // get the JumpToAdd-Page for this calendar
            $page = PageModel::findByPk($this->caledit_add_jumpTo);
            if ($page !== null) {
                $addUrl = System::getContainer()->get('contao.routing.content_url_generator')->generate($page);
            }
        }

        $intYear = date('Y', $this->Date->tstamp);
        $intMonth = date('m', $this->Date->tstamp);

        $intColumnCount = -1;
        $intNumberOfRows = ceil(($intDaysInMonth + $intFirstDayOffset) / 7);

        $calendarEventsGenerator = System::getContainer()->get('contao_calendar.generator.calendar_events');
        $allEvents = $calendarEventsGenerator->getAllEvents(
            $this->cal_calendar,
            (new \DateTime())->setTimestamp($this->Date->monthBegin),
            (new \DateTime())->setTimestamp($this->Date->monthEnd),
            null,
            $this->cal_noSpan,
            null,
            null
        );

        // HOOK: modify the result set (manually because we passed null to getAllEvents)
        if (isset($GLOBALS['TL_HOOKS']['getAllEvents']) && \is_array($GLOBALS['TL_HOOKS']['getAllEvents'])) {
            foreach ($GLOBALS['TL_HOOKS']['getAllEvents'] as $callback) {
                $allEvents = System::importStatic($callback[0])->{$callback[1]}($allEvents, $this->cal_calendar, $this->Date->monthBegin, $this->Date->monthEnd, null);
            }
        }

        $arrDays = [];

        $dateformat = Config::get('dateFormat');

        // Compile days
        for ($i = 1; $i <= ($intNumberOfRows * 7); $i++) {
            $intWeek = floor(++$intColumnCount / 7);
            $intDay = $i - $intFirstDayOffset;
            $intCurrentDay = ($i + $this->cal_startDay) % 7;

            $strWeekClass = 'week_' . $intWeek;
            $strWeekClass .= ($intWeek == 0) ? ' first' : '';
            $strWeekClass .= ($intWeek == ($intNumberOfRows - 1)) ? ' last' : '';

            $strClass = ($intCurrentDay < 2) ? ' weekend' : '';
            $strClass .= ($i == 1 || $i == 8 || $i == 15 || $i == 22 || $i == 29 || $i == 36) ? ' col_first' : '';
            $strClass .= ($i == 7 || $i == 14 || $i == 21 || $i == 28 || $i == 35 || $i == 42) ? ' col_last' : '';

            // Empty cell
            if ($intDay < 1 || $intDay > $intDaysInMonth) {
                $arrDays[$strWeekClass][$i]['label'] = '&nbsp;';
                $arrDays[$strWeekClass][$i]['class'] = 'days empty' . $strClass;
                $arrDays[$strWeekClass][$i]['events'] = array();
                $arrDays[$strWeekClass][$i]['holidayEvents'] = array();

                continue;
            }

            $intKey = date('Ym', $this->Date->tstamp) . ((strlen($intDay) < 2) ? '0' . $intDay : $intDay);
            $strClass .= ($intKey == date('Ymd')) ? ' today' : '';

            $arrDays[$strWeekClass][$i]['addLabel'] = $GLOBALS['TL_LANG']['MSC']['caledit_addLabel'];
            $arrDays[$strWeekClass][$i]['addTitle'] = $GLOBALS['TL_LANG']['MSC']['caledit_addTitle'];
            $arrDays[$strWeekClass][$i]['editLabel'] = $GLOBALS['TL_LANG']['MSC']['caledit_editLabel'];
            $arrDays[$strWeekClass][$i]['editTitle'] = $GLOBALS['TL_LANG']['MSC']['caledit_editTitle'];

            // Inactive days
            if (empty($intKey) || !isset($allEvents[$intKey])) {
                $arrDays[$strWeekClass][$i]['label'] = $intDay;
                $arrDays[$strWeekClass][$i]['class'] = 'days' . $strClass;
                // add Links to add Events, if allowed
                if ($this->allowEditEvents && ($this->allowElapsedEvents || ($intKey >= date('Ymd')))) {
                    $ts = mktime(8, 0, 0, $intMonth, $intDay, $intYear); // 8:00 at this day
                    $arrDays[$strWeekClass][$i]['addRef'] = $addUrl . '?add=' . Date::parse($dateformat, $ts);
                    //$arrDays[$strWeekClass][$i]['editRef'] = $addUrl . '?edit=' . $allEvents[$intKey]['id'];
                }
                $arrDays[$strWeekClass][$i]['events'] = [];
                $arrDays[$strWeekClass][$i]['holidayEvents'] = [];
                continue;
            }

            $events = [];
            $holidayEvents = [];

            $validHolidays = [];
            if (is_array($this->cal_holidayCalendar) && !empty($this->cal_holidayCalendar)) {
                $validHolidays = $this->getHolidayCalendarIDs($this->cal_holidayCalendar);
            }

            // Get all events of a day
            foreach ($allEvents[$intKey] as $v) {
                foreach ($v as $vv) {
                    if (in_array($vv['parent'], $validHolidays)) {
                        $holidayEvents[] = $vv;
                    } else {
                        $vv['editRef'] = $addUrl . '?edit=' . $vv['id'];
                        $vv['editTitle'] = $GLOBALS['TL_LANG']['MSC']['caledit_editTitle'];
                        $vv['editLabel'] = $GLOBALS['TL_LANG']['MSC']['caledit_editLabel'];
                        $events[] = $vv;
                    }
                }
            }

            if (count($holidayEvents) > 0) {
                $strClass .= ' holiday';
            }

            $arrDays[$strWeekClass][$i]['label'] = $intDay;
            if ($this->allowEditEvents && ($this->allowElapsedEvents || ($intKey >= date('Ymd')))) {
                $ts = mktime(8, 0, 0, $intMonth, $intDay, $intYear); // 8:00 at this day
                $arrDays[$strWeekClass][$i]['addRef'] = $addUrl . '?add=' . Date::parse($dateformat, $ts);
                //$arrDays[$strWeekClass][$i]['editRef'] = $addUrl . '?edit=' . $events[$intKey]['id'];
            }

            $arrDays[$strWeekClass][$i]['class'] = 'days active' . $strClass;
            $arrDays[$strWeekClass][$i]['href'] = $request->getPathInfo() . '?day=' . $intKey;
            $arrDays[$strWeekClass][$i]['title'] = sprintf($GLOBALS['TL_LANG']['MSC']['cal_events'], count($events));
            $arrDays[$strWeekClass][$i]['events'] = $events;
            $arrDays[$strWeekClass][$i]['holidayEvents'] = $holidayEvents ?? [];

        }
        return $arrDays;
    }

    protected function compileDays(): array
    {
        $arrDays = [];

        for ($i = 0; $i < 7; $i++) {
            $strClass = '';
            $intCurrentDay = ($i + $this->cal_startDay) % 7;

            if ($intCurrentDay == 0 || $intCurrentDay == 6) {
                $strClass = ' weekend';
            }

            $arrDays[$intCurrentDay] = array
            (
                'class' => $strClass,
                'name' => $GLOBALS['TL_LANG']['DAYS'][$intCurrentDay]
            );
        }

        return $arrDays;
    }

    protected function getResponse(FragmentTemplate $template, ModuleModel $model, Request $request): Response
    {
        $this->model = $model;
        $this->initializeServices();

        $this->cal_startDay = (int)$model->cal_startDay;

        // Get the current month and year from request
        $year = $request->query->get('year');
        $month = $request->query->get('month');

        if (!$year || !$month) {
            $year = date('Y');
            $month = date('m');
        }

        $this->Date = new Date(mktime(0, 0, 0, (int)$month, 1, (int)$year));

        // Prefixes logic
        $this->cal_ctemplate = $model->cal_ctemplate ?: ($model->cal_template ?: 'frontend_module/cal_default_edit');
        if ($this->cal_ctemplate && !str_contains($this->cal_ctemplate, '/') && (str_starts_with($this->cal_ctemplate, 'cal_') || str_starts_with($this->cal_ctemplate, 'event_'))) {
            $this->cal_ctemplate = 'frontend_module/' . $this->cal_ctemplate;
        }

        // Create the sub-template context for the calendar grid
        $subTemplateData = [
            'prevHref' => $request->getPathInfo() . '?year=' . date('Y', $this->Date->prevMonth) . '&month=' . date('m', $this->Date->prevMonth),
            'nextHref' => $request->getPathInfo() . '?year=' . date('Y', $this->Date->nextMonth) . '&month=' . date('m', $this->Date->nextMonth),
            'prevTitle' => Date::parse('F Y', $this->Date->prevMonth),
            'nextTitle' => Date::parse('F Y', $this->Date->nextMonth),
            'prevLink' => ($GLOBALS['TL_LANG']['MSC']['cal_previous'] ?? 'Previous month') . ' ' . Date::parse('F Y', $this->Date->prevMonth),
            'nextLink' => Date::parse('F Y', $this->Date->nextMonth) . ' ' . ($GLOBALS['TL_LANG']['MSC']['cal_next'] ?? 'Next month'),
            'current' => Date::parse('F Y', $this->Date->tstamp),
            'days' => $this->compileDays(),
            'weeks' => $this->compileWeeks(),
        ];

        // Assign the rendered calendar grid to the main template
        $template->calendar = $this->renderView("@Contao/{$this->cal_ctemplate}.html.twig", $subTemplateData);

        // Standard variables for block_searchable
        $cssID = StringUtil::deserialize($model->cssID, true);
        $template->class = trim('mod_' . $model->type . ' ' . ($model->class ?: '') . ' ' . ($cssID[1] ?? ''));
        $template->cssID = $cssID[0] ?? '';
        $template->type = $model->type;
        $headline = StringUtil::deserialize($model->headline);
        $template->headline = is_array($headline) ? $headline['value'] : $model->headline;
        $template->hl = $model->hl ?: 'h1';

        return $template->getResponse();
    }
}
