<?php

namespace Diversworld\CalendarEditorBundle\Controller\Module;

use Contao\CalendarEventsModel;
use Contao\CalendarModel;
use Contao\Config;
use Contao\Date;
use Contao\CoreBundle\Controller\FrontendModule\AbstractFrontendModuleController;
use Contao\CoreBundle\DependencyInjection\Attribute\AsFrontendModule;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\ModuleModel;
use Contao\PageModel;
use Contao\StringUtil;
use Contao\System;
use Contao\CoreBundle\Twig\FragmentTemplate;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[AsFrontendModule('EventHiddenList', category: 'calendar', template: 'frontend_module/mod_event_list_hidden')]
class ModuleHiddenEventlist extends AbstractFrontendModuleController
{
    /**
     * @var ModuleModel|null
     */
    protected $model;

    public function __construct(
        private ContaoFramework|ModuleModel|null $framework = null,
        ModuleModel|null                         $model = null,
    )
    {
        if ($this->framework instanceof ModuleModel) {
            $model = $this->framework;
            $this->framework = null;
        }

        if ($model !== null) {
            $this->model = $model;
        }

        $this->initializeServices();
    }

    public function generate(): string
    {
        $this->initializeServices();
        $request = System::getContainer()->get('request_stack')->getCurrentRequest();

        if ($request === null) {
            return '';
        }

        $this->setFragmentOptions([
            'type' => 'EventHiddenList',
            'template' => $this->model->customTpl ?: 'frontend_module/mod_event_list_hidden'
        ]);

        return $this->__invoke($request, $this->model, 'main')->getContent();
    }

    protected function initializeServices(): void
    {
        if ($this->framework instanceof ContaoFramework && isset($this->container)) {
            return;
        }

        $container = System::getContainer();

        if (!isset($this->container)) {
            $this->setContainer($container);
        }

        $this->framework = $container->get('contao.framework');
    }

    protected function getResponse(FragmentTemplate $template, ModuleModel $model, Request $request): Response
    {
        $this->model = $model;

        $headline = StringUtil::deserialize($model->headline);
        $template->headline = is_array($headline) ? $headline['value'] : $model->headline;
        $template->hl = $model->hl ?: 'h1';

        $cssID = StringUtil::deserialize($model->cssID, true);
        $template->class = trim('mod_' . $model->type . ' ' . ($model->class ?: '') . ' ' . ($cssID[1] ?? ''));
        $template->cssID = $cssID[0] ?? '';
        $template->type = $model->type;

        $calendars = StringUtil::deserialize($model->cal_calendar, true);
        $calendars = array_map('\intval', $calendars);

        if (empty($calendars)) {
            $template->events = [];
            $template->emptyMsg = $GLOBALS['TL_LANG']['MSC']['emptyEventList'] ?? 'No events found.';
            return $template->getResponse();
        }

        $hiddenEvents = [];
        $calendarModels = CalendarModel::findMultipleByIds($calendars);

        if ($calendarModels !== null) {
            // Get events for all calendars (from epoch to far future)
            $start = 0;
            $end = 2147483647;

            foreach ($calendarModels as $calendar) {
                $objEvents = self::findCurrentUnPublishedByPid((int)$calendar->id, $start, $end);

                if ($objEvents !== null) {
                    foreach ($objEvents as $objEvent) {
                        $hiddenEvents[] = $this->renderEvent($objEvent, $calendar, $model);
                    }
                }
            }
        }

        $template->events = $hiddenEvents;
        $template->hiddenEvents = $hiddenEvents;
        $template->emptyMsg = $GLOBALS['TL_LANG']['MSC']['emptyEventList'] ?? 'No events found.';

        return $template->getResponse();
    }

    protected function renderEvent(CalendarEventsModel $event, CalendarModel $calendar, ModuleModel $model): string
    {
        $container = System::getContainer();
        $dateFormat = Config::get('dateFormat');
        $timeFormat = Config::get('timeFormat');

        $data = [
            'title' => $event->title,
            'date' => Date::parse($dateFormat, $event->startTime),
            'time' => $event->addTime ? Date::parse($timeFormat, $event->startTime) : '',
            'day' => Date::parse('l', $event->startTime),
            'classUpcoming' => '',
        ];

        // Generation of edit link
        if ($calendar->caledit_jumpTo) {
            $objPage = PageModel::findByPk($calendar->caledit_jumpTo);
            if ($objPage !== null) {
                /** @var \Contao\CoreBundle\Routing\ContentUrlGenerator $urlGenerator */
                $urlGenerator = $container->get('contao.routing.content_url_generator');
                $data['editRef'] = $urlGenerator->generate($objPage) . '?edit=' . $event->id;
                $data['editTitle'] = sprintf($GLOBALS['TL_LANG']['MSC']['caledit_editTitle'] ?? 'Edit event %s', $event->title);
                $data['editLabel'] = $GLOBALS['TL_LANG']['MSC']['caledit_editLabel'] ?? 'Edit';
            }
        }

        $templateName = $model->cal_template ?: 'event_list_hidden_layout';

        // 1. Try with @Contao prefix directly (handles core templates and prefixed bundle templates)
        try {
            return $this->renderView("@Contao/$templateName.html.twig", $data);
        } catch (\Exception $e) {
            // 2. Try with frontend_module/ prefix if not present
            if (!str_contains($templateName, '/')) {
                try {
                    return $this->renderView("@Contao/frontend_module/$templateName.html.twig", $data);
                } catch (\Exception $ee) {
                    // Ignore
                }
            }
            // 3. Final fallback
            return $this->renderView("@Contao/frontend_module/event_list_hidden_layout.html.twig", $data);
        }
    }

    public static function findCurrentUnPublishedByPid(int $pid, int $start, int $end, array $options = [])
    {
        $t = 'tl_calendar_events';
        $start = intval($start);
        $end = intval($end);

        $arrColumns = array("$t.pid=? AND $t.published!='1' AND (($t.startTime>=$start AND $t.startTime<=$end) OR ($t.endTime>=$start AND $t.endTime<=$end) OR ($t.startTime<=$start AND $t.endTime>=$end) OR ($t.recurring='1' AND ($t.recurrences=0 OR $t.repeatEnd>=$start) AND $t.startTime<=$end))");

        if (!isset($options['order'])) {
            $options['order'] = "$t.startTime";
        }

        return CalendarEventsModel::findBy($arrColumns, $pid, $options);
    }
}
