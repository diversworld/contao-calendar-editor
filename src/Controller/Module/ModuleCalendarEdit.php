<?php

namespace Diversworld\CalendarEditorBundle\Controller\Module;

use Contao\Config;
use Contao\ModuleModel;
use Contao\Date;
use Contao\FrontendUser;
use Contao\PageModel;
use Contao\StringUtil;
use Contao\System;
use Diversworld\CalendarEditorBundle\Models\CalendarModelEdit;
use Diversworld\CalendarEditorBundle\Services\CheckAuthService;
use Contao\CoreBundle\Routing\ScopeMatcher;
use Symfony\Component\HttpFoundation\RequestStack;

use Contao\CoreBundle\Framework\ContaoFramework;
use Symfony\Bundle\SecurityBundle\Security;
use Contao\CoreBundle\Controller\FrontendModule\AbstractFrontendModuleController;
use Contao\CoreBundle\DependencyInjection\Attribute\AsFrontendModule;
use Contao\CoreBundle\Twig\FragmentTemplate;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use RuntimeException;

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

    /**
     * @var FragmentTemplate
     */
    protected $Template;

    public function __construct(
        private readonly ?ScopeMatcher     $scopeMatcher = null,
        private readonly ?RequestStack     $requestStack = null,
        private readonly ?CheckAuthService $checkAuthService = null,
        private readonly ?ContaoFramework  $framework = null,
        private readonly ?Security         $security = null,
        ModuleModel|null                   $model = null,
    )
    {
        if ($model !== null) {
            $this->model = $model;
        }
    }

    protected function getResponse(FragmentTemplate $template, ModuleModel $model, Request $request): Response
    {
        $this->Template = $template;
        $this->model = $model;

        // Map properties to $this for internal use to avoid dynamic property warnings
        $this->cal_calendar = StringUtil::deserialize($model->cal_calendar, true);
        $this->cal_holidayCalendar = StringUtil::deserialize($model->cal_holidayCalendar, true);
        $this->caledit_add_jumpTo = $model->caledit_add_jumpTo;
        $this->cal_startDay = (int)$model->cal_startDay;
        $this->cal_noSpan = (bool)$model->cal_noSpan;

        // Special handling for serialized fields
        $this->cal_calendar = $this->sortOutProtected($this->cal_calendar);
        $this->cal_calendar = array_map('\intval', $this->cal_calendar);

        $this->cal_holidayCalendar = $this->sortOutProtected($this->cal_holidayCalendar);
        $this->cal_holidayCalendar = array_map('\intval', $this->cal_holidayCalendar);

        $year = $request->query->get('year');
        $month = $request->query->get('month');

        if (!$year && is_string($month) && preg_match('/^\d{6}$/', $month)) {
            $year = substr($month, 0, 4);
            $month = substr($month, 4, 2);
        }

        if (!$year || !$month || !preg_match('/^\d{4}$/', (string) $year) || !preg_match('/^\d{1,2}$/', (string) $month)) {
            $year = date('Y');
            $month = date('m');
        }

        $this->Date = new Date(mktime(0, 0, 0, (int)$month, 1, (int)$year));

        // Prefixes logic
        $this->cal_ctemplate = $model->cal_ctemplate ?: 'cal_default_edit';

        $intYear = (int) date('Y', $this->Date->tstamp);
        $intMonth = (int) date('m', $this->Date->tstamp);

        $prevMonth = ($intMonth === 1) ? 12 : ($intMonth - 1);
        $prevYear = ($intMonth === 1) ? ($intYear - 1) : $intYear;
        $nextMonth = ($intMonth === 12) ? 1 : ($intMonth + 1);
        $nextYear = ($intMonth === 12) ? ($intYear + 1) : $intYear;

        $previousLabel = ($GLOBALS['TL_LANG']['MONTHS'][$prevMonth - 1] ?? Date::parse('F', mktime(0, 0, 0, $prevMonth, 1, $prevYear))) . ' ' . $prevYear;
        $nextLabel = ($GLOBALS['TL_LANG']['MONTHS'][$nextMonth - 1] ?? Date::parse('F', mktime(0, 0, 0, $nextMonth, 1, $nextYear))) . ' ' . $nextYear;

        $subTemplateData = [
            'prevHref' => $request->getPathInfo() . '?month=' . $prevYear . str_pad((string) $prevMonth, 2, '0', STR_PAD_LEFT),
            'nextHref' => $request->getPathInfo() . '?month=' . $nextYear . str_pad((string) $nextMonth, 2, '0', STR_PAD_LEFT),
            'prevTitle' => $previousLabel,
            'nextTitle' => $nextLabel,
            'prevLink' => ($GLOBALS['TL_LANG']['MSC']['cal_previous'] ?? 'Previous month') . ' ' . $previousLabel,
            'nextLink' => $nextLabel . ' ' . ($GLOBALS['TL_LANG']['MSC']['cal_next'] ?? 'Next month'),
            'current' => ($GLOBALS['TL_LANG']['MONTHS'][$intMonth - 1] ?? Date::parse('F', $this->Date->tstamp)) . ' ' . $intYear,
            'days' => $this->compileDays(),
            'weeks' => $this->compileWeeks(),
        ];

        $template->calendar = $this->renderCalendarTemplate($this->cal_ctemplate, $subTemplateData);

        // Standard variables for block_searchable
        $template->class = trim('mod_' . $model->type . ' ' . ($model->class ?: ''));

        $cssID = StringUtil::deserialize($model->cssID, true);
        $template->cssID = $cssID[0] ?? '';
        if (isset($cssID[1]) && $cssID[1] !== '') {
            $template->class .= ' ' . $cssID[1];
        }
        $template->type = $model->type;
        $headline = StringUtil::deserialize($model->headline);
        $template->headline = is_array($headline) ? ($headline['value'] ?? '') : $model->headline;
        $template->hl = $model->hl ?: 'h1';

        return $template->getResponse();
    }

    /**
     * Sort out protected calendars
     */
    protected function sortOutProtected(array $arrCalendars): array
    {
        if (empty($arrCalendars)) {
            return [];
        }

        $arrResult = [];
        $frontendUser = $this->getFrontendUser();

        /** @var CalendarModelEdit $calendarAdapter */
        $calendarAdapter = $this->framework->getAdapter(CalendarModelEdit::class);
        $objCalendars = $calendarAdapter->findByIds($arrCalendars);

        if ($objCalendars === null) {
            return [];
        }

        foreach ($objCalendars as $objCalendar) {
            $userGroups = is_array($frontendUser?->groups) ? $frontendUser->groups : [];

            if (!$objCalendar->protected || ($frontendUser !== null && count(array_intersect(StringUtil::deserialize($objCalendar->groups, true), $userGroups)) > 0)) {
                $arrResult[] = $objCalendar->id;
            }
        }

        return $arrResult;
    }

    protected function compileDays(): array
    {
        $arrDays = [];
        $arrDaysOfWeek = [0, 1, 2, 3, 4, 5, 6];

        if ($this->cal_startDay > 0) {
            for ($i = 0; $i < $this->cal_startDay; $i++) {
                $day = array_shift($arrDaysOfWeek);
                $arrDaysOfWeek[] = $day;
            }
        }

        foreach ($arrDaysOfWeek as $i) {
            $arrDays[] = [
                'name' => $GLOBALS['TL_LANG']['DAYS_SHORT'][$i],
                'label' => $GLOBALS['TL_LANG']['DAYS'][$i],
                'slabel' => $GLOBALS['TL_LANG']['DAYS_SHORT'][$i],
                'class' => ' ' . strtolower($GLOBALS['TL_LANG']['DAYS_SHORT'][$i]) . (($i == 0 || $i == 6) ? ' weekend' : ''),
            ];
        }

        return $arrDays;
    }

    public function getHolidayCalendarIDs(array $calendars): array
    {
        return array_map('\intval', $calendars);
    }

    protected function checkUserAuthorizations(array $calendars): void
    {
        $this->allowElapsedEvents = false;
        $this->allowEditEvents = false;

        if (empty($calendars)) {
            return;
        }

        $frontendUser = $this->getFrontendUser();

        /** @var CalendarModelEdit $calendarAdapter */
        $calendarAdapter = $this->framework->getAdapter(CalendarModelEdit::class);
        $calendarModels = $calendarAdapter->findByIds($calendars);

        if ($calendarModels === null) {
            return;
        }

        foreach ($calendarModels as $calendarModel) {
            $this->allowElapsedEvents = $this->allowElapsedEvents || $this->checkAuthService->isUserAuthorizedElapsedEvents($calendarModel, $frontendUser);
            $this->allowEditEvents = $this->allowEditEvents || $this->checkAuthService->isUserAuthorized($calendarModel, $frontendUser);
        }
    }

    private function getFrontendUser(): FrontendUser|null
    {
        $tokenChecker = System::getContainer()->get('contao.security.token_checker');

        if ($tokenChecker->hasFrontendUser()) {
            $user = FrontendUser::getInstance();

            if ($user instanceof FrontendUser) {
                return $user;
            }
        }

        $user = $this->security?->getUser();

        return $user instanceof FrontendUser ? $user : null;
    }

    private function getCalendarEditUrls(array $calendars): array
    {
        if (empty($calendars)) {
            return [];
        }

        $frontendUser = $this->getFrontendUser();

        /** @var CalendarModelEdit $calendarAdapter */
        $calendarAdapter = $this->framework->getAdapter(CalendarModelEdit::class);
        $calendarModels = $calendarAdapter->findByIds($calendars);

        if ($calendarModels === null) {
            return [];
        }

        $urls = [];
        $urlGenerator = System::getContainer()->get('contao.routing.content_url_generator');

        foreach ($calendarModels as $calendarModel) {
            if (!$this->checkAuthService->isUserAuthorized($calendarModel, $frontendUser) || !$calendarModel->caledit_jumpTo) {
                continue;
            }

            $page = PageModel::findByPk($calendarModel->caledit_jumpTo);

            if ($page !== null) {
                $urls[(int) $calendarModel->id] = $urlGenerator->generate($page);
            }
        }

        return $urls;
    }

    private function renderCalendarTemplate(string $templateName, array $data): string
    {
        $templateName = $this->normalizeTemplateName($templateName);
        $templateName = match ($templateName) {
            'frontend_module/cal_default_edit' => 'cal_default_edit',
            default => $templateName,
        };

        $candidates = [$templateName];

        if (!str_contains($templateName, '/')) {
            $candidates[] = 'frontend_module/' . $templateName;
        }

        $lastException = null;

        foreach (array_unique($candidates) as $candidate) {
            try {
                return $this->renderView('@Contao/' . $candidate . '.html.twig', $data);
            } catch (\Throwable $e) {
                $lastException = $e;
            }
        }

        throw new RuntimeException(
            sprintf('Could not find or render calendar template "%s".', $templateName),
            0,
            $lastException
        );
    }

    private function normalizeTemplateName(string $templateName): string
    {
        $templateName = trim($templateName);

        if (str_starts_with($templateName, '@Contao/')) {
            $templateName = substr($templateName, 8);
        }

        if (str_ends_with($templateName, '.html.twig')) {
            return substr($templateName, 0, -10);
        }

        if (str_ends_with($templateName, '.html5')) {
            return substr($templateName, 0, -6);
        }

        return $templateName;
    }

    protected function compileWeeks(): array
    {
        $request = $this->requestStack?->getCurrentRequest() ?? System::getContainer()->get('request_stack')->getCurrentRequest();
        $intDaysInMonth = (int)date('t', $this->Date->monthBegin);
        $intFirstDayOffset = (int)(date('w', $this->Date->monthBegin) - $this->cal_startDay);

        if ($intFirstDayOffset < 0) {
            $intFirstDayOffset += 7;
        }

        $this->checkUserAuthorizations($this->cal_calendar);

        $addUrl = '';

        if ($this->allowEditEvents && $this->caledit_add_jumpTo) {
            $page = PageModel::findByPk($this->caledit_add_jumpTo);

            if ($page !== null) {
                $addUrl = System::getContainer()->get('contao.routing.content_url_generator')->generate($page);
            }
        }

        $intYear = date('Y', $this->Date->tstamp);
        $intMonth = date('m', $this->Date->tstamp);
        $intColumnCount = -1;
        $intNumberOfRows = (int)ceil(($intDaysInMonth + $intFirstDayOffset) / 7);

        $moduleProxy = new class($this->model) extends \Contao\Module {
            protected function compile(): void
            {
            }
        };

        $calendarEventsGenerator = System::getContainer()->get('contao_calendar.generator.calendar_events');
        $allEvents = $calendarEventsGenerator->getAllEvents(
            $this->cal_calendar,
            (new \DateTime())->setTimestamp($this->Date->monthBegin),
            (new \DateTime())->setTimestamp($this->Date->monthEnd),
            null,
            $this->cal_noSpan,
            null,
            $moduleProxy
        );

        $arrDays = [];
        $dateFormat = Config::get('dateFormat');
        $editUrls = $this->getCalendarEditUrls($this->cal_calendar);
        $validHolidays = is_array($this->cal_holidayCalendar) && !empty($this->cal_holidayCalendar)
            ? $this->getHolidayCalendarIDs($this->cal_holidayCalendar)
            : [];

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

            if ($intDay < 1 || $intDay > $intDaysInMonth) {
                $arrDays[$strWeekClass][$i] = [
                    'label' => '&nbsp;',
                    'class' => 'days empty' . $strClass,
                    'events' => [],
                    'holidayEvents' => [],
                ];

                continue;
            }

            $intKey = date('Ym', $this->Date->tstamp) . str_pad((string)$intDay, 2, '0', STR_PAD_LEFT);
            $strClass .= ($intKey == date('Ymd')) ? ' today' : '';

            $dayData = [
                'label' => $intDay,
                'class' => 'days' . $strClass,
                'addLabel' => $GLOBALS['TL_LANG']['MSC']['caledit_addLabel'] ?? 'Add event',
                'addTitle' => $GLOBALS['TL_LANG']['MSC']['caledit_addTitle'] ?? 'Add event',
                'editLabel' => $GLOBALS['TL_LANG']['MSC']['caledit_editLabel'] ?? 'Edit',
                'editTitle' => $GLOBALS['TL_LANG']['MSC']['caledit_editTitle'] ?? 'Edit event',
                'events' => [],
                'holidayEvents' => [],
            ];

            if ($this->allowEditEvents && $addUrl !== '' && ($this->allowElapsedEvents || ($intKey >= date('Ymd')))) {
                $timestamp = mktime(8, 0, 0, (int)$intMonth, $intDay, (int)$intYear);
                $dayData['addRef'] = $addUrl . '?add=' . Date::parse($dateFormat, $timestamp);
            }

            if (isset($allEvents[$intKey])) {
                $events = [];
                $holidayEvents = [];

                foreach ($allEvents[$intKey] as $eventGroup) {
                    foreach ($eventGroup as $event) {
                        if (in_array((int)$event['parent'], $validHolidays, true)) {
                            $holidayEvents[] = $event;
                            continue;
                        }

                        $eventCalendarId = (int) $event['parent'];

                        if (empty($event['editRef'])) {
                            $event['editRef'] = isset($editUrls[$eventCalendarId]) ? $editUrls[$eventCalendarId] . '?edit=' . $event['id'] : '';
                        }

                        $event['editTitle'] = $event['editTitle'] ?? ($GLOBALS['TL_LANG']['MSC']['caledit_editTitle'] ?? 'Edit event');
                        $event['editLabel'] = $event['editLabel'] ?? ($GLOBALS['TL_LANG']['MSC']['caledit_editLabel'] ?? 'Edit');

                        $events[] = $event;
                    }
                }

                if (!empty($events) || !empty($holidayEvents)) {
                    $dayData['class'] = 'days active' . $strClass . (!empty($holidayEvents) ? ' holiday' : '');
                    $dayData['href'] = ($request?->getPathInfo() ?? '') . '?day=' . $intKey;
                    $dayData['title'] = sprintf($GLOBALS['TL_LANG']['MSC']['cal_events'] ?? '%s events', count($events));
                }

                $dayData['events'] = $events;
                $dayData['holidayEvents'] = $holidayEvents;
            }

            $arrDays[$strWeekClass][$i] = $dayData;
        }

        return $arrDays;
    }
}
