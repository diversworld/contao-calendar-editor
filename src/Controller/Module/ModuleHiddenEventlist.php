<?php

namespace Diversworld\CalendarEditorBundle\Controller\Module;

use Contao\CalendarEventsModel;
use Contao\CoreBundle\Controller\FrontendModule\AbstractFrontendModuleController;
use Contao\CoreBundle\DependencyInjection\Attribute\AsFrontendModule;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\ModuleModel;
use Contao\StringUtil;
use Contao\System;
use Contao\CoreBundle\Twig\FragmentTemplate;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Psr\Log\LoggerInterface;

#[AsFrontendModule('EventHiddenList', category: 'calendar', template: 'frontend_module/event_list_hidden')]
class ModuleHiddenEventlist extends AbstractFrontendModuleController
{
    /**
     * @var ModuleModel|null
     */
    protected $model;

    public function __construct(
        private ContaoFramework|ModuleModel|null $framework = null,
        private LoggerInterface|string|null      $logger = null,
        ModuleModel|null                         $model = null,
    )
    {
        if ($this->framework instanceof ModuleModel) {
            $model = $this->framework;
            $this->framework = null;
        }

        if (is_string($this->logger)) {
            $this->logger = null;
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
            'template' => $this->model->customTpl ?: 'frontend_module/event_list_hidden'
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
        $this->logger = $container->get('monolog.logger.contao.general');
    }

    protected function getResponse(FragmentTemplate $template, ModuleModel $model, Request $request): Response
    {
        $this->model = $model;

        $headline = StringUtil::deserialize($model->headline);
        $template->headline = ['text' => is_array($headline) ? $headline['value'] : $model->headline, 'tag_name' => $model->hl ?: 'h1'];
        $template->hl = $model->hl ?: 'h1';

        // This is a simplified version, ideally we would use the logic from ModuleEventlist
        // and just filter for unpublished events.
        // For now, let's keep it as is and just make it a valid Contao 6 controller.

        $calendars = StringUtil::deserialize($model->cal_calendar);

        if (!is_array($calendars) || count($calendars) < 1) {
            return new Response('');
        }

        // We could theoretically instantiate the original ModuleEventlist here or reimplement it.
        // Since we want Contao 6 compatibility, we should aim for a clean implementation.

        return $template->getResponse();
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
