<?php

namespace Diversworld\CalendarEditorBundle\Controller\Module;

use Contao\CalendarEventsModel;
use Contao\CoreBundle\Controller\FrontendModule\AbstractFrontendModuleController;
use Contao\CoreBundle\DependencyInjection\Attribute\AsFrontendModule;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\FrontendUser;
use Contao\ModuleModel;
use Contao\PageModel;
use Contao\StringUtil;
use Contao\System;
use Contao\CoreBundle\Twig\FragmentTemplate;
use Diversworld\CalendarEditorBundle\Models\CalendarModelEdit;
use Diversworld\CalendarEditorBundle\Services\CheckAuthService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Bundle\SecurityBundle\Security;

#[AsFrontendModule('EventReaderEditLink', category: 'calendar', template: 'frontend_module/event_reader_edit_link')]
class ModuleEventReaderEdit extends AbstractFrontendModuleController
{
    /**
     * @var ModuleModel|null
     */
    protected $model;

    public function __construct(
        private CheckAuthService|ModuleModel|null $checkAuthService = null,
        private ContaoFramework|string|null       $framework = null,
        private ?Security                         $security = null,
        ModuleModel|null                          $model = null,
    )
    {
        if ($this->checkAuthService instanceof ModuleModel) {
            $model = $this->checkAuthService;
            $this->checkAuthService = null;
        }

        if (is_string($this->framework)) {
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
            'type' => 'EventReaderEditLink',
            'template' => $this->model->customTpl ?: 'frontend_module/event_reader_edit_link'
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
        $this->checkAuthService = $container->get('caledit.service.auth');
        $this->security = $container->get('security.helper');
    }

    protected function getResponse(FragmentTemplate $template, ModuleModel $model, Request $request): Response
    {
        $this->model = $model;

        // Return if no event has been specified
        if (!$request->query->has('auto_item') && !($request->attributes->has('_route_params') && isset($request->attributes->get('_route_params')['auto_item']))) {
            return new Response('');
        }

        $autoItem = $request->query->get('auto_item') ?: $request->attributes->get('_route_params')['auto_item'];

        $calendars = StringUtil::deserialize($model->cal_calendar, true);
        $calendars = array_map('\intval', $calendars);

        // Return if there are no calendars
        if (!is_array($calendars) || count($calendars) < 1) {
            return new Response('');
        }

        $template->editRef = '';

        $cssID = StringUtil::deserialize($model->cssID, true);
        $template->class = trim('mod_' . $model->type . ' ' . ($model->class ?: '') . ' ' . ($cssID[1] ?? ''));
        $template->cssID = $cssID[0] ?? '';
        $template->type = $model->type;

        $headline = StringUtil::deserialize($model->headline);
        $headlineText = is_array($headline) ? $headline['value'] : $model->headline;
        $template->headline = [
            'text' => $headlineText,
            'unit' => $model->hl ?: 'h1'
        ];
        $template->hl = $model->hl ?: 'h1';
        $user = $this->security->getUser();

        if (!$user instanceof FrontendUser) {
            $user = null;
        }

        /** @var CalendarEventsModel $calendarEventsModelAdapter */
        $calendarEventsModelAdapter = $this->framework->getAdapter(CalendarEventsModel::class);

        $hasBackendUser = $this->security->isGranted('ROLE_USER'); // Simplified for now, or use proper Contao role

        // Get current event
        if (!$hasBackendUser) {
            $objEvent = $calendarEventsModelAdapter->findPublishedByParentAndIdOrAlias($autoItem, $calendars);
        } else {
            $objEvent = $calendarEventsModelAdapter->findByIdOrAlias($autoItem);
            if ($objEvent !== null && !in_array($objEvent->pid, $calendars)) {
                $objEvent = null;
            }
        }

        if ($objEvent === null) {
            $template->error = $GLOBALS['TL_LANG']['MSC']['caledit_NoEditAllowed'];
            $template->error_class = 'error';
            return $template->getResponse();
        }

        /** @var CalendarModelEdit $calendarModelEditAdapter */
        $calendarModelEditAdapter = $this->framework->getAdapter(CalendarModelEdit::class);
        $calendarModel = $calendarModelEditAdapter->findByPk($objEvent->pid);

        if ($calendarModel === null) {
            return new Response('');
        }

        if ($calendarModel->AllowEdit === '1') {
            $isUserAuthorized = $this->checkAuthService->isUserAuthorized($calendarModel, $user);
            $isUserAdmin = $this->checkAuthService->isUserAdmin($calendarModel, $user);
            $authorizedElapsedEvents = $this->checkAuthService->isUserAuthorizedElapsedEvents($calendarModel, $user);
            $areEditLinksAllowed = $this->checkAuthService->areEditLinksAllowed($calendarModel, $objEvent->row(), $user ? (int)$user->id : 0, $isUserAdmin, $isUserAuthorized);

            if ($areEditLinksAllowed) {
                /** @var PageModel $pageModelAdapter */
                $pageModelAdapter = $this->framework->getAdapter(PageModel::class);
                $objPage = $pageModelAdapter->findByPk($calendarModel->caledit_jumpTo);

                if ($objPage !== null) {
                    $strUrl = System::getContainer()->get('contao.routing.content_url_generator')->generate($objPage);

                    $template->editRef = $strUrl . '?edit=' . $objEvent->id;
                    $template->editLabel = $GLOBALS['TL_LANG']['MSC']['caledit_editLabel'];
                    $template->editTitle = $GLOBALS['TL_LANG']['MSC']['caledit_editTitle'];

                    if ($model->caledit_showCloneLink) {
                        $template->cloneRef = $strUrl . '?clone=' . $objEvent->id;
                        $template->cloneLabel = $GLOBALS['TL_LANG']['MSC']['caledit_cloneLabel'];
                        $template->cloneTitle = $GLOBALS['TL_LANG']['MSC']['caledit_cloneTitle'];
                    }
                    if ($model->caledit_showDeleteLink) {
                        $template->deleteRef = $strUrl . '?delete=' . $objEvent->id;
                        $template->deleteLabel = $GLOBALS['TL_LANG']['MSC']['caledit_deleteLabel'];
                        $template->deleteTitle = $GLOBALS['TL_LANG']['MSC']['caledit_deleteTitle'];
                    }
                }
            } else {
                if (!$isUserAuthorized) {
                    $template->error_class = 'error';
                    $template->error = $GLOBALS['TL_LANG']['MSC']['caledit_UnauthorizedUser'];
                    return $template->getResponse();
                }

                if ($objEvent->disable_editing) {
                    $template->error = $GLOBALS['TL_LANG']['MSC']['caledit_DisabledEvent'];
                    $template->error_class = 'error';
                } else {
                    if (!$authorizedElapsedEvents) {
                        $template->error = $GLOBALS['TL_LANG']['MSC']['caledit_NoPast'];
                    } else {
                        $template->error = $GLOBALS['TL_LANG']['MSC']['caledit_OnlyUser'];
                    }
                    $template->error_class = 'error';
                }
            }
        } else {
            $template->error_class = 'error';
            $template->error = $GLOBALS['TL_LANG']['MSC']['caledit_NoEditAllowed'];
        }

        return $template->getResponse();
    }
}
