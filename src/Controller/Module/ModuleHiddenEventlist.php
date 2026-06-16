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
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[AsFrontendModule(ModuleHiddenEventlist::TYPE, category: 'calendar')]
class ModuleHiddenEventlist extends AbstractFrontendModuleController
{
    public const TYPE = 'event_hidden_list';

    /**
     * @var ModuleModel|null
     */
    protected $model;

    /**
     * @var FragmentTemplate
     */
    protected $Template;

    public function __construct(
        private ?ContaoFramework $framework = null,
        ModuleModel|null         $model = null,
    )
    {
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
            'type' => self::TYPE,
            'template' => $this->model->customTpl ?: 'frontend_module/event_hidden_list',
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
        $this->Template = $template;
        $this->model = $model;
        $this->addFrontendModuleTemplateDefaults($template, $model);

        $calendars = StringUtil::deserialize($model->cal_calendar, true);
        $calendars = array_map('\intval', $calendars);

        if (empty($calendars)) {
            $template->set('events', []);
            $template->set('emptyMsg', $GLOBALS['TL_LANG']['MSC']['emptyEventList'] ?? 'No events found.');
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

        $template->set('events', $hiddenEvents);
        $template->set('hiddenEvents', $hiddenEvents);
        $template->set('emptyMsg', $GLOBALS['TL_LANG']['MSC']['emptyEventList'] ?? 'No events found.');

        return $template->getResponse();
    }

    private function addFrontendModuleTemplateDefaults(FragmentTemplate $template, ModuleModel $model): void
    {
        $cssID = StringUtil::deserialize($model->cssID, true);
        $headline = StringUtil::deserialize($model->headline);
        $data = $model->row();

        $data += [
            'subline' => '',
            'headline_inline' => '',
            'subheadline' => '',
        ];

        $template->set('type', $model->type ?: self::TYPE);
        $template->set('element_html_id', $cssID[0] ?? null);
        $template->set('element_css_classes', trim('mod_' . $model->type . ' ' . ($model->class ?: '') . ' ' . ($cssID[1] ?? '')));
        $template->set('headline', [
            'text' => is_array($headline) ? ($headline['value'] ?? '') : (string)$model->headline,
            'tag_name' => is_array($headline) ? ($headline['unit'] ?? 'h1') : ($model->hl ?: 'h1'),
        ]);
        $template->set('data', $data);
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
            'editRef' => null,
            'editTitle' => null,
            'editLabel' => null,
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

        return $this->renderEventTemplate($templateName, $data);
    }

    private function renderEventTemplate(string $templateName, array $data): string
    {
        $templateName = $this->normalizeTemplateName($templateName);

        $aliases = [
            'event_list_hidden' => 'event_list_hidden_layout',
            'mod_event_list_hidden' => 'event_list_hidden_layout',
            'frontend_module/event_list_hidden' => 'frontend_module/event_list_hidden_layout',
            'frontend_module/mod_event_list_hidden' => 'frontend_module/event_list_hidden_layout',
        ];

        $templateName = $aliases[$templateName] ?? $templateName;

        $candidates = [$templateName];

        if (!str_contains($templateName, '/')) {
            $candidates[] = 'frontend_module/' . $templateName;
        }

        $lastException = null;

        foreach (array_unique($candidates) as $candidate) {
            try {
                return $this->renderView(
                    '@Contao/' . $candidate . '.html.twig',
                    array_merge(
                        [
                            'type' => $this->model?->type ?? self::TYPE,
                            'element_html_id' => null,
                            'element_css_classes' => '',
                            'headline' => [
                                'text' => '',
                                'tag_name' => 'h1',
                            ],
                        ],
                        $data,
                    )
                );
            } catch (\Throwable $e) {
                $lastException = $e;
            }
        }

        throw new RuntimeException(
            sprintf('Could not find or render hidden event template "%s".', $templateName),
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

        if (str_ends_with($templateName, '.html.Twig')) {
            return substr($templateName, 0, -10);
        }

        if (str_ends_with($templateName, '.html5')) {
            return substr($templateName, 0, -6);
        }

        return $templateName;
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
